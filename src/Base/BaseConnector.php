<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Base;

use Http\Discovery\Exception\NotFoundException;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Thinwrap\Llm\Enum\ProviderCode;
use Thinwrap\Llm\Exception\ConnectorError;

/**
 * Shared transport for every connector: lazy PSR-18/PSR-17 discovery, JSON verb
 * helpers, credential-redacting error handling, and an SSE parser that reads the
 * `text/event-stream` body incrementally off the PSR-7 stream.
 *
 * Holds no state — no cache, no retry, no pool.
 */
abstract class BaseConnector
{
    /** Residual-buffer cap: one SSE event without a boundary may not exceed 16 MiB. */
    protected const MAX_SSE_EVENT_CHARS = 16 * 1024 * 1024;

    public function __construct(
        protected readonly ?ClientInterface $client = null,
        protected readonly ?RequestFactoryInterface $requestFactory = null,
        protected readonly ?StreamFactoryInterface $streamFactory = null,
    ) {}

    protected function getClient(): ClientInterface
    {
        if ($this->client !== null) {
            return $this->client;
        }

        try {
            return Psr18ClientDiscovery::find();
        } catch (NotFoundException $err) {
            throw new ConnectorError(
                providerCode: ProviderCode::ProviderUnavailable,
                providerMessage: 'No PSR-18 HTTP client found — install one (e.g. guzzlehttp/guzzle) or inject one',
                cause: ['raw' => ['exception' => $err::class, 'message' => $err->getMessage()], 'retryAfter' => null],
            );
        }
    }

    protected function getRequestFactory(): RequestFactoryInterface
    {
        return $this->requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
    }

    protected function getStreamFactory(): StreamFactoryInterface
    {
        return $this->streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    /**
     * @param array<string,mixed>  $body
     * @param array<string,string> $headers
     */
    protected function sendPostJson(string $url, array $body, array $headers = []): ResponseInterface
    {
        try {
            $serialized = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $err) {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Failed to encode request body',
                cause: ['raw' => ['exception' => $err::class], 'retryAfter' => null],
            );
        }

        return $this->sendPostJsonRaw($url, $serialized, $headers);
    }

    /**
     * Sends a pre-serialized body byte-for-byte (required for AWS SigV4, whose
     * signature is over the exact bytes on the wire).
     *
     * @param array<string,string> $headers
     */
    protected function sendPostJsonRaw(string $url, string $rawJsonBody, array $headers = []): ResponseInterface
    {
        $request = $this->getRequestFactory()->createRequest('POST', $url)
            ->withBody($this->getStreamFactory()->createStream($rawJsonBody));

        $finalHeaders = array_merge(['Content-Type' => 'application/json'], $headers);
        foreach ($finalHeaders as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->dispatch($request);
    }

    private function dispatch(RequestInterface $request): ResponseInterface
    {
        try {
            return $this->getClient()->sendRequest($request);
        } catch (ClientExceptionInterface $err) {
            // Never echo the raw message — some provider URLs carry the credential.
            throw new ConnectorError(
                providerCode: ProviderCode::ProviderUnavailable,
                providerMessage: 'Upstream transport error',
                cause: ['raw' => ['exception' => $err::class], 'retryAfter' => null],
            );
        }
    }

    /**
     * Reads and JSON-decodes a full response body. Returns null on empty/invalid
     * body (parity with the TS `.catch(() => null)`).
     *
     * @return array<mixed>|null
     */
    protected function decodeJson(ResponseInterface $response): ?array
    {
        $raw = (string) $response->getBody();
        if ($raw === '') {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Guard a decoded 2xx body: `null` means the body was empty or not JSON (a
     * proxy/captive-portal HTML page, a truncated read). Parsing that into an
     * empty "successful" result is silent data loss, so raise instead.
     *
     * @param array<mixed>|null $body
     * @return array<mixed>
     */
    protected function requireDecodedBody(?array $body, int $status): array
    {
        if ($body === null) {
            $msg = 'Provider returned a successful status with an empty or non-JSON body';

            throw new ConnectorError(
                providerCode: ProviderCode::ProviderUnavailable,
                providerMessage: $msg,
                statusCode: $status,
            );
        }

        return $body;
    }

    /**
     * Yields each JSON-decoded `data:` payload from a Server-Sent-Events body,
     * stopping at `[DONE]`. Mirrors the TS `parseSSEStream` boundary handling,
     * `[DONE]` semantics, residual tail flush, and 16 MiB buffer cap.
     *
     * @return \Generator<int,array<mixed>>
     */
    protected function parseSSEStream(StreamInterface $body): \Generator
    {
        // The body is closed deterministically in `finally` — this covers normal
        // completion, `[DONE]` early return, the buffer-cap throw, an error thrown
        // by the caller mapping a chunk, and consumer `break` (PHP runs a
        // generator's `finally` when it is destroyed). Never leaks the connection.
        try {
            $buffer = '';

            while (!$body->eof()) {
                try {
                    $chunk = $body->read(8192);
                } catch (ConnectorError $err) {
                    throw $err;
                } catch (\RuntimeException $err) {
                    // A mid-stream read failure (dropped connection, broken PSR-7
                    // stream) must surface with the ConnectorError taxonomy, not as a
                    // raw RuntimeException — streams are the failures a consumer can
                    // least easily catch generically. (The buffer-cap ConnectorError
                    // is itself a RuntimeException, so it is re-thrown untouched above.)
                    throw new ConnectorError(
                        providerCode: ProviderCode::ProviderUnavailable,
                        providerMessage: 'Stream read failed',
                        cause: ['raw' => ['exception' => $err::class], 'retryAfter' => null],
                    );
                }
                if ($chunk === '') {
                    continue;
                }
                $buffer .= $chunk;

                while (($boundary = $this->indexOfEventBoundary($buffer)) !== -1) {
                    $rawEvent = substr($buffer, 0, $boundary);
                    $buffer = ltrim(substr($buffer, $boundary), "\r\n");
                    $payload = $this->extractData($rawEvent);
                    if ($payload === null) {
                        continue;
                    }
                    if ($payload === '[DONE]') {
                        return;
                    }
                    $parsed = $this->tryParseJson($payload);
                    if ($parsed !== null) {
                        yield $parsed;
                    }
                }

                if (strlen($buffer) > self::MAX_SSE_EVENT_CHARS) {
                    throw new ConnectorError(
                        providerCode: ProviderCode::ProviderUnavailable,
                        providerMessage: 'SSE event exceeded ' . self::MAX_SSE_EVENT_CHARS . ' characters without a boundary',
                    );
                }
            }

            // Flush residual tail after the stream ends.
            $tail = trim($buffer);
            if ($tail !== '') {
                $payload = $this->extractData($tail);
                if ($payload !== null && $payload !== '[DONE]') {
                    $parsed = $this->tryParseJson($payload);
                    if ($parsed !== null) {
                        yield $parsed;
                    }
                }
            }
        } finally {
            $body->close();
        }
    }

    private function indexOfEventBoundary(string $buffer): int
    {
        // An event boundary is a blank line: two consecutive line terminators.
        // The SSE spec permits CRLF, CR, or LF, so accept any pairing (\n\n,
        // \r\n\r\n, \r\r, and mixed \n\r\n / \r\n\n) — a CR-only or mixed upstream
        // would otherwise never split and buffer until the 16 MiB cap.
        if (preg_match('/(?:\r\n|\r|\n)(?:\r\n|\r|\n)/', $buffer, $m, PREG_OFFSET_CAPTURE) === 1) {
            return (int) $m[0][1];
        }

        return -1;
    }

    private function extractData(string $rawEvent): ?string
    {
        $lines = preg_split('/\r\n|\r|\n/', $rawEvent);
        if ($lines === false) {
            return null;
        }

        $parts = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, 'data:')) {
                $value = substr($line, 5);
                if (str_starts_with($value, ' ')) {
                    $value = substr($value, 1);
                }
                $parts[] = $value;
            }
        }

        return $parts === [] ? null : implode("\n", $parts);
    }

    /**
     * @return array<mixed>|null
     */
    private function tryParseJson(string $text): ?array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Number-or-HTTP-date `Retry-After` parse. Shared shape across connectors.
     */
    protected static function parseRetryAfterSeconds(string $header): ?int
    {
        if (is_numeric($header)) {
            return (int) round((float) $header);
        }

        $timestamp = strtotime($header);
        if ($timestamp !== false) {
            $seconds = $timestamp - time();

            return $seconds >= 0 ? $seconds : 0;
        }

        return null;
    }
}

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
        if ($this->requestFactory !== null) {
            return $this->requestFactory;
        }

        try {
            return Psr17FactoryDiscovery::findRequestFactory();
        } catch (NotFoundException $err) {
            throw self::noFactory($err);
        }
    }

    protected function getStreamFactory(): StreamFactoryInterface
    {
        if ($this->streamFactory !== null) {
            return $this->streamFactory;
        }

        try {
            return Psr17FactoryDiscovery::findStreamFactory();
        } catch (NotFoundException $err) {
            throw self::noFactory($err);
        }
    }

    private static function noFactory(NotFoundException $err): ConnectorError
    {
        return new ConnectorError(
            providerCode: ProviderCode::ProviderUnavailable,
            providerMessage: 'No PSR-17 factory found — install one (e.g. guzzlehttp/psr7 or nyholm/psr7) or inject one',
            cause: ['raw' => ['exception' => $err::class, 'message' => $err->getMessage()], 'retryAfter' => null],
        );
    }

    /**
     * Appends `_passthrough['query']` to a URL that may already carry a query.
     *
     * @param array<string,mixed> $query
     */
    protected static function appendQuery(#[\SensitiveParameter] string $url, #[\SensitiveParameter] array $query): string
    {
        if ($query === []) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The URL, body and headers are `#[\SensitiveParameter]`: they carry the API key
     * (header or query) and the prompt, and PHP otherwise records them verbatim in
     * every exception's stack trace — which ends up in logs and error trackers.
     *
     * @param array<string,mixed>  $body
     * @param array<string,string> $headers
     */
    protected function sendPostJson(
        #[\SensitiveParameter]
        string $url,
        #[\SensitiveParameter]
        array $body,
        #[\SensitiveParameter]
        array $headers = [],
    ): ResponseInterface {
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
    protected function sendPostJsonRaw(
        #[\SensitiveParameter]
        string $url,
        #[\SensitiveParameter]
        string $rawJsonBody,
        #[\SensitiveParameter]
        array $headers = [],
    ): ResponseInterface {
        $requestFactory = $this->getRequestFactory();
        $streamFactory = $this->getStreamFactory();

        $finalHeaders = array_merge(['Content-Type' => 'application/json'], $headers);
        // Checked here rather than left to PSR-7: nyholm/psr7 accepts a value
        // ending in "\n", so a key pasted with a trailing newline would reach the
        // wire (or a header-splitting proxy) on that library and not on others.
        foreach ($finalHeaders as $headerName => $value) {
            $headerName = (string) $headerName;
            $safeName = !self::hasLineBreakOrNul($headerName);
            if (!$safeName || self::hasLineBreakOrNul($value)) {
                throw new ConnectorError(
                    providerCode: ProviderCode::InvalidRequest,
                    providerMessage: $safeName
                        ? "Request header '{$headerName}' contains CR, LF or NUL (check the configured key for a stray newline)"
                        : 'A request header name contains CR, LF or NUL',
                    cause: ['raw' => null, 'retryAfter' => null],
                );
            }
        }
        $name = null;
        try {
            $request = $requestFactory->createRequest('POST', $url)
                ->withBody($streamFactory->createStream($rawJsonBody));
            foreach ($finalHeaders as $name => $value) {
                $request = $request->withHeader($name, $value);
            }
        } catch (\InvalidArgumentException $err) {
            // PSR-7 implementations reject a malformed URL or header value (an API
            // key pasted with a trailing newline) and echo the offending value in
            // the message. Re-raise without that message so the key never reaches
            // a log; the header NAME is safe to report and points at the culprit.
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: $name === null
                    ? 'Request URL is malformed'
                    : "Request header '{$name}' has an invalid name or value (check the configured key for stray whitespace or newlines)",
                cause: ['raw' => ['exception' => $err::class], 'retryAfter' => null],
            );
        }

        return $this->dispatch($request);
    }

    /**
     * Typed `mixed`: passthrough headers come from the caller, and a non-string
     * value is left for PSR-7 to reject.
     */
    private static function hasLineBreakOrNul(#[\SensitiveParameter] mixed $value): bool
    {
        return is_string($value) && strpbrk($value, "\r\n\0") !== false;
    }

    private function dispatch(#[\SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        try {
            return $this->getClient()->sendRequest($request);
        } catch (ClientExceptionInterface $err) {
            // PSR-18 requires a non-2xx to be RETURNED, not thrown, and every
            // connector's status mapping depends on it — a rate-limited provider
            // must read as `rate_limited`, not as an outage. A client that ignores
            // that (Guzzle used outside its PSR-18 adapter, a decorator calling
            // raise-on-error) still answered, so recover the response and let the
            // connector map it. Duck-typed: no HTTP-client dependency.
            $response = $this->recoverResponse($err);
            if ($response !== null) {
                return $response;
            }

            // Never echo the raw message — some provider URLs carry the credential.
            throw new ConnectorError(
                providerCode: ProviderCode::ProviderUnavailable,
                providerMessage: 'Upstream transport error',
                cause: ['raw' => ['exception' => $err::class], 'retryAfter' => null],
            );
        }
    }

    /**
     * Recover the PSR-7 response a client attached to its exception, if any.
     *
     * Guzzle's `BadResponseException`, Symfony's `HttpExceptionInterface` and
     * most decorators expose the answered response through `getResponse()`.
     * Checked reflectively so no HTTP-client package becomes a dependency.
     */
    private function recoverResponse(\Throwable $exception): ?ResponseInterface
    {
        if (!method_exists($exception, 'getResponse')) {
            return null;
        }

        try {
            $response = $exception->getResponse();
        } catch (\Throwable) {
            return null;
        }

        return $response instanceof ResponseInterface ? $response : null;
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

        return self::decodePlainArray($raw);
    }

    /**
     * The body decoded twice from the same bytes: plain arrays for reading and
     * for `raw` (what consumers see), plus an object-preserving tree for the
     * parts that are re-encoded and sent back to a vendor — see
     * {@see decodeJsonPreservingObjects()}.
     *
     * @return array{0:array<mixed>|null,1:array<mixed>|null}
     */
    protected function decodeJsonWithObjects(ResponseInterface $response): array
    {
        $raw = (string) $response->getBody();
        if ($raw === '') {
            return [null, null];
        }

        return [self::decodePlainArray($raw), self::decodeJsonArray($raw)];
    }

    /**
     * Follows array keys into a decoded tree; null when any step is missing.
     */
    protected static function pick(mixed $tree, int|string ...$path): mixed
    {
        foreach ($path as $key) {
            if (!is_array($tree) || !array_key_exists($key, $tree)) {
                return null;
            }
            $tree = $tree[$key];
        }

        return $tree;
    }

    /**
     * @return array<mixed>|null
     */
    private static function decodePlainArray(string $json): ?array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Decodes a JSON document whose top level must be an object or array,
     * keeping nested objects' shape.
     *
     * @return array<mixed>|null
     */
    private static function decodeJsonArray(string $json): ?array
    {
        try {
            $decoded = self::decodeJsonPreservingObjects($json);
        } catch (\JsonException) {
            return null;
        }
        if ($decoded instanceof \stdClass) {
            $decoded = get_object_vars($decoded);
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Decodes JSON into arrays, except that an object whose array form would
     * re-encode as a JSON list — `{}`, or keys "0", "1", … — stays a `\stdClass`.
     * Used only for the parts that go back on the wire (tool arguments,
     * Anthropic's verbatim content blocks), where a plain assoc decode turns
     * `{"opts":{}}` into `{"opts":[]}` on the way out; everything else, `raw`
     * included, stays plain arrays.
     *
     * @throws \JsonException
     */
    protected static function decodeJsonPreservingObjects(string $json): mixed
    {
        return self::preserveObjects(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
    }

    private static function preserveObjects(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::preserveObjects(...), $value);
        }
        if (!$value instanceof \stdClass) {
            return $value;
        }

        $out = [];
        foreach (get_object_vars($value) as $key => $item) {
            $out[$key] = self::preserveObjects($item);
        }
        if ($out !== [] && !array_is_list($out)) {
            return $out;
        }
        $object = new \stdClass();
        foreach ($out as $key => $item) {
            $object->{$key} = $item;
        }

        return $object;
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
            throw new ConnectorError(
                providerCode: ProviderCode::ProviderUnavailable,
                providerMessage: 'Provider returned a successful status with an empty or non-JSON body',
                statusCode: $status,
            );
        }

        return $body;
    }

    /**
     * A 2xx JSON body without the field that carries the result (`{}`, a gateway
     * envelope, a truncated object) is the same silent-data-loss case as an empty
     * body: raise rather than return an empty "successful" result.
     *
     * @param array<mixed> $body
     */
    protected function missingPayloadError(string $field, array $body, int $status): ConnectorError
    {
        return new ConnectorError(
            providerCode: ProviderCode::ProviderUnavailable,
            providerMessage: "Provider returned a successful status without the expected `{$field}` payload",
            cause: ['raw' => $body, 'retryAfter' => null],
            statusCode: $status,
        );
    }

    /**
     * Guard a 2xx streaming response before reading it as SSE. A body labelled as
     * anything but `text/event-stream` (a JSON completion from a proxy that
     * dropped `stream: true`, an HTML gateway page) holds no events, so reading it
     * would end the stream with nothing and no error. A missing content-type is
     * let through; the end-of-stream check still catches an empty body.
     */
    protected function requireEventStream(ResponseInterface $response): void
    {
        $contentType = trim($response->getHeaderLine('Content-Type'));
        if ($contentType === '' || str_contains(strtolower($contentType), 'text/event-stream')) {
            return;
        }

        $mediaType = trim(explode(';', $contentType)[0]);
        $response->getBody()->close();

        throw new ConnectorError(
            providerCode: ProviderCode::ProviderUnavailable,
            providerMessage: "Provider returned a successful status with a non-SSE body ({$mediaType}) for a streaming request",
            statusCode: $response->getStatusCode(),
        );
    }

    /**
     * The error for a stream whose body ended before the provider's terminal
     * event. Every supported provider closes a complete stream with one, so an
     * EOF before it is a truncated answer (dropped connection, proxy timeout),
     * not a short one.
     */
    protected function streamEndedEarlyError(): ConnectorError
    {
        return new ConnectorError(
            providerCode: ProviderCode::ProviderUnavailable,
            providerMessage: 'Stream ended before the provider sent its terminal event',
        );
    }

    /**
     * Yields each JSON-decoded `data:` payload from a Server-Sent-Events body,
     * stopping at `[DONE]`. Mirrors the TS `parseSSEStream` boundary handling,
     * `[DONE]` semantics, residual tail flush, and 16 MiB buffer cap. The
     * generator's return value says whether the stream ended on `[DONE]`.
     *
     * @return \Generator<int,array<mixed>,mixed,bool>
     */
    protected function parseSSEStream(StreamInterface $body): \Generator
    {
        $payloads = $this->ssePayloads($body);
        foreach ($payloads as $payload) {
            $parsed = self::decodePlainArray($payload);
            if ($parsed !== null) {
                yield $parsed;
            }
        }

        return $payloads->getReturn();
    }

    /**
     * {@see parseSSEStream()}, yielding each event both as plain arrays and as
     * an object-preserving tree, for connectors that send parts of an event
     * back to the vendor.
     *
     * @return \Generator<int,array{0:array<mixed>,1:array<mixed>},mixed,bool>
     */
    protected function parseSSEStreamWithObjects(StreamInterface $body): \Generator
    {
        $payloads = $this->ssePayloads($body);
        foreach ($payloads as $payload) {
            $parsed = self::decodePlainArray($payload);
            $kept = self::decodeJsonArray($payload);
            if ($parsed !== null && $kept !== null) {
                yield [$parsed, $kept];
            }
        }

        return $payloads->getReturn();
    }

    /**
     * The `data:` payload of each event, as text.
     *
     * @return \Generator<int,string,mixed,bool>
     */
    private function ssePayloads(StreamInterface $body): \Generator
    {
        // The body is closed deterministically in `finally` — this covers normal
        // completion, `[DONE]` early return, the buffer-cap throw, an error thrown
        // by the caller mapping a chunk, and consumer `break` (PHP runs a
        // generator's `finally` when it is destroyed). Never leaks the connection.
        try {
            $buffer = '';

            while (!$body->eof()) {
                // Only the bytes after the previous scan can complete a boundary (a
                // terminator pair is at most 4 bytes), so rescanning the whole buffer
                // for every chunk of one large event would be quadratic.
                $scanFrom = max(0, strlen($buffer) - 3);
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

                while (($boundary = $this->indexOfEventBoundary($buffer, $scanFrom)) !== -1) {
                    $scanFrom = 0;
                    $rawEvent = substr($buffer, 0, $boundary);
                    $buffer = ltrim(substr($buffer, $boundary), "\r\n");
                    $payload = $this->extractData($rawEvent);
                    if ($payload === null) {
                        continue;
                    }
                    if ($payload === '[DONE]') {
                        return true;
                    }
                    yield $payload;
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
                if ($payload === '[DONE]') {
                    return true;
                }
                if ($payload !== null) {
                    yield $payload;
                }
            }

            return false;
        } finally {
            $body->close();
        }
    }

    private function indexOfEventBoundary(string $buffer, int $offset = 0): int
    {
        // An event boundary is a blank line: two consecutive line terminators.
        // The SSE spec permits CRLF, CR, or LF, so accept any pairing (\n\n,
        // \r\n\r\n, \r\r, and mixed \n\r\n / \r\n\n) — a CR-only or mixed upstream
        // would otherwise never split and buffer until the 16 MiB cap. The groups
        // are atomic: a single CRLF must not backtrack into "CR, then LF" and read
        // as two terminators, which would split a multi-line `data:` event.
        if (preg_match('/(?>\r\n|\r|\n)(?>\r\n|\r|\n)/', $buffer, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
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
     * `Retry-After` per RFC 9110: delta-seconds (a non-negative integer) or an
     * HTTP-date, which gives the seconds until then (0 once it has passed).
     * Anything else — "1.5", "-5", "tomorrow" — is not a hint and yields null,
     * so a consumer never reads a guess as "retry immediately".
     */
    protected static function parseRetryAfterSeconds(string $header): ?int
    {
        $header = trim($header);
        if (preg_match('/^\d+$/', $header) === 1) {
            return (int) $header;
        }

        $timestamp = self::parseHttpDate($header);

        return $timestamp === null ? null : max(0, $timestamp - time());
    }

    /**
     * The three HTTP-date forms RFC 9110 lets a recipient accept, and nothing
     * else: `strtotime` would also take "tomorrow" or "+1 week". The weekday is
     * checked for shape only; `createFromFormat` would otherwise roll a
     * mismatched one forward to the next such day.
     */
    private static function parseHttpDate(string $value): ?int
    {
        $month = '(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)';
        $forms = [
            // IMF-fixdate: Sun, 06 Nov 1994 08:49:37 GMT
            "/^(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun), (\d{2} {$month} \d{4} \d{2}:\d{2}:\d{2}) GMT$/" => 'd M Y H:i:s',
            // RFC 850: Sunday, 06-Nov-94 08:49:37 GMT
            "/^(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday), (\d{2}-{$month}-\d{2} \d{2}:\d{2}:\d{2}) GMT$/" => 'd-M-y H:i:s',
            // asctime: Sun Nov  6 08:49:37 1994
            "/^(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun) ({$month} (?: \d|\d{2}) \d{2}:\d{2}:\d{2} \d{4})$/" => 'M j H:i:s Y',
        ];
        foreach ($forms as $pattern => $format) {
            if (preg_match($pattern, $value, $m) !== 1) {
                continue;
            }
            $date = \DateTimeImmutable::createFromFormat('!' . $format, (string) preg_replace('/ {2,}/', ' ', $m[1]), new \DateTimeZone('UTC'));
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                return null;
            }

            return $date->getTimestamp();
        }

        return null;
    }
}

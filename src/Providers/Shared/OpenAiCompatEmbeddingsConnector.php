<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Providers\Shared;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Thinwrap\Llm\Base\BaseConnector;
use Thinwrap\Llm\Contract\EmbeddingsConnectorInterface;
use Thinwrap\Llm\DTO\Embeddings\EmbeddingsInput;
use Thinwrap\Llm\DTO\Embeddings\EmbeddingsResult;
use Thinwrap\Llm\DTO\Embeddings\EmbeddingsUsage;
use Thinwrap\Llm\Enum\ProviderCode;
use Thinwrap\Llm\Exception\ConnectorError;
use Thinwrap\Llm\Util\Passthrough;
use Thinwrap\Llm\Util\RedactsSecrets;

/**
 * Shared embeddings connector for the OpenAI-compatible providers that expose an
 * `/embeddings` surface.
 */
final class OpenAiCompatEmbeddingsConnector extends BaseConnector implements EmbeddingsConnectorInterface
{
    use RedactsSecrets;

    private readonly string $id;
    private readonly string $baseUrl;

    public function __construct(
        private readonly OpenAiCompatSpec $spec,
        #[\SensitiveParameter]
        private readonly OpenAiCompatConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = $spec->id;

        $base = $config->baseUrl ?? $spec->defaultBaseUrl;
        if ($base === null || $base === '') {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: "Provider '{$spec->id}' requires an explicit `baseUrl` in config",
            );
        }
        $this->baseUrl = rtrim($base, '/');

        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function create(EmbeddingsInput $input): EmbeddingsResult
    {
        $body = [
            'model' => $input->model,
            'input' => $input->input,
            'encoding_format' => 'float',
        ];
        if ($input->dimensions !== null) {
            $body[$this->spec->dimensionsField] = $input->dimensions;
        }

        $headers = $this->spec->buildAuthHeaders($this->config);
        if ($this->config->headers !== null) {
            $headers = array_merge($headers, $this->config->headers);
        }

        $merged = Passthrough::merge(['body' => $body, 'headers' => $headers, 'query' => []], $input->_passthrough);

        $response = $this->sendPostJson(self::appendQuery($this->baseUrl . '/embeddings', $merged['query']), $merged['body'], $merged['headers']);

        $status = $response->getStatusCode();
        $json = $this->decodeJson($response);
        if ($status < 200 || $status >= 300) {
            throw $this->mapVendorError($status, $json, $response);
        }
        $json = $this->requireDecodedBody($json, $status);

        // Gateways (OpenRouter) report a failure after processing started as a 2xx
        // body carrying only an `error` object.
        if (($json['error'] ?? null) !== null) {
            throw $this->inBodyError($json, $status);
        }

        $rows = $json['data'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            throw $this->missingPayloadError('data', $json, $status);
        }

        // One vector per input, in input order, is the whole contract: a short or
        // long `data` would silently misalign vectors with the texts they embed.
        $expected = self::inputCount($merged['body']['input'] ?? null);
        if ($expected !== null && count($rows) !== $expected) {
            throw $this->malformedResult('Provider returned ' . count($rows) . " embeddings for {$expected} inputs", $json, $status);
        }

        usort($rows, static function (mixed $a, mixed $b): int {
            $ai = (is_array($a) && is_int($a['index'] ?? null)) ? $a['index'] : 0;
            $bi = (is_array($b) && is_int($b['index'] ?? null)) ? $b['index'] : 0;

            return $ai <=> $bi;
        });

        $embeddings = [];
        foreach ($rows as $row) {
            $vec = (is_array($row) && is_array($row['embedding'] ?? null)) ? $row['embedding'] : null;
            if ($vec === null) {
                throw $this->malformedResult('Provider returned an embedding row without an `embedding` vector', $json, $status);
            }

            $floats = [];
            foreach ($vec as $n) {
                // A null or string cell is a broken vector; coercing it to 0.0 would
                // corrupt similarity scores without any signal.
                if (!is_int($n) && !(is_float($n) && is_finite($n))) {
                    throw $this->malformedResult('Provider returned a non-numeric embedding value', $json, $status);
                }
                $floats[] = (float) $n;
            }
            $embeddings[] = $floats;
        }

        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : [];
        $inputTokens = is_int($usage['prompt_tokens'] ?? null) ? $usage['prompt_tokens'] : 0;
        $totalTokens = is_int($usage['total_tokens'] ?? null) ? $usage['total_tokens'] : $inputTokens;
        $model = $json['model'] ?? null;

        return new EmbeddingsResult(
            embeddings: $embeddings,
            usage: new EmbeddingsUsage(inputTokens: $inputTokens, totalTokens: $totalTokens),
            model: is_string($model) ? $model : $input->model,
            raw: $json,
        );
    }

    /**
     * Inputs the request carries: a string is one input, a list is one per entry.
     * Anything else (a passthrough-supplied shape) is left unchecked.
     */
    private static function inputCount(mixed $input): ?int
    {
        if (is_string($input)) {
            return 1;
        }

        return is_array($input) ? count($input) : null;
    }

    /**
     * @param array<mixed> $json
     */
    private function malformedResult(string $message, array $json, int $status): ConnectorError
    {
        return new ConnectorError(
            providerCode: ProviderCode::ProviderUnavailable,
            providerMessage: $message,
            cause: ['raw' => $json, 'retryAfter' => null],
            statusCode: $status,
        );
    }

    /**
     * Maps an `error` object reported inside a 2xx body by its own `code` /
     * `type`, exactly as chat does; `statusCode` stays the status the body
     * arrived with. An error naming neither is `unknown`.
     *
     * @param array<mixed> $json
     */
    private function inBodyError(array $json, int $arrivalStatus): ConnectorError
    {
        $status = EmbeddedErrorStatus::of($json['error'] ?? null);
        $message = $this->extractErrorMessage($json) ?? 'Provider reported an error';

        return new ConnectorError(
            providerCode: $status === null ? ProviderCode::Unknown : $this->mapStatusToProviderCode($status, $message, $json),
            providerMessage: $message,
            cause: ['raw' => $json, 'retryAfter' => null],
            statusCode: $arrivalStatus,
        );
    }

    /**
     * @param array<mixed>|null $body
     */
    private function mapVendorError(int $status, ?array $body, ResponseInterface $response): ConnectorError
    {
        $message = $this->extractErrorMessage($body) ?? "HTTP {$status}";

        $retryAfterHeader = $response->getHeaderLine('Retry-After');
        $retryAfter = $retryAfterHeader !== '' ? $retryAfterHeader : null;
        $seconds = $retryAfter !== null ? self::parseRetryAfterSeconds($retryAfter) : null;

        return new ConnectorError(
            providerCode: $this->mapStatusToProviderCode($status, $message, $body),
            providerMessage: $message,
            cause: ['raw' => $body, 'retryAfter' => $retryAfter, 'retryAfterSeconds' => $seconds],
            statusCode: $status,
        );
    }

    /**
     * @param array<mixed>|null $body
     */
    private function extractErrorMessage(?array $body): ?string
    {
        if ($body === null) {
            return null;
        }
        $error = $body['error'] ?? null;
        if (is_array($error) && is_string($error['message'] ?? null)) {
            return $error['message'];
        }
        if (is_string($body['message'] ?? null)) {
            return $body['message'];
        }

        return null;
    }

    /**
     * @param array<mixed>|null $body
     */
    private function mapStatusToProviderCode(int $status, string $message, ?array $body): ProviderCode
    {
        if ($status === 401 || $status === 403) {
            return ProviderCode::AuthFailed;
        }
        // 402 is exhausted credit or quota, which OpenAI (429 insufficient_quota)
        // and Gemini (RESOURCE_EXHAUSTED) already report as rate limiting.
        if ($status === 429 || $status === 402) {
            return ProviderCode::RateLimited;
        }
        // 408 is a timeout, which a retry can clear.
        if ($status >= 500 || $status === 408) {
            return ProviderCode::ProviderUnavailable;
        }
        if ($status === 400 || $status === 404 || $status === 422) {
            // An oversized embedding input comes back as a 400 (or a 413 handled below);
            // classify it like the chat mapper so callers see ContextLengthExceeded.
            $error = $body['error'] ?? null;
            $msg = strtolower($message);
            if ((is_array($error) && ($error['code'] ?? null) === 'context_length_exceeded') || str_contains($msg, 'context length') || str_contains($msg, 'maximum context') || str_contains($msg, 'context_length')) {
                return ProviderCode::ContextLengthExceeded;
            }

            return ProviderCode::InvalidRequest;
        }
        // Any other client error (405, 409, 410, 413, …) is a bad request.
        if ($status >= 400) {
            return ProviderCode::InvalidRequest;
        }

        return ProviderCode::Unknown;
    }
}

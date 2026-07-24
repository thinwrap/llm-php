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

/**
 * Shared embeddings connector for the OpenAI-compatible providers that expose an
 * `/embeddings` surface.
 */
final class OpenAiCompatEmbeddingsConnector extends BaseConnector implements EmbeddingsConnectorInterface
{
    private readonly string $id;
    private readonly string $baseUrl;

    public function __construct(
        private readonly OpenAiCompatSpec $spec,
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
            $body['dimensions'] = $input->dimensions;
        }

        $headers = $this->spec->buildAuthHeaders($this->config);
        if ($this->config->headers !== null) {
            $headers = array_merge($headers, $this->config->headers);
        }

        $merged = Passthrough::merge(['body' => $body, 'headers' => $headers, 'query' => []], $input->_passthrough);

        $response = $this->sendPostJson($this->baseUrl . '/embeddings', $merged['body'], $merged['headers']);

        $status = $response->getStatusCode();
        $json = $this->decodeJson($response);
        if ($status < 200 || $status >= 300) {
            throw $this->mapVendorError($status, $json, $response);
        }
        $json = $this->requireDecodedBody($json, $status);

        $rows = is_array($json['data'] ?? null) ? array_values($json['data']) : [];
        usort($rows, static function (mixed $a, mixed $b): int {
            $ai = (is_array($a) && is_int($a['index'] ?? null)) ? $a['index'] : 0;
            $bi = (is_array($b) && is_int($b['index'] ?? null)) ? $b['index'] : 0;

            return $ai <=> $bi;
        });

        $embeddings = array_map(static function (mixed $row): array {
            $vec = (is_array($row) && is_array($row['embedding'] ?? null)) ? $row['embedding'] : [];

            /** @var list<float> $floats */
            $floats = array_map(static fn(mixed $n): float => is_numeric($n) ? (float) $n : 0.0, array_values($vec));

            return $floats;
        }, $rows);

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
     * @param array<mixed>|null $body
     */
    private function mapVendorError(int $status, ?array $body, ResponseInterface $response): ConnectorError
    {
        $message = $this->extractErrorMessage($body) ?? "HTTP {$status}";

        $retryAfterHeader = $response->getHeaderLine('Retry-After');
        $retryAfter = $retryAfterHeader !== '' ? $retryAfterHeader : null;
        $seconds = $retryAfter !== null ? self::parseRetryAfterSeconds($retryAfter) : null;

        return new ConnectorError(
            providerCode: $this->mapStatusToProviderCode($status, $message),
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

    private function mapStatusToProviderCode(int $status, string $message): ProviderCode
    {
        if ($status === 401 || $status === 403) {
            return ProviderCode::AuthFailed;
        }
        if ($status === 429) {
            return ProviderCode::RateLimited;
        }
        if ($status >= 500) {
            return ProviderCode::ProviderUnavailable;
        }
        if ($status === 400 || $status === 404 || $status === 422) {
            // An oversized embedding input comes back as a 400 (or a 413 handled below);
            // classify it like the chat mapper so callers see ContextLengthExceeded.
            $msg = strtolower($message);
            if (str_contains($msg, 'context length') || str_contains($msg, 'maximum context') || str_contains($msg, 'context_length')) {
                return ProviderCode::ContextLengthExceeded;
            }

            return ProviderCode::InvalidRequest;
        }
        // Any other client error (402, 405, 409, 410, 413, …) is a bad request.
        if ($status >= 400) {
            return ProviderCode::InvalidRequest;
        }

        return ProviderCode::Unknown;
    }
}

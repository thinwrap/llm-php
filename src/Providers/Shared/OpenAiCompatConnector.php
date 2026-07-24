<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Providers\Shared;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Thinwrap\Llm\Base\BaseConnector;
use Thinwrap\Llm\Contract\ChatConnectorInterface;
use Thinwrap\Llm\DTO\Chat\AssistantMessage;
use Thinwrap\Llm\DTO\Chat\ChatInput;
use Thinwrap\Llm\DTO\Chat\ChatMessage;
use Thinwrap\Llm\DTO\Chat\ChatResult;
use Thinwrap\Llm\DTO\Chat\ChatStreamDelta;
use Thinwrap\Llm\DTO\Chat\ContentPart;
use Thinwrap\Llm\DTO\Chat\ImagePart;
use Thinwrap\Llm\DTO\Chat\ResponseFormat;
use Thinwrap\Llm\DTO\Chat\TextPart;
use Thinwrap\Llm\DTO\Chat\ToolCall;
use Thinwrap\Llm\DTO\Chat\ToolCallDelta;
use Thinwrap\Llm\DTO\Chat\ToolCallFunction;
use Thinwrap\Llm\DTO\Chat\ToolDefinition;
use Thinwrap\Llm\DTO\Chat\Usage;
use Thinwrap\Llm\Enum\FinishReason;
use Thinwrap\Llm\Enum\ProviderCode;
use Thinwrap\Llm\Exception\ConnectorError;
use Thinwrap\Llm\Util\Passthrough;

/**
 * One connector for every OpenAI-compatible provider, parameterized by a
 * {@see OpenAiCompatSpec} row. Canonical wire = OpenAI Chat Completions.
 */
final class OpenAiCompatConnector extends BaseConnector implements ChatConnectorInterface
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

    public function complete(ChatInput $input): ChatResult
    {
        $req = $this->buildRequest($input, false);
        $response = $this->sendPostJson($req['url'], $req['body'], $req['headers']);

        $status = $response->getStatusCode();
        $body = $this->decodeJson($response);
        if ($status < 200 || $status >= 300) {
            throw $this->mapVendorError($status, $body, $response);
        }

        return $this->parseResult($this->requireDecodedBody($body, $status), $input->model);
    }

    public function stream(ChatInput $input): \Generator
    {
        $req = $this->buildRequest($input, true);
        $headers = array_merge($req['headers'], ['Accept' => 'text/event-stream']);
        $response = $this->sendPostJson($req['url'], $req['body'], $headers);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw $this->mapVendorError($status, $this->decodeJson($response), $response);
        }

        foreach ($this->parseSSEStream($response->getBody()) as $chunk) {
            foreach ($this->mapStreamChunk($chunk) as $delta) {
                yield $delta;
            }
        }
    }

    /**
     * @return array{url:string,body:array<string,mixed>,headers:array<string,string>}
     */
    private function buildRequest(ChatInput $input, bool $stream): array
    {
        $body = [
            'model' => $input->model,
            'messages' => array_map($this->mapMessage(...), $input->messages),
        ];

        $toolsAllowed = $this->spec->supportsTools;
        if ($toolsAllowed && $input->tools !== null && $input->tools !== []) {
            $body['tools'] = array_map(static function (ToolDefinition $t): array {
                $fn = ['name' => $t->function->name];
                if ($t->function->description !== null) {
                    $fn['description'] = $t->function->description;
                }
                if ($t->function->parameters !== null) {
                    $fn['parameters'] = $t->function->parameters;
                }

                return ['type' => 'function', 'function' => $fn];
            }, $input->tools);
        }
        if ($toolsAllowed && $input->toolChoice !== null) {
            $body['tool_choice'] = $input->toolChoice;
        }
        if ($input->responseFormat !== null) {
            $body['response_format'] = $this->mapResponseFormat($input->responseFormat);
        }
        if ($input->temperature !== null) {
            $body['temperature'] = $input->temperature;
        }
        if ($input->topP !== null) {
            $body['top_p'] = $input->topP;
        }
        if ($input->maxOutputTokens !== null) {
            $body[$this->spec->maxTokensField] = $input->maxOutputTokens;
        }
        if ($input->stop !== null) {
            $body['stop'] = $input->stop;
        }
        if ($input->reasoning !== null) {
            $body['reasoning_effort'] = $input->reasoning->effort->value;
        }

        if ($stream) {
            $body['stream'] = true;
            if (!$this->spec->strictParams) {
                $body['stream_options'] = ['include_usage' => true];
            }
        }

        $merged = Passthrough::merge(
            ['body' => $body, 'headers' => $this->buildHeaders(), 'query' => []],
            $input->_passthrough,
        );

        $url = $this->baseUrl . '/chat/completions';
        if ($merged['query'] !== []) {
            $url .= '?' . http_build_query($merged['query']);
        }

        return ['url' => $url, 'body' => $merged['body'], 'headers' => $merged['headers']];
    }

    /**
     * @return array<string,string>
     */
    private function buildHeaders(): array
    {
        $headers = $this->spec->buildAuthHeaders($this->config);
        if ($this->config->headers !== null) {
            $headers = array_merge($headers, $this->config->headers);
        }

        return $headers;
    }

    /**
     * @return array<string,mixed>
     */
    private function mapMessage(ChatMessage $m): array
    {
        $out = ['role' => $m->role->value, 'content' => $this->mapContent($m->content)];
        if ($m->name !== null) {
            $out['name'] = $m->name;
        }
        if ($m->toolCalls !== null && $m->toolCalls !== []) {
            $out['tool_calls'] = array_map(static fn(ToolCall $tc): array => [
                'id' => $tc->id,
                'type' => 'function',
                'function' => ['name' => $tc->function->name, 'arguments' => $tc->function->arguments],
            ], $m->toolCalls);
        }
        if ($m->toolCallId !== null) {
            $out['tool_call_id'] = $m->toolCallId;
        }

        return $out;
    }

    /**
     * @param string|list<ContentPart>|null $content
     *
     * @return string|list<array<string,mixed>>|null
     */
    private function mapContent(string|array|null $content): string|array|null
    {
        if ($content === null || is_string($content)) {
            return $content;
        }

        return array_map(static function (ContentPart $p): array {
            if ($p instanceof TextPart) {
                return ['type' => 'text', 'text' => $p->text];
            }
            if ($p instanceof ImagePart) {
                return ['type' => 'image_url', 'image_url' => ['url' => "data:{$p->mediaType};base64,{$p->base64}"]];
            }

            throw new ConnectorError(providerCode: ProviderCode::InvalidRequest, providerMessage: 'Unsupported content part');
        }, $content);
    }

    /**
     * @return array<string,mixed>
     */
    private function mapResponseFormat(ResponseFormat $rf): array
    {
        if ($rf->type === 'json_schema') {
            return ['type' => 'json_schema', 'json_schema' => ['name' => $rf->name, 'schema' => $rf->schema]];
        }

        return ['type' => $rf->type];
    }

    /**
     * @param array<mixed> $json
     */
    private function parseResult(array $json, string $requestedModel): ChatResult
    {
        $choice = (is_array($json['choices'] ?? null) ? ($json['choices'][0] ?? null) : null);
        $choice = is_array($choice) ? $choice : [];
        $msg = is_array($choice['message'] ?? null) ? $choice['message'] : [];

        $toolCalls = null;
        if (isset($msg['tool_calls']) && is_array($msg['tool_calls'])) {
            $mapped = array_map($this->mapToolCall(...), array_values($msg['tool_calls']));
            if ($mapped !== []) {
                $toolCalls = $mapped;
            }
        }

        $content = $msg['content'] ?? null;
        $finish = $choice['finish_reason'] ?? null;
        $model = $json['model'] ?? null;

        return new ChatResult(
            message: new AssistantMessage(
                content: is_string($content) ? $content : null,
                toolCalls: $toolCalls,
            ),
            finishReason: $this->mapFinishReason(is_string($finish) ? $finish : null),
            usage: $this->mapUsage(is_array($json['usage'] ?? null) ? $json['usage'] : null),
            model: is_string($model) ? $model : $requestedModel,
            raw: $json,
        );
    }

    private function mapToolCall(mixed $tc): ToolCall
    {
        $tc = is_array($tc) ? $tc : [];
        $fn = is_array($tc['function'] ?? null) ? $tc['function'] : [];

        return new ToolCall(
            id: is_string($tc['id'] ?? null) ? $tc['id'] : '',
            function: new ToolCallFunction(
                name: is_string($fn['name'] ?? null) ? $fn['name'] : '',
                arguments: is_string($fn['arguments'] ?? null) ? $fn['arguments'] : '',
            ),
        );
    }

    private function mapFinishReason(?string $fr): FinishReason
    {
        return match ($fr) {
            // Together AI reports natural end-of-sequence as 'eos' rather than 'stop'.
            'stop', 'eos' => FinishReason::Stop,
            'length' => FinishReason::Length,
            'tool_calls', 'function_call' => FinishReason::ToolCalls,
            'content_filter' => FinishReason::ContentFilter,
            default => FinishReason::Unknown,
        };
    }

    /**
     * @param array<mixed>|null $u
     */
    private function mapUsage(?array $u): Usage
    {
        $in = is_int($u['prompt_tokens'] ?? null) ? $u['prompt_tokens'] : 0;
        $out = is_int($u['completion_tokens'] ?? null) ? $u['completion_tokens'] : 0;
        $total = is_int($u['total_tokens'] ?? null) ? $u['total_tokens'] : $in + $out;

        return new Usage($in, $out, $total);
    }

    /**
     * @param array<mixed> $chunk
     *
     * @return list<ChatStreamDelta>
     */
    private function mapStreamChunk(array $chunk): array
    {
        // A provider/gateway failure emitted mid-stream arrives as an `{"error": …}`
        // data frame (no `choices`). Surface it as a ConnectorError instead of
        // silently truncating the stream into a finish-less "success".
        if (($chunk['error'] ?? null) !== null) {
            throw $this->streamError($chunk);
        }

        $choice = (is_array($chunk['choices'] ?? null) ? ($chunk['choices'][0] ?? null) : null);
        $choice = is_array($choice) ? $choice : [];
        $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];

        $deltas = [];

        $content = $delta['content'] ?? null;
        if (is_string($content) && $content !== '') {
            $deltas[] = new ChatStreamDelta(contentDelta: $content, raw: $chunk);
        }

        // Parallel tool-call fragments can arrive in a single chunk — emit one
        // toolCallDelta per entry (not just `tool_calls[0]`).
        $toolCalls = is_array($delta['tool_calls'] ?? null) ? $delta['tool_calls'] : [];
        foreach ($toolCalls as $tc) {
            if (!is_array($tc)) {
                continue;
            }
            $fn = is_array($tc['function'] ?? null) ? $tc['function'] : [];
            $id = $tc['id'] ?? null;
            $name = $fn['name'] ?? null;
            $args = $fn['arguments'] ?? null;
            $deltas[] = new ChatStreamDelta(
                toolCallDelta: new ToolCallDelta(
                    index: is_int($tc['index'] ?? null) ? $tc['index'] : 0,
                    id: (is_string($id) && $id !== '') ? $id : null,
                    functionName: (is_string($name) && $name !== '') ? $name : null,
                    argumentsDelta: (is_string($args) && $args !== '') ? $args : null,
                ),
                raw: $chunk,
            );
        }

        $finishReason = null;
        $usage = null;
        $hasTrailer = false;

        $finish = $choice['finish_reason'] ?? null;
        if (is_string($finish) && $finish !== '') {
            $finishReason = $this->mapFinishReason($finish);
            $hasTrailer = true;
        }

        $usageRaw = $chunk['usage'] ?? null;
        if (!is_array($usageRaw) && $this->spec->streamUsagePath === 'x_groq') {
            $xGroq = $chunk['x_groq'] ?? null;
            $usageRaw = is_array($xGroq) ? ($xGroq['usage'] ?? null) : null;
        }
        if (is_array($usageRaw)) {
            $usage = $this->mapUsage($usageRaw);
            $hasTrailer = true;
        }

        if ($hasTrailer) {
            $deltas[] = new ChatStreamDelta(
                finishReason: $finishReason,
                usage: $usage,
                raw: $chunk,
            );
        }

        return $deltas;
    }

    /**
     * Builds a ConnectorError from an `{"error": …}` frame emitted mid-stream,
     * reusing the non-stream error mapping.
     *
     * @param array<mixed> $chunk
     */
    private function streamError(array $chunk): ConnectorError
    {
        $status = $this->streamErrorStatus($chunk['error'] ?? null);
        $message = $this->extractErrorMessage($chunk) ?? "HTTP {$status}";

        return new ConnectorError(
            providerCode: $this->mapStatusToProviderCode($status, $chunk),
            providerMessage: $message,
            cause: ['raw' => $chunk, 'retryAfter' => null],
            statusCode: $status,
        );
    }

    /** Best-effort HTTP status for a mid-stream `{"error": …}` frame (some gateways carry a numeric `code`). */
    private function streamErrorStatus(mixed $error): int
    {
        if (is_array($error) && is_int($error['code'] ?? null) && $error['code'] >= 400 && $error['code'] <= 599) {
            return $error['code'];
        }

        return 500;
    }

    /**
     * @param array<mixed>|null $body
     */
    private function mapVendorError(int $status, ?array $body, ResponseInterface $response): ConnectorError
    {
        $message = $this->extractErrorMessage($body) ?? "HTTP {$status}";
        $providerCode = $this->mapStatusToProviderCode($status, $body);

        $retryAfterHeader = $response->getHeaderLine('Retry-After');
        $retryAfter = $retryAfterHeader !== '' ? $retryAfterHeader : null;
        $seconds = $retryAfter !== null ? self::parseRetryAfterSeconds($retryAfter) : null;

        return new ConnectorError(
            providerCode: $providerCode,
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
        if (is_string($error)) {
            return $error;
        }
        if (is_array($error) && is_string($error['message'] ?? null)) {
            return $error['message'];
        }
        if (is_string($body['message'] ?? null)) {
            return $body['message'];
        }
        $errors = $body['errors'] ?? null;
        if (is_array($errors) && is_array($errors[0] ?? null) && is_string($errors[0]['message'] ?? null)) {
            return $errors[0]['message'];
        }

        return null;
    }

    /**
     * @param array<mixed>|null $body
     */
    private function mapStatusToProviderCode(int $status, ?array $body): ProviderCode
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
            $msg = strtolower($this->extractErrorMessage($body) ?? '');
            if (str_contains($msg, 'context length') || str_contains($msg, 'maximum context') || str_contains($msg, 'context_length')) {
                return ProviderCode::ContextLengthExceeded;
            }
            if (str_contains($msg, 'content') && str_contains($msg, 'filter')) {
                return ProviderCode::ContentFiltered;
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

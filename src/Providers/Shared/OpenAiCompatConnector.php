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
use Thinwrap\Llm\Util\RedactsSecrets;

/**
 * One connector for every OpenAI-compatible provider, parameterized by a
 * {@see OpenAiCompatSpec} row. Canonical wire = OpenAI Chat Completions.
 */
final class OpenAiCompatConnector extends BaseConnector implements ChatConnectorInterface
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

    public function complete(ChatInput $input): ChatResult
    {
        $req = $this->buildRequest($input, false);
        $response = $this->sendPostJson($req['url'], $req['body'], $req['headers']);

        $status = $response->getStatusCode();
        $body = $this->decodeJson($response);
        if ($status < 200 || $status >= 300) {
            throw $this->mapVendorError($status, $body, $response);
        }

        $json = $this->requireDecodedBody($body, $status);
        $this->guardSuccessBody($json, $status);

        return $this->parseResult($json, $input->model);
    }

    /**
     * OpenRouter (and other gateways) report a failure that happens after
     * processing started as HTTP 200: either a body holding only an `error`
     * object, or `choices[0]` with `finish_reason: "error"` and its own `error`
     * next to partial content. Parsing either as a completion would hand the
     * caller empty or truncated output as success.
     *
     * @param array<mixed> $json
     */
    private function guardSuccessBody(array $json, int $status): void
    {
        if (($json['error'] ?? null) !== null) {
            throw $this->inBodyError($json, $json, $status);
        }

        $choices = $json['choices'] ?? null;
        if (!is_array($choices) || $choices === [] || !array_is_list($choices)) {
            throw $this->missingPayloadError('choices', $json, $status);
        }

        $first = $choices[0];
        if (is_array($first) && (($first['error'] ?? null) !== null || ($first['finish_reason'] ?? null) === 'error')) {
            throw $this->inBodyError(['error' => $first['error'] ?? null], $json, $status);
        }
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

        $this->requireEventStream($response);
        // A complete stream ends on `[DONE]`, and its last choice chunk carries a
        // `finish_reason`; either one proves the answer was not cut off.
        $sawFinish = false;
        $events = $this->parseSSEStream($response->getBody());
        foreach ($events as $chunk) {
            foreach ($this->mapStreamChunk($chunk, $status) as $delta) {
                if ($delta->finishReason !== null) {
                    $sawFinish = true;
                }
                yield $delta;
            }
        }
        if (!$events->getReturn() && !$sawFinish) {
            throw $this->streamEndedEarlyError();
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

        $url = self::appendQuery($this->baseUrl . '/chat/completions', $merged['query']);

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
                content: self::contentText($content),
                toolCalls: $toolCalls,
            ),
            finishReason: $this->mapFinishReason(is_string($finish) ? $finish : null),
            usage: $this->mapUsage(is_array($json['usage'] ?? null) ? $json['usage'] : null),
            model: is_string($model) ? $model : $requestedModel,
            raw: $json,
        );
    }

    /**
     * Mistral reasoning models return `content` / `delta.content` as a list of
     * typed chunks rather than a string: `thinking` chunks carry the reasoning
     * trace, `text` chunks the answer. Only the answer is content — the trace
     * stays reachable on `raw`.
     */
    private static function contentText(mixed $content): ?string
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return null;
        }

        $text = '';
        foreach ($content as $chunk) {
            if (is_array($chunk) && ($chunk['type'] ?? null) === 'text' && is_string($chunk['text'] ?? null)) {
                $text .= $chunk['text'];
            }
        }

        return $text !== '' ? $text : null;
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
    private function mapStreamChunk(array $chunk, int $status): array
    {
        // A provider/gateway failure emitted mid-stream arrives as an `{"error": …}`
        // data frame (no `choices`). Surface it as a ConnectorError instead of
        // silently truncating the stream into a finish-less "success".
        if (($chunk['error'] ?? null) !== null) {
            throw $this->inBodyError($chunk, $chunk, $status);
        }

        $choice = (is_array($chunk['choices'] ?? null) ? ($chunk['choices'][0] ?? null) : null);
        $choice = is_array($choice) ? $choice : [];
        $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];

        $deltas = [];

        $content = self::contentText($delta['content'] ?? null);
        if ($content !== null && $content !== '') {
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
     * Builds a ConnectorError from an error reported inside a 2xx body or a
     * mid-stream SSE frame. The code comes from the error's own `code` / `type`
     * (see {@see EmbeddedErrorStatus}), classified as that HTTP status would be;
     * `statusCode` stays the status the response arrived with, since no other was
     * ever sent. An error naming neither is `unknown`.
     *
     * @param array<mixed> $errorView array whose `error` key holds the vendor error
     * @param array<mixed> $raw       the full body/frame, kept on `cause['raw']`
     */
    private function inBodyError(array $errorView, array $raw, int $arrivalStatus): ConnectorError
    {
        $status = EmbeddedErrorStatus::of($errorView['error'] ?? null);
        $message = $this->extractErrorMessage($errorView) ?? 'Provider reported an error';

        return new ConnectorError(
            providerCode: $status === null ? ProviderCode::Unknown : $this->mapStatusToProviderCode($status, $errorView),
            providerMessage: $message,
            cause: ['raw' => $raw, 'retryAfter' => null],
            statusCode: $arrivalStatus,
        );
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
            $error = $body['error'] ?? null;
            if (is_array($error) && ($error['code'] ?? null) === 'context_length_exceeded') {
                return ProviderCode::ContextLengthExceeded;
            }
            $msg = strtolower($this->extractErrorMessage($body) ?? '');
            if (str_contains($msg, 'context length') || str_contains($msg, 'maximum context') || str_contains($msg, 'context_length')) {
                return ProviderCode::ContextLengthExceeded;
            }
            // OpenAI says "content_policy_violation", Azure "content_filter",
            // others "moderation"; matched the same way in every language.
            if (
                (str_contains($msg, 'content') && str_contains($msg, 'filter'))
                || str_contains($msg, 'content_policy')
                || str_contains($msg, 'content policy')
                || str_contains($msg, 'moderation')
            ) {
                return ProviderCode::ContentFiltered;
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

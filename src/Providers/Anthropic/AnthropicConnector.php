<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Providers\Anthropic;

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
use Thinwrap\Llm\DTO\Chat\TextPart;
use Thinwrap\Llm\DTO\Chat\ToolCall;
use Thinwrap\Llm\DTO\Chat\ToolCallDelta;
use Thinwrap\Llm\DTO\Chat\ToolCallFunction;
use Thinwrap\Llm\DTO\Chat\ToolDefinition;
use Thinwrap\Llm\DTO\Chat\Usage;
use Thinwrap\Llm\Enum\ChatRole;
use Thinwrap\Llm\Enum\FinishReason;
use Thinwrap\Llm\Enum\ProviderCode;
use Thinwrap\Llm\Enum\ReasoningEffort;
use Thinwrap\Llm\Exception\ConnectorError;

/**
 * Native adapter for the Anthropic Messages API. Structurally different wire,
 * identical normalized surface.
 */
final class AnthropicConnector extends BaseConnector implements ChatConnectorInterface
{
    private const DEFAULT_BASE_URL = 'https://api.anthropic.com/v1';
    private const DEFAULT_VERSION = '2023-06-01';
    private const DEFAULT_MAX_TOKENS = 4096;

    private readonly string $id;
    private readonly string $baseUrl;

    public function __construct(
        private readonly AnthropicConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'anthropic';
        $this->baseUrl = rtrim($config->baseUrl ?? self::DEFAULT_BASE_URL, '/');

        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function complete(ChatInput $input): ChatResult
    {
        $req = $this->buildRequest($input, false);
        $response = $this->sendPostJson($this->baseUrl . '/messages', $req['body'], $req['headers']);

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
        $response = $this->sendPostJson($this->baseUrl . '/messages', $req['body'], $headers);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw $this->mapVendorError($status, $this->decodeJson($response), $response);
        }

        $inputTokens = 0;
        foreach ($this->parseSSEStream($response->getBody()) as $evt) {
            $type = $evt['type'] ?? null;

            if ($type === 'error') {
                // A provider error emitted mid-stream (e.g. `overloaded_error`) —
                // throw instead of silently ending the stream with no finishReason.
                throw $this->streamError($evt);
            }

            if ($type === 'message_start') {
                $msg = is_array($evt['message'] ?? null) ? $evt['message'] : [];
                $usage = is_array($msg['usage'] ?? null) ? $msg['usage'] : [];
                $inputTokens = is_int($usage['input_tokens'] ?? null) ? $usage['input_tokens'] : 0;
            } elseif ($type === 'content_block_start') {
                $cb = is_array($evt['content_block'] ?? null) ? $evt['content_block'] : [];
                if (($cb['type'] ?? null) === 'tool_use') {
                    yield new ChatStreamDelta(
                        toolCallDelta: new ToolCallDelta(
                            index: is_int($evt['index'] ?? null) ? $evt['index'] : 0,
                            id: is_string($cb['id'] ?? null) ? $cb['id'] : null,
                            functionName: is_string($cb['name'] ?? null) ? $cb['name'] : null,
                        ),
                        raw: $evt,
                    );
                }
            } elseif ($type === 'content_block_delta') {
                $d = is_array($evt['delta'] ?? null) ? $evt['delta'] : [];
                $dtype = $d['type'] ?? null;
                if ($dtype === 'text_delta' && is_string($d['text'] ?? null) && $d['text'] !== '') {
                    yield new ChatStreamDelta(contentDelta: $d['text'], raw: $evt);
                } elseif ($dtype === 'input_json_delta' && is_string($d['partial_json'] ?? null)) {
                    yield new ChatStreamDelta(
                        toolCallDelta: new ToolCallDelta(
                            index: is_int($evt['index'] ?? null) ? $evt['index'] : 0,
                            argumentsDelta: $d['partial_json'],
                        ),
                        raw: $evt,
                    );
                }
            } elseif ($type === 'message_delta') {
                $delta = is_array($evt['delta'] ?? null) ? $evt['delta'] : [];
                $usage = is_array($evt['usage'] ?? null) ? $evt['usage'] : [];
                $stopReason = $delta['stop_reason'] ?? null;
                $outputTokens = is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : 0;
                yield new ChatStreamDelta(
                    finishReason: is_string($stopReason) ? $this->mapStopReason($stopReason) : null,
                    usage: new Usage($inputTokens, $outputTokens, $inputTokens + $outputTokens),
                    raw: $evt,
                );
            }
        }
    }

    /**
     * @return array{body:array<string,mixed>,headers:array<string,string>}
     */
    private function buildRequest(ChatInput $input, bool $stream): array
    {
        [$system, $messages] = $this->splitMessages($input->messages);

        $thinking = null;
        if ($input->reasoning !== null) {
            $thinking = ['type' => 'enabled', 'budget_tokens' => $this->effortBudget($input->reasoning->effort)];
        }
        $budget = $thinking !== null ? $thinking['budget_tokens'] : 0;
        $callerSetMax = $input->maxOutputTokens !== null;
        $maxTokens = $input->maxOutputTokens ?? $this->config->defaultMaxTokens ?? self::DEFAULT_MAX_TOKENS;
        if ($thinking !== null && $maxTokens <= $budget) {
            // Anthropic requires max_tokens > thinking.budget_tokens (max_tokens is
            // the TOTAL, thinking included). If the CALLER set an explicit
            // maxOutputTokens below the budget, silently inflating it would spend
            // past their cap — raise so they lower reasoning.effort or raise the cap.
            // Only a non-explicit default is inflated to keep the request valid.
            if ($callerSetMax) {
                throw new ConnectorError(
                    providerCode: ProviderCode::InvalidRequest,
                    providerMessage: "maxOutputTokens ({$maxTokens}) must be greater than the reasoning budget ({$budget}) for extended thinking — raise maxOutputTokens or lower reasoning.effort",
                );
            }
            $maxTokens = $budget + 4096;
        }

        $body = ['model' => $input->model, 'max_tokens' => $maxTokens, 'messages' => $messages];
        if ($system !== null) {
            $body['system'] = $system;
        }
        if ($input->tools !== null && $input->tools !== []) {
            $body['tools'] = array_map(static function (ToolDefinition $t): array {
                $toolSpec = ['name' => $t->function->name];
                if ($t->function->description !== null) {
                    $toolSpec['description'] = $t->function->description;
                }
                $toolSpec['input_schema'] = $t->function->parameters ?? ['type' => 'object', 'properties' => new \stdClass()];

                return $toolSpec;
            }, $input->tools);
        }
        if ($input->toolChoice !== null) {
            $tc = $this->mapToolChoice($input->toolChoice);
            if ($tc !== null) {
                $body['tool_choice'] = $tc;
            }
        }
        if ($thinking !== null) {
            $body['thinking'] = $thinking;
        } else {
            if ($input->temperature !== null) {
                $body['temperature'] = $input->temperature;
            }
            if ($input->topP !== null) {
                $body['top_p'] = $input->topP;
            }
        }
        if ($input->stop !== null) {
            $body['stop_sequences'] = is_array($input->stop) ? $input->stop : [$input->stop];
        }
        if ($stream) {
            $body['stream'] = true;
        }
        $ptBody = $input->_passthrough['body'] ?? null;
        if (is_array($ptBody)) {
            $body = array_merge($body, $ptBody);
        }

        return ['body' => $body, 'headers' => $this->buildHeaders($input)];
    }

    /**
     * @return array<string,string>
     */
    private function buildHeaders(ChatInput $input): array
    {
        $headers = [
            'x-api-key' => $this->config->apiKey,
            'anthropic-version' => $this->config->anthropicVersion ?? self::DEFAULT_VERSION,
        ];
        if ($this->config->headers !== null) {
            $headers = array_merge($headers, $this->config->headers);
        }
        $ptHeaders = $input->_passthrough['headers'] ?? null;
        if (is_array($ptHeaders)) {
            $headers = array_merge($headers, $ptHeaders);
        }

        return $headers;
    }

    /**
     * @param list<ChatMessage> $messages
     *
     * @return array{0:string|null,1:list<array<string,mixed>>}
     */
    private function splitMessages(array $messages): array
    {
        $systemParts = [];
        $out = [];

        foreach ($messages as $m) {
            if ($m->role === ChatRole::System) {
                $text = $this->contentToText($m->content);
                if ($text !== '') {
                    $systemParts[] = $text;
                }

                continue;
            }

            if ($m->role === ChatRole::Tool) {
                $block = [
                    'type' => 'tool_result',
                    'tool_use_id' => $m->toolCallId ?? '',
                    'content' => $this->contentToText($m->content),
                ];
                $last = count($out) - 1;
                if ($last >= 0 && $out[$last]['role'] === 'user' && $this->isToolResultContent($out[$last]['content'])) {
                    $prev = $out[$last];
                    /** @var list<array<string,mixed>> $content */
                    $content = $prev['content'];
                    $content[] = $block;
                    $prev['content'] = $content;
                    $out[$last] = $prev;
                } else {
                    $out[] = ['role' => 'user', 'content' => [$block]];
                }

                continue;
            }

            $out[] = ['role' => $m->role->value, 'content' => $this->mapContentBlocks($m)];
        }

        return [$systemParts === [] ? null : implode("\n\n", $systemParts), $out];
    }

    private function isToolResultContent(mixed $content): bool
    {
        return is_array($content)
            && isset($content[0])
            && is_array($content[0])
            && ($content[0]['type'] ?? null) === 'tool_result';
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function mapContentBlocks(ChatMessage $m): array
    {
        $blocks = [];
        $content = $m->content;

        if (is_string($content)) {
            if ($content !== '') {
                $blocks[] = ['type' => 'text', 'text' => $content];
            }
        } elseif (is_array($content)) {
            foreach ($content as $p) {
                if ($p instanceof TextPart) {
                    $blocks[] = ['type' => 'text', 'text' => $p->text];
                } elseif ($p instanceof ImagePart) {
                    $blocks[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $p->mediaType, 'data' => $p->base64]];
                }
            }
        }

        if ($m->role === ChatRole::Assistant && $m->toolCalls !== null) {
            foreach ($m->toolCalls as $tc) {
                $blocks[] = [
                    'type' => 'tool_use',
                    'id' => $tc->id,
                    'name' => $tc->function->name,
                    'input' => $this->safeJsonParse($tc->function->arguments),
                ];
            }
        }

        if ($blocks === []) {
            $blocks[] = ['type' => 'text', 'text' => ''];
        }

        return $blocks;
    }

    /**
     * @param string|list<ContentPart>|null $content
     */
    private function contentToText(string|array|null $content): string
    {
        if ($content === null) {
            return '';
        }
        if (is_string($content)) {
            return $content;
        }
        $text = '';
        foreach ($content as $p) {
            if ($p instanceof TextPart) {
                $text .= $p->text;
            }
        }

        return $text;
    }

    /**
     * @param string|array<string,mixed> $tc
     *
     * @return array<string,mixed>|null
     */
    private function mapToolChoice(string|array $tc): ?array
    {
        if (is_string($tc)) {
            return match ($tc) {
                'auto' => ['type' => 'auto'],
                'required' => ['type' => 'any'],
                'none' => ['type' => 'none'],
                default => null,
            };
        }
        $fn = is_array($tc['function'] ?? null) ? $tc['function'] : [];
        $name = $fn['name'] ?? null;

        return is_string($name) ? ['type' => 'tool', 'name' => $name] : null;
    }

    /**
     * @param array<mixed> $json
     */
    private function parseResult(array $json, string $requestedModel): ChatResult
    {
        $text = '';
        $toolCalls = [];
        $content = is_array($json['content'] ?? null) ? $json['content'] : [];
        foreach ($content as $b) {
            if (!is_array($b)) {
                continue;
            }
            $type = $b['type'] ?? null;
            if ($type === 'text' && is_string($b['text'] ?? null)) {
                $text .= $b['text'];
            } elseif ($type === 'tool_use') {
                $toolCalls[] = new ToolCall(
                    id: is_string($b['id'] ?? null) ? $b['id'] : '',
                    function: new ToolCallFunction(
                        name: is_string($b['name'] ?? null) ? $b['name'] : '',
                        arguments: $this->encodeJson($b['input'] ?? []),
                    ),
                );
            }
        }

        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : [];
        $in = is_int($usage['input_tokens'] ?? null) ? $usage['input_tokens'] : 0;
        $out = is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : 0;
        $model = $json['model'] ?? null;
        $stopReason = $json['stop_reason'] ?? null;

        return new ChatResult(
            message: new AssistantMessage(
                content: $text !== '' ? $text : null,
                toolCalls: $toolCalls !== [] ? $toolCalls : null,
            ),
            finishReason: $this->mapStopReason(is_string($stopReason) ? $stopReason : null),
            usage: new Usage($in, $out, $in + $out),
            model: is_string($model) ? $model : $requestedModel,
            raw: $json,
        );
    }

    private function mapStopReason(?string $sr): FinishReason
    {
        return match ($sr) {
            'end_turn', 'stop_sequence' => FinishReason::Stop,
            'max_tokens' => FinishReason::Length,
            'tool_use' => FinishReason::ToolCalls,
            'refusal' => FinishReason::ContentFilter,
            default => FinishReason::Unknown,
        };
    }

    private function effortBudget(ReasoningEffort $effort): int
    {
        return match ($effort) {
            ReasoningEffort::Low => 1024,
            ReasoningEffort::Medium => 4096,
            ReasoningEffort::High => 12288,
        };
    }

    private function safeJsonParse(string $json): mixed
    {
        if ($json === '') {
            return new \stdClass();
        }
        try {
            return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new \stdClass();
        }
    }

    private function encodeJson(mixed $value): string
    {
        if (is_array($value) && $value === []) {
            $value = new \stdClass();
        }
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '{}' : $encoded;
    }

    /**
     * @param array<mixed>|null $body
     */
    private function mapVendorError(int $status, ?array $body, ResponseInterface $response): ConnectorError
    {
        $message = $this->extractError($body) ?? "HTTP {$status}";
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
     * Builds a ConnectorError from a mid-stream `event: error` frame, reusing the
     * non-stream error mapping.
     *
     * @param array<mixed> $evt
     */
    private function streamError(array $evt): ConnectorError
    {
        $error = is_array($evt['error'] ?? null) ? $evt['error'] : [];
        $status = $this->errorEventStatus(is_string($error['type'] ?? null) ? $error['type'] : null);
        $message = $this->extractError($evt) ?? "HTTP {$status}";

        return new ConnectorError(
            providerCode: $this->mapStatusToProviderCode($status, $message),
            providerMessage: $message,
            cause: ['raw' => $evt, 'retryAfter' => null],
            statusCode: $status,
        );
    }

    /** Maps an Anthropic SSE `error` event's `type` to the HTTP status its non-stream path would carry. */
    private function errorEventStatus(?string $type): int
    {
        return match ($type) {
            'authentication_error' => 401,
            'permission_error' => 403,
            'not_found_error' => 404,
            'rate_limit_error' => 429,
            'invalid_request_error' => 400,
            'overloaded_error' => 529,
            default => 500,
        };
    }

    /**
     * @param array<mixed>|null $body
     */
    private function extractError(?array $body): ?string
    {
        if ($body === null) {
            return null;
        }
        $error = $body['error'] ?? null;
        if (is_array($error) && is_string($error['message'] ?? null)) {
            return $error['message'];
        }

        return is_string($body['message'] ?? null) ? $body['message'] : null;
    }

    private function mapStatusToProviderCode(int $status, string $message): ProviderCode
    {
        if ($status === 401 || $status === 403) {
            return ProviderCode::AuthFailed;
        }
        if ($status === 429) {
            return ProviderCode::RateLimited;
        }
        // Any 5xx is a provider-side failure. A fixed {500,502,503,529} whitelist
        // dropped 504/520/522/524 (gateway / Cloudflare-origin errors in front of
        // the API) to Unknown, so a consumer's retry policy wouldn't fire on them.
        if ($status >= 500) {
            return ProviderCode::ProviderUnavailable;
        }
        if (in_array($status, [400, 404, 413, 422], true)) {
            $m = strtolower($message);
            if (str_contains($m, 'too long') || str_contains($m, 'context') || str_contains($m, 'max_tokens')) {
                return ProviderCode::ContextLengthExceeded;
            }

            return ProviderCode::InvalidRequest;
        }

        return ProviderCode::Unknown;
    }
}

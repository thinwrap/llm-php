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
use Thinwrap\Llm\Exception\ConnectorError;
use Thinwrap\Llm\Util\RedactsSecrets;

/**
 * Native adapter for the Anthropic Messages API. Structurally different wire,
 * identical normalized surface.
 */
final class AnthropicConnector extends BaseConnector implements ChatConnectorInterface
{
    use RedactsSecrets;

    private const DEFAULT_BASE_URL = 'https://api.anthropic.com/v1';
    private const DEFAULT_VERSION = '2023-06-01';
    /**
     * Used when the caller sets no `maxOutputTokens` (Anthropic requires one).
     * Current models think by default and the thinking counts toward this
     * ceiling, so a small one ends at `length` before any text. 16000 is
     * Anthropic's recommended non-streaming default; it caps, it doesn't charge.
     */
    private const DEFAULT_MAX_TOKENS = 16000;

    private readonly string $id;
    private readonly string $baseUrl;

    public function __construct(
        #[\SensitiveParameter]
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
        $response = $this->sendPostJson($req['url'], $req['body'], $req['headers']);

        $status = $response->getStatusCode();
        [$body, $kept] = $this->decodeJsonWithObjects($response);
        if ($status < 200 || $status >= 300) {
            throw $this->mapVendorError($status, $body, $response);
        }

        $json = $this->requireDecodedBody($body, $status);
        if (!is_array($json['content'] ?? null)) {
            throw $this->missingPayloadError('content', $json, $status);
        }

        return $this->parseResult($json, $kept, $input->model);
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
        // A complete stream reports its stop reason in `message_delta` and closes
        // with `message_stop`; without either the answer was cut off.
        $sawStop = false;
        // Usage counters merged field by field, as the official SDK's
        // MessageStream does: a `message_delta` may restate some fields and send
        // others as null or not at all, which must not reset them.
        $usage = ['input_tokens' => 0, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0, 'output_tokens' => 0];
        // The response's content blocks, rebuilt from the events so the terminal
        // delta can carry the same `providerMetadata` a non-stream call returns.
        /** @var array<int,array<string,mixed>> $blocks */
        $blocks = [];
        /** @var array<int,string> $partialJson */
        $partialJson = [];
        // Content-block index => tool-call ordinal. ToolCallDelta::$index is the
        // position among tool calls (as on every other connector), not the block
        // index, which also counts text and thinking blocks.
        /** @var array<int,int> $toolOrdinal */
        $toolOrdinal = [];
        foreach ($this->parseSSEStreamWithObjects($response->getBody()) as [$evt, $keptEvt]) {
            $type = $evt['type'] ?? null;

            if ($type === 'error') {
                // A provider error emitted mid-stream (e.g. `overloaded_error`) —
                // throw instead of silently ending the stream with no finishReason.
                throw $this->streamError($evt, $status);
            }

            $blockIndex = is_int($evt['index'] ?? null) ? $evt['index'] : 0;
            if ($type === 'message_stop') {
                $sawStop = true;
            } elseif ($type === 'message_start') {
                $msg = is_array($evt['message'] ?? null) ? $evt['message'] : [];
                $usage = self::mergeUsage($usage, $msg['usage'] ?? null);
            } elseif ($type === 'content_block_start') {
                $cb = is_array($evt['content_block'] ?? null) ? $evt['content_block'] : [];
                $keptBlock = self::pick($keptEvt, 'content_block');
                $blocks[$blockIndex] = is_array($keptBlock) ? $keptBlock : $cb;
                if (($cb['type'] ?? null) === 'tool_use') {
                    $ordinal = count($toolOrdinal);
                    $toolOrdinal[$blockIndex] = $ordinal;
                    yield new ChatStreamDelta(
                        toolCallDelta: new ToolCallDelta(
                            index: $ordinal,
                            id: is_string($cb['id'] ?? null) ? $cb['id'] : null,
                            functionName: is_string($cb['name'] ?? null) ? $cb['name'] : null,
                        ),
                        raw: $evt,
                    );
                }
            } elseif ($type === 'content_block_delta') {
                $d = is_array($evt['delta'] ?? null) ? $evt['delta'] : [];
                $dtype = $d['type'] ?? null;
                if ($dtype === 'text_delta' && is_string($d['text'] ?? null)) {
                    $blocks[$blockIndex]['text'] = self::stringField($blocks[$blockIndex] ?? [], 'text') . $d['text'];
                    if ($d['text'] !== '') {
                        yield new ChatStreamDelta(contentDelta: $d['text'], raw: $evt);
                    }
                } elseif ($dtype === 'thinking_delta' && is_string($d['thinking'] ?? null)) {
                    $blocks[$blockIndex]['thinking'] = self::stringField($blocks[$blockIndex] ?? [], 'thinking') . $d['thinking'];
                } elseif ($dtype === 'signature_delta' && is_string($d['signature'] ?? null)) {
                    $blocks[$blockIndex]['signature'] = $d['signature'];
                } elseif ($dtype === 'citations_delta' && ($d['citation'] ?? null) !== null) {
                    // Citations belong to the text block they annotate; dropping them
                    // would make the rebuilt block differ from a non-stream response.
                    $citations = $blocks[$blockIndex]['citations'] ?? [];
                    $citations = is_array($citations) ? $citations : [];
                    $citations[] = self::pick($keptEvt, 'delta', 'citation') ?? $d['citation'];
                    $blocks[$blockIndex]['citations'] = $citations;
                } elseif ($dtype === 'input_json_delta' && is_string($d['partial_json'] ?? null)) {
                    $partialJson[$blockIndex] = ($partialJson[$blockIndex] ?? '') . $d['partial_json'];
                    if (isset($toolOrdinal[$blockIndex])) {
                        yield new ChatStreamDelta(
                            toolCallDelta: new ToolCallDelta(
                                index: $toolOrdinal[$blockIndex],
                                argumentsDelta: $d['partial_json'],
                            ),
                            raw: $evt,
                        );
                    }
                }
            } elseif ($type === 'message_delta') {
                $delta = is_array($evt['delta'] ?? null) ? $evt['delta'] : [];
                $usage = self::mergeUsage($usage, $evt['usage'] ?? null);
                $stopReason = $delta['stop_reason'] ?? null;
                $terminal = is_string($stopReason);
                if ($terminal) {
                    $sawStop = true;
                }
                $inputTokens = $usage['input_tokens'] + $usage['cache_read_input_tokens'] + $usage['cache_creation_input_tokens'];
                yield new ChatStreamDelta(
                    finishReason: $terminal ? $this->mapStopReason($stopReason) : null,
                    usage: new Usage($inputTokens, $usage['output_tokens'], $inputTokens + $usage['output_tokens']),
                    raw: $evt,
                    // Only the terminal delta carries the turn: before the stop
                    // reason the blocks may still be incomplete.
                    providerMetadata: $terminal ? ['anthropic' => ['content' => self::assembleBlocks($blocks, $partialJson)]] : null,
                );
            }
        }
        if (!$sawStop) {
            throw $this->streamEndedEarlyError();
        }
    }

    /**
     * @param array<int,array<string,mixed>> $blocks
     * @param array<int,string>              $partialJson
     *
     * @return list<array<string,mixed>>
     */
    private static function assembleBlocks(array $blocks, array $partialJson): array
    {
        ksort($blocks);
        $out = [];
        foreach ($blocks as $index => $block) {
            $json = $partialJson[$index] ?? '';
            if ($json !== '') {
                try {
                    $block['input'] = self::decodeJsonPreservingObjects($json);
                } catch (\JsonException) {
                    // A truncated argument stream keeps the start event's input.
                }
            }
            $out[] = $block;
        }

        return $out;
    }

    /**
     * @param array{input_tokens:int,cache_read_input_tokens:int,cache_creation_input_tokens:int,output_tokens:int} $usage
     *
     * @return array{input_tokens:int,cache_read_input_tokens:int,cache_creation_input_tokens:int,output_tokens:int}
     */
    private static function mergeUsage(array $usage, mixed $update): array
    {
        if (!is_array($update)) {
            return $usage;
        }
        foreach (array_keys($usage) as $key) {
            if (is_int($update[$key] ?? null)) {
                $usage[$key] = $update[$key];
            }
        }

        return $usage;
    }

    /**
     * @param array<string,mixed> $block
     */
    private static function stringField(array $block, string $key): string
    {
        return is_string($block[$key] ?? null) ? $block[$key] : '';
    }

    /**
     * Anthropic splits the prompt across `input_tokens` (uncached),
     * `cache_read_input_tokens` and `cache_creation_input_tokens`; all three are
     * billed input, so the normalized count is their sum. Null when the usage
     * object reports none of them.
     *
     * @param array<mixed> $usage
     */
    private static function inputTokens(array $usage): ?int
    {
        $seen = false;
        $sum = 0;
        foreach (['input_tokens', 'cache_read_input_tokens', 'cache_creation_input_tokens'] as $key) {
            if (is_int($usage[$key] ?? null)) {
                $seen = true;
                $sum += $usage[$key];
            }
        }

        return $seen ? $sum : null;
    }

    /**
     * @return array{url:string,body:array<string,mixed>,headers:array<string,string>}
     */
    private function buildRequest(ChatInput $input, bool $stream): array
    {
        [$system, $messages] = $this->splitMessages($input->messages);

        $maxTokens = $input->maxOutputTokens ?? $this->config->defaultMaxTokens ?? self::DEFAULT_MAX_TOKENS;

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
        if ($input->reasoning !== null) {
            // Adaptive thinking is the current-generation control: the model sizes
            // its own reasoning within `max_tokens`, steered by `effort`. Models
            // before Claude 4.6 reject it with a 400, which passes through; they
            // take a budget via `_passthrough['body']['thinking']` instead.
            $body['thinking'] = ['type' => 'adaptive'];
            $body['output_config'] = ['effort' => $input->reasoning->effort->value];
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
        $ptQuery = $input->_passthrough['query'] ?? null;
        $url = self::appendQuery($this->baseUrl . '/messages', is_array($ptQuery) ? $ptQuery : []);

        return ['url' => $url, 'body' => $body, 'headers' => $this->buildHeaders($input)];
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
        // Each thinking block's signature also covers the thinking before it, so
        // once one assistant turn is rebuilt without its blocks, a later turn's
        // verbatim blocks no longer verify (a 400 on accounts that enforce
        // preserved thinking). From the first rebuilt turn on, rebuild them all.
        $rebuilding = false;

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

            $replay = null;
            if ($m->role === ChatRole::Assistant && !$rebuilding) {
                $replay = $this->replayBlocks($m);
                $rebuilding = $replay === null;
            }
            $out[] = ['role' => $m->role->value, 'content' => $replay ?? $this->mapContentBlocks($m)];
        }

        return [$systemParts === [] ? null : implode("\n\n", $systemParts), $out];
    }

    /**
     * The verbatim content blocks from `providerMetadata['anthropic']['content']`,
     * when they still describe this message. Sending them back keeps the turn's
     * thinking blocks (signatures included), which Anthropic needs to continue
     * reasoning across a tool loop. A caller that edited the text, the tool calls
     * or their arguments gets the rebuilt turn instead: replaying stale blocks
     * would send something other than what the message says.
     *
     * @return list<array<string,mixed>>|null
     */
    private function replayBlocks(ChatMessage $m): ?array
    {
        $meta = $m->providerMetadata['anthropic'] ?? null;
        $content = is_array($meta) ? ($meta['content'] ?? null) : null;
        if (!is_array($content) || $content === [] || !array_is_list($content)) {
            return null;
        }

        $toolCalls = $m->toolCalls ?? [];
        $toolIds = [];
        $text = '';
        $blocks = [];
        foreach ($content as $block) {
            if (!is_array($block) || !is_string($block['type'] ?? null)) {
                return null;
            }
            // A hand-built `[]` for an argument-less call; the wire needs an object.
            if (in_array($block['type'], ['tool_use', 'server_tool_use', 'mcp_tool_use'], true) && ($block['input'] ?? null) === []) {
                $block['input'] = new \stdClass();
            }
            if ($block['type'] === 'tool_use') {
                $call = $toolCalls[count($toolIds)] ?? null;
                if (!is_string($block['id'] ?? null) || $call === null || !self::sameArguments($block['input'] ?? null, $call->function->arguments)) {
                    return null;
                }
                $toolIds[] = $block['id'];
            } elseif ($block['type'] === 'text') {
                if (!is_string($block['text'] ?? null)) {
                    return null;
                }
                $text .= $block['text'];
            }
            $blocks[] = $block;
        }

        $expectedIds = array_map(static fn(ToolCall $tc): string => $tc->id, $toolCalls);
        if ($toolIds !== $expectedIds || $text !== $this->contentToText($m->content)) {
            return null;
        }

        return $blocks;
    }

    /**
     * Whether a stored `tool_use.input` still equals the ToolCall's (possibly
     * edited) arguments: compared as JSON values, so key order and whitespace
     * don't count.
     */
    private static function sameArguments(mixed $input, string $arguments): bool
    {
        try {
            $parsed = json_decode($arguments, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return self::canonicalJson($input) === self::canonicalJson($parsed);
    }

    private static function canonicalJson(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        } elseif (is_array($value) && array_is_list($value)) {
            return array_map(self::canonicalJson(...), $value);
        }
        if (!is_array($value)) {
            // JSON numbers: 1 and 1.0 are the same value.
            return is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX ? (int) $value : $value;
        }
        ksort($value, SORT_STRING);

        return ['{}' => array_map(self::canonicalJson(...), $value)];
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
                // Anthropic rejects an empty text block.
                if ($p instanceof TextPart && $p->text !== '') {
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
     * @param array<mixed>      $json plain decode: read here and kept as `raw`
     * @param array<mixed>|null $kept the same body with objects preserved, for what goes back on the wire
     */
    private function parseResult(array $json, ?array $kept, string $requestedModel): ChatResult
    {
        $text = '';
        $toolCalls = [];
        $content = is_array($json['content'] ?? null) ? $json['content'] : [];
        $keptContent = self::pick($kept, 'content');
        foreach ($content as $i => $b) {
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
                        arguments: $this->encodeJson(self::pick($keptContent, $i, 'input') ?? $b['input'] ?? []),
                    ),
                );
            }
        }

        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : [];
        $in = self::inputTokens($usage) ?? 0;
        $out = is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : 0;
        $model = $json['model'] ?? null;
        $stopReason = $json['stop_reason'] ?? null;

        return new ChatResult(
            message: new AssistantMessage(
                content: $text !== '' ? $text : null,
                toolCalls: $toolCalls !== [] ? $toolCalls : null,
                providerMetadata: array_is_list($content)
                    ? ['anthropic' => ['content' => is_array($keptContent) && array_is_list($keptContent) ? $keptContent : $content]]
                    : null,
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
            // Generation hit the context window rather than `max_tokens` — still a
            // truncated answer.
            'max_tokens', 'model_context_window_exceeded' => FinishReason::Length,
            'tool_use' => FinishReason::ToolCalls,
            'refusal' => FinishReason::ContentFilter,
            // `pause_turn` (a server-tool loop paused mid-turn; send the response
            // back to continue) has no normalized equivalent.
            default => FinishReason::Unknown,
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
     * Builds a ConnectorError from a mid-stream `event: error` frame. The code
     * comes from the frame's `error.type`, classified as its non-stream HTTP
     * status would be; `statusCode` stays the status the stream arrived with,
     * since no other status was ever sent. An unrecognised type is `unknown`.
     *
     * @param array<mixed> $evt
     */
    private function streamError(array $evt, int $arrivalStatus): ConnectorError
    {
        $error = is_array($evt['error'] ?? null) ? $evt['error'] : [];
        $typeStatus = $this->errorEventStatus(is_string($error['type'] ?? null) ? $error['type'] : null);
        $message = $this->extractError($evt) ?? 'Stream error';

        return new ConnectorError(
            providerCode: $typeStatus === null ? ProviderCode::Unknown : $this->mapStatusToProviderCode($typeStatus, $message),
            providerMessage: $message,
            cause: ['raw' => $evt, 'retryAfter' => null],
            statusCode: $arrivalStatus,
        );
    }

    /** Maps an Anthropic SSE `error` event's `type` to the HTTP status its non-stream path would carry. */
    private function errorEventStatus(?string $type): ?int
    {
        return match ($type) {
            'invalid_request_error' => 400,
            'authentication_error' => 401,
            'billing_error' => 402,
            'permission_error' => 403,
            'not_found_error' => 404,
            'conflict_error' => 409,
            'request_too_large' => 413,
            'rate_limit_error' => 429,
            'api_error' => 500,
            'timeout_error' => 504,
            'overloaded_error' => 529,
            default => null,
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
        // 402 is exhausted credit or quota, which OpenAI (429 insufficient_quota)
        // and Gemini (RESOURCE_EXHAUSTED) already report as rate limiting.
        if ($status === 429 || $status === 402) {
            return ProviderCode::RateLimited;
        }
        // Any 5xx is a provider-side failure, including the gateway and
        // Cloudflare-origin errors (504/520/522/524) in front of the API, so a
        // consumer's retry policy fires on them.
        // 408 is a timeout, which a retry can clear.
        if ($status >= 500 || $status === 408) {
            return ProviderCode::ProviderUnavailable;
        }
        if ($status === 400) {
            // Only Anthropic's two context-overflow messages: "prompt is too long:
            // N tokens > M maximum" and (older models) "input length and
            // `max_tokens` exceed context limit". A looser 'context' / 'max_tokens'
            // match would catch ordinary 400s and loop history-trimming callers.
            $m = strtolower($message);
            if (str_contains($m, 'prompt is too long') || str_contains($m, 'exceed context limit')) {
                return ProviderCode::ContextLengthExceeded;
            }
        }
        // Every other 4xx is a request the caller must change: 413
        // `request_too_large` is the byte limit, not the token window.
        if ($status >= 400) {
            return ProviderCode::InvalidRequest;
        }

        return ProviderCode::Unknown;
    }
}

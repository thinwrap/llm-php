<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Providers\Gemini;

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
use Thinwrap\Llm\Enum\ChatRole;
use Thinwrap\Llm\Enum\FinishReason;
use Thinwrap\Llm\Enum\ProviderCode;
use Thinwrap\Llm\Enum\ReasoningEffort;
use Thinwrap\Llm\Exception\ConnectorError;
use Thinwrap\Llm\Util\RedactsSecrets;

/**
 * Native adapter for the Gemini `generateContent` API (AI Studio). The model id
 * is URL-encoded into the path — a path/query-injection guard.
 */
final class GeminiConnector extends BaseConnector implements ChatConnectorInterface
{
    use RedactsSecrets;

    private const DEFAULT_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    /**
     * Google's documented stand-in for a missing thought signature. Gemini 3
     * rejects a replayed `functionCall` turn without one (400), which is every
     * hand-built history and every history from another provider.
     */
    private const SKIP_SIGNATURE = 'skip_thought_signature_validator';

    /** Ids this connector invents when Gemini sends none — never echoed back as Gemini's own. */
    private const SYNTHETIC_ID = '/^call_\d+$/';

    private readonly string $id;
    private readonly string $baseUrl;

    public function __construct(
        #[\SensitiveParameter]
        private readonly GeminiConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'gemini';
        $this->baseUrl = rtrim($config->baseUrl ?? self::DEFAULT_BASE_URL, '/');

        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function complete(ChatInput $input): ChatResult
    {
        $req = $this->buildRequest($input);
        $url = self::appendQuery($this->baseUrl . '/models/' . rawurlencode($input->model) . ':generateContent', $req['query']);
        $response = $this->sendPostJson($url, $req['body'], $req['headers']);

        $status = $response->getStatusCode();
        [$body, $kept] = $this->decodeJsonWithObjects($response);
        if ($status < 200 || $status >= 300) {
            throw $this->mapVendorError($status, $body, $response);
        }

        $json = $this->requireDecodedBody($body, $status);
        // A prompt-level safety block legitimately carries no `candidates` — only
        // `promptFeedback.blockReason`, which parseResult reports as content_filter.
        $pf = $json['promptFeedback'] ?? null;
        $promptBlocked = is_array($pf) && ($pf['blockReason'] ?? null) !== null && $pf['blockReason'] !== '';
        if (!$promptBlocked && !is_array($json['candidates'] ?? null)) {
            throw $this->missingPayloadError('candidates', $json, $status);
        }

        return $this->parseResult($json, $kept, $input->model);
    }

    public function stream(ChatInput $input): \Generator
    {
        $req = $this->buildRequest($input);
        $url = self::appendQuery(
            $this->baseUrl . '/models/' . rawurlencode($input->model) . ':streamGenerateContent',
            array_merge(['alt' => 'sse'], $req['query']),
        );
        $headers = array_merge($req['headers'], ['Accept' => 'text/event-stream']);
        $response = $this->sendPostJson($url, $req['body'], $headers);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw $this->mapVendorError($status, $this->decodeJson($response), $response);
        }

        $this->requireEventStream($response);
        $sawFunctionCall = false;
        $promptBlocked = false;
        $toolIndex = 0;
        $latestFinishRaw = null;
        $latestUsageMeta = null;
        $trailerRaw = null;
        foreach ($this->parseSSEStreamWithObjects($response->getBody()) as [$chunk, $keptChunk]) {
            // A provider error emitted mid-stream arrives as an `{"error": …}` frame —
            // throw instead of silently ending the stream with no finishReason.
            if (($chunk['error'] ?? null) !== null) {
                throw $this->streamError($chunk, $status);
            }

            $cand = (is_array($chunk['candidates'] ?? null) ? ($chunk['candidates'][0] ?? null) : null);
            // A prompt-level safety block arrives as a chunk with no candidate and
            // only `promptFeedback.blockReason`; record it so the trailer reports
            // content_filter rather than a silent finish-less end.
            if (!is_array($cand)) {
                $pf = $chunk['promptFeedback'] ?? null;
                if (is_array($pf) && ($pf['blockReason'] ?? null) !== null && $pf['blockReason'] !== '') {
                    $promptBlocked = true;
                    $trailerRaw = $chunk;
                }
            }
            $cand = is_array($cand) ? $cand : [];
            $content = is_array($cand['content'] ?? null) ? $cand['content'] : [];
            $parts = is_array($content['parts'] ?? null) ? $content['parts'] : [];

            foreach ($parts as $partIndex => $p) {
                if (!is_array($p)) {
                    continue;
                }
                // Gemini-3 reasoning parts are marked thought:true — not answer
                // content; skip them (still available to the consumer via `raw`).
                if (($p['thought'] ?? null) === true) {
                    continue;
                }
                if (is_string($p['text'] ?? null) && $p['text'] !== '') {
                    yield new ChatStreamDelta(contentDelta: $p['text'], raw: $chunk);
                } elseif (is_array($p['functionCall'] ?? null)) {
                    $fc = $p['functionCall'];
                    $sawFunctionCall = true;
                    $name = is_string($fc['name'] ?? null) ? $fc['name'] : '';
                    // Monotonic index so parallel/multiple calls are never merged
                    // by a consumer aggregating deltas by index; the synthetic id is
                    // index-based for the same reason (two calls to one function
                    // must not share an id).
                    $index = $toolIndex++;
                    yield new ChatStreamDelta(
                        toolCallDelta: new ToolCallDelta(
                            index: $index,
                            id: self::callId($fc, $index),
                            functionName: $name,
                            argumentsDelta: $this->encodeJson(self::pick($keptChunk, 'candidates', 0, 'content', 'parts', $partIndex, 'functionCall', 'args') ?? $fc['args'] ?? []),
                            providerMetadata: self::signatureMetadata($p),
                        ),
                        raw: $chunk,
                    );
                }
            }

            // Gemini repeats a CUMULATIVE `usageMetadata` on every chunk. Buffer the
            // latest usage/finishReason and emit a SINGLE trailer at stream end
            // (matching OpenAiCompat), so a consumer summing per-delta usage does not
            // over-count.
            $finishRaw = $cand['finishReason'] ?? null;
            if ($finishRaw !== null && $finishRaw !== '') {
                $latestFinishRaw = $finishRaw;
                $trailerRaw = $chunk;
            }
            $usageMeta = $chunk['usageMetadata'] ?? null;
            if (is_array($usageMeta)) {
                $latestUsageMeta = $usageMeta;
                $trailerRaw = $chunk;
            }
        }

        // A complete stream ends on a chunk carrying `finishReason` (or, for a
        // blocked prompt, `promptFeedback.blockReason`); without one the answer
        // was cut off.
        if ($latestFinishRaw === null && !$promptBlocked) {
            throw $this->streamEndedEarlyError();
        }

        $finish = match (true) {
            $sawFunctionCall => FinishReason::ToolCalls,
            // Past the guard above, no finishReason means a blocked prompt.
            $latestFinishRaw === null => FinishReason::ContentFilter,
            default => $this->mapFinishReason(is_string($latestFinishRaw) ? $latestFinishRaw : null),
        };
        yield new ChatStreamDelta(
            finishReason: $finish,
            usage: $latestUsageMeta !== null ? $this->mapUsage($latestUsageMeta) : null,
            raw: $trailerRaw,
        );
    }

    /**
     * Gemini's own `functionCall.id` when it sent one, else an index-based id
     * unique within the response.
     *
     * @param array<mixed> $fc
     */
    private static function callId(array $fc, int $index): string
    {
        return is_string($fc['id'] ?? null) && $fc['id'] !== '' ? $fc['id'] : "call_{$index}";
    }

    /**
     * The part's `thoughtSignature` as ToolCall metadata, or null when it has none
     * (Gemini signs only the first of several parallel calls).
     *
     * @param array<mixed> $part
     *
     * @return array<string,mixed>|null
     */
    private static function signatureMetadata(array $part): ?array
    {
        $sig = $part['thoughtSignature'] ?? null;

        return is_string($sig) && $sig !== '' ? ['gemini' => ['thoughtSignature' => $sig]] : null;
    }

    private static function storedSignature(ToolCall $tc): ?string
    {
        $gemini = $tc->providerMetadata['gemini'] ?? null;
        $sig = is_array($gemini) ? ($gemini['thoughtSignature'] ?? null) : null;

        return is_string($sig) && $sig !== '' ? $sig : null;
    }

    /**
     * Whether a tool-call id can be echoed as `functionCall.id` /
     * `functionResponse.id`. The connector's own `call_N` stand-ins are not ids
     * Gemini issued, so they stay off the wire and the call is matched by name.
     */
    private static function isEchoableId(?string $id): bool
    {
        return $id !== null && $id !== '' && preg_match(self::SYNTHETIC_ID, $id) !== 1;
    }

    /**
     * @return array{body:array<string,mixed>,headers:array<string,string>,query:array<string,mixed>}
     */
    private function buildRequest(ChatInput $input): array
    {
        [$systemInstruction, $contents] = $this->splitMessages($input->messages);

        $body = ['contents' => $contents];
        if ($systemInstruction !== null) {
            $body['systemInstruction'] = $systemInstruction;
        }
        if ($input->tools !== null && $input->tools !== []) {
            $decls = array_map(static function (ToolDefinition $t): array {
                $d = ['name' => $t->function->name];
                if ($t->function->description !== null) {
                    $d['description'] = $t->function->description;
                }
                // `parametersJsonSchema` takes real JSON Schema; `parameters` takes
                // Google's OpenAPI subset and 400s on `additionalProperties`.
                if ($t->function->parameters !== null) {
                    $d['parametersJsonSchema'] = $t->function->parameters;
                }

                return $d;
            }, $input->tools);
            $body['tools'] = [['functionDeclarations' => $decls]];
            $fc = $this->mapFunctionCallingConfig($input->toolChoice);
            if ($fc !== null) {
                $body['toolConfig'] = ['functionCallingConfig' => $fc];
            }
        }

        $gen = [];
        $maxTok = $input->maxOutputTokens ?? $this->config->defaultMaxTokens;
        if ($maxTok !== null) {
            $gen['maxOutputTokens'] = $maxTok;
        }
        if ($input->temperature !== null) {
            $gen['temperature'] = $input->temperature;
        }
        if ($input->topP !== null) {
            $gen['topP'] = $input->topP;
        }
        if ($input->stop !== null) {
            $gen['stopSequences'] = is_array($input->stop) ? $input->stop : [$input->stop];
        }
        $this->applyResponseFormat($gen, $input->responseFormat);
        if ($input->reasoning !== null) {
            $gen['thinkingConfig'] = ['thinkingBudget' => $this->effortBudget($input->reasoning->effort)];
        }
        if ($gen !== []) {
            $body['generationConfig'] = $gen;
        }

        $ptBody = $input->_passthrough['body'] ?? null;
        if (is_array($ptBody)) {
            $body = array_merge($body, $ptBody);
        }
        $ptQuery = $input->_passthrough['query'] ?? null;

        return ['body' => $body, 'headers' => $this->buildHeaders($input), 'query' => is_array($ptQuery) ? $ptQuery : []];
    }

    /**
     * @return array<string,string>
     */
    private function buildHeaders(ChatInput $input): array
    {
        $headers = ['x-goog-api-key' => $this->config->apiKey];
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
     * @return array{0:array<string,mixed>|null,1:list<array<string,mixed>>}
     */
    private function splitMessages(array $messages): array
    {
        // Filled as the history is walked, so a tool result resolves against the
        // nearest preceding call: synthetic `call_N` ids repeat across turns.
        $nameById = [];
        $systemParts = [];
        $contents = [];
        foreach ($messages as $m) {
            if ($m->role === ChatRole::Assistant && $m->toolCalls !== null) {
                foreach ($m->toolCalls as $tc) {
                    $nameById[$tc->id] = $tc->function->name;
                }
            }

            if ($m->role === ChatRole::System) {
                $t = $this->contentToText($m->content);
                if ($t !== '') {
                    $systemParts[] = $t;
                }

                continue;
            }

            if ($m->role === ChatRole::Tool) {
                $name = $m->name ?? ($m->toolCallId !== null ? ($nameById[$m->toolCallId] ?? $m->toolCallId) : 'tool');
                $response = ['name' => $name, 'response' => ['result' => $this->parseJsonOrString($this->contentToText($m->content))]];
                if (self::isEchoableId($m->toolCallId)) {
                    $response['id'] = $m->toolCallId;
                }
                $part = ['functionResponse' => $response];
                $last = count($contents) - 1;
                if ($last >= 0 && $contents[$last]['role'] === 'user' && $this->isFunctionResponseParts($contents[$last]['parts'])) {
                    $prev = $contents[$last];
                    /** @var list<array<string,mixed>> $parts */
                    $parts = $prev['parts'];
                    $parts[] = $part;
                    $prev['parts'] = $parts;
                    $contents[$last] = $prev;
                } else {
                    $contents[] = ['role' => 'user', 'parts' => [$part]];
                }

                continue;
            }

            $contents[] = ['role' => $m->role === ChatRole::Assistant ? 'model' : 'user', 'parts' => $this->mapParts($m)];
        }

        $systemInstruction = $systemParts === [] ? null : ['parts' => [['text' => implode("\n\n", $systemParts)]]];

        return [$systemInstruction, $contents];
    }

    private function isFunctionResponseParts(mixed $parts): bool
    {
        return is_array($parts) && isset($parts[0]) && is_array($parts[0]) && isset($parts[0]['functionResponse']);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function mapParts(ChatMessage $m): array
    {
        $parts = [];
        $content = $m->content;

        if (is_string($content)) {
            if ($content !== '') {
                $parts[] = ['text' => $content];
            }
        } elseif (is_array($content)) {
            foreach ($content as $p) {
                if ($p instanceof TextPart) {
                    $parts[] = ['text' => $p->text];
                } elseif ($p instanceof ImagePart) {
                    $parts[] = ['inlineData' => ['mimeType' => $p->mediaType, 'data' => $p->base64]];
                }
            }
        }

        if ($m->role === ChatRole::Assistant && $m->toolCalls !== null && $m->toolCalls !== []) {
            $first = count($parts);
            $signed = false;
            foreach ($m->toolCalls as $tc) {
                $call = ['name' => $tc->function->name, 'args' => $this->safeJsonParse($tc->function->arguments)];
                if (self::isEchoableId($tc->id)) {
                    $call['id'] = $tc->id;
                }
                $part = ['functionCall' => $call];
                $sig = self::storedSignature($tc);
                if ($sig !== null) {
                    $part['thoughtSignature'] = $sig;
                    $signed = true;
                }
                $parts[] = $part;
            }
            // Gemini signs only the first call of a parallel batch, so one real
            // signature anywhere means the turn came from Gemini intact.
            if (!$signed) {
                $parts[$first]['thoughtSignature'] = self::SKIP_SIGNATURE;
            }
        }

        if ($parts === []) {
            $parts[] = ['text' => ''];
        }

        return $parts;
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
     * @param string|array<string,mixed>|null $tc
     *
     * @return array<string,mixed>|null
     */
    private function mapFunctionCallingConfig(string|array|null $tc): ?array
    {
        if ($tc === null) {
            return null;
        }
        if (is_string($tc)) {
            return match ($tc) {
                'auto' => ['mode' => 'AUTO'],
                'required' => ['mode' => 'ANY'],
                'none' => ['mode' => 'NONE'],
                default => null,
            };
        }
        $fn = is_array($tc['function'] ?? null) ? $tc['function'] : [];
        $name = $fn['name'] ?? null;

        return is_string($name) ? ['mode' => 'ANY', 'allowedFunctionNames' => [$name]] : null;
    }

    /**
     * @param array<string,mixed> $gen
     */
    private function applyResponseFormat(array &$gen, ?ResponseFormat $rf): void
    {
        if ($rf === null) {
            return;
        }
        if ($rf->type === 'json_object') {
            $gen['responseMimeType'] = 'application/json';
        } elseif ($rf->type === 'json_schema') {
            $gen['responseMimeType'] = 'application/json';
            // JSON Schema proper; the deprecated `responseSchema` takes only
            // Google's OpenAPI subset.
            if ($rf->schema !== null) {
                $gen['responseJsonSchema'] = $rf->schema;
            }
        }
    }

    /**
     * @param array<mixed>      $json plain decode: read here and kept as `raw`
     * @param array<mixed>|null $kept the same body with objects preserved, for the arguments
     */
    private function parseResult(array $json, ?array $kept, string $requestedModel): ChatResult
    {
        $cand = (is_array($json['candidates'] ?? null) ? ($json['candidates'][0] ?? null) : null);
        $cand = is_array($cand) ? $cand : [];
        $content = is_array($cand['content'] ?? null) ? $cand['content'] : [];
        $parts = is_array($content['parts'] ?? null) ? $content['parts'] : [];

        $text = '';
        $toolCalls = [];
        foreach ($parts as $partIndex => $p) {
            if (!is_array($p)) {
                continue;
            }
            // Gemini-3 reasoning parts are marked thought:true — not answer
            // content; skip them (still available to the consumer via `raw`).
            if (($p['thought'] ?? null) === true) {
                continue;
            }
            if (is_string($p['text'] ?? null)) {
                $text .= $p['text'];
            } elseif (is_array($p['functionCall'] ?? null)) {
                $fc = $p['functionCall'];
                $toolCalls[] = new ToolCall(
                    id: self::callId($fc, count($toolCalls)),
                    function: new ToolCallFunction(
                        name: is_string($fc['name'] ?? null) ? $fc['name'] : '',
                        arguments: $this->encodeJson(self::pick($kept, 'candidates', 0, 'content', 'parts', $partIndex, 'functionCall', 'args') ?? $fc['args'] ?? []),
                    ),
                    providerMetadata: self::signatureMetadata($p),
                );
            }
        }

        $finishRaw = $cand['finishReason'] ?? null;
        $model = $json['modelVersion'] ?? null;

        // No candidate finishReason: a prompt-level safety block reports only
        // `promptFeedback.blockReason`. Surface it as content_filter rather than a
        // silent empty completion with finishReason unknown.
        $pf = $json['promptFeedback'] ?? null;
        $promptBlocked = $finishRaw === null
            && is_array($pf)
            && ($pf['blockReason'] ?? null) !== null
            && $pf['blockReason'] !== '';

        $finish = match (true) {
            $toolCalls !== [] => FinishReason::ToolCalls,
            $promptBlocked => FinishReason::ContentFilter,
            default => $this->mapFinishReason(is_string($finishRaw) ? $finishRaw : null),
        };

        return new ChatResult(
            message: new AssistantMessage(
                content: $text !== '' ? $text : null,
                toolCalls: $toolCalls !== [] ? $toolCalls : null,
            ),
            finishReason: $finish,
            usage: $this->mapUsage(is_array($json['usageMetadata'] ?? null) ? $json['usageMetadata'] : null),
            model: is_string($model) ? $model : $requestedModel,
            raw: $json,
        );
    }

    /**
     * @param array<mixed>|null $u
     */
    private function mapUsage(?array $u): Usage
    {
        // Tool-use prompt tokens are billed input, and thoughts billed output,
        // though Gemini reports both apart from the prompt and the candidates.
        // Summing both sides keeps total = input + output, as Gemini's own
        // totalTokenCount is.
        $in = (is_int($u['promptTokenCount'] ?? null) ? $u['promptTokenCount'] : 0)
            + (is_int($u['toolUsePromptTokenCount'] ?? null) ? $u['toolUsePromptTokenCount'] : 0);
        $out = (is_int($u['candidatesTokenCount'] ?? null) ? $u['candidatesTokenCount'] : 0)
            + (is_int($u['thoughtsTokenCount'] ?? null) ? $u['thoughtsTokenCount'] : 0);

        return new Usage($in, $out, $in + $out);
    }

    private function mapFinishReason(?string $fr): FinishReason
    {
        return match ($fr) {
            'STOP' => FinishReason::Stop,
            // CONTINUATION: the per-request token limit cut the answer short.
            'MAX_TOKENS', 'CONTINUATION' => FinishReason::Length,
            'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII',
            'IMAGE_SAFETY', 'IMAGE_PROHIBITED_CONTENT', 'IMAGE_RECITATION' => FinishReason::ContentFilter,
            // The rest of google-genai's FinishReason enum (LANGUAGE,
            // MALFORMED_FUNCTION_CALL, UNEXPECTED_TOOL_CALL, TOO_MANY_TOOL_CALLS,
            // NO_IMAGE, IMAGE_OTHER, OTHER, FINISH_REASON_UNSPECIFIED) has no
            // normalized equivalent; the raw value stays on `raw`.
            default => FinishReason::Unknown,
        };
    }

    private function effortBudget(ReasoningEffort $effort): int
    {
        return match ($effort) {
            ReasoningEffort::Low => 1024,
            ReasoningEffort::Medium => 8192,
            ReasoningEffort::High => 24576,
        };
    }

    private function parseJsonOrString(string $text): mixed
    {
        if ($text === '') {
            return '';
        }
        try {
            return json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $text;
        }
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
        if ($seconds === null) {
            $seconds = $this->extractRetryDelaySeconds($body);
        }

        return new ConnectorError(
            providerCode: $this->mapStatusToProviderCode($status, $message, $body),
            providerMessage: $message,
            cause: ['raw' => $body, 'retryAfter' => $retryAfter, 'retryAfterSeconds' => $seconds],
            statusCode: $status,
        );
    }

    /**
     * Builds a ConnectorError from a mid-stream `{"error": …}` frame. The code
     * comes from the frame's `error.code` (an HTTP status) or, failing that, its
     * `error.status` name, classified as the non-stream path would; `statusCode`
     * stays the status the stream arrived with, since no other was ever sent.
     * A frame with neither is `unknown`.
     *
     * @param array<mixed> $chunk
     */
    private function streamError(array $chunk, int $arrivalStatus): ConnectorError
    {
        $error = $chunk['error'] ?? null;
        $frameStatus = null;
        if (is_array($error)) {
            $frameStatus = is_int($error['code'] ?? null) && $error['code'] >= 400 && $error['code'] <= 599
                ? $error['code']
                : self::rpcStatus($error['status'] ?? null);
        }
        $message = $this->extractError($chunk) ?? 'Stream error';
        $code = $frameStatus === null && self::errorReason($chunk) !== 'API_KEY_INVALID'
            ? ProviderCode::Unknown
            : $this->mapStatusToProviderCode($frameStatus ?? 400, $message, $chunk);

        return new ConnectorError(
            providerCode: $code,
            providerMessage: $message,
            cause: ['raw' => $chunk, 'retryAfter' => null, 'retryAfterSeconds' => $this->extractRetryDelaySeconds($chunk)],
            statusCode: $arrivalStatus,
        );
    }

    /** HTTP status for a `google.rpc.Code` name, per Google's error-model mapping. */
    private static function rpcStatus(mixed $name): ?int
    {
        return match ($name) {
            'INVALID_ARGUMENT', 'FAILED_PRECONDITION', 'OUT_OF_RANGE' => 400,
            'UNAUTHENTICATED' => 401,
            'PERMISSION_DENIED' => 403,
            'NOT_FOUND' => 404,
            'RESOURCE_EXHAUSTED' => 429,
            'INTERNAL', 'UNKNOWN', 'DATA_LOSS' => 500,
            'UNIMPLEMENTED' => 501,
            'UNAVAILABLE' => 503,
            'DEADLINE_EXCEEDED' => 504,
            default => null,
        };
    }

    /**
     * @param array<mixed>|null $body
     */
    private function extractRetryDelaySeconds(?array $body): ?int
    {
        if ($body === null) {
            return null;
        }
        $error = $body['error'] ?? null;
        $details = is_array($error) ? ($error['details'] ?? null) : null;
        if (!is_array($details)) {
            return null;
        }
        foreach ($details as $d) {
            if (is_array($d) && is_string($d['retryDelay'] ?? null) && preg_match('/^(\d+(?:\.\d+)?)s$/', $d['retryDelay'], $mm) === 1) {
                return (int) round((float) $mm[1]);
            }
        }

        return null;
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

    /**
     * @param array<mixed>|null $body
     */
    private function mapStatusToProviderCode(int $status, string $message, ?array $body): ProviderCode
    {
        if ($status === 401 || $status === 403 || self::errorReason($body) === 'API_KEY_INVALID') {
            // A bad key is a 400 INVALID_ARGUMENT whose ErrorInfo reason is
            // API_KEY_INVALID, not a 401.
            return ProviderCode::AuthFailed;
        }
        // 402 is exhausted credit or quota, which OpenAI (429 insufficient_quota)
        // and Gemini (RESOURCE_EXHAUSTED) already report as rate limiting.
        if ($status === 429 || $status === 402) {
            return ProviderCode::RateLimited;
        }
        // 408 is a timeout; Gemini's troubleshooting guide says to retry it.
        if ($status >= 500 || $status === 408) {
            return ProviderCode::ProviderUnavailable;
        }
        if ($status === 400) {
            // "The input token count (N) exceeds the maximum number of tokens
            // allowed (M)." A looser 'context' match would catch ordinary 400s
            // (cachedContent errors) and loop history-trimming callers.
            $m = strtolower($message);
            if (str_contains($m, 'exceeds the maximum number of tokens')) {
                return ProviderCode::ContextLengthExceeded;
            }
        }
        if ($status >= 400) {
            return ProviderCode::InvalidRequest;
        }

        return ProviderCode::Unknown;
    }

    /**
     * The `reason` of the `google.rpc.ErrorInfo` entry in `error.details`.
     *
     * @param array<mixed>|null $body
     */
    private static function errorReason(?array $body): ?string
    {
        $error = $body['error'] ?? null;
        $details = is_array($error) ? ($error['details'] ?? null) : null;
        if (!is_array($details)) {
            return null;
        }
        foreach ($details as $d) {
            if (is_array($d) && is_string($d['reason'] ?? null)) {
                return $d['reason'];
            }
        }

        return null;
    }
}

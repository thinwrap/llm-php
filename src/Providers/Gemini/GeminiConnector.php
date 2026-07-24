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

/**
 * Native adapter for the Gemini `generateContent` API (AI Studio). The model id
 * is URL-encoded into the path — a path/query-injection guard.
 */
final class GeminiConnector extends BaseConnector implements ChatConnectorInterface
{
    private const DEFAULT_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    private readonly string $id;
    private readonly string $baseUrl;

    public function __construct(
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
        $url = $this->baseUrl . '/models/' . rawurlencode($input->model) . ':generateContent';
        $response = $this->sendPostJson($url, $req['body'], $req['headers']);

        $status = $response->getStatusCode();
        $body = $this->decodeJson($response);
        if ($status < 200 || $status >= 300) {
            throw $this->mapVendorError($status, $body, $response);
        }

        return $this->parseResult($this->requireDecodedBody($body, $status), $input->model);
    }

    public function stream(ChatInput $input): \Generator
    {
        $req = $this->buildRequest($input);
        $url = $this->baseUrl . '/models/' . rawurlencode($input->model) . ':streamGenerateContent?alt=sse';
        $headers = array_merge($req['headers'], ['Accept' => 'text/event-stream']);
        $response = $this->sendPostJson($url, $req['body'], $headers);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw $this->mapVendorError($status, $this->decodeJson($response), $response);
        }

        $sawFunctionCall = false;
        $promptBlocked = false;
        $toolIndex = 0;
        $latestFinishRaw = null;
        $latestUsageMeta = null;
        $trailerRaw = null;
        foreach ($this->parseSSEStream($response->getBody()) as $chunk) {
            // A provider error emitted mid-stream arrives as an `{"error": …}` frame —
            // throw instead of silently ending the stream with no finishReason.
            if (($chunk['error'] ?? null) !== null) {
                throw $this->streamError($chunk);
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

            foreach ($parts as $p) {
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
                    $id = is_string($fc['id'] ?? null) ? $fc['id'] : "call_{$name}";
                    // Monotonic index so parallel/multiple calls are never merged
                    // by a consumer aggregating deltas by index.
                    yield new ChatStreamDelta(
                        toolCallDelta: new ToolCallDelta(
                            index: $toolIndex++,
                            id: $id,
                            functionName: $name,
                            argumentsDelta: $this->encodeJson($fc['args'] ?? []),
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

        if ($latestFinishRaw !== null || $latestUsageMeta !== null || $promptBlocked) {
            $finish = match (true) {
                $sawFunctionCall => FinishReason::ToolCalls,
                $latestFinishRaw === null && $promptBlocked => FinishReason::ContentFilter,
                default => $this->mapFinishReason(is_string($latestFinishRaw) ? $latestFinishRaw : null),
            };
            yield new ChatStreamDelta(
                finishReason: $finish,
                usage: $latestUsageMeta !== null ? $this->mapUsage($latestUsageMeta) : null,
                raw: $trailerRaw,
            );
        }
    }

    /**
     * @return array{body:array<string,mixed>,headers:array<string,string>}
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
                if ($t->function->parameters !== null) {
                    $d['parameters'] = $t->function->parameters;
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

        return ['body' => $body, 'headers' => $this->buildHeaders($input)];
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
        $nameById = [];
        foreach ($messages as $m) {
            if ($m->role === ChatRole::Assistant && $m->toolCalls !== null) {
                foreach ($m->toolCalls as $tc) {
                    $nameById[$tc->id] = $tc->function->name;
                }
            }
        }

        $systemParts = [];
        $contents = [];
        foreach ($messages as $m) {
            if ($m->role === ChatRole::System) {
                $t = $this->contentToText($m->content);
                if ($t !== '') {
                    $systemParts[] = $t;
                }

                continue;
            }

            if ($m->role === ChatRole::Tool) {
                $name = $m->name ?? ($m->toolCallId !== null ? ($nameById[$m->toolCallId] ?? $m->toolCallId) : 'tool');
                $part = ['functionResponse' => ['name' => $name, 'response' => ['result' => $this->parseJsonOrString($this->contentToText($m->content))]]];
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

        if ($m->role === ChatRole::Assistant && $m->toolCalls !== null) {
            foreach ($m->toolCalls as $tc) {
                $parts[] = ['functionCall' => ['name' => $tc->function->name, 'args' => $this->safeJsonParse($tc->function->arguments)]];
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
            if ($rf->schema !== null) {
                $gen['responseSchema'] = $rf->schema;
            }
        }
    }

    /**
     * @param array<mixed> $json
     */
    private function parseResult(array $json, string $requestedModel): ChatResult
    {
        $cand = (is_array($json['candidates'] ?? null) ? ($json['candidates'][0] ?? null) : null);
        $cand = is_array($cand) ? $cand : [];
        $content = is_array($cand['content'] ?? null) ? $cand['content'] : [];
        $parts = is_array($content['parts'] ?? null) ? $content['parts'] : [];

        $text = '';
        $toolCalls = [];
        $idx = 0;
        foreach ($parts as $p) {
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
                    id: is_string($fc['id'] ?? null) ? $fc['id'] : "call_{$idx}",
                    function: new ToolCallFunction(
                        name: is_string($fc['name'] ?? null) ? $fc['name'] : '',
                        arguments: $this->encodeJson($fc['args'] ?? []),
                    ),
                );
                $idx++;
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
        $in = is_int($u['promptTokenCount'] ?? null) ? $u['promptTokenCount'] : 0;
        $out = is_int($u['candidatesTokenCount'] ?? null) ? $u['candidatesTokenCount'] : 0;
        $total = is_int($u['totalTokenCount'] ?? null) ? $u['totalTokenCount'] : $in + $out;

        return new Usage($in, $out, $total);
    }

    private function mapFinishReason(?string $fr): FinishReason
    {
        return match ($fr) {
            'STOP' => FinishReason::Stop,
            'MAX_TOKENS' => FinishReason::Length,
            'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII' => FinishReason::ContentFilter,
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
            providerCode: $this->mapStatusToProviderCode($status, $message),
            providerMessage: $message,
            cause: ['raw' => $body, 'retryAfter' => $retryAfter, 'retryAfterSeconds' => $seconds],
            statusCode: $status,
        );
    }

    /**
     * Builds a ConnectorError from a mid-stream `{"error": …}` frame, reusing the
     * non-stream error mapping (Gemini's `error.code` IS the HTTP status).
     *
     * @param array<mixed> $chunk
     */
    private function streamError(array $chunk): ConnectorError
    {
        $error = $chunk['error'] ?? null;
        $status = (is_array($error) && is_int($error['code'] ?? null) && $error['code'] >= 400 && $error['code'] <= 599)
            ? $error['code']
            : 500;
        $message = $this->extractError($chunk) ?? "HTTP {$status}";

        return new ConnectorError(
            providerCode: $this->mapStatusToProviderCode($status, $message),
            providerMessage: $message,
            cause: ['raw' => $chunk, 'retryAfter' => null, 'retryAfterSeconds' => $this->extractRetryDelaySeconds($chunk)],
            statusCode: $status,
        );
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
            $m = strtolower($message);
            if (str_contains($m, 'too long') || str_contains($m, 'context') || str_contains($m, 'token count')) {
                return ProviderCode::ContextLengthExceeded;
            }

            return ProviderCode::InvalidRequest;
        }

        return ProviderCode::Unknown;
    }
}

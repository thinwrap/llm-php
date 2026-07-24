<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Providers\Bedrock;

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

/**
 * Native adapter for the AWS Bedrock Converse API. Requests are signed with a
 * hand-rolled SigV4 over the exact serialized body (zero-dependency).
 *
 * Streaming is a v1 non-incremental fallback: Converse-stream uses AWS binary
 * event-stream framing (not SSE), so `stream()` runs one Converse call and
 * replays the result as deltas.
 */
final class BedrockConnector extends BaseConnector implements ChatConnectorInterface
{
    private const SERVICE = 'bedrock';
    private const DEFAULT_MAX_TOKENS = 4096;

    /** Lowercased headers excluded from the SigV4 signature. */
    private const UNSIGNABLE_HEADERS = [
        'authorization', 'cache-control', 'connection', 'content-length', 'expect',
        'from', 'keep-alive', 'max-forwards', 'pragma', 'proxy-authenticate',
        'proxy-authorization', 'referer', 'te', 'trailer', 'transfer-encoding',
        'upgrade', 'user-agent', 'x-amzn-trace-id',
    ];

    private readonly string $id;
    private readonly string $origin;
    private readonly string $host;
    private readonly string $canonicalBasePath;

    public function __construct(
        private readonly BedrockConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'bedrock';

        $origin = rtrim($config->baseUrl ?? ('https://bedrock-runtime.' . $config->region . '.amazonaws.com'), '/');
        $host = parse_url($origin, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: "Bedrock: invalid `baseUrl`/`region` — could not derive a host from '{$origin}'",
            );
        }
        $this->origin = $origin;

        // A non-default port in a custom baseUrl must appear in the signed Host header;
        // AWS omits the port for the scheme default (443/80), so the real endpoints stay
        // byte-identical.
        $scheme = parse_url($origin, PHP_URL_SCHEME);
        $port = parse_url($origin, PHP_URL_PORT);
        $defaultPort = (is_string($scheme) && strtolower($scheme) === 'http') ? 80 : 443;
        $this->host = (is_int($port) && $port !== $defaultPort) ? "{$host}:{$port}" : $host;

        // A custom baseUrl may carry a path prefix (a gateway mount point). The wire URL
        // already includes it via `$origin`; the SigV4 canonical URI must include it too.
        $path = parse_url($origin, PHP_URL_PATH);
        $this->canonicalBasePath = self::canonicalizePathPrefix(is_string($path) ? $path : '');

        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function complete(ChatInput $input): ChatResult
    {
        $body = $this->buildRequest($input);
        $serialized = $this->encodeJson($body);
        // The wire path is single-encoded, but the SigV4 canonical URI must be
        // DOUBLE-encoded: every non-S3 AWS service URI-encodes the received path a
        // SECOND time before recomputing the signature. Model ids containing `:`
        // (on-demand `…-v2:0`, cross-region profiles, ARNs with `:`/`/`) would
        // otherwise sign `%3A` while the server computes `%253A` → 403.
        $wirePath = '/model/' . rawurlencode($input->model) . '/converse';
        $canonicalPath = $this->canonicalBasePath . '/model/' . rawurlencode(rawurlencode($input->model)) . '/converse';
        $headers = $this->buildSignedHeaders('POST', $canonicalPath, $serialized, $input);

        $response = $this->sendPostJsonRaw($this->origin . $wirePath, $serialized, $headers);

        $status = $response->getStatusCode();
        $json = $this->decodeJson($response);
        if ($status < 200 || $status >= 300) {
            throw $this->mapVendorError($status, $json, $response);
        }

        return $this->parseResult($this->requireDecodedBody($json, $status), $input->model);
    }

    public function stream(ChatInput $input): \Generator
    {
        $result = $this->complete($input);

        if ($result->message->content !== null && $result->message->content !== '') {
            yield new ChatStreamDelta(contentDelta: $result->message->content);
        }

        $index = 0;
        foreach ($result->message->toolCalls ?? [] as $tc) {
            yield new ChatStreamDelta(
                toolCallDelta: new ToolCallDelta(
                    index: $index,
                    id: $tc->id,
                    functionName: $tc->function->name,
                    argumentsDelta: $tc->function->arguments,
                ),
            );
            $index++;
        }

        yield new ChatStreamDelta(finishReason: $result->finishReason, usage: $result->usage, raw: $result->raw);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildRequest(ChatInput $input): array
    {
        [$system, $messages] = $this->splitMessages($input->messages);

        $body = ['messages' => $messages];
        if ($system !== []) {
            $body['system'] = $system;
        }

        $inferenceConfig = ['maxTokens' => $input->maxOutputTokens ?? $this->config->defaultMaxTokens ?? self::DEFAULT_MAX_TOKENS];
        if ($input->temperature !== null) {
            $inferenceConfig['temperature'] = $input->temperature;
        }
        if ($input->topP !== null) {
            $inferenceConfig['topP'] = $input->topP;
        }
        if ($input->stop !== null) {
            $inferenceConfig['stopSequences'] = is_array($input->stop) ? $input->stop : [$input->stop];
        }
        $body['inferenceConfig'] = $inferenceConfig;

        if ($input->tools !== null && $input->tools !== []) {
            $tools = array_map(static function (ToolDefinition $t): array {
                $spec = ['name' => $t->function->name];
                if ($t->function->description !== null) {
                    $spec['description'] = $t->function->description;
                }
                $spec['inputSchema'] = ['json' => $t->function->parameters ?? ['type' => 'object', 'properties' => new \stdClass()]];

                return ['toolSpec' => $spec];
            }, $input->tools);
            $toolConfig = ['tools' => $tools];
            $tc = $this->mapToolChoice($input->toolChoice);
            if ($tc !== null) {
                $toolConfig['toolChoice'] = $tc;
            }
            $body['toolConfig'] = $toolConfig;
        }

        $ptBody = $input->_passthrough['body'] ?? null;
        if (is_array($ptBody)) {
            $body = array_merge($body, $ptBody);
        }

        return $body;
    }

    /**
     * @param list<ChatMessage> $messages
     *
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
     */
    private function splitMessages(array $messages): array
    {
        $system = [];
        $out = [];

        foreach ($messages as $m) {
            if ($m->role === ChatRole::System) {
                $text = $this->contentToText($m->content);
                if ($text !== '') {
                    $system[] = ['text' => $text];
                }

                continue;
            }

            if ($m->role === ChatRole::Tool) {
                $block = [
                    'toolResult' => [
                        'toolUseId' => $m->toolCallId ?? '',
                        'content' => [['text' => $this->contentToText($m->content)]],
                    ],
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

        return [$system, $out];
    }

    private function isToolResultContent(mixed $content): bool
    {
        return is_array($content)
            && isset($content[0])
            && is_array($content[0])
            && isset($content[0]['toolResult']);
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
                $blocks[] = ['text' => $content];
            }
        } elseif (is_array($content)) {
            foreach ($content as $p) {
                if ($p instanceof TextPart) {
                    $blocks[] = ['text' => $p->text];
                } elseif ($p instanceof ImagePart) {
                    $blocks[] = ['image' => ['format' => $this->imageFormat($p->mediaType), 'source' => ['bytes' => $p->base64]]];
                }
            }
        }

        if ($m->role === ChatRole::Assistant && $m->toolCalls !== null) {
            foreach ($m->toolCalls as $tc) {
                $blocks[] = ['toolUse' => ['toolUseId' => $tc->id, 'name' => $tc->function->name, 'input' => $this->safeJsonParse($tc->function->arguments)]];
            }
        }

        if ($blocks === []) {
            $blocks[] = ['text' => ''];
        }

        return $blocks;
    }

    private function imageFormat(string $mediaType): string
    {
        $m = strtolower($mediaType);
        if (str_contains($m, 'png')) {
            return 'png';
        }
        if (str_contains($m, 'jpeg') || str_contains($m, 'jpg')) {
            return 'jpeg';
        }
        if (str_contains($m, 'gif')) {
            return 'gif';
        }
        if (str_contains($m, 'webp')) {
            return 'webp';
        }

        return 'png';
    }

    /**
     * @param string|list<\Thinwrap\Llm\DTO\Chat\ContentPart>|null $content
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
    private function mapToolChoice(string|array|null $tc): ?array
    {
        if ($tc === null) {
            return null;
        }
        if (is_string($tc)) {
            return match ($tc) {
                'auto' => ['auto' => new \stdClass()],
                'required' => ['any' => new \stdClass()],
                default => null,
            };
        }
        $fn = is_array($tc['function'] ?? null) ? $tc['function'] : [];
        $name = $fn['name'] ?? null;

        return is_string($name) ? ['tool' => ['name' => $name]] : null;
    }

    /**
     * @param array<mixed> $json
     */
    private function parseResult(array $json, string $requestedModel): ChatResult
    {
        $output = is_array($json['output'] ?? null) ? $json['output'] : [];
        $message = is_array($output['message'] ?? null) ? $output['message'] : [];
        $content = is_array($message['content'] ?? null) ? $message['content'] : [];

        $text = '';
        $toolCalls = [];
        foreach ($content as $b) {
            if (!is_array($b)) {
                continue;
            }
            if (is_string($b['text'] ?? null)) {
                $text .= $b['text'];
            } elseif (is_array($b['toolUse'] ?? null)) {
                $tu = $b['toolUse'];
                $toolCalls[] = new ToolCall(
                    id: is_string($tu['toolUseId'] ?? null) ? $tu['toolUseId'] : '',
                    function: new ToolCallFunction(
                        name: is_string($tu['name'] ?? null) ? $tu['name'] : '',
                        arguments: $this->encodeJson($tu['input'] ?? []),
                    ),
                );
            }
        }

        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : [];
        $in = is_int($usage['inputTokens'] ?? null) ? $usage['inputTokens'] : 0;
        $out = is_int($usage['outputTokens'] ?? null) ? $usage['outputTokens'] : 0;
        $total = is_int($usage['totalTokens'] ?? null) ? $usage['totalTokens'] : $in + $out;
        $stopReason = $json['stopReason'] ?? null;

        return new ChatResult(
            message: new AssistantMessage(
                content: $text !== '' ? $text : null,
                toolCalls: $toolCalls !== [] ? $toolCalls : null,
            ),
            finishReason: $this->mapStopReason(is_string($stopReason) ? $stopReason : null),
            usage: new Usage($in, $out, $total),
            model: $requestedModel,
            raw: $json,
        );
    }

    private function mapStopReason(?string $sr): FinishReason
    {
        return match ($sr) {
            'end_turn', 'stop_sequence' => FinishReason::Stop,
            'max_tokens' => FinishReason::Length,
            'tool_use' => FinishReason::ToolCalls,
            'content_filtered', 'guardrail_intervened' => FinishReason::ContentFilter,
            default => FinishReason::Unknown,
        };
    }

    // ---- SigV4 -------------------------------------------------------------

    /**
     * @return array<string,string>
     */
    private function buildSignedHeaders(string $method, string $canonicalPath, string $serializedBody, ChatInput $input): array
    {
        $extra = ['Content-Type' => 'application/json'];
        if ($this->config->headers !== null) {
            $extra = array_merge($extra, $this->config->headers);
        }
        $ptHeaders = $input->_passthrough['headers'] ?? null;
        if (is_array($ptHeaders)) {
            $extra = array_merge($extra, $ptHeaders);
        }

        $signed = $this->signAwsRequest($method, $canonicalPath, $serializedBody, $extra);

        // extra headers are sent AND signed; the signed set (Host/X-Amz-*/Authorization) wins on collision.
        return array_merge($extra, $signed);
    }

    /**
     * @param array<string,string> $extraHeaders
     *
     * @return array<string,string>
     */
    private function signAwsRequest(string $method, string $canonicalPath, string $serializedBody, array $extraHeaders): array
    {
        $timestamp = gmdate('Ymd\THis\Z');
        $dateStamp = substr($timestamp, 0, 8);
        $hashedPayload = hash('sha256', $serializedBody);
        $canonicalQuery = '';

        $candidate = array_merge($extraHeaders, [
            'Host' => $this->host,
            'X-Amz-Date' => $timestamp,
            'X-Amz-Content-Sha256' => $hashedPayload,
        ]);
        if ($this->config->sessionToken !== null) {
            $candidate['X-Amz-Security-Token'] = $this->config->sessionToken;
        }

        $normalized = [];
        foreach ($candidate as $name => $value) {
            $lower = strtolower($name);
            if (in_array($lower, self::UNSIGNABLE_HEADERS, true)) {
                continue;
            }
            $collapsed = preg_replace('/\s+/', ' ', trim($value));
            $normalized[$lower] = $collapsed ?? '';
        }
        ksort($normalized, SORT_STRING);

        $signedHeadersList = implode(';', array_keys($normalized));
        $canonicalHeadersString = '';
        foreach ($normalized as $n => $v) {
            $canonicalHeadersString .= "{$n}:{$v}\n";
        }

        $canonicalRequest = "{$method}\n{$canonicalPath}\n{$canonicalQuery}\n{$canonicalHeadersString}\n{$signedHeadersList}\n{$hashedPayload}";

        $credentialScope = "{$dateStamp}/{$this->config->region}/" . self::SERVICE . '/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n{$timestamp}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->config->secretAccessKey, true);
        $kRegion = hash_hmac('sha256', $this->config->region, $kDate, true);
        $kService = hash_hmac('sha256', self::SERVICE, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning, false);

        $authorization = 'AWS4-HMAC-SHA256 '
            . 'Credential=' . $this->config->accessKeyId . '/' . $credentialScope . ', '
            . 'SignedHeaders=' . $signedHeadersList . ', '
            . 'Signature=' . $signature;

        $out = [
            'Host' => $this->host,
            'X-Amz-Date' => $timestamp,
            'X-Amz-Content-Sha256' => $hashedPayload,
            'Authorization' => $authorization,
        ];
        if ($this->config->sessionToken !== null) {
            $out['X-Amz-Security-Token'] = $this->config->sessionToken;
        }

        return $out;
    }

    /**
     * URI-encodes each segment of a base-url path prefix for the SigV4 canonical
     * URI (non-S3 services encode the received wire path once more). Returns '' for
     * the default endpoints, which carry no path — keeping their signature identical.
     */
    private static function canonicalizePathPrefix(string $path): string
    {
        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return '';
        }
        $segments = array_map(
            static fn(string $segment): string => rawurlencode($segment),
            explode('/', $trimmed),
        );

        return '/' . implode('/', $segments);
    }

    // ---- helpers -----------------------------------------------------------

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

        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $err) {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Failed to encode request body',
                cause: ['raw' => ['exception' => $err::class], 'retryAfter' => null],
            );
        }
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
     * @param array<mixed>|null $body
     */
    private function extractError(?array $body): ?string
    {
        if ($body === null) {
            return null;
        }
        if (is_string($body['message'] ?? null)) {
            return $body['message'];
        }
        if (is_string($body['Message'] ?? null)) {
            return $body['Message'];
        }
        if (is_string($body['__type'] ?? null)) {
            return $body['__type'];
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
            $m = strtolower($message);
            if (str_contains($m, 'throttl')) {
                return ProviderCode::RateLimited;
            }
            // Match only phrases that actually denote a context overflow. Bare
            // 'token'/'max' substrings misclassified ordinary 400s ("invalid
            // security token", "malformed…", "exceeds max items") as
            // context_length_exceeded.
            if (
                str_contains($m, 'too long')
                || str_contains($m, 'too many tokens')
                || str_contains($m, 'context length')
                || str_contains($m, 'context window')
                || str_contains($m, 'maximum context')
            ) {
                return ProviderCode::ContextLengthExceeded;
            }

            return ProviderCode::InvalidRequest;
        }

        return ProviderCode::Unknown;
    }
}

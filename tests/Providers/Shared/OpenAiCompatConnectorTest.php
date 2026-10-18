<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Tests\Providers\Shared;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thinwrap\Llm\DTO\Chat\ChatInput;
use Thinwrap\Llm\DTO\Chat\ChatMessage;
use Thinwrap\Llm\DTO\Chat\ChatStreamDelta;
use Thinwrap\Llm\DTO\Chat\ReasoningOptions;
use Thinwrap\Llm\Enum\ChatRole;
use Thinwrap\Llm\Enum\FinishReason;
use Thinwrap\Llm\Enum\ProviderCode;
use Thinwrap\Llm\Enum\ReasoningEffort;
use Thinwrap\Llm\Exception\ConnectorError;
use Thinwrap\Llm\Providers\Shared\OpenAiCompatConfig;
use Thinwrap\Llm\Providers\Shared\OpenAiCompatConnector;
use Thinwrap\Llm\Providers\Shared\Specs;
use Thinwrap\Llm\Tests\Support\ChunkedStream;

final class OpenAiCompatConnectorTest extends TestCase
{
    private MockClient $mock;
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->mock = new MockClient();
        $this->factory = new Psr17Factory();
    }

    private function connector(string $id = 'openai', ?OpenAiCompatConfig $config = null): OpenAiCompatConnector
    {
        $spec = Specs::get($id);
        self::assertNotNull($spec);

        return new OpenAiCompatConnector($spec, $config ?? new OpenAiCompatConfig(apiKey: 'k'), $this->mock, $this->factory, $this->factory);
    }

    /**
     * PSR-18 requires a non-2xx to be RETURNED, not thrown. A client that raises
     * instead still answered — and for an LLM caller, a 429 misreported as an
     * outage is the difference between backing off and failing the request.
     */
    public function testRaisedNon2xxIsStillMappedByTheConnector(): void
    {
        $throwing = new class implements \Psr\Http\Client\ClientInterface {
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $response = new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '20'], '{"error":{"message":"Rate limit reached"}}');

                throw new class ('429 Too Many Requests', $response) extends \RuntimeException implements \Psr\Http\Client\ClientExceptionInterface {
                    public function __construct(string $message, private readonly \Psr\Http\Message\ResponseInterface $response)
                    {
                        parent::__construct($message);
                    }

                    public function getResponse(): \Psr\Http\Message\ResponseInterface
                    {
                        return $this->response;
                    }
                };
            }
        };

        $spec = Specs::get('openai');
        self::assertNotNull($spec);
        $connector = new OpenAiCompatConnector($spec, new OpenAiCompatConfig(apiKey: 'k'), $throwing, $this->factory, $this->factory);

        try {
            $connector->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')]));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(429, $e->statusCode);
            self::assertSame(ProviderCode::RateLimited, $e->providerCode);
            self::assertStringContainsString('Rate limit reached', (string) $e->providerMessage);
        }
    }

    /**
     * @param array<string,mixed>  $body
     * @param array<string,string> $headers
     */
    private function json(int $status, array $body, array $headers = []): Response
    {
        return new Response($status, array_merge(['Content-Type' => 'application/json'], $headers), json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testCompleteMapsResponseAndBuildsRequest(): void
    {
        $this->mock->addResponse($this->json(200, [
            'model' => 'gpt-4o-mini',
            'choices' => [['message' => ['content' => 'hello'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7],
        ]));

        $res = $this->connector()->complete(new ChatInput(
            model: 'gpt-4o-mini',
            messages: [new ChatMessage(ChatRole::User, 'hi')],
        ));

        self::assertSame('hello', $res->message->content);
        self::assertSame(FinishReason::Stop, $res->finishReason);
        self::assertSame(5, $res->usage->inputTokens);
        self::assertSame(7, $res->usage->totalTokens);
        self::assertSame('gpt-4o-mini', $res->model);

        $req = $this->mock->getRequests()[0];
        self::assertSame('https://api.openai.com/v1/chat/completions', (string) $req->getUri());
        self::assertSame('Bearer k', $req->getHeaderLine('Authorization'));

        /** @var array<string,mixed> $sent */
        $sent = json_decode((string) $req->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('gpt-4o-mini', $sent['model']);
        self::assertSame([['role' => 'user', 'content' => 'hi']], $sent['messages']);
    }

    public function testAzureUsesApiKeyHeaderAndRequiresBaseUrl(): void
    {
        $this->mock->addResponse($this->json(200, ['choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']], 'usage' => []]));

        $connector = $this->connector('azure-openai', new OpenAiCompatConfig(apiKey: 'zk', baseUrl: 'https://r.openai.azure.com/openai/v1'));
        $connector->complete(new ChatInput(model: 'gpt', messages: [new ChatMessage(ChatRole::User, 'hi')]));

        $req = $this->mock->getRequests()[0];
        self::assertSame('zk', $req->getHeaderLine('api-key'));
        self::assertSame('', $req->getHeaderLine('Authorization'));
    }

    public function testProviderWithoutDefaultBaseUrlThrows(): void
    {
        $this->expectException(ConnectorError::class);
        $this->connector('azure-openai', new OpenAiCompatConfig(apiKey: 'k'));
    }

    public function testMaps401ToAuthFailed(): void
    {
        $this->mock->addResponse($this->json(401, ['error' => ['message' => 'bad key']]));
        try {
            $this->connector()->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'x')]));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::AuthFailed, $e->providerCode);
            self::assertSame('bad key', $e->providerMessage);
        }
    }

    public function testMaps429WithRetryAfterToRateLimited(): void
    {
        $this->mock->addResponse($this->json(429, ['error' => ['message' => 'slow down']], ['Retry-After' => '30']));
        try {
            $this->connector()->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'x')]));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::RateLimited, $e->providerCode);
            /** @var array<string,mixed> $cause */
            $cause = $e->cause;
            self::assertSame('30', $cause['retryAfter']);
            self::assertSame(30, $cause['retryAfterSeconds']);
        }
    }

    public function testMaps400ContextLengthToContextLengthExceeded(): void
    {
        $this->mock->addResponse($this->json(400, ['error' => ['message' => 'This model\'s maximum context length is 8192 tokens']]));
        try {
            $this->connector()->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'x')]));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::ContextLengthExceeded, $e->providerCode);
        }
    }

    public function testStreamYieldsContentFinishAndUsage(): void
    {
        $sse = 'data: {"choices":[{"delta":{"content":"He"}}]}' . "\n\n"
            . 'data: {"choices":[{"delta":{"content":"llo"}}]}' . "\n\n"
            . 'data: {"choices":[{"delta":{},"finish_reason":"stop"}],"usage":{"prompt_tokens":2,"completion_tokens":1,"total_tokens":3}}' . "\n\n"
            . 'data: [DONE]' . "\n\n";
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'text/event-stream'], $sse));

        $deltas = [];
        foreach ($this->connector()->stream(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')])) as $d) {
            $deltas[] = $d;
        }

        $text = '';
        $sawStop = false;
        $usage = null;
        foreach ($deltas as $d) {
            self::assertInstanceOf(ChatStreamDelta::class, $d);
            $text .= $d->contentDelta ?? '';
            if ($d->finishReason === FinishReason::Stop) {
                $sawStop = true;
            }
            if ($d->usage !== null) {
                $usage = $d->usage;
            }
        }
        self::assertSame('Hello', $text);
        self::assertTrue($sawStop);
        self::assertNotNull($usage);
        self::assertSame(3, $usage->totalTokens);

        // stream request carried stream:true + stream_options
        /** @var array<string,mixed> $sent */
        $sent = json_decode((string) $this->mock->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($sent['stream']);
        self::assertSame(['include_usage' => true], $sent['stream_options']);
    }

    public function testMidStreamErrorFrameThrowsConnectorError(): void
    {
        // Partial content, then a gateway/provider `{"error": …}` frame — must
        // surface as a ConnectorError, not a silently truncated success.
        $frame1 = json_encode(['choices' => [['delta' => ['content' => 'Hel']]]], JSON_THROW_ON_ERROR);
        $frame2 = json_encode(['error' => ['message' => 'upstream exploded', 'type' => 'server_error', 'code' => 503]], JSON_THROW_ON_ERROR);
        $sse = "data: {$frame1}\n\ndata: {$frame2}\n\n";
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'text/event-stream'], $sse));

        $text = '';
        try {
            foreach ($this->connector()->stream(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')])) as $d) {
                $text .= $d->contentDelta ?? '';
            }
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame('Hel', $text, 'content before the error frame is still delivered');
            self::assertSame(ProviderCode::ProviderUnavailable, $e->providerCode);
            self::assertSame('upstream exploded', $e->providerMessage);
            self::assertSame(200, $e->statusCode);
            /** @var array<string,mixed> $cause */
            $cause = $e->cause;
            self::assertIsArray($cause['raw']);
        }
    }

    public function testStreamEmitsAllParallelToolCallDeltas(): void
    {
        // Two tool-call fragments in ONE chunk — both must be emitted, not just [0].
        $frame = json_encode(['choices' => [['delta' => ['tool_calls' => [
            ['index' => 0, 'id' => 'call_a', 'function' => ['name' => 'fa', 'arguments' => '{"x":1}']],
            ['index' => 1, 'id' => 'call_b', 'function' => ['name' => 'fb', 'arguments' => '{"y":2}']],
        ]]]]], JSON_THROW_ON_ERROR);
        $trailer = json_encode(['choices' => [['delta' => new \stdClass(), 'finish_reason' => 'tool_calls']]], JSON_THROW_ON_ERROR);
        $sse = "data: {$frame}\n\ndata: {$trailer}\n\ndata: [DONE]\n\n";
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'text/event-stream'], $sse));

        $toolDeltas = [];
        $sawToolCallsFinish = false;
        foreach ($this->connector()->stream(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')])) as $d) {
            if ($d->toolCallDelta !== null) {
                $toolDeltas[] = $d->toolCallDelta;
            }
            if ($d->finishReason === FinishReason::ToolCalls) {
                $sawToolCallsFinish = true;
            }
        }

        self::assertCount(2, $toolDeltas);
        self::assertSame(0, $toolDeltas[0]->index);
        self::assertSame('call_a', $toolDeltas[0]->id);
        self::assertSame('fa', $toolDeltas[0]->functionName);
        self::assertSame('{"x":1}', $toolDeltas[0]->argumentsDelta);
        self::assertSame(1, $toolDeltas[1]->index);
        self::assertSame('call_b', $toolDeltas[1]->id);
        self::assertSame('fb', $toolDeltas[1]->functionName);
        self::assertSame('{"y":2}', $toolDeltas[1]->argumentsDelta);
        self::assertTrue($sawToolCallsFinish);
    }

    public function testGroqReadsStreamingUsageFromXGroq(): void
    {
        $sse = 'data: {"choices":[{"delta":{"content":"hi"}}]}' . "\n\n"
            . 'data: {"choices":[{"delta":{},"finish_reason":"stop"}],"x_groq":{"usage":{"prompt_tokens":4,"completion_tokens":1,"total_tokens":5}}}' . "\n\n"
            . 'data: [DONE]' . "\n\n";
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'text/event-stream'], $sse));

        $usage = null;
        foreach ($this->connector('groq')->stream(new ChatInput(model: 'llama', messages: [new ChatMessage(ChatRole::User, 'hi')])) as $d) {
            if ($d->usage !== null) {
                $usage = $d->usage;
            }
        }
        self::assertNotNull($usage);
        self::assertSame(5, $usage->totalTokens);
    }

    /**
     * OpenAI deprecates `max_tokens` for `max_completion_tokens` (o-series / GPT-5
     * reject it); Azure reasoning deployments accept only `max_completion_tokens`.
     * Every other OpenAI-compatible provider keeps `max_tokens`.
     */
    public function testMaxOutputTokensFieldPerProvider(): void
    {
        $cases = [
            ['openai', null, 'max_completion_tokens'],
            ['azure-openai', 'https://r.openai.azure.com/openai/v1', 'max_completion_tokens'],
            ['groq', null, 'max_tokens'],
        ];
        foreach ($cases as $i => [$id, $baseUrl, $field]) {
            $this->mock->addResponse($this->json(200, ['choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']]]));
            $this->connector($id, new OpenAiCompatConfig(apiKey: 'k', baseUrl: $baseUrl))
                ->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')], maxOutputTokens: 64));

            /** @var array<string,mixed> $sent */
            $sent = json_decode((string) $this->mock->getRequests()[$i]->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(64, $sent[$field] ?? null, "{$id} sends {$field}");
            $other = $field === 'max_tokens' ? 'max_completion_tokens' : 'max_tokens';
            self::assertArrayNotHasKey($other, $sent, "{$id} must not send {$other}");
        }
    }

    /**
     * OpenRouter: an error after processing started is a 200 whose body is only an
     * `error` object with no `choices`.
     */
    public function testHttp200WithTopLevelErrorThrows(): void
    {
        $this->mock->addResponse($this->json(200, ['error' => ['code' => 429, 'message' => 'Provider rate limited the request']]));

        try {
            $this->connector('openrouter')->complete(new ChatInput(model: 'openai/gpt-4o', messages: [new ChatMessage(ChatRole::User, 'hi')]));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::RateLimited, $e->providerCode);
            // In-body errors carry the HTTP status they arrived with.
            self::assertSame(200, $e->statusCode);
            self::assertSame('Provider rate limited the request', $e->providerMessage);
        }
    }

    public function testHttp200WithNonHttpErrorCodeIsProviderUnavailable(): void
    {
        $this->mock->addResponse($this->json(200, ['error' => ['code' => 'server_error', 'message' => 'boom']]));

        try {
            $this->connector('openrouter')->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')]));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::ProviderUnavailable, $e->providerCode);
            self::assertSame('boom', $e->providerMessage);
        }
    }

    /**
     * OpenRouter: a mid-generation failure is a 200 with `choices[0].finish_reason:
     * "error"` and `choices[0].error` alongside the partial content.
     */
    public function testHttp200WithChoiceErrorThrowsAndKeepsPartialContentInRaw(): void
    {
        $this->mock->addResponse($this->json(200, [
            'id' => 'gen-1',
            'model' => 'openai/gpt-4o',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Partial ans'],
                'finish_reason' => 'error',
                'error' => ['code' => 502, 'message' => 'Upstream provider disconnected'],
            ]],
        ]));

        try {
            $this->connector('openrouter')->complete(new ChatInput(model: 'openai/gpt-4o', messages: [new ChatMessage(ChatRole::User, 'hi')]));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::ProviderUnavailable, $e->providerCode);
            self::assertSame(200, $e->statusCode);
            self::assertSame('Upstream provider disconnected', $e->providerMessage);
            /** @var array{raw:array<string,mixed>} $cause */
            $cause = $e->cause;
            self::assertSame('gen-1', $cause['raw']['id']);
        }
    }

    public function testHttp200EmptyObjectThrows(): void
    {
        $this->mock->addResponse($this->json(200, []));

        try {
            $this->connector()->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')]));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::ProviderUnavailable, $e->providerCode);
            self::assertSame(200, $e->statusCode);
        }
    }

    public function testHttp200EmptyChoicesThrows(): void
    {
        $this->mock->addResponse($this->json(200, ['id' => 'x', 'choices' => []]));

        $this->expectException(ConnectorError::class);
        $this->connector()->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')]));
    }

    /**
     * Mistral reasoning (`reasoning_effort: "high"`): `message.content` is a list of
     * chunks — a ThinkChunk `{type: thinking, thinking: [TextChunk…]}` then TextChunks.
     */
    public function testChunkArrayContentYieldsOnlyAnswerText(): void
    {
        $this->mock->addResponse($this->json(200, [
            'model' => 'magistral-medium-latest',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => [
                    ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'Let me work this out.']]],
                    ['type' => 'text', 'text' => 'The answer '],
                    ['type' => 'text', 'text' => 'is 4.'],
                ]],
                'finish_reason' => 'stop',
            ]],
        ]));

        $res = $this->connector('mistral')->complete(new ChatInput(
            model: 'magistral-medium-latest',
            messages: [new ChatMessage(ChatRole::User, '2+2?')],
            reasoning: new ReasoningOptions(ReasoningEffort::High),
        ));

        self::assertSame('The answer is 4.', $res->message->content);
        self::assertSame(FinishReason::Stop, $res->finishReason);
    }

    public function testChunkArrayWithOnlyThinkingIsNoContent(): void
    {
        $this->mock->addResponse($this->json(200, [
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => [
                    ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'hmm']]],
                ]],
                'finish_reason' => 'length',
            ]],
        ]));

        $res = $this->connector('mistral')->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'x')]));
        self::assertNull($res->message->content);
    }

    /**
     * Mistral reasoning stream: `delta.content` is a list holding a ThinkChunk while
     * thinking, a list with the closing ThinkChunk + first TextChunk at the
     * transition, then a plain string.
     */
    public function testStreamChunkArrayDeltasEmitOnlyAnswerText(): void
    {
        $frames = [
            ['choices' => [['delta' => ['role' => 'assistant', 'content' => [
                ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'Thinking about it']]],
            ]]]]],
            ['choices' => [['delta' => ['content' => [
                ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => ' done.']]],
                ['type' => 'text', 'text' => 'The answer'],
            ]]]]],
            ['choices' => [['delta' => ['content' => ' is 4.']]]],
            ['choices' => [['delta' => ['content' => ''], 'finish_reason' => 'stop']]],
        ];
        $sse = '';
        foreach ($frames as $f) {
            $sse .= 'data: ' . json_encode($f, JSON_THROW_ON_ERROR) . "\n\n";
        }
        $sse .= "data: [DONE]\n\n";
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'text/event-stream'], $sse));

        $text = '';
        $sawStop = false;
        foreach ($this->connector('mistral')->stream(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'x')])) as $d) {
            $text .= $d->contentDelta ?? '';
            $sawStop = $sawStop || $d->finishReason === FinishReason::Stop;
        }

        self::assertSame('The answer is 4.', $text);
        self::assertTrue($sawStop);
    }

    /**
     * A transport failure must not leave the API key in the exception's stack
     * trace: PHP records call arguments there (`zend.exception_ignore_args=0`, the
     * default without a php.ini), and traces end up in logs and error trackers.
     */
    public function testTransportFailureTraceCarriesNoSecret(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        $secret = 'sk-TRACE-LEAK-CANARY';
        $headerSecret = 'hdr-TRACE-LEAK-CANARY';

        $failing = new class implements \Psr\Http\Client\ClientInterface {
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                throw new class ('connection refused') extends \RuntimeException implements \Psr\Http\Client\NetworkExceptionInterface {
                    public function getRequest(): \Psr\Http\Message\RequestInterface
                    {
                        throw new \LogicException('unused');
                    }
                };
            }
        };

        try {
            $spec = Specs::get('openai');
            self::assertNotNull($spec);
            $connector = new OpenAiCompatConnector(
                $spec,
                new OpenAiCompatConfig(apiKey: $secret, headers: ['cf-aig-authorization' => $headerSecret]),
                $failing,
                $this->factory,
                $this->factory,
            );
            $connector->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')]));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::ProviderUnavailable, $e->providerCode);
            $trace = print_r($e->getTrace(), true);
            self::assertStringContainsString('SensitiveParameterValue', $trace, 'trace args are recorded, so the check is meaningful');
            self::assertStringNotContainsString($secret, $trace);
            self::assertStringNotContainsString($headerSecret, $trace);
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '0' : $previous);
        }
    }

    public function testMidStreamErrorFrameHonoursItsType(): void
    {
        $frame = json_encode(['error' => ['message' => "This model's maximum context length is 128000 tokens.", 'type' => 'invalid_request_error', 'code' => 'context_length_exceeded']], JSON_THROW_ON_ERROR);
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'text/event-stream'], "data: {$frame}\n\n"));

        try {
            foreach ($this->connector()->stream(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')])) as $_) {
            }
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::ContextLengthExceeded, $e->providerCode);
            self::assertSame(200, $e->statusCode);
        }

        $frame = json_encode(['error' => ['message' => 'Rate limit reached', 'type' => 'rate_limit_error']], JSON_THROW_ON_ERROR);
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'text/event-stream'], "data: {$frame}\n\n"));
        try {
            foreach ($this->connector()->stream(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')])) as $_) {
            }
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::RateLimited, $e->providerCode);
        }
    }

    public function testPassthroughQueryIsRfc3986Encoded(): void
    {
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
        ], JSON_THROW_ON_ERROR)));

        $this->connector()->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')], _passthrough: ['query' => ['tag' => 'a b']]));

        self::assertSame('https://api.openai.com/v1/chat/completions?tag=a%20b', (string) $this->mock->getRequests()[0]->getUri());
    }

    /**
     * @return list<\Thinwrap\Llm\DTO\Chat\ChatStreamDelta>
     */
    private function drainStream(Response $response): array
    {
        $this->mock->addResponse($response);
        $out = [];
        foreach ($this->connector()->stream(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')])) as $d) {
            $out[] = $d;
        }

        return $out;
    }

    private function sse(string $body, ?string $contentType = 'text/event-stream'): Response
    {
        $headers = $contentType === null ? [] : ['Content-Type' => $contentType];

        return (new Response(200, $headers))->withBody(new ChunkedStream($body, 4));
    }

    public function testStreamEndingBeforeDoneOrFinishThrows(): void
    {
        $text = '';
        try {
            foreach ($this->drainStream($this->sse('data: {"choices":[{"delta":{"content":"Hel"}}]}' . "\n\n")) as $d) {
                $text .= $d->contentDelta ?? '';
            }
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::ProviderUnavailable, $e->providerCode);
            self::assertStringContainsString('terminal event', (string) $e->providerMessage);
        }
    }

    public function testStreamCompletesOnDoneOrOnAFinishReason(): void
    {
        $content = 'data: {"choices":[{"delta":{"content":"Hi"}}]}' . "\n\n";
        self::assertCount(1, $this->drainStream($this->sse($content . "data: [DONE]\n\n")));
        self::assertCount(2, $this->drainStream($this->sse($content . 'data: {"choices":[{"delta":{},"finish_reason":"stop"}]}' . "\n\n")));
        // A missing content-type is let through; the terminal check still applies.
        self::assertCount(1, $this->drainStream($this->sse($content . "data: [DONE]\n\n", null)));
    }

    public function testNonSseSuccessBodyThrows(): void
    {
        try {
            $this->drainStream(new Response(200, ['Content-Type' => 'application/json; charset=utf-8'], '{"choices":[{"message":{"content":"hi"}}]}'));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::ProviderUnavailable, $e->providerCode);
            self::assertSame(200, $e->statusCode);
            self::assertStringContainsString('application/json', (string) $e->providerMessage);
        }
    }

    public function testPaymentRequiredIsRateLimited(): void
    {
        $this->mock->addResponse(new Response(402, ['Content-Type' => 'application/json'], '{"error":{"message":"Insufficient credits","code":402}}'));
        try {
            $this->connector('openrouter')->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')]));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::RateLimited, $e->providerCode);
        }
    }

    public function testContentPolicyAndModerationAreContentFiltered(): void
    {
        foreach (['Your request was rejected as a result of our safety system: content_policy_violation', 'Flagged by moderation'] as $message) {
            $this->mock->addResponse(new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => ['message' => $message]], JSON_THROW_ON_ERROR)));
            try {
                $this->connector()->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')]));
                self::fail('expected ConnectorError');
            } catch (ConnectorError $e) {
                self::assertSame(ProviderCode::ContentFiltered, $e->providerCode, $message);
            }
        }
    }

    public function testRequestTimeoutIsProviderUnavailable(): void
    {
        $this->mock->addResponse(new Response(408, ['Content-Type' => 'application/json'], '{"error":{"message":"timeout"}}'));
        try {
            $this->connector()->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')]));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::ProviderUnavailable, $e->providerCode);
        }
    }

    public function testInBodyErrorWithoutTypeOrCodeIsUnknown(): void
    {
        $frame = json_encode(['error' => ['message' => 'something odd']], JSON_THROW_ON_ERROR);
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'text/event-stream'], "data: {$frame}\n\n"));
        try {
            foreach ($this->connector()->stream(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')])) as $_) {
            }
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::Unknown, $e->providerCode);
            self::assertSame(200, $e->statusCode);
        }
    }

    public function testNonStreamInBodyErrorKeepsTheArrivalStatus(): void
    {
        $cases = [
            [['error' => ['code' => '429', 'message' => 'slow down']], ProviderCode::RateLimited],
            [['error' => ['code' => 'insufficient_quota', 'message' => 'no credit']], ProviderCode::RateLimited],
            [['choices' => [['message' => ['content' => 'Part'], 'finish_reason' => 'error']]], ProviderCode::Unknown],
            [['error' => ['message' => 'mystery']], ProviderCode::Unknown],
        ];
        foreach ($cases as [$body, $expected]) {
            $this->mock->addResponse(new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)));
            try {
                $this->connector('openrouter')->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')]));
                self::fail('expected ConnectorError');
            } catch (ConnectorError $e) {
                self::assertSame($expected, $e->providerCode, json_encode($body, JSON_THROW_ON_ERROR));
                self::assertSame(200, $e->statusCode);
            }
        }
    }
}

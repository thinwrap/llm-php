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
use Thinwrap\Llm\Enum\ChatRole;
use Thinwrap\Llm\Enum\FinishReason;
use Thinwrap\Llm\Enum\ProviderCode;
use Thinwrap\Llm\Exception\ConnectorError;
use Thinwrap\Llm\Providers\Shared\OpenAiCompatConfig;
use Thinwrap\Llm\Providers\Shared\OpenAiCompatConnector;
use Thinwrap\Llm\Providers\Shared\Specs;

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
            self::assertSame(503, $e->statusCode);
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
}

<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Tests\Providers\Anthropic;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thinwrap\Llm\DTO\Chat\ChatInput;
use Thinwrap\Llm\DTO\Chat\ChatMessage;
use Thinwrap\Llm\DTO\Chat\ReasoningOptions;
use Thinwrap\Llm\DTO\Chat\ToolCall;
use Thinwrap\Llm\DTO\Chat\ToolCallFunction;
use Thinwrap\Llm\Enum\ChatRole;
use Thinwrap\Llm\Enum\FinishReason;
use Thinwrap\Llm\Enum\ProviderCode;
use Thinwrap\Llm\Enum\ReasoningEffort;
use Thinwrap\Llm\Exception\ConnectorError;
use Thinwrap\Llm\Providers\Anthropic\AnthropicConfig;
use Thinwrap\Llm\Providers\Anthropic\AnthropicConnector;

final class AnthropicConnectorTest extends TestCase
{
    private MockClient $mock;
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->mock = new MockClient();
        $this->factory = new Psr17Factory();
    }

    private function connector(): AnthropicConnector
    {
        return new AnthropicConnector(new AnthropicConfig(apiKey: 'ak'), $this->mock, $this->factory, $this->factory);
    }

    /**
     * @param array<string,mixed> $body
     */
    private function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testTranslatesMessagesAndHoistsSystem(): void
    {
        $this->mock->addResponse($this->json(200, [
            'model' => 'claude-x',
            'content' => [['type' => 'text', 'text' => 'hello']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
        ]));

        $res = $this->connector()->complete(new ChatInput(
            model: 'claude-x',
            messages: [
                new ChatMessage(ChatRole::System, 'be brief'),
                new ChatMessage(ChatRole::User, 'hi'),
            ],
            maxOutputTokens: 50,
        ));

        self::assertSame('hello', $res->message->content);
        self::assertSame(FinishReason::Stop, $res->finishReason);
        self::assertSame(7, $res->usage->totalTokens);

        $req = $this->mock->getRequests()[0];
        self::assertSame('https://api.anthropic.com/v1/messages', (string) $req->getUri());
        self::assertSame('ak', $req->getHeaderLine('x-api-key'));
        self::assertSame('2023-06-01', $req->getHeaderLine('anthropic-version'));

        /** @var array<string,mixed> $sent */
        $sent = json_decode((string) $req->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('be brief', $sent['system']);
        self::assertSame(50, $sent['max_tokens']);
        self::assertSame([['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]]], $sent['messages']);
    }

    public function testExplicitMaxTokensBelowReasoningBudgetRaises(): void
    {
        // Explicit maxOutputTokens (100) below the "low" budget (1024) → invalid_request,
        // not a silent inflation past the caller's cap.
        try {
            $this->connector()->complete(new ChatInput(
                model: 'm',
                messages: [new ChatMessage(ChatRole::User, 'hi')],
                maxOutputTokens: 100,
                reasoning: new ReasoningOptions(ReasoningEffort::Low),
            ));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::InvalidRequest, $e->providerCode);
        }
    }

    public function testDefaultMaxTokensInflatedWhenReasoningAndNoExplicitCap(): void
    {
        $this->mock->addResponse($this->json(200, [
            'model' => 'm',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]));

        $this->connector()->complete(new ChatInput(
            model: 'm',
            messages: [new ChatMessage(ChatRole::User, 'hi')],
            reasoning: new ReasoningOptions(ReasoningEffort::Low),
        ));

        /** @var array<string,mixed> $sent */
        $sent = json_decode((string) $this->mock->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertGreaterThan(1024, $sent['max_tokens']);
    }

    public function testToolUseBlockBecomesToolCalls(): void
    {
        $this->mock->addResponse($this->json(200, [
            'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'get_weather', 'input' => ['city' => 'SF']]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 8, 'output_tokens' => 4],
        ]));

        $res = $this->connector()->complete(new ChatInput(
            model: 'claude-x',
            messages: [new ChatMessage(ChatRole::User, 'weather?')],
            tools: [new \Thinwrap\Llm\DTO\Chat\ToolDefinition(new \Thinwrap\Llm\DTO\Chat\ToolFunction('get_weather', null, ['type' => 'object']))],
            toolChoice: 'required',
        ));

        self::assertNull($res->message->content);
        self::assertSame(FinishReason::ToolCalls, $res->finishReason);
        self::assertNotNull($res->message->toolCalls);
        self::assertSame('t1', $res->message->toolCalls[0]->id);
        self::assertSame('get_weather', $res->message->toolCalls[0]->function->name);
        self::assertSame('{"city":"SF"}', $res->message->toolCalls[0]->function->arguments);

        /** @var array<string,mixed> $sent */
        $sent = json_decode((string) $this->mock->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['type' => 'any'], $sent['tool_choice']);
        self::assertSame('get_weather', $sent['tools'][0]['name']);
        self::assertSame(['type' => 'object'], $sent['tools'][0]['input_schema']);
    }

    public function testCoalescesConsecutiveToolMessagesIntoOneUserTurn(): void
    {
        $this->mock->addResponse($this->json(200, ['content' => [['type' => 'text', 'text' => 'ok']], 'stop_reason' => 'end_turn', 'usage' => []]));

        $this->connector()->complete(new ChatInput(
            model: 'claude-x',
            messages: [
                new ChatMessage(ChatRole::User, 'q'),
                new ChatMessage(ChatRole::Assistant, null, toolCalls: [
                    new ToolCall('t1', new ToolCallFunction('a', '{}')),
                    new ToolCall('t2', new ToolCallFunction('b', '{}')),
                ]),
                new ChatMessage(ChatRole::Tool, 'first', toolCallId: 't1'),
                new ChatMessage(ChatRole::Tool, 'second', toolCallId: 't2'),
            ],
        ));

        /** @var array<string,mixed> $sent */
        $sent = json_decode((string) $this->mock->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $messages = $sent['messages'];
        $lastTurn = $messages[count($messages) - 1];
        self::assertSame('user', $lastTurn['role']);
        self::assertCount(2, $lastTurn['content']);
        self::assertSame('tool_result', $lastTurn['content'][0]['type']);
        self::assertSame('t1', $lastTurn['content'][0]['tool_use_id']);
        self::assertSame('t2', $lastTurn['content'][1]['tool_use_id']);
    }

    public function testMaps401ToAuthAnd529ToProviderUnavailable(): void
    {
        $this->mock->addResponse($this->json(401, ['error' => ['message' => 'bad']]));
        try {
            $this->connector()->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'x')]));
            self::fail('expected');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::AuthFailed, $e->providerCode);
        }

        $this->mock->addResponse($this->json(529, ['error' => ['message' => 'overloaded']]));
        try {
            $this->connector()->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'x')]));
            self::fail('expected');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::ProviderUnavailable, $e->providerCode);
        }
    }

    public function testGatewayFiveHundredsMapToProviderUnavailable(): void
    {
        // 504/520/522/524 (gateway / Cloudflare-origin) fell through the old fixed
        // {500,502,503,529} whitelist to Unknown; any 5xx must be provider_unavailable.
        foreach ([504, 520, 522, 524] as $status) {
            $this->mock->addResponse($this->json($status, ['error' => ['message' => 'gateway']]));
            try {
                $this->connector()->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'x')]));
                self::fail('expected');
            } catch (ConnectorError $e) {
                self::assertSame(ProviderCode::ProviderUnavailable, $e->providerCode, (string) $status);
            }
        }
    }

    public function testMidStreamErrorEventThrowsConnectorError(): void
    {
        // Partial text, then an `event: error` frame (e.g. overloaded_error) — must
        // surface as a ConnectorError instead of ending with no finishReason.
        $frame1 = json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'partial']], JSON_THROW_ON_ERROR);
        $frame2 = json_encode(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], JSON_THROW_ON_ERROR);
        $sse = "data: {$frame1}\n\ndata: {$frame2}\n\n";
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'text/event-stream'], $sse));

        $text = '';
        try {
            foreach ($this->connector()->stream(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')])) as $d) {
                $text .= $d->contentDelta ?? '';
            }
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame('partial', $text);
            self::assertSame(ProviderCode::ProviderUnavailable, $e->providerCode);
            self::assertSame('Overloaded', $e->providerMessage);
            self::assertSame(529, $e->statusCode);
        }
    }

    public function testStreamsTypedSseEvents(): void
    {
        $sse = 'data: {"type":"message_start","message":{"usage":{"input_tokens":5}}}' . "\n\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Hello"}}' . "\n\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":3}}' . "\n\n";
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'text/event-stream'], $sse));

        $text = '';
        $sawStop = false;
        $usage = null;
        foreach ($this->connector()->stream(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')])) as $d) {
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
        self::assertSame(5, $usage->inputTokens);
        self::assertSame(8, $usage->totalTokens);
    }
}

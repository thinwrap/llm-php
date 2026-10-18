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
use Thinwrap\Llm\Tests\Support\ChunkedStream;

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

    public function testReasoningSendsAdaptiveThinkingWithEffortAndNoBudget(): void
    {
        $this->mock->addResponse($this->json(200, [
            'model' => 'claude-opus-5-5',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]));

        // A cap below any former budget is sent untouched: adaptive thinking has no
        // budget to exceed, so neither a guard nor an inflation applies.
        $this->connector()->complete(new ChatInput(
            model: 'claude-opus-5-5',
            messages: [new ChatMessage(ChatRole::User, 'hi')],
            temperature: 0.3,
            topP: 0.9,
            maxOutputTokens: 100,
            reasoning: new ReasoningOptions(ReasoningEffort::Medium),
        ));

        /** @var array<string,mixed> $sent */
        $sent = json_decode((string) $this->mock->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['type' => 'adaptive'], $sent['thinking']);
        self::assertSame(['effort' => 'medium'], $sent['output_config']);
        self::assertSame(100, $sent['max_tokens']);
        self::assertArrayNotHasKey('temperature', $sent);
        self::assertArrayNotHasKey('top_p', $sent);
        self::assertStringNotContainsString('budget_tokens', (string) $this->mock->getRequests()[0]->getBody());
    }

    public function testReasoningKeepsDefaultMaxTokensWhenCallerSetsNone(): void
    {
        $this->mock->addResponse($this->json(200, [
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]));

        $this->connector()->complete(new ChatInput(
            model: 'claude-sonnet-5',
            messages: [new ChatMessage(ChatRole::User, 'hi')],
            reasoning: new ReasoningOptions(ReasoningEffort::High),
        ));

        /** @var array<string,mixed> $sent */
        $sent = json_decode((string) $this->mock->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(16000, $sent['max_tokens']);
        self::assertSame(['effort' => 'high'], $sent['output_config']);
    }

    public function testBudgetThinkingRidesPassthroughForOlderModels(): void
    {
        $this->mock->addResponse($this->json(200, [
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]));

        $this->connector()->complete(new ChatInput(
            model: 'claude-haiku-4-5',
            messages: [new ChatMessage(ChatRole::User, 'hi')],
            maxOutputTokens: 4096,
            _passthrough: ['body' => ['thinking' => ['type' => 'enabled', 'budget_tokens' => 2048]]],
        ));

        /** @var array<string,mixed> $sent */
        $sent = json_decode((string) $this->mock->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['type' => 'enabled', 'budget_tokens' => 2048], $sent['thinking']);
        self::assertArrayNotHasKey('output_config', $sent);
    }

    public function testAdaptiveRejectionOnOlderModelPassesThroughAsInvalidRequest(): void
    {
        $this->mock->addResponse($this->json(400, [
            'type' => 'error',
            'error' => ['type' => 'invalid_request_error', 'message' => 'adaptive thinking is not supported on this model'],
        ]));

        try {
            $this->connector()->complete(new ChatInput(
                model: 'claude-haiku-4-5',
                messages: [new ChatMessage(ChatRole::User, 'hi')],
                reasoning: new ReasoningOptions(ReasoningEffort::Low),
            ));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::InvalidRequest, $e->providerCode);
            self::assertSame(400, $e->statusCode);
        }
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
            // The stream arrived as a 200; the frame's type only classifies it.
            self::assertSame(200, $e->statusCode);
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

    public function testHttp200WithoutPayloadThrows(): void
    {
        $this->mock->addResponse($this->json(200, []));

        try {
            $this->connector()->complete(new ChatInput(model: 'claude-x', messages: [new ChatMessage(ChatRole::User, 'hi')]));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::ProviderUnavailable, $e->providerCode);
            self::assertSame(200, $e->statusCode);
        }
    }

    /**
     * A thinking + tool_use response shaped as the Messages API documents it.
     *
     * @return list<array<string,mixed>>
     */
    private static function thinkingToolContent(): array
    {
        return [
            ['type' => 'thinking', 'thinking' => 'Need the weather tool.', 'signature' => 'EqQBCgIYAhIM1gbcDa9GJwZA2b3hGgxB'],
            ['type' => 'redacted_thinking', 'data' => 'EmwKAhgBEgy3va3pzix'],
            ['type' => 'text', 'text' => 'Checking.'],
            ['type' => 'tool_use', 'id' => 'toolu_01A', 'name' => 'get_weather', 'input' => ['city' => 'Paris']],
        ];
    }

    public function testNonStreamResultCarriesVerbatimContentAsProviderMetadata(): void
    {
        $this->mock->addResponse($this->json(200, [
            'model' => 'claude-opus-5-5',
            'content' => self::thinkingToolContent(),
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
        ]));

        $res = $this->connector()->complete(new ChatInput(model: 'claude-opus-5-5', messages: [new ChatMessage(ChatRole::User, 'weather?')]));

        self::assertSame(['anthropic' => ['content' => self::thinkingToolContent()]], $res->message->providerMetadata);
        self::assertSame('Checking.', $res->message->content);
        self::assertNotNull($res->message->toolCalls);
        self::assertNull($res->message->toolCalls[0]->providerMetadata);
    }

    public function testReplaysVerbatimBlocksWhenTheyMatchTheMessage(): void
    {
        $this->mock->addResponse($this->json(200, ['content' => [['type' => 'text', 'text' => 'Sunny']], 'stop_reason' => 'end_turn', 'usage' => []]));

        $blocks = self::thinkingToolContent();
        $blocks[] = ['type' => 'tool_use', 'id' => 'toolu_01B', 'name' => 'ping', 'input' => []];
        $this->connector()->complete(new ChatInput(
            model: 'claude-opus-5-5',
            messages: [
                new ChatMessage(ChatRole::User, 'weather?'),
                new ChatMessage(
                    ChatRole::Assistant,
                    'Checking.',
                    toolCalls: [
                        new ToolCall('toolu_01A', new ToolCallFunction('get_weather', '{"city":"Paris"}')),
                        new ToolCall('toolu_01B', new ToolCallFunction('ping', '{}')),
                    ],
                    providerMetadata: ['anthropic' => ['content' => $blocks], 'gemini' => ['foreign' => true]],
                ),
                new ChatMessage(ChatRole::Tool, '18C', toolCallId: 'toolu_01A'),
                new ChatMessage(ChatRole::Tool, 'pong', toolCallId: 'toolu_01B'),
            ],
        ));

        $raw = (string) $this->mock->getRequests()[0]->getBody();
        /** @var array<string,mixed> $sent */
        $sent = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $assistant = $sent['messages'][1];
        self::assertSame('assistant', $assistant['role']);
        self::assertSame('thinking', $assistant['content'][0]['type']);
        self::assertSame('EqQBCgIYAhIM1gbcDa9GJwZA2b3hGgxB', $assistant['content'][0]['signature']);
        self::assertSame(['type' => 'redacted_thinking', 'data' => 'EmwKAhgBEgy3va3pzix'], $assistant['content'][1]);
        self::assertCount(5, $assistant['content']);
        // An argument-less call goes back as an object, never `[]`.
        self::assertStringContainsString('"name":"ping","input":{}', $raw);
        self::assertStringNotContainsString('foreign', $raw);
    }

    public function testRebuildsTheTurnWhenMetadataNoLongerMatches(): void
    {
        $cases = [
            'text edited' => ['Changed.', 'toolu_01A'],
            'tool id differs' => ['Checking.', 'toolu_OTHER'],
        ];
        foreach ($cases as $label => [$text, $toolId]) {
            $this->mock->addResponse($this->json(200, ['content' => [['type' => 'text', 'text' => 'ok']], 'stop_reason' => 'end_turn', 'usage' => []]));
            $this->connector()->complete(new ChatInput(
                model: 'claude-opus-5-5',
                messages: [
                    new ChatMessage(ChatRole::User, 'weather?'),
                    new ChatMessage(
                        ChatRole::Assistant,
                        $text,
                        toolCalls: [new ToolCall($toolId, new ToolCallFunction('get_weather', '{"city":"Paris"}'))],
                        providerMetadata: ['anthropic' => ['content' => self::thinkingToolContent()]],
                    ),
                    new ChatMessage(ChatRole::Tool, '18C', toolCallId: $toolId),
                ],
            ));

            $requests = $this->mock->getRequests();
            /** @var array<string,mixed> $sent */
            $sent = json_decode((string) $requests[count($requests) - 1]->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame([
                ['type' => 'text', 'text' => $text],
                ['type' => 'tool_use', 'id' => $toolId, 'name' => 'get_weather', 'input' => ['city' => 'Paris']],
            ], $sent['messages'][1]['content'], $label);
        }
    }

    public function testStreamRebuildsTheSameContentArrayOnTheTerminalDelta(): void
    {
        $events = [
            ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 10, 'cache_read_input_tokens' => 100, 'cache_creation_input_tokens' => 5, 'output_tokens' => 1]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'Need the ']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'weather tool.']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'EqQBCgIYAhIM1gbcDa9GJwZA2b3hGgxB']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'redacted_thinking', 'data' => 'EmwKAhgBEgy3va3pzix']],
            ['type' => 'content_block_stop', 'index' => 1],
            ['type' => 'content_block_start', 'index' => 2, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 2, 'delta' => ['type' => 'text_delta', 'text' => 'Checking.']],
            ['type' => 'content_block_stop', 'index' => 2],
            ['type' => 'content_block_start', 'index' => 3, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_01A', 'name' => 'get_weather', 'input' => []]],
            ['type' => 'content_block_delta', 'index' => 3, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"city": ']],
            ['type' => 'content_block_delta', 'index' => 3, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '"Paris"}']],
            ['type' => 'content_block_stop', 'index' => 3],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 20]],
            ['type' => 'message_stop'],
        ];
        $sse = '';
        foreach ($events as $e) {
            $sse .= 'event: ' . $e['type'] . "\n" . 'data: ' . json_encode($e, JSON_THROW_ON_ERROR) . "\n\n";
        }
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'text/event-stream'], $sse));

        $toolDeltas = [];
        $terminal = null;
        foreach ($this->connector()->stream(new ChatInput(model: 'claude-opus-5-5', messages: [new ChatMessage(ChatRole::User, 'weather?')])) as $d) {
            if ($d->toolCallDelta !== null) {
                $toolDeltas[] = $d->toolCallDelta;
            }
            if ($d->finishReason !== null) {
                $terminal = $d;
            }
        }

        self::assertNotNull($terminal);
        self::assertSame(FinishReason::ToolCalls, $terminal->finishReason);
        self::assertSame(['anthropic' => ['content' => self::thinkingToolContent()]], $terminal->providerMetadata);
        // The tool call is the FIRST tool call (ordinal 0), though it is content block 3.
        self::assertSame([0, 0, 0], array_map(static fn($t) => $t->index, $toolDeltas));
        self::assertNotNull($terminal->usage);
        self::assertSame(115, $terminal->usage->inputTokens);
        self::assertSame(20, $terminal->usage->outputTokens);
        self::assertSame(135, $terminal->usage->totalTokens);
    }

    public function testStreamToolIndexCountsToolCallsOnly(): void
    {
        $events = [
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 'a', 'name' => 'x', 'input' => []]],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{}']],
            ['type' => 'content_block_start', 'index' => 2, 'content_block' => ['type' => 'tool_use', 'id' => 'b', 'name' => 'y', 'input' => []]],
            ['type' => 'content_block_delta', 'index' => 2, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{}']],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 2]],
        ];
        $sse = '';
        foreach ($events as $e) {
            $sse .= 'data: ' . json_encode($e, JSON_THROW_ON_ERROR) . "\n\n";
        }
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'text/event-stream'], $sse));

        $indexes = [];
        foreach ($this->connector()->stream(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')])) as $d) {
            if ($d->toolCallDelta !== null) {
                $indexes[] = [$d->toolCallDelta->index, $d->toolCallDelta->id];
            }
        }
        self::assertSame([[0, 'a'], [0, null], [1, 'b'], [1, null]], $indexes);
    }

    public function testUsageCountsCachedInputTokens(): void
    {
        $this->mock->addResponse($this->json(200, [
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 3, 'cache_read_input_tokens' => 1000, 'cache_creation_input_tokens' => 200, 'output_tokens' => 7],
        ]));

        $res = $this->connector()->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')]));
        self::assertSame(1203, $res->usage->inputTokens);
        self::assertSame(7, $res->usage->outputTokens);
        self::assertSame(1210, $res->usage->totalTokens);
    }

    public function testCurrentStopReasonsMapToTheClosestFinishReason(): void
    {
        $cases = [
            'model_context_window_exceeded' => FinishReason::Length,
            'refusal' => FinishReason::ContentFilter,
            'pause_turn' => FinishReason::Unknown,
        ];
        foreach ($cases as $stop => $expected) {
            $this->mock->addResponse($this->json(200, ['content' => [['type' => 'text', 'text' => 'x']], 'stop_reason' => $stop, 'usage' => []]));
            $res = $this->connector()->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')]));
            self::assertSame($expected, $res->finishReason, $stop);
        }
    }

    public function testOnlyDocumentedOverflowMessagesAreContextLength(): void
    {
        $cases = [
            [400, 'prompt is too long: 1048577 tokens > 1048576 maximum', ProviderCode::ContextLengthExceeded],
            [400, 'input length and `max_tokens` exceed context limit: 188240 + 21333 > 200000, decrease input length or `max_tokens` and try again', ProviderCode::ContextLengthExceeded],
            [400, 'max_tokens: Field required', ProviderCode::InvalidRequest],
            [400, 'messages.0.content: context management is not enabled', ProviderCode::InvalidRequest],
            [413, 'Request exceeds the maximum allowed number of bytes.', ProviderCode::InvalidRequest],
            [402, 'Your credit balance is too low.', ProviderCode::RateLimited],
            [409, 'conflict', ProviderCode::InvalidRequest],
        ];
        foreach ($cases as [$status, $message, $expected]) {
            $this->mock->addResponse($this->json($status, ['type' => 'error', 'error' => ['type' => 'x', 'message' => $message]]));
            try {
                $this->connector()->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')]));
                self::fail('expected ConnectorError');
            } catch (ConnectorError $e) {
                self::assertSame($expected, $e->providerCode, $message);
                self::assertSame($status, $e->statusCode);
            }
        }
    }

    public function testMidStreamErrorTypesMapToTheirHttpStatus(): void
    {
        $cases = [
            'billing_error' => [402, ProviderCode::RateLimited],
            'request_too_large' => [413, ProviderCode::InvalidRequest],
            'rate_limit_error' => [429, ProviderCode::RateLimited],
            'timeout_error' => [504, ProviderCode::ProviderUnavailable],
            'api_error' => [500, ProviderCode::ProviderUnavailable],
            'some_future_error' => [0, ProviderCode::Unknown],
        ];
        foreach ($cases as $type => [$status, $code]) {
            $frame = json_encode(['type' => 'error', 'error' => ['type' => $type, 'message' => 'boom']], JSON_THROW_ON_ERROR);
            $this->mock->addResponse(new Response(200, ['Content-Type' => 'text/event-stream'], "event: error\ndata: {$frame}\n\n"));
            try {
                foreach ($this->connector()->stream(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')])) as $_) {
                }
                self::fail('expected ConnectorError');
            } catch (ConnectorError $e) {
                self::assertSame(200, $e->statusCode, $type);
                self::assertSame($code, $e->providerCode, $type);
            }
        }
    }

    public function testPassthroughQueryIsAppendedToTheUrl(): void
    {
        $this->mock->addResponse($this->json(200, ['content' => [['type' => 'text', 'text' => 'ok']], 'stop_reason' => 'end_turn', 'usage' => []]));

        $this->connector()->complete(new ChatInput(
            model: 'm',
            messages: [new ChatMessage(ChatRole::User, 'hi')],
            _passthrough: ['query' => ['beta' => 'true']],
        ));

        self::assertSame('https://api.anthropic.com/v1/messages?beta=true', (string) $this->mock->getRequests()[0]->getUri());
    }

    public function testInvalidHeaderValueNeverLeaksTheKey(): void
    {
        $connector = new AnthropicConnector(new AnthropicConfig(apiKey: "sk-ant-SECRET\n"), $this->mock, new \GuzzleHttp\Psr7\HttpFactory(), new \GuzzleHttp\Psr7\HttpFactory());
        try {
            $connector->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')]));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::InvalidRequest, $e->providerCode);
            self::assertStringNotContainsString('SECRET', $e->getMessage());
            self::assertStringNotContainsString('SECRET', var_export($e->cause, true));
            self::assertNull($e->getPrevious());
            self::assertStringContainsString('x-api-key', $e->getMessage());
        }
        self::assertCount(0, $this->mock->getRequests());
    }

    public function testEmptyTextPartsAreNotSentAsEmptyBlocks(): void
    {
        $this->mock->addResponse($this->json(200, ['content' => [['type' => 'text', 'text' => 'ok']], 'stop_reason' => 'end_turn', 'usage' => []]));

        $this->connector()->complete(new ChatInput(
            model: 'm',
            messages: [new ChatMessage(ChatRole::User, [new \Thinwrap\Llm\DTO\Chat\TextPart(''), new \Thinwrap\Llm\DTO\Chat\TextPart('hi')])],
        ));

        /** @var array<string,mixed> $sent */
        $sent = json_decode((string) $this->mock->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([['type' => 'text', 'text' => 'hi']], $sent['messages'][0]['content']);
    }

    private function streamOf(string $sse, string $contentType = 'text/event-stream'): string
    {
        $this->mock->addResponse((new Response(200, ['Content-Type' => $contentType]))->withBody(new ChunkedStream($sse, 5)));
        $text = '';
        foreach ($this->connector()->stream(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')])) as $d) {
            $text .= $d->contentDelta ?? '';
        }

        return $text;
    }

    public function testStreamEndingBeforeTheStopThrows(): void
    {
        $sse = 'event: message_start' . "\n" . 'data: {"type":"message_start","message":{"usage":{"input_tokens":1}}}' . "\n\n"
            . 'event: content_block_delta' . "\n" . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Hel"}}' . "\n\n";
        try {
            $this->streamOf($sse);
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::ProviderUnavailable, $e->providerCode);
        }
    }

    public function testMessageStopAloneCompletesTheStream(): void
    {
        $sse = 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Hi"}}' . "\n\n"
            . 'data: {"type":"message_stop"}' . "\n\n";
        self::assertSame('Hi', $this->streamOf($sse));
    }

    public function testNonSseSuccessBodyThrowsOnStream(): void
    {
        try {
            $this->streamOf('{"type":"message","content":[]}', 'application/json');
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::ProviderUnavailable, $e->providerCode);
            self::assertSame(200, $e->statusCode);
        }
    }

    public function testNestedEmptyObjectsSurviveArgumentsAndReplay(): void
    {
        $body = '{"model":"m","stop_reason":"tool_use","usage":{"input_tokens":1,"output_tokens":1},"content":['
            . '{"type":"thinking","thinking":"t","signature":"sig"},'
            . '{"type":"tool_use","id":"toolu_1","name":"search","input":{"q":"x","opts":{}}},'
            . '{"type":"tool_use","id":"toolu_2","name":"ping","input":{}}]}';
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'application/json'], $body));
        $res = $this->connector()->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'go')]));

        self::assertNotNull($res->message->toolCalls);
        self::assertSame('{"q":"x","opts":{}}', $res->message->toolCalls[0]->function->arguments);
        self::assertSame('{}', $res->message->toolCalls[1]->function->arguments);

        $this->mock->addResponse($this->json(200, ['content' => [['type' => 'text', 'text' => 'ok']], 'stop_reason' => 'end_turn', 'usage' => []]));
        $this->connector()->complete(new ChatInput(model: 'm', messages: [
            new ChatMessage(ChatRole::User, 'go'),
            new ChatMessage(ChatRole::Assistant, $res->message->content, toolCalls: $res->message->toolCalls, providerMetadata: $res->message->providerMetadata),
            new ChatMessage(ChatRole::Tool, 'r1', toolCallId: 'toolu_1'),
            new ChatMessage(ChatRole::Tool, 'r2', toolCallId: 'toolu_2'),
        ]));
        $raw = (string) $this->mock->getRequests()[1]->getBody();
        self::assertStringContainsString('"input":{"q":"x","opts":{}}', $raw);
        self::assertStringContainsString('"name":"ping","input":{}', $raw);
        self::assertStringContainsString('"signature":"sig"', $raw);
    }

    public function testStreamRebuildKeepsNestedEmptyObjectsAndStartInput(): void
    {
        $sse = 'data: {"type":"content_block_start","index":0,"content_block":{"type":"tool_use","id":"a","name":"x","input":{}}}' . "\n\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"input_json_delta","partial_json":"{\"opts\":{}}"}}' . "\n\n"
            . 'data: {"type":"content_block_start","index":1,"content_block":{"type":"server_tool_use","id":"b","name":"web_search","input":{"query":"q","filters":{}}}}' . "\n\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":1}}' . "\n\n";
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'text/event-stream'], $sse));

        $meta = null;
        foreach ($this->connector()->stream(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'go')])) as $d) {
            $meta = $d->providerMetadata ?? $meta;
        }
        self::assertNotNull($meta);
        $json = json_encode($meta, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"input":{"opts":{}}', $json);
        // A start-event input with no deltas after it is kept, not emptied.
        self::assertStringContainsString('"input":{"query":"q","filters":{}}', $json);
    }

    public function testEditedArgumentsRebuildTheTurn(): void
    {
        $this->mock->addResponse($this->json(200, ['content' => [['type' => 'text', 'text' => 'ok']], 'stop_reason' => 'end_turn', 'usage' => []]));
        $this->connector()->complete(new ChatInput(model: 'm', messages: [
            new ChatMessage(ChatRole::User, 'weather?'),
            new ChatMessage(
                ChatRole::Assistant,
                'Checking.',
                toolCalls: [new ToolCall('toolu_01A', new ToolCallFunction('get_weather', '{"city":"Rome"}'))],
                providerMetadata: ['anthropic' => ['content' => self::thinkingToolContent()]],
            ),
            new ChatMessage(ChatRole::Tool, '21C', toolCallId: 'toolu_01A'),
        ]));

        /** @var array<string,mixed> $sent */
        $sent = json_decode((string) $this->mock->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['city' => 'Rome'], $sent['messages'][1]['content'][1]['input']);
        self::assertSame('text', $sent['messages'][1]['content'][0]['type']);
    }

    public function testKeyOrderAndWhitespaceInArgumentsStillReplay(): void
    {
        $this->mock->addResponse($this->json(200, ['content' => [['type' => 'text', 'text' => 'ok']], 'stop_reason' => 'end_turn', 'usage' => []]));
        $content = [['type' => 'thinking', 'thinking' => 't', 'signature' => 's'], ['type' => 'tool_use', 'id' => 'a', 'name' => 'f', 'input' => ['x' => 1, 'y' => [1, 2]]]];
        $this->connector()->complete(new ChatInput(model: 'm', messages: [
            new ChatMessage(ChatRole::User, 'go'),
            new ChatMessage(ChatRole::Assistant, null, toolCalls: [new ToolCall('a', new ToolCallFunction('f', '{ "y": [1, 2], "x": 1.0 }'))], providerMetadata: ['anthropic' => ['content' => $content]]),
            new ChatMessage(ChatRole::Tool, 'r', toolCallId: 'a'),
        ]));

        /** @var array<string,mixed> $sent */
        $sent = json_decode((string) $this->mock->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('thinking', $sent['messages'][1]['content'][0]['type']);
    }

    public function testOnceATurnIsRebuiltEveryLaterTurnIsRebuiltToo(): void
    {
        $this->mock->addResponse($this->json(200, ['content' => [['type' => 'text', 'text' => 'ok']], 'stop_reason' => 'end_turn', 'usage' => []]));
        $turn = static fn(string $id, string $sig): array => [
            ['type' => 'thinking', 'thinking' => 't', 'signature' => $sig],
            ['type' => 'tool_use', 'id' => $id, 'name' => 'f', 'input' => []],
        ];
        $this->connector()->complete(new ChatInput(model: 'm', messages: [
            new ChatMessage(ChatRole::User, 'go'),
            // Turn 1 lost its metadata (hand-built or edited): rebuilt.
            new ChatMessage(ChatRole::Assistant, null, toolCalls: [new ToolCall('t1', new ToolCallFunction('f', '{}'))]),
            new ChatMessage(ChatRole::Tool, 'r1', toolCallId: 't1'),
            // Turn 2 still matches its metadata, but its signature chains to turn 1.
            new ChatMessage(ChatRole::Assistant, null, toolCalls: [new ToolCall('t2', new ToolCallFunction('f', '{}'))], providerMetadata: ['anthropic' => ['content' => $turn('t2', 'sig2')]]),
            new ChatMessage(ChatRole::Tool, 'r2', toolCallId: 't2'),
        ]));

        $raw = (string) $this->mock->getRequests()[0]->getBody();
        self::assertStringNotContainsString('sig2', $raw);
        self::assertStringNotContainsString('"thinking"', $raw);

        // With turn 1 intact, both replay.
        $this->mock->addResponse($this->json(200, ['content' => [['type' => 'text', 'text' => 'ok']], 'stop_reason' => 'end_turn', 'usage' => []]));
        $this->connector()->complete(new ChatInput(model: 'm', messages: [
            new ChatMessage(ChatRole::User, 'go'),
            new ChatMessage(ChatRole::Assistant, null, toolCalls: [new ToolCall('t1', new ToolCallFunction('f', '{}'))], providerMetadata: ['anthropic' => ['content' => $turn('t1', 'sig1')]]),
            new ChatMessage(ChatRole::Tool, 'r1', toolCallId: 't1'),
            new ChatMessage(ChatRole::Assistant, null, toolCalls: [new ToolCall('t2', new ToolCallFunction('f', '{}'))], providerMetadata: ['anthropic' => ['content' => $turn('t2', 'sig2')]]),
            new ChatMessage(ChatRole::Tool, 'r2', toolCallId: 't2'),
        ]));
        $raw = (string) $this->mock->getRequests()[1]->getBody();
        self::assertStringContainsString('sig1', $raw);
        self::assertStringContainsString('sig2', $raw);
    }

    public function testStreamUsageMergesFieldByField(): void
    {
        $sse = 'data: {"type":"message_start","message":{"usage":{"input_tokens":10,"cache_read_input_tokens":100,"output_tokens":1}}}' . "\n\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"input_tokens":10,"cache_read_input_tokens":null,"output_tokens":20}}' . "\n\n";
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'text/event-stream'], $sse));

        $usage = null;
        foreach ($this->connector()->stream(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')])) as $d) {
            $usage = $d->usage ?? $usage;
        }
        self::assertNotNull($usage);
        self::assertSame([110, 20, 130], [$usage->inputTokens, $usage->outputTokens, $usage->totalTokens]);
    }

    public function testProviderMetadataOnlyOnTheTerminalDeltaAndCitationsFolded(): void
    {
        $citation = ['type' => 'char_location', 'cited_text' => 'x', 'document_index' => 0, 'start_char_index' => 0, 'end_char_index' => 1];
        $sse = 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Hi"}}' . "\n\n"
            . 'data: ' . json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'citations_delta', 'citation' => $citation]], JSON_THROW_ON_ERROR) . "\n\n"
            . 'data: {"type":"message_delta","delta":{},"usage":{"output_tokens":1}}' . "\n\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n";
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'text/event-stream'], $sse));

        $withMeta = [];
        foreach ($this->connector()->stream(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')])) as $d) {
            if ($d->providerMetadata !== null) {
                $withMeta[] = $d;
            }
        }
        self::assertCount(1, $withMeta);
        self::assertSame(FinishReason::Stop, $withMeta[0]->finishReason);
        self::assertSame(
            ['anthropic' => ['content' => [['type' => 'text', 'text' => 'Hi', 'citations' => [$citation]]]]],
            $withMeta[0]->providerMetadata,
        );
    }

    public function testRequestTimeoutIsProviderUnavailable(): void
    {
        $this->mock->addResponse($this->json(408, ['type' => 'error', 'error' => ['type' => 'timeout_error', 'message' => 'timeout']]));
        try {
            $this->connector()->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')]));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::ProviderUnavailable, $e->providerCode);
        }
    }

    public function testTrailingNewlineInKeyIsRejectedWithNyholmToo(): void
    {
        $connector = new AnthropicConnector(new AnthropicConfig(apiKey: "sk-ant-SECRET\n"), $this->mock, $this->factory, $this->factory);
        try {
            $connector->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')]));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::InvalidRequest, $e->providerCode);
            self::assertStringNotContainsString('SECRET', $e->getMessage());
            self::assertStringContainsString('x-api-key', $e->getMessage());
        }
        self::assertCount(0, $this->mock->getRequests());
    }

    public function testDebugOutputRedactsTheKey(): void
    {
        $config = new AnthropicConfig(apiKey: 'sk-ant-SECRET', headers: ['x-extra' => 'HDRSECRET']);
        $chat = \Thinwrap\Llm\Chat::fromConnector(new AnthropicConnector($config, $this->mock, $this->factory, $this->factory));
        foreach ([$config, $chat] as $subject) {
            $dump = print_r($subject, true);
            ob_start();
            var_dump($subject);
            $dump .= (string) ob_get_clean();
            self::assertStringNotContainsString('SECRET', $dump);
        }
    }

    private static function assertPlainArrays(mixed $value, string $where): void
    {
        if (is_object($value)) {
            self::fail("{$where} holds an object (" . $value::class . ')');
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                self::assertPlainArrays($item, "{$where}.{$key}");
            }
        }
    }

    public function testRawStaysPlainArraysWhenTheBodyHoldsEmptyObjects(): void
    {
        $body = '{"model":"m","stop_reason":"tool_use","usage":{},"content":[{"type":"tool_use","id":"t","name":"f","input":{"opts":{}}}],"container":{}}';
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'application/json'], $body));
        $res = $this->connector()->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')]));
        self::assertIsArray($res->raw);
        self::assertPlainArrays($res->raw, 'raw');
        self::assertSame([], $res->raw['container']);
        // ...while what goes back on the wire keeps the object.
        self::assertNotNull($res->message->toolCalls);
        self::assertSame('{"opts":{}}', $res->message->toolCalls[0]->function->arguments);

        $sse = 'data: {"type":"content_block_start","index":0,"content_block":{"type":"tool_use","id":"t","name":"f","input":{}}}' . "\n\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":1}}' . "\n\n";
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'text/event-stream'], $sse));
        foreach ($this->connector()->stream(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')])) as $d) {
            self::assertPlainArrays($d->raw, 'delta raw');
        }

        $this->mock->addResponse(new Response(400, ['Content-Type' => 'application/json'], '{"type":"error","error":{"type":"invalid_request_error","message":"bad","details":{}}}'));
        try {
            $this->connector()->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')]));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertPlainArrays($e->cause, 'cause');
        }
    }
}

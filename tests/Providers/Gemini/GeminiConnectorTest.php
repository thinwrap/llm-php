<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Tests\Providers\Gemini;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thinwrap\Llm\DTO\Chat\ChatInput;
use Thinwrap\Llm\DTO\Chat\ChatMessage;
use Thinwrap\Llm\DTO\Chat\ToolCall;
use Thinwrap\Llm\DTO\Chat\ToolCallFunction;
use Thinwrap\Llm\DTO\Chat\ToolDefinition;
use Thinwrap\Llm\DTO\Chat\ToolFunction;
use Thinwrap\Llm\Enum\ChatRole;
use Thinwrap\Llm\Enum\FinishReason;
use Thinwrap\Llm\Enum\ProviderCode;
use Thinwrap\Llm\Exception\ConnectorError;
use Thinwrap\Llm\Providers\Gemini\GeminiConfig;
use Thinwrap\Llm\Providers\Gemini\GeminiConnector;
use Thinwrap\Llm\Tests\Support\ChunkedStream;

final class GeminiConnectorTest extends TestCase
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta';

    private MockClient $mock;
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->mock = new MockClient();
        $this->factory = new Psr17Factory();
    }

    private function connector(): GeminiConnector
    {
        return new GeminiConnector(new GeminiConfig(apiKey: 'gk'), $this->mock, $this->factory, $this->factory);
    }

    /**
     * @param array<string,mixed> $body
     */
    private function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testTranslatesGenerateContentAndHoistsSystemInstruction(): void
    {
        $this->mock->addResponse($this->json(200, [
            'modelVersion' => 'gemini-3.8-flash',
            'candidates' => [['content' => ['parts' => [['text' => 'hello']]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 2, 'totalTokenCount' => 7],
        ]));

        $res = $this->connector()->complete(new ChatInput(
            model: 'gemini-3.8-flash',
            messages: [
                new ChatMessage(ChatRole::System, 'be brief'),
                new ChatMessage(ChatRole::User, 'hi'),
            ],
            maxOutputTokens: 100,
        ));

        self::assertSame('hello', $res->message->content);
        self::assertSame(FinishReason::Stop, $res->finishReason);
        self::assertSame('gemini-3.8-flash', $res->model);

        $req = $this->mock->getRequests()[0];
        self::assertSame(self::BASE . '/models/gemini-3.8-flash:generateContent', (string) $req->getUri());
        self::assertSame('gk', $req->getHeaderLine('x-goog-api-key'));

        /** @var array<string,mixed> $sent */
        $sent = json_decode((string) $req->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['parts' => [['text' => 'be brief']]], $sent['systemInstruction']);
        self::assertSame([['role' => 'user', 'parts' => [['text' => 'hi']]]], $sent['contents']);
        self::assertSame(100, $sent['generationConfig']['maxOutputTokens']);
    }

    public function testUrlEncodesModelIdInPath(): void
    {
        $this->mock->addResponse($this->json(200, ['candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']], 'usageMetadata' => []]));
        $this->connector()->complete(new ChatInput(model: 'a/b:c', messages: [new ChatMessage(ChatRole::User, 'hi')]));

        self::assertSame(self::BASE . '/models/a%2Fb%3Ac:generateContent', (string) $this->mock->getRequests()[0]->getUri());
    }

    public function testFunctionCallBecomesToolCallsAndToolConfig(): void
    {
        $this->mock->addResponse($this->json(200, [
            'candidates' => [['content' => ['parts' => [['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'SF']]]]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 8, 'candidatesTokenCount' => 4, 'totalTokenCount' => 12],
        ]));

        $res = $this->connector()->complete(new ChatInput(
            model: 'gemini-3.8-flash',
            messages: [new ChatMessage(ChatRole::User, 'weather?')],
            tools: [new ToolDefinition(new ToolFunction('get_weather', null, ['type' => 'object']))],
            toolChoice: 'required',
        ));

        self::assertNull($res->message->content);
        self::assertSame(FinishReason::ToolCalls, $res->finishReason);
        self::assertNotNull($res->message->toolCalls);
        self::assertSame('call_0', $res->message->toolCalls[0]->id);
        self::assertSame('{"city":"SF"}', $res->message->toolCalls[0]->function->arguments);

        /** @var array<string,mixed> $sent */
        $sent = json_decode((string) $this->mock->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([['functionDeclarations' => [['name' => 'get_weather', 'parametersJsonSchema' => ['type' => 'object']]]]], $sent['tools']);
        self::assertSame(['functionCallingConfig' => ['mode' => 'ANY']], $sent['toolConfig']);
    }

    public function testToolMessageBecomesFunctionResponseKeyedByName(): void
    {
        $this->mock->addResponse($this->json(200, ['candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']], 'usageMetadata' => []]));

        $this->connector()->complete(new ChatInput(
            model: 'gemini-3.8-flash',
            messages: [
                new ChatMessage(ChatRole::User, 'weather?'),
                new ChatMessage(ChatRole::Assistant, null, toolCalls: [new ToolCall('call-xyz', new ToolCallFunction('get_weather', '{}'))]),
                new ChatMessage(ChatRole::Tool, 'sunny', toolCallId: 'call-xyz'),
            ],
        ));

        /** @var array<string,mixed> $sent */
        $sent = json_decode((string) $this->mock->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $contents = $sent['contents'];
        self::assertSame('model', $contents[1]['role']);
        self::assertSame('get_weather', $contents[1]['parts'][0]['functionCall']['name']);
        self::assertSame(['role' => 'user', 'parts' => [['functionResponse' => ['name' => 'get_weather', 'response' => ['result' => 'sunny'], 'id' => 'call-xyz']]]], $contents[2]);
    }

    public function testMaps429WithRetryInfoToRateLimited(): void
    {
        $this->mock->addResponse($this->json(429, [
            'error' => [
                'code' => 429,
                'message' => 'quota exceeded',
                'status' => 'RESOURCE_EXHAUSTED',
                'details' => [['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '30s']],
            ],
        ]));

        try {
            $this->connector()->complete(new ChatInput(model: 'gemini-3.8-flash', messages: [new ChatMessage(ChatRole::User, 'x')]));
            self::fail('expected');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::RateLimited, $e->providerCode);
            /** @var array<string,mixed> $cause */
            $cause = $e->cause;
            self::assertSame(30, $cause['retryAfterSeconds']);
        }
    }

    public function testPromptLevelSafetyBlockMapsToContentFilter(): void
    {
        // No candidates, only promptFeedback.blockReason — must be content_filter,
        // not a silent empty completion with finishReason unknown.
        $this->mock->addResponse($this->json(200, [
            'promptFeedback' => ['blockReason' => 'SAFETY'],
            'usageMetadata' => ['promptTokenCount' => 9, 'totalTokenCount' => 9],
        ]));

        $res = $this->connector()->complete(new ChatInput(model: 'gemini-3.8-flash', messages: [new ChatMessage(ChatRole::User, 'x')]));
        self::assertNull($res->message->content);
        self::assertSame(FinishReason::ContentFilter, $res->finishReason);
    }

    public function testThoughtPartsAreExcludedFromContent(): void
    {
        $this->mock->addResponse($this->json(200, [
            'candidates' => [[
                'content' => ['parts' => [
                    ['text' => 'secret chain of thought', 'thought' => true],
                    ['text' => 'the answer'],
                ]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => [],
        ]));

        $res = $this->connector()->complete(new ChatInput(model: 'gemini-3-pro', messages: [new ChatMessage(ChatRole::User, 'hi')]));
        self::assertSame('the answer', $res->message->content);
    }

    public function testStreamPromptBlockEmitsContentFilterFinish(): void
    {
        $frame = json_encode(['promptFeedback' => ['blockReason' => 'SAFETY']], JSON_THROW_ON_ERROR);
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'text/event-stream'], "data: {$frame}\n\n"));

        $finish = null;
        foreach ($this->connector()->stream(new ChatInput(model: 'gemini-3.8-flash', messages: [new ChatMessage(ChatRole::User, 'x')])) as $d) {
            if ($d->finishReason !== null) {
                $finish = $d->finishReason;
            }
        }
        self::assertSame(FinishReason::ContentFilter, $finish);
    }

    public function testMidStreamErrorFrameThrowsConnectorError(): void
    {
        // Partial text, then a mid-stream `{"error": …}` frame (Gemini's error.code
        // IS the HTTP status) — must surface as a ConnectorError, not a truncation.
        $frame1 = json_encode(['candidates' => [['content' => ['parts' => [['text' => 'partial']]]]]], JSON_THROW_ON_ERROR);
        $frame2 = json_encode(['error' => ['code' => 429, 'message' => 'quota exceeded', 'status' => 'RESOURCE_EXHAUSTED']], JSON_THROW_ON_ERROR);
        $sse = "data: {$frame1}\n\ndata: {$frame2}\n\n";
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'text/event-stream'], $sse));

        $text = '';
        try {
            foreach ($this->connector()->stream(new ChatInput(model: 'gemini-3.8-flash', messages: [new ChatMessage(ChatRole::User, 'hi')])) as $d) {
                $text .= $d->contentDelta ?? '';
            }
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame('partial', $text);
            self::assertSame(ProviderCode::RateLimited, $e->providerCode);
            self::assertSame('quota exceeded', $e->providerMessage);
            // The stream arrived as a 200; the frame's code only classifies it.
            self::assertSame(200, $e->statusCode);
        }
    }

    public function testStreamsIncrementalSse(): void
    {
        $sse = 'data: {"candidates":[{"content":{"parts":[{"text":"He"}]}}]}' . "\n\n"
            . 'data: {"candidates":[{"content":{"parts":[{"text":"llo"}]}}]}' . "\n\n"
            . 'data: {"candidates":[{"content":{"parts":[]},"finishReason":"STOP"}],"usageMetadata":{"promptTokenCount":2,"candidatesTokenCount":1,"totalTokenCount":3}}' . "\n\n";
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'text/event-stream'], $sse));

        $text = '';
        $usage = null;
        foreach ($this->connector()->stream(new ChatInput(model: 'gemini-3.8-flash', messages: [new ChatMessage(ChatRole::User, 'hi')])) as $d) {
            $text .= $d->contentDelta ?? '';
            if ($d->usage !== null) {
                $usage = $d->usage;
            }
        }
        self::assertSame('Hello', $text);
        self::assertNotNull($usage);
        self::assertSame(3, $usage->totalTokens);
        self::assertStringContainsString(':streamGenerateContent?alt=sse', (string) $this->mock->getRequests()[0]->getUri());
    }

    public function testHttp200WithoutPayloadThrows(): void
    {
        $this->mock->addResponse($this->json(200, []));

        try {
            $this->connector()->complete(new ChatInput(model: 'gemini-3.8-flash', messages: [new ChatMessage(ChatRole::User, 'hi')]));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::ProviderUnavailable, $e->providerCode);
            self::assertSame(200, $e->statusCode);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function sentBody(int $request = 0): array
    {
        /** @var array<string,mixed> $sent */
        $sent = json_decode((string) $this->mock->getRequests()[$request]->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $sent;
    }

    private function okText(): Response
    {
        return $this->json(200, ['candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']], 'usageMetadata' => []]);
    }

    public function testThoughtSignatureAndGeminiIdAreCapturedOnTheToolCall(): void
    {
        // Gemini 3 parallel calls: only the first functionCall part is signed.
        $this->mock->addResponse($this->json(200, [
            'candidates' => [['content' => ['role' => 'model', 'parts' => [
                ['functionCall' => ['id' => 'fc-1', 'name' => 'get_weather', 'args' => ['city' => 'Paris']], 'thoughtSignature' => 'CiQBjz1rX0sig'],
                ['functionCall' => ['id' => 'fc-2', 'name' => 'get_weather', 'args' => ['city' => 'Rome']]],
            ]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 10, 'totalTokenCount' => 15],
        ]));

        $res = $this->connector()->complete(new ChatInput(model: 'gemini-3.8-flash', messages: [new ChatMessage(ChatRole::User, 'weather?')]));

        $calls = $res->message->toolCalls;
        self::assertNotNull($calls);
        self::assertSame('fc-1', $calls[0]->id);
        self::assertSame(['gemini' => ['thoughtSignature' => 'CiQBjz1rX0sig']], $calls[0]->providerMetadata);
        self::assertSame('fc-2', $calls[1]->id);
        self::assertNull($calls[1]->providerMetadata);
    }

    public function testSignatureIsReplayedAndIdsAreEchoed(): void
    {
        $this->mock->addResponse($this->okText());

        $this->connector()->complete(new ChatInput(
            model: 'gemini-3.8-flash',
            messages: [
                new ChatMessage(ChatRole::User, 'weather?'),
                new ChatMessage(ChatRole::Assistant, null, toolCalls: [
                    new ToolCall('fc-1', new ToolCallFunction('get_weather', '{"city":"Paris"}'), ['gemini' => ['thoughtSignature' => 'CiQBjz1rX0sig']]),
                    new ToolCall('fc-2', new ToolCallFunction('get_weather', '{"city":"Rome"}')),
                ]),
                new ChatMessage(ChatRole::Tool, '18C', toolCallId: 'fc-1'),
                new ChatMessage(ChatRole::Tool, '21C', toolCallId: 'fc-2'),
            ],
        ));

        $contents = $this->sentBody()['contents'];
        self::assertSame([
            ['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Paris'], 'id' => 'fc-1'], 'thoughtSignature' => 'CiQBjz1rX0sig'],
            ['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Rome'], 'id' => 'fc-2']],
        ], $contents[1]['parts']);
        self::assertSame('fc-1', $contents[2]['parts'][0]['functionResponse']['id']);
        self::assertSame('fc-2', $contents[2]['parts'][1]['functionResponse']['id']);
    }

    public function testUnsignedTurnGetsTheValidatorBypassOnTheFirstCallOnly(): void
    {
        $this->mock->addResponse($this->okText());

        // A history from another provider: foreign metadata, foreign ids.
        $this->connector()->complete(new ChatInput(
            model: 'gemini-3.8-flash',
            messages: [
                new ChatMessage(ChatRole::User, 'weather?'),
                new ChatMessage(
                    ChatRole::Assistant,
                    'Checking both.',
                    toolCalls: [
                        new ToolCall('toolu_01A', new ToolCallFunction('get_weather', '{"city":"Paris"}'), ['anthropic' => ['x' => 1]]),
                        new ToolCall('toolu_01B', new ToolCallFunction('get_weather', '{"city":"Rome"}')),
                    ],
                    providerMetadata: ['anthropic' => ['content' => [['type' => 'thinking', 'thinking' => 't', 'signature' => 's']]]],
                ),
                new ChatMessage(ChatRole::Tool, '18C', toolCallId: 'toolu_01A'),
                new ChatMessage(ChatRole::Tool, '21C', toolCallId: 'toolu_01B'),
            ],
        ));

        $raw = (string) $this->mock->getRequests()[0]->getBody();
        $parts = $this->sentBody()['contents'][1]['parts'];
        self::assertSame(['text' => 'Checking both.'], $parts[0]);
        self::assertSame('skip_thought_signature_validator', $parts[1]['thoughtSignature']);
        self::assertArrayNotHasKey('thoughtSignature', $parts[2]);
        self::assertStringNotContainsString('anthropic', $raw);
        self::assertStringNotContainsString('"thinking"', $raw);
    }

    public function testSyntheticIdsAreUniqueAndNeverEchoed(): void
    {
        $this->mock->addResponse($this->json(200, [
            'candidates' => [['content' => ['parts' => [
                ['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Paris']]],
                ['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Rome']]],
            ]], 'finishReason' => 'STOP']],
            'usageMetadata' => [],
        ]));
        $res = $this->connector()->complete(new ChatInput(model: 'gemini-3.8-flash', messages: [new ChatMessage(ChatRole::User, 'weather?')]));
        self::assertNotNull($res->message->toolCalls);
        self::assertSame(['call_0', 'call_1'], array_map(static fn(ToolCall $t): string => $t->id, $res->message->toolCalls));

        $this->mock->addResponse($this->okText());
        $this->connector()->complete(new ChatInput(
            model: 'gemini-3.8-flash',
            messages: [
                new ChatMessage(ChatRole::User, 'weather?'),
                new ChatMessage(ChatRole::Assistant, null, toolCalls: $res->message->toolCalls),
                new ChatMessage(ChatRole::Tool, '18C', toolCallId: 'call_0'),
                new ChatMessage(ChatRole::Tool, '21C', toolCallId: 'call_1'),
            ],
        ));
        $contents = $this->sentBody(1)['contents'];
        self::assertArrayNotHasKey('id', $contents[1]['parts'][0]['functionCall']);
        self::assertArrayNotHasKey('id', $contents[2]['parts'][0]['functionResponse']);
    }

    public function testToolResultResolvesTheNearestCallWhenSyntheticIdsRepeat(): void
    {
        $this->mock->addResponse($this->okText());

        $this->connector()->complete(new ChatInput(
            model: 'gemini-3.8-flash',
            messages: [
                new ChatMessage(ChatRole::User, 'q'),
                new ChatMessage(ChatRole::Assistant, null, toolCalls: [new ToolCall('call_0', new ToolCallFunction('first_tool', '{}'))]),
                new ChatMessage(ChatRole::Tool, 'a', toolCallId: 'call_0'),
                new ChatMessage(ChatRole::Assistant, null, toolCalls: [new ToolCall('call_0', new ToolCallFunction('second_tool', '{}'))]),
                new ChatMessage(ChatRole::Tool, 'b', toolCallId: 'call_0'),
            ],
        ));

        $contents = $this->sentBody()['contents'];
        self::assertSame('first_tool', $contents[2]['parts'][0]['functionResponse']['name']);
        self::assertSame('second_tool', $contents[4]['parts'][0]['functionResponse']['name']);
    }

    public function testStreamCapturesSignatureAndKeepsParallelIdsDistinct(): void
    {
        $chunk = json_encode(['candidates' => [['content' => ['role' => 'model', 'parts' => [
            ['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Paris']], 'thoughtSignature' => 'CiQBsig'],
            ['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Rome']]],
        ]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 4, 'candidatesTokenCount' => 6, 'thoughtsTokenCount' => 30, 'totalTokenCount' => 40]], JSON_THROW_ON_ERROR);
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'text/event-stream'], "data: {$chunk}\n\n"));

        $deltas = [];
        $usage = null;
        foreach ($this->connector()->stream(new ChatInput(model: 'gemini-3.8-flash', messages: [new ChatMessage(ChatRole::User, 'weather?')])) as $d) {
            if ($d->toolCallDelta !== null) {
                $deltas[] = $d->toolCallDelta;
            }
            $usage = $d->usage ?? $usage;
        }

        self::assertCount(2, $deltas);
        self::assertSame(['call_0', 'call_1'], [$deltas[0]->id, $deltas[1]->id]);
        self::assertSame(['gemini' => ['thoughtSignature' => 'CiQBsig']], $deltas[0]->providerMetadata);
        self::assertNull($deltas[1]->providerMetadata);
        self::assertNotNull($usage);
        self::assertSame(36, $usage->outputTokens);
        self::assertSame(40, $usage->totalTokens);
    }

    public function testStreamUsageIsCumulativeNotSummed(): void
    {
        $sse = 'data: {"candidates":[{"content":{"parts":[{"text":"A"}]}}],"usageMetadata":{"promptTokenCount":3,"candidatesTokenCount":1,"thoughtsTokenCount":10,"totalTokenCount":14}}' . "\n\n"
            . 'data: {"candidates":[{"content":{"parts":[{"text":"B"}]},"finishReason":"STOP"}],"usageMetadata":{"promptTokenCount":3,"candidatesTokenCount":2,"thoughtsTokenCount":10,"totalTokenCount":15}}' . "\n\n";
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'text/event-stream'], $sse));

        $usages = [];
        foreach ($this->connector()->stream(new ChatInput(model: 'gemini-3.8-flash', messages: [new ChatMessage(ChatRole::User, 'hi')])) as $d) {
            if ($d->usage !== null) {
                $usages[] = $d->usage;
            }
        }
        self::assertCount(1, $usages);
        self::assertSame(3, $usages[0]->inputTokens);
        self::assertSame(12, $usages[0]->outputTokens);
        self::assertSame(15, $usages[0]->totalTokens);
    }

    public function testOutputTokensIncludeThoughts(): void
    {
        $this->mock->addResponse($this->json(200, [
            'candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'thoughtsTokenCount' => 50, 'totalTokenCount' => 65],
        ]));

        $res = $this->connector()->complete(new ChatInput(model: 'gemini-3.8-flash', messages: [new ChatMessage(ChatRole::User, 'hi')]));
        self::assertSame(10, $res->usage->inputTokens);
        self::assertSame(55, $res->usage->outputTokens);
        self::assertSame(65, $res->usage->totalTokens);
    }

    public function testJsonSchemaGoesToResponseJsonSchema(): void
    {
        $this->mock->addResponse($this->okText());
        $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'additionalProperties' => false];

        $this->connector()->complete(new ChatInput(
            model: 'gemini-3.8-flash',
            messages: [new ChatMessage(ChatRole::User, 'hi')],
            responseFormat: \Thinwrap\Llm\DTO\Chat\ResponseFormat::jsonSchema('out', $schema),
        ));

        $gen = $this->sentBody()['generationConfig'];
        self::assertSame('application/json', $gen['responseMimeType']);
        self::assertSame($schema, $gen['responseJsonSchema']);
        self::assertArrayNotHasKey('responseSchema', $gen);
    }

    public function testFinishReasonsMapToTheClosestValue(): void
    {
        $cases = [
            'MALFORMED_FUNCTION_CALL' => FinishReason::Unknown,
            'MISSING_THOUGHT_SIGNATURE' => FinishReason::Unknown,
            'CONTINUATION' => FinishReason::Length,
            'IMAGE_SAFETY' => FinishReason::ContentFilter,
            'LANGUAGE' => FinishReason::Unknown,
            'TOO_MANY_TOOL_CALLS' => FinishReason::Unknown,
        ];
        foreach ($cases as $raw => $expected) {
            $this->mock->addResponse($this->json(200, ['candidates' => [['content' => ['parts' => [['text' => 'x']]], 'finishReason' => $raw]], 'usageMetadata' => []]));
            $res = $this->connector()->complete(new ChatInput(model: 'gemini-3.8-flash', messages: [new ChatMessage(ChatRole::User, 'hi')]));
            self::assertSame($expected, $res->finishReason, $raw);
        }
    }

    public function testErrorClassification(): void
    {
        $cases = [
            'bad key' => [400, ['error' => ['code' => 400, 'message' => 'API key not valid. Please pass a valid API key.', 'status' => 'INVALID_ARGUMENT', 'details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'API_KEY_INVALID', 'domain' => 'googleapis.com']]]], ProviderCode::AuthFailed],
            'overflow' => [400, ['error' => ['code' => 400, 'message' => 'The input token count (1200000) exceeds the maximum number of tokens allowed (1048576).', 'status' => 'INVALID_ARGUMENT']], ProviderCode::ContextLengthExceeded],
            'cache 400' => [400, ['error' => ['code' => 400, 'message' => 'cachedContent context not found', 'status' => 'INVALID_ARGUMENT']], ProviderCode::InvalidRequest],
            'payload 413' => [413, ['error' => ['code' => 413, 'message' => 'Request payload size exceeds the limit']], ProviderCode::InvalidRequest],
        ];
        foreach ($cases as $label => [$status, $body, $expected]) {
            $this->mock->addResponse($this->json($status, $body));
            try {
                $this->connector()->complete(new ChatInput(model: 'gemini-3.8-flash', messages: [new ChatMessage(ChatRole::User, 'hi')]));
                self::fail('expected ConnectorError');
            } catch (ConnectorError $e) {
                self::assertSame($expected, $e->providerCode, $label);
            }
        }
    }

    public function testPassthroughQueryIsAppendedOnBothEndpoints(): void
    {
        $this->mock->addResponse($this->okText());
        $this->connector()->complete(new ChatInput(model: 'gemini-3.8-flash', messages: [new ChatMessage(ChatRole::User, 'hi')], _passthrough: ['query' => ['trace' => 'a b']]));
        self::assertSame(self::BASE . '/models/gemini-3.8-flash:generateContent?trace=a%20b', (string) $this->mock->getRequests()[0]->getUri());

        $done = json_encode(['candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']]], JSON_THROW_ON_ERROR);
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'text/event-stream'], "data: {$done}\n\n"));
        foreach ($this->connector()->stream(new ChatInput(model: 'gemini-3.8-flash', messages: [new ChatMessage(ChatRole::User, 'hi')], _passthrough: ['query' => ['trace' => 'x']])) as $_) {
        }
        self::assertSame(self::BASE . '/models/gemini-3.8-flash:streamGenerateContent?alt=sse&trace=x', (string) $this->mock->getRequests()[1]->getUri());
    }

    private function streamText(string $sse, string $contentType = 'text/event-stream'): string
    {
        $this->mock->addResponse((new Response(200, ['Content-Type' => $contentType]))->withBody(new ChunkedStream($sse, 6)));
        $text = '';
        foreach ($this->connector()->stream(new ChatInput(model: 'gemini-3.8-flash', messages: [new ChatMessage(ChatRole::User, 'hi')])) as $d) {
            $text .= $d->contentDelta ?? '';
        }

        return $text;
    }

    public function testStreamEndingBeforeAFinishReasonThrows(): void
    {
        // Usage alone is not a terminal: Gemini repeats it on every chunk.
        $sse = 'data: {"candidates":[{"content":{"parts":[{"text":"Hel"}]}}],"usageMetadata":{"promptTokenCount":1,"totalTokenCount":2}}' . "\n\n";
        try {
            $this->streamText($sse);
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::ProviderUnavailable, $e->providerCode);
        }
    }

    public function testChunkedStreamWithFinishCompletes(): void
    {
        $sse = 'data: {"candidates":[{"content":{"parts":[{"text":"He"}]}}]}' . "\r\n\r\n"
            . 'data: {"candidates":[{"content":{"parts":[{"text":"llo"}]},"finishReason":"STOP"}]}' . "\r\n\r\n";
        self::assertSame('Hello', $this->streamText($sse));
    }

    public function testNonSseSuccessBodyThrowsOnStream(): void
    {
        try {
            $this->streamText('<html>gateway</html>', 'text/html');
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::ProviderUnavailable, $e->providerCode);
            self::assertStringContainsString('text/html', (string) $e->providerMessage);
        }
    }

    public function testPaymentRequiredIsRateLimited(): void
    {
        $this->mock->addResponse($this->json(402, ['error' => ['code' => 402, 'message' => 'Prepayment credits are depleted', 'status' => 'FAILED_PRECONDITION']]));
        try {
            $this->connector()->complete(new ChatInput(model: 'gemini-3.8-flash', messages: [new ChatMessage(ChatRole::User, 'hi')]));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::RateLimited, $e->providerCode);
        }
    }

    public function testNestedEmptyObjectsInArgsSurvive(): void
    {
        $body = '{"candidates":[{"content":{"parts":[{"functionCall":{"name":"f","args":{"o":{}}},"thoughtSignature":"s"}]},"finishReason":"STOP"}]}';
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'application/json'], $body));
        $res = $this->connector()->complete(new ChatInput(model: 'gemini-3.8-flash', messages: [new ChatMessage(ChatRole::User, 'hi')]));
        self::assertNotNull($res->message->toolCalls);
        self::assertSame('{"o":{}}', $res->message->toolCalls[0]->function->arguments);

        $this->mock->addResponse($this->okText());
        $this->connector()->complete(new ChatInput(model: 'gemini-3.8-flash', messages: [
            new ChatMessage(ChatRole::User, 'hi'),
            new ChatMessage(ChatRole::Assistant, null, toolCalls: $res->message->toolCalls),
            new ChatMessage(ChatRole::Tool, 'r', toolCallId: 'call_0'),
        ]));
        self::assertStringContainsString('"args":{"o":{}}', (string) $this->mock->getRequests()[1]->getBody());
    }

    public function testUsageAddsToolUsePromptTokensToInput(): void
    {
        $this->mock->addResponse($this->json(200, [
            'candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 10, 'toolUsePromptTokenCount' => 7, 'candidatesTokenCount' => 5, 'thoughtsTokenCount' => 3, 'totalTokenCount' => 25],
        ]));
        $res = $this->connector()->complete(new ChatInput(model: 'gemini-3.8-flash', messages: [new ChatMessage(ChatRole::User, 'hi')]));
        self::assertSame([17, 8, 25], [$res->usage->inputTokens, $res->usage->outputTokens, $res->usage->totalTokens]);
    }

    public function testStatusMapping408AndOverflowPhrase(): void
    {
        $cases = [
            [408, 'Request timed out', ProviderCode::ProviderUnavailable],
            [400, 'Request exceeds the maximum number of tokens allowed (1048576).', ProviderCode::ContextLengthExceeded],
            [400, 'The input token count is invalid for this field', ProviderCode::InvalidRequest],
        ];
        foreach ($cases as [$status, $message, $expected]) {
            $this->mock->addResponse($this->json($status, ['error' => ['code' => $status, 'message' => $message]]));
            try {
                $this->connector()->complete(new ChatInput(model: 'gemini-3.8-flash', messages: [new ChatMessage(ChatRole::User, 'hi')]));
                self::fail('expected ConnectorError');
            } catch (ConnectorError $e) {
                self::assertSame($expected, $e->providerCode, $message);
            }
        }
    }

    public function testStreamErrorFrameClassifiedByStatusNameOrUnknown(): void
    {
        $cases = [
            [['error' => ['message' => 'busy', 'status' => 'UNAVAILABLE']], ProviderCode::ProviderUnavailable],
            [['error' => ['message' => '???']], ProviderCode::Unknown],
        ];
        foreach ($cases as [$frame, $expected]) {
            $this->mock->addResponse(new Response(200, ['Content-Type' => 'text/event-stream'], 'data: ' . json_encode($frame, JSON_THROW_ON_ERROR) . "\n\n"));
            try {
                foreach ($this->connector()->stream(new ChatInput(model: 'gemini-3.8-flash', messages: [new ChatMessage(ChatRole::User, 'hi')])) as $_) {
                }
                self::fail('expected ConnectorError');
            } catch (ConnectorError $e) {
                self::assertSame($expected, $e->providerCode);
                self::assertSame(200, $e->statusCode);
            }
        }
    }

    public function testRawStaysPlainArraysWhileArgumentsKeepObjects(): void
    {
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'application/json'], '{"candidates":[{"content":{"parts":[{"functionCall":{"name":"f","args":{"o":{}}}}]},"finishReason":"STOP","safetyRatings":{}}]}'));
        $res = $this->connector()->complete(new ChatInput(model: 'gemini-3.8-flash', messages: [new ChatMessage(ChatRole::User, 'hi')]));
        self::assertIsArray($res->raw);
        $raw = $res->raw;
        array_walk_recursive($raw, static function (mixed $v): void {
            self::assertIsNotObject($v);
        });
        self::assertStringNotContainsString('{}', json_encode($res->raw, JSON_THROW_ON_ERROR));
        self::assertNotNull($res->message->toolCalls);
        self::assertSame('{"o":{}}', $res->message->toolCalls[0]->function->arguments);
    }
}

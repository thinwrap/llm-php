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
            'modelVersion' => 'gemini-2.5-flash',
            'candidates' => [['content' => ['parts' => [['text' => 'hello']]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 2, 'totalTokenCount' => 7],
        ]));

        $res = $this->connector()->complete(new ChatInput(
            model: 'gemini-2.5-flash',
            messages: [
                new ChatMessage(ChatRole::System, 'be brief'),
                new ChatMessage(ChatRole::User, 'hi'),
            ],
            maxOutputTokens: 100,
        ));

        self::assertSame('hello', $res->message->content);
        self::assertSame(FinishReason::Stop, $res->finishReason);
        self::assertSame('gemini-2.5-flash', $res->model);

        $req = $this->mock->getRequests()[0];
        self::assertSame(self::BASE . '/models/gemini-2.5-flash:generateContent', (string) $req->getUri());
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
            model: 'gemini-2.5-pro',
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
        self::assertSame([['functionDeclarations' => [['name' => 'get_weather', 'parameters' => ['type' => 'object']]]]], $sent['tools']);
        self::assertSame(['functionCallingConfig' => ['mode' => 'ANY']], $sent['toolConfig']);
    }

    public function testToolMessageBecomesFunctionResponseKeyedByName(): void
    {
        $this->mock->addResponse($this->json(200, ['candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']], 'usageMetadata' => []]));

        $this->connector()->complete(new ChatInput(
            model: 'gemini-2.5-flash',
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
        self::assertSame(['role' => 'user', 'parts' => [['functionResponse' => ['name' => 'get_weather', 'response' => ['result' => 'sunny']]]]], $contents[2]);
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
            $this->connector()->complete(new ChatInput(model: 'gemini-2.5-flash', messages: [new ChatMessage(ChatRole::User, 'x')]));
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

        $res = $this->connector()->complete(new ChatInput(model: 'gemini-2.5-flash', messages: [new ChatMessage(ChatRole::User, 'x')]));
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
        foreach ($this->connector()->stream(new ChatInput(model: 'gemini-2.5-flash', messages: [new ChatMessage(ChatRole::User, 'x')])) as $d) {
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
            foreach ($this->connector()->stream(new ChatInput(model: 'gemini-2.5-flash', messages: [new ChatMessage(ChatRole::User, 'hi')])) as $d) {
                $text .= $d->contentDelta ?? '';
            }
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame('partial', $text);
            self::assertSame(ProviderCode::RateLimited, $e->providerCode);
            self::assertSame('quota exceeded', $e->providerMessage);
            self::assertSame(429, $e->statusCode);
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
        foreach ($this->connector()->stream(new ChatInput(model: 'gemini-2.5-flash', messages: [new ChatMessage(ChatRole::User, 'hi')])) as $d) {
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
}

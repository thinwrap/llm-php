<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Tests\Facade;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thinwrap\Llm\Chat;
use Thinwrap\Llm\Contract\ChatConnectorInterface;
use Thinwrap\Llm\DTO\Chat\AssistantMessage;
use Thinwrap\Llm\DTO\Chat\ChatInput;
use Thinwrap\Llm\DTO\Chat\ChatMessage;
use Thinwrap\Llm\DTO\Chat\ChatResult;
use Thinwrap\Llm\DTO\Chat\ChatStreamDelta;
use Thinwrap\Llm\DTO\Chat\Usage;
use Thinwrap\Llm\Enum\ChatRole;
use Thinwrap\Llm\Enum\FinishReason;
use Thinwrap\Llm\Exception\ConnectorError;
use Thinwrap\Llm\Providers\Anthropic\AnthropicConfig;
use Thinwrap\Llm\Providers\Shared\OpenAiCompatConfig;

final class ChatTest extends TestCase
{
    private MockClient $mock;
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->mock = new MockClient();
        $this->factory = new Psr17Factory();
    }

    public function testDispatchesOpenAiCompatById(): void
    {
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ], JSON_THROW_ON_ERROR)));

        $chat = new Chat(\Thinwrap\Llm\Enum\LlmProviderId::OpenAI, new OpenAiCompatConfig(apiKey: 'k'), $this->mock, $this->factory, $this->factory);
        self::assertSame('openai', $chat->getId());

        $res = $chat->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')]));
        self::assertSame('ok', $res->message->content);
        self::assertStringContainsString('api.openai.com', (string) $this->mock->getRequests()[0]->getUri());
    }

    public function testDispatchesNativeAnthropic(): void
    {
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ], JSON_THROW_ON_ERROR)));

        $chat = new Chat(\Thinwrap\Llm\Enum\LlmProviderId::Anthropic, new AnthropicConfig(apiKey: 'k'), $this->mock, $this->factory, $this->factory);
        self::assertSame('anthropic', $chat->getId());
        self::assertSame('ok', $chat->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')]))->message->content);
    }

    public function testFromConnectorUsesInjectedConnector(): void
    {
        $fake = new class implements ChatConnectorInterface {
            public function complete(ChatInput $input): ChatResult
            {
                return new ChatResult(new AssistantMessage('faked'), FinishReason::Stop, new Usage(0, 0, 0), $input->model, null);
            }

            public function stream(ChatInput $input): \Generator
            {
                yield new ChatStreamDelta(contentDelta: 'faked');
            }

            public function getId(): string
            {
                return 'fake';
            }
        };

        $chat = Chat::fromConnector($fake);
        self::assertSame('fake', $chat->getId());
        self::assertSame('faked', $chat->complete(new ChatInput(model: 'm', messages: []))->message->content);
    }

    public function testConfigProviderMismatchThrows(): void
    {
        $this->expectException(ConnectorError::class);
        new Chat(\Thinwrap\Llm\Enum\LlmProviderId::Anthropic, new OpenAiCompatConfig(apiKey: 'k'));
    }

    public function testRequiresProviderAndConfig(): void
    {
        $this->expectException(ConnectorError::class);
        new Chat(null, null);
    }
}

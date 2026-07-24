<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Tests\Facade;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thinwrap\Llm\DTO\Embeddings\EmbeddingsInput;
use Thinwrap\Llm\Embeddings;
use Thinwrap\Llm\Enum\LlmProviderId;
use Thinwrap\Llm\Exception\ConnectorError;
use Thinwrap\Llm\Providers\Shared\OpenAiCompatConfig;

final class EmbeddingsTest extends TestCase
{
    private MockClient $mock;
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->mock = new MockClient();
        $this->factory = new Psr17Factory();
    }

    public function testDispatchesOpenAiById(): void
    {
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'model' => 'text-embedding-3-small',
            'data' => [['index' => 0, 'embedding' => [0.1, 0.2, 0.3]]],
            'usage' => ['prompt_tokens' => 2, 'total_tokens' => 2],
        ], JSON_THROW_ON_ERROR)));

        $emb = new Embeddings(LlmProviderId::OpenAI, new OpenAiCompatConfig(apiKey: 'k'), $this->mock, $this->factory, $this->factory);
        self::assertSame('openai', $emb->getId());

        $res = $emb->create(new EmbeddingsInput(model: 'text-embedding-3-small', input: 'hello'));
        self::assertSame([[0.1, 0.2, 0.3]], $res->embeddings);
    }

    public function testUnsupportedProviderThrows(): void
    {
        $this->expectException(ConnectorError::class);
        new Embeddings(LlmProviderId::Groq, new OpenAiCompatConfig(apiKey: 'k'));
    }
}

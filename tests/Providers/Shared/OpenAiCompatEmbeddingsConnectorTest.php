<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Tests\Providers\Shared;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thinwrap\Llm\DTO\Embeddings\EmbeddingsInput;
use Thinwrap\Llm\Enum\ProviderCode;
use Thinwrap\Llm\Exception\ConnectorError;
use Thinwrap\Llm\Providers\Shared\OpenAiCompatConfig;
use Thinwrap\Llm\Providers\Shared\OpenAiCompatEmbeddingsConnector;
use Thinwrap\Llm\Providers\Shared\Specs;

final class OpenAiCompatEmbeddingsConnectorTest extends TestCase
{
    private MockClient $mock;
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->mock = new MockClient();
        $this->factory = new Psr17Factory();
    }

    private function connector(): OpenAiCompatEmbeddingsConnector
    {
        $spec = Specs::get('openai');
        self::assertNotNull($spec);

        return new OpenAiCompatEmbeddingsConnector($spec, new OpenAiCompatConfig(apiKey: 'k'), $this->mock, $this->factory, $this->factory);
    }

    public function testCreateNormalizesVectorsInInputOrder(): void
    {
        // Deliberately out of order — must be sorted by `index`.
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'model' => 'text-embedding-3-small',
            'data' => [
                ['index' => 1, 'embedding' => [0.1, 0.2]],
                ['index' => 0, 'embedding' => [0.9, 0.8]],
            ],
            'usage' => ['prompt_tokens' => 3, 'total_tokens' => 3],
        ], JSON_THROW_ON_ERROR)));

        $res = $this->connector()->create(new EmbeddingsInput(model: 'text-embedding-3-small', input: ['a', 'b']));

        self::assertSame([[0.9, 0.8], [0.1, 0.2]], $res->embeddings);
        self::assertSame(3, $res->usage->inputTokens);
        self::assertSame(3, $res->usage->totalTokens);

        $req = $this->mock->getRequests()[0];
        self::assertSame('https://api.openai.com/v1/embeddings', (string) $req->getUri());
        /** @var array<string,mixed> $sent */
        $sent = json_decode((string) $req->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('float', $sent['encoding_format']);
        self::assertSame(['a', 'b'], $sent['input']);
    }

    public function testMaps429WithHttpDateRetryAfterToSeconds(): void
    {
        // Retry-After as an HTTP-date rather than a bare number must still resolve to
        // seconds, matching the chat connectors.
        $future = gmdate('D, d M Y H:i:s \G\M\T', time() + 120);
        $this->mock->addResponse(new Response(
            429,
            ['Content-Type' => 'application/json', 'Retry-After' => $future],
            json_encode(['error' => ['message' => 'slow down']], JSON_THROW_ON_ERROR),
        ));

        try {
            $this->connector()->create(new EmbeddingsInput(model: 'm', input: 'x'));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::RateLimited, $e->providerCode);
            /** @var array<string,mixed> $cause */
            $cause = $e->cause;
            self::assertSame($future, $cause['retryAfter']);
            self::assertIsInt($cause['retryAfterSeconds']);
            self::assertGreaterThanOrEqual(110, $cause['retryAfterSeconds']);
            self::assertLessThanOrEqual(120, $cause['retryAfterSeconds']);
        }
    }

    public function testMaps401ToAuthFailed(): void
    {
        $this->mock->addResponse(new Response(401, ['Content-Type' => 'application/json'], json_encode(['error' => ['message' => 'nope']], JSON_THROW_ON_ERROR)));
        $this->expectException(ConnectorError::class);
        try {
            $this->connector()->create(new EmbeddingsInput(model: 'm', input: 'x'));
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::AuthFailed, $e->providerCode);

            throw $e;
        }
    }
}

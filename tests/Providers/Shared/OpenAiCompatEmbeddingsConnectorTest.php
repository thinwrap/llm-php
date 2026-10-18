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

    /**
     * @param array<string,mixed> $body
     */
    private function assertCreateFails(array $body, string|array $input, ProviderCode $code, ?int $status = null): ConnectorError
    {
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)));

        try {
            $this->connector()->create(new EmbeddingsInput(model: 'm', input: $input));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame($code, $e->providerCode);
            if ($status !== null) {
                self::assertSame($status, $e->statusCode);
            }

            return $e;
        }
    }

    public function testHttp200WithErrorObjectThrowsMappedError(): void
    {
        $e = $this->assertCreateFails(['error' => ['code' => 401, 'message' => 'No auth credentials found']], 'x', ProviderCode::AuthFailed, 200);
        self::assertSame('No auth credentials found', $e->providerMessage);
    }

    public function testHttp200WithoutDataThrows(): void
    {
        $this->assertCreateFails([], 'x', ProviderCode::ProviderUnavailable, 200);
    }

    public function testVectorCountMismatchThrows(): void
    {
        $this->assertCreateFails([
            'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2]]],
        ], ['a', 'b'], ProviderCode::ProviderUnavailable);
    }

    public function testNullVectorCellThrowsInsteadOfCoercingToZero(): void
    {
        $this->assertCreateFails([
            'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, null, 0.3]]],
        ], 'x', ProviderCode::ProviderUnavailable);
    }

    public function testStringVectorCellThrows(): void
    {
        $this->assertCreateFails([
            'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, '0.2']]],
        ], ['x'], ProviderCode::ProviderUnavailable);
    }

    private function okVectors(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'data' => [['index' => 0, 'embedding' => [0.5]]],
            'usage' => ['prompt_tokens' => 1, 'total_tokens' => 1],
        ], JSON_THROW_ON_ERROR));
    }

    public function testMistralSendsDimensionsAsOutputDimension(): void
    {
        $spec = Specs::get('mistral');
        self::assertNotNull($spec);
        $this->mock->addResponse($this->okVectors());

        (new OpenAiCompatEmbeddingsConnector($spec, new OpenAiCompatConfig(apiKey: 'k'), $this->mock, $this->factory, $this->factory))
            ->create(new EmbeddingsInput(model: 'mistral-embed', input: 'a', dimensions: 256));

        /** @var array<string,mixed> $sent */
        $sent = json_decode((string) $this->mock->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(256, $sent['output_dimension']);
        self::assertArrayNotHasKey('dimensions', $sent);
    }

    public function testOpenAiKeepsDimensions(): void
    {
        $this->mock->addResponse($this->okVectors());
        $this->connector()->create(new EmbeddingsInput(model: 'text-embedding-3-small', input: 'a', dimensions: 256));

        /** @var array<string,mixed> $sent */
        $sent = json_decode((string) $this->mock->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(256, $sent['dimensions']);
    }

    public function testPassthroughQueryIsAppended(): void
    {
        $this->mock->addResponse($this->okVectors());
        $this->connector()->create(new EmbeddingsInput(model: 'm', input: 'a', _passthrough: ['query' => ['api-version' => '2024-10-21']]));

        self::assertSame('https://api.openai.com/v1/embeddings?api-version=2024-10-21', (string) $this->mock->getRequests()[0]->getUri());
    }

    public function testNegativeOrBlankRetryAfterIsIgnored(): void
    {
        foreach (['-5', ' '] as $header) {
            $this->mock->addResponse(new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => $header], '{"error":{"message":"slow down"}}'));
            try {
                $this->connector()->create(new EmbeddingsInput(model: 'm', input: 'a'));
                self::fail('expected ConnectorError');
            } catch (ConnectorError $e) {
                /** @var array<string,mixed> $cause */
                $cause = $e->cause;
                self::assertNull($cause['retryAfterSeconds'], "Retry-After '{$header}'");
            }
        }
    }

    public function testPaymentRequiredIsRateLimited(): void
    {
        $this->mock->addResponse(new Response(402, ['Content-Type' => 'application/json'], '{"error":{"message":"Insufficient credits"}}'));
        try {
            $this->connector()->create(new EmbeddingsInput(model: 'm', input: 'a'));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::RateLimited, $e->providerCode);
        }
    }

    public function testInBodyStringCodesClassifyLikeChat(): void
    {
        $this->assertCreateFails(['error' => ['code' => 'insufficient_quota', 'message' => 'quota']], 'x', ProviderCode::RateLimited, 200);
        $this->assertCreateFails(['error' => ['code' => '429', 'message' => 'slow']], 'x', ProviderCode::RateLimited, 200);
        $this->assertCreateFails(['error' => ['message' => 'odd']], 'x', ProviderCode::Unknown, 200);
    }

    public function testRequestTimeoutIsProviderUnavailable(): void
    {
        $this->mock->addResponse(new Response(408, ['Content-Type' => 'application/json'], '{"error":{"message":"timeout"}}'));
        try {
            $this->connector()->create(new EmbeddingsInput(model: 'm', input: 'a'));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::ProviderUnavailable, $e->providerCode);
        }
    }

    public function testRetryAfterIsStrict(): void
    {
        $cases = [
            '120' => 120,
            '1.5' => null,
            '-5' => null,
            'tomorrow' => null,
            '+1 week' => null,
            'Sun, 06 Nov 1994 08:49:37 GMT' => 0,
            'Sunday, 06-Nov-94 08:49:37 GMT' => 0,
            'Sun Nov  6 08:49:37 1994' => 0,
            'Sun, 06 Nov 1994 08:49:37 PST' => null,
        ];
        $future = time() + 120;
        $cases[gmdate('D, d M Y H:i:s', $future) . ' GMT'] = 120;
        $cases[gmdate('l, d-M-y H:i:s', $future) . ' GMT'] = 120;
        $cases[gmdate('D M ', $future) . str_pad(gmdate('j', $future), 2, ' ', STR_PAD_LEFT) . gmdate(' H:i:s Y', $future)] = 120;
        foreach ($cases as $header => $expected) {
            $this->mock->addResponse(new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => (string) $header], '{"error":{"message":"slow"}}'));
            try {
                $this->connector()->create(new EmbeddingsInput(model: 'm', input: 'a'));
                self::fail('expected ConnectorError');
            } catch (ConnectorError $e) {
                /** @var array<string,mixed> $cause */
                $cause = $e->cause;
                if ($expected === 120) {
                    self::assertIsInt($cause['retryAfterSeconds'], (string) $header);
                    self::assertEqualsWithDelta(120, $cause['retryAfterSeconds'], 2, (string) $header);
                } else {
                    self::assertSame($expected, $cause['retryAfterSeconds'], (string) $header);
                }
            }
        }
    }

    public function testDebugOutputRedactsTheKey(): void
    {
        $config = new OpenAiCompatConfig(apiKey: 'sk-SECRET');
        $emb = \Thinwrap\Llm\Embeddings::fromConnector($this->connectorWith($config));
        self::assertStringNotContainsString('SECRET', print_r($emb, true));
        self::assertStringNotContainsString('SECRET', print_r($config, true));
    }

    private function connectorWith(OpenAiCompatConfig $config): OpenAiCompatEmbeddingsConnector
    {
        $spec = Specs::get('openai');
        self::assertNotNull($spec);

        return new OpenAiCompatEmbeddingsConnector($spec, $config, $this->mock, $this->factory, $this->factory);
    }
}

<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Tests\Providers\Bedrock;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thinwrap\Llm\DTO\Chat\ChatInput;
use Thinwrap\Llm\DTO\Chat\ChatMessage;
use Thinwrap\Llm\DTO\Chat\ToolDefinition;
use Thinwrap\Llm\DTO\Chat\ToolFunction;
use Thinwrap\Llm\Enum\ChatRole;
use Thinwrap\Llm\Enum\FinishReason;
use Thinwrap\Llm\Enum\ProviderCode;
use Thinwrap\Llm\Exception\ConnectorError;
use Thinwrap\Llm\Providers\Bedrock\BedrockConfig;
use Thinwrap\Llm\Providers\Bedrock\BedrockConnector;

final class BedrockConnectorTest extends TestCase
{
    private MockClient $mock;
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->mock = new MockClient();
        $this->factory = new Psr17Factory();
    }

    private function connector(?string $sessionToken = null): BedrockConnector
    {
        return new BedrockConnector(
            new BedrockConfig(region: 'us-east-1', accessKeyId: 'AKIAEXAMPLE', secretAccessKey: 'secret', sessionToken: $sessionToken),
            $this->mock,
            $this->factory,
            $this->factory,
        );
    }

    /**
     * @param array<string,mixed> $body
     */
    private function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testTranslatesConverseAndSignsWithSigV4(): void
    {
        $this->mock->addResponse($this->json(200, [
            'output' => ['message' => ['content' => [['text' => 'hello']]]],
            'stopReason' => 'end_turn',
            'usage' => ['inputTokens' => 5, 'outputTokens' => 2, 'totalTokens' => 7],
        ]));

        $res = $this->connector()->complete(new ChatInput(
            model: 'anthropic.claude-3-5-sonnet-20241022-v2:0',
            messages: [new ChatMessage(ChatRole::System, 'be brief'), new ChatMessage(ChatRole::User, 'hi')],
            maxOutputTokens: 50,
        ));

        self::assertSame('hello', $res->message->content);
        self::assertSame(FinishReason::Stop, $res->finishReason);
        self::assertSame(7, $res->usage->totalTokens);

        $req = $this->mock->getRequests()[0];
        self::assertSame(
            'https://bedrock-runtime.us-east-1.amazonaws.com/model/anthropic.claude-3-5-sonnet-20241022-v2%3A0/converse',
            (string) $req->getUri(),
        );

        $auth = $req->getHeaderLine('Authorization');
        self::assertMatchesRegularExpression('#^AWS4-HMAC-SHA256 Credential=AKIAEXAMPLE/#', $auth);
        self::assertStringContainsString('/us-east-1/bedrock/aws4_request', $auth);
        self::assertMatchesRegularExpression('/SignedHeaders=[^,]*host[^,]*, Signature=[0-9a-f]{64}$/', $auth);
        self::assertMatchesRegularExpression('/^\d{8}T\d{6}Z$/', $req->getHeaderLine('X-Amz-Date'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $req->getHeaderLine('X-Amz-Content-Sha256'));

        /** @var array<string,mixed> $sent */
        $sent = json_decode((string) $req->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([['text' => 'be brief']], $sent['system']);
        self::assertSame(50, $sent['inferenceConfig']['maxTokens']);
        self::assertSame([['role' => 'user', 'content' => [['text' => 'hi']]]], $sent['messages']);
    }

    public function testSigV4CanonicalUriIsDoubleEncodedForColonModelId(): void
    {
        $this->mock->addResponse($this->json(200, [
            'output' => ['message' => ['content' => [['text' => 'ok']]]],
            'stopReason' => 'end_turn',
            'usage' => [],
        ]));

        $model = 'anthropic.claude-3-5-sonnet-20241022-v2:0';
        $this->connector()->complete(new ChatInput(model: $model, messages: [new ChatMessage(ChatRole::User, 'hi')]));

        $req = $this->mock->getRequests()[0];

        // The WIRE path is SINGLE-encoded: `:` → `%3A`, and never `%253A`.
        $uri = (string) $req->getUri();
        self::assertStringContainsString('anthropic.claude-3-5-sonnet-20241022-v2%3A0/converse', $uri);
        self::assertStringNotContainsString('%253A', $uri);

        // The SIGNED canonical URI must be DOUBLE-encoded (`%253A`) — and therefore
        // differ from the wire path. Recompute the SigV4 signature both ways from
        // the timestamp/payload-hash the connector actually signed, and prove the
        // Authorization signature matches the DOUBLE-encoded path and NOT the single.
        $timestamp = $req->getHeaderLine('X-Amz-Date');
        $hashedPayload = $req->getHeaderLine('X-Amz-Content-Sha256');

        $auth = $req->getHeaderLine('Authorization');
        self::assertSame(1, preg_match('/Signature=([0-9a-f]{64})$/', $auth, $m));
        $actualSignature = $m[1];

        $singlePath = '/model/' . rawurlencode($model) . '/converse';               // …v2%3A0…
        $doublePath = '/model/' . rawurlencode(rawurlencode($model)) . '/converse'; // …v2%253A0…
        self::assertStringContainsString('%3A', $singlePath);
        self::assertStringContainsString('%253A', $doublePath);
        self::assertNotSame($singlePath, $doublePath, 'wire and canonical paths must differ for a colon id');

        self::assertSame(
            $this->recomputeSignature($doublePath, $timestamp, $hashedPayload),
            $actualSignature,
            'the SigV4 canonical URI must be double-encoded',
        );
        self::assertNotSame(
            $this->recomputeSignature($singlePath, $timestamp, $hashedPayload),
            $actualSignature,
            'the signature must NOT be computed over the single-encoded wire path',
        );
    }

    /**
     * Independent re-implementation of the connector's SigV4 for a given canonical
     * path (known-answer oracle). Mirrors {@see BedrockConnector::signAwsRequest}
     * for the default no-session connector (signed headers:
     * content-type;host;x-amz-content-sha256;x-amz-date).
     */
    private function recomputeSignature(string $canonicalPath, string $timestamp, string $hashedPayload): string
    {
        $host = 'bedrock-runtime.us-east-1.amazonaws.com';
        $region = 'us-east-1';
        $service = 'bedrock';
        $secret = 'secret';

        $canonicalHeaders
            = "content-type:application/json\n"
            . "host:{$host}\n"
            . "x-amz-content-sha256:{$hashedPayload}\n"
            . "x-amz-date:{$timestamp}\n";
        $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';

        $canonicalRequest = "POST\n{$canonicalPath}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$hashedPayload}";

        $dateStamp = substr($timestamp, 0, 8);
        $scope = "{$dateStamp}/{$region}/{$service}/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$timestamp}\n{$scope}\n" . hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

        return hash_hmac('sha256', $stringToSign, $kSigning, false);
    }

    public function testToolsBecomeToolConfigWithDefaultMaxTokens(): void
    {
        $this->mock->addResponse($this->json(200, [
            'output' => ['message' => ['content' => [['toolUse' => ['toolUseId' => 't1', 'name' => 'get_weather', 'input' => ['city' => 'SF']]]]]],
            'stopReason' => 'tool_use',
            'usage' => ['inputTokens' => 8, 'outputTokens' => 4, 'totalTokens' => 12],
        ]));

        $res = $this->connector()->complete(new ChatInput(
            model: 'anthropic.claude-x',
            messages: [new ChatMessage(ChatRole::User, 'weather?')],
            tools: [new ToolDefinition(new ToolFunction('get_weather', null, ['type' => 'object']))],
            toolChoice: 'required',
        ));

        self::assertSame(FinishReason::ToolCalls, $res->finishReason);
        self::assertNotNull($res->message->toolCalls);
        self::assertSame('{"city":"SF"}', $res->message->toolCalls[0]->function->arguments);

        /** @var array<string,mixed> $sent */
        $sent = json_decode((string) $this->mock->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([['toolSpec' => ['name' => 'get_weather', 'inputSchema' => ['json' => ['type' => 'object']]]]], $sent['toolConfig']['tools']);
        self::assertSame(['any' => []], $sent['toolConfig']['toolChoice']);
        self::assertSame(4096, $sent['inferenceConfig']['maxTokens']);
    }

    public function testIncludesSecurityTokenWhenSessionTokenSupplied(): void
    {
        $this->mock->addResponse($this->json(200, ['output' => ['message' => ['content' => [['text' => 'ok']]]], 'stopReason' => 'end_turn', 'usage' => []]));
        $this->connector('sess-123')->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'x')]));

        $req = $this->mock->getRequests()[0];
        self::assertSame('sess-123', $req->getHeaderLine('X-Amz-Security-Token'));
        self::assertStringContainsString('x-amz-security-token', $req->getHeaderLine('Authorization'));
    }

    public function testMalformedBaseUrlThrowsConnectorError(): void
    {
        $this->expectException(ConnectorError::class);
        $this->expectExceptionMessageMatches('/could not derive a host/');
        new BedrockConnector(new BedrockConfig(region: 'us-east-1', accessKeyId: 'a', secretAccessKey: 'b', baseUrl: 'not a url'));
    }

    public function testMaps403ToAuthAnd400ValidationToInvalidRequest(): void
    {
        $this->mock->addResponse($this->json(403, ['message' => 'not authorized', '__type' => 'AccessDeniedException']));
        try {
            $this->connector()->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'x')]));
            self::fail('expected');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::AuthFailed, $e->providerCode);
        }

        $this->mock->addResponse($this->json(400, ['message' => 'bad input', '__type' => 'ValidationException']));
        try {
            $this->connector()->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'x')]));
            self::fail('expected');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::InvalidRequest, $e->providerCode);
        }
    }

    public function testTokenBearing400IsNotMisclassifiedAsContextLength(): void
    {
        // A 400 mentioning "token"/"max" that is NOT a context overflow must map to
        // invalid_request — the old bare-substring match wrongly flagged these.
        foreach (['The security token included in the request is invalid', 'Value exceeds max items'] as $msg) {
            $this->mock->addResponse($this->json(400, ['message' => $msg, '__type' => 'ValidationException']));
            try {
                $this->connector()->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'x')]));
                self::fail('expected');
            } catch (ConnectorError $e) {
                self::assertSame(ProviderCode::InvalidRequest, $e->providerCode, $msg);
            }
        }

        // A genuine context overflow still maps to context_length_exceeded.
        $this->mock->addResponse($this->json(400, ['message' => "This model's maximum context length is 200000 tokens", '__type' => 'ValidationException']));
        try {
            $this->connector()->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'x')]));
            self::fail('expected');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::ContextLengthExceeded, $e->providerCode);
        }
    }

    public function testStreamFallbackYieldsContentFinishUsage(): void
    {
        $this->mock->addResponse($this->json(200, [
            'output' => ['message' => ['content' => [['text' => 'hello world']]]],
            'stopReason' => 'end_turn',
            'usage' => ['inputTokens' => 3, 'outputTokens' => 5, 'totalTokens' => 8],
        ]));

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
        self::assertSame('hello world', $text);
        self::assertTrue($sawStop);
        self::assertNotNull($usage);
        self::assertSame(8, $usage->totalTokens);

        $uri = (string) $this->mock->getRequests()[0]->getUri();
        self::assertStringContainsString('/converse', $uri);
        self::assertStringNotContainsString('converse-stream', $uri);
    }
}

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
            model: 'anthropic.claude-haiku-4-5-20251001-v1:0',
            messages: [new ChatMessage(ChatRole::System, 'be brief'), new ChatMessage(ChatRole::User, 'hi')],
            maxOutputTokens: 50,
        ));

        self::assertSame('hello', $res->message->content);
        self::assertSame(FinishReason::Stop, $res->finishReason);
        self::assertSame(7, $res->usage->totalTokens);

        $req = $this->mock->getRequests()[0];
        self::assertSame(
            'https://bedrock-runtime.us-east-1.amazonaws.com/model/anthropic.claude-haiku-4-5-20251001-v1%3A0/converse',
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

        $model = 'anthropic.claude-haiku-4-5-20251001-v1:0';
        $this->connector()->complete(new ChatInput(model: $model, messages: [new ChatMessage(ChatRole::User, 'hi')]));

        $req = $this->mock->getRequests()[0];

        // The WIRE path is SINGLE-encoded: `:` → `%3A`, and never `%253A`.
        $uri = (string) $req->getUri();
        self::assertStringContainsString('anthropic.claude-haiku-4-5-20251001-v1%3A0/converse', $uri);
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
    private function recomputeSignature(string $canonicalPath, string $timestamp, string $hashedPayload, string $canonicalQuery = '', string $contentType = 'application/json'): string
    {
        $host = 'bedrock-runtime.us-east-1.amazonaws.com';
        $region = 'us-east-1';
        $service = 'bedrock';
        $secret = 'secret';

        $canonicalHeaders
            = "content-type:{$contentType}\n"
            . "host:{$host}\n"
            . "x-amz-content-sha256:{$hashedPayload}\n"
            . "x-amz-date:{$timestamp}\n";
        $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';

        $canonicalRequest = "POST\n{$canonicalPath}\n{$canonicalQuery}\n{$canonicalHeaders}\n{$signedHeaders}\n{$hashedPayload}";

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

    public function testHttp200WithoutPayloadThrows(): void
    {
        $this->mock->addResponse($this->json(200, []));

        try {
            $this->connector()->complete(new ChatInput(model: 'anthropic.claude-3-haiku-20240307-v1:0', messages: [new ChatMessage(ChatRole::User, 'hi')]));
            self::fail('expected ConnectorError');
        } catch (ConnectorError $e) {
            self::assertSame(ProviderCode::ProviderUnavailable, $e->providerCode);
            self::assertSame(200, $e->statusCode);
        }
    }

    /**
     * @return array{0:string,1:string}
     */
    private function signatureOf(int $request = 0): array
    {
        $req = $this->mock->getRequests()[$request];
        self::assertSame(1, preg_match('/Signature=([0-9a-f]{64})$/', $req->getHeaderLine('Authorization'), $m));

        return [$m[1], $req->getHeaderLine('X-Amz-Date')];
    }

    private function okBody(): Response
    {
        return $this->json(200, ['output' => ['message' => ['content' => [['text' => 'ok']]]], 'stopReason' => 'end_turn', 'usage' => []]);
    }

    public function testPassthroughQueryIsSentAndSigned(): void
    {
        $this->mock->addResponse($this->okBody());
        $this->connector()->complete(new ChatInput(
            model: 'm',
            messages: [new ChatMessage(ChatRole::User, 'hi')],
            _passthrough: ['query' => ['z' => 'last', 'a' => 'x y']],
        ));

        $req = $this->mock->getRequests()[0];
        self::assertSame('https://bedrock-runtime.us-east-1.amazonaws.com/model/m/converse?a=x%20y&z=last', (string) $req->getUri());
        [$signature, $timestamp] = $this->signatureOf();
        self::assertSame(
            $this->recomputeSignature('/model/m/converse', $timestamp, $req->getHeaderLine('X-Amz-Content-Sha256'), 'a=x%20y&z=last'),
            $signature,
        );
    }

    public function testCaseVariantDuplicateHeaderIsSignedWithTheValueSent(): void
    {
        $this->mock->addResponse($this->okBody());
        $this->connector()->complete(new ChatInput(
            model: 'm',
            messages: [new ChatMessage(ChatRole::User, 'hi')],
            _passthrough: ['headers' => ['content-type' => 'application/json; charset=utf-8']],
        ));

        $req = $this->mock->getRequests()[0];
        self::assertSame(['application/json; charset=utf-8'], $req->getHeader('Content-Type'));
        [$signature, $timestamp] = $this->signatureOf();
        self::assertSame(
            $this->recomputeSignature('/model/m/converse', $timestamp, $req->getHeaderLine('X-Amz-Content-Sha256'), '', 'application/json; charset=utf-8'),
            $signature,
        );
    }

    public function testRegionMustBeAHostLabel(): void
    {
        foreach (['evil.com/x', 'us-east-1.evil.com', 'US EAST', '', 'us-east-1#'] as $region) {
            try {
                new BedrockConnector(new BedrockConfig(region: $region, accessKeyId: 'AKIA', secretAccessKey: 's'), $this->mock, $this->factory, $this->factory);
                self::fail("expected ConnectorError for '{$region}'");
            } catch (ConnectorError $e) {
                self::assertSame(ProviderCode::InvalidRequest, $e->providerCode);
            }
        }
    }

    public function testToolChoiceNoneOmitsToolConfig(): void
    {
        $this->mock->addResponse($this->okBody());
        $this->connector()->complete(new ChatInput(
            model: 'm',
            messages: [new ChatMessage(ChatRole::User, 'hi')],
            tools: [new ToolDefinition(new ToolFunction('get_weather'))],
            toolChoice: 'none',
        ));

        /** @var array<string,mixed> $sent */
        $sent = json_decode((string) $this->mock->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('toolConfig', $sent);
    }

    public function testUsageCountsCacheReadsAndWritesAsInput(): void
    {
        $this->mock->addResponse($this->json(200, [
            'output' => ['message' => ['content' => [['text' => 'ok']]]],
            'stopReason' => 'end_turn',
            'usage' => ['inputTokens' => 4, 'cacheReadInputTokens' => 500, 'cacheWriteInputTokens' => 100, 'outputTokens' => 6, 'totalTokens' => 610],
        ]));

        $res = $this->connector()->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')]));
        self::assertSame(604, $res->usage->inputTokens);
        self::assertSame(6, $res->usage->outputTokens);
        self::assertSame(610, $res->usage->totalTokens);
    }

    public function testStopReasonsAndStatusGaps(): void
    {
        $this->mock->addResponse($this->json(200, ['output' => ['message' => ['content' => [['text' => 'x']]]], 'stopReason' => 'model_context_window_exceeded', 'usage' => []]));
        $res = $this->connector()->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')]));
        self::assertSame(FinishReason::Length, $res->finishReason);

        $this->mock->addResponse($this->json(200, ['output' => ['message' => ['content' => [['text' => 'x']]]], 'stopReason' => 'malformed_tool_use', 'usage' => []]));
        $res = $this->connector()->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')]));
        self::assertSame(FinishReason::Unknown, $res->finishReason);

        $cases = [
            [408, 'Model timed out', ProviderCode::ProviderUnavailable],
            [424, 'Model error', ProviderCode::ProviderUnavailable],
            [400, 'Input is too long for requested model.', ProviderCode::ContextLengthExceeded],
            [400, 'The model name is too long', ProviderCode::InvalidRequest],
            [413, 'Payload too large', ProviderCode::InvalidRequest],
            [402, 'Payment required', ProviderCode::RateLimited],
        ];
        foreach ($cases as [$status, $message, $expected]) {
            $this->mock->addResponse($this->json($status, ['message' => $message]));
            try {
                $this->connector()->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')]));
                self::fail('expected ConnectorError');
            } catch (ConnectorError $e) {
                self::assertSame($expected, $e->providerCode, "{$status} {$message}");
            }
        }
    }

    public function testRegionRegexRejectsDoubleHyphens(): void
    {
        foreach (['us--east-1', '-us-east-1', 'us-east-1-'] as $region) {
            try {
                new BedrockConnector(new BedrockConfig(region: $region, accessKeyId: 'AKIA', secretAccessKey: 's'), $this->mock, $this->factory, $this->factory);
                self::fail("expected ConnectorError for '{$region}'");
            } catch (ConnectorError $e) {
                self::assertSame(ProviderCode::InvalidRequest, $e->providerCode);
            }
        }
        new BedrockConnector(new BedrockConfig(region: 'ap-southeast-7', accessKeyId: 'AKIA', secretAccessKey: 's'), $this->mock, $this->factory, $this->factory);
        $this->addToAssertionCount(1);
    }

    public function testEmptyTextPartsAreDropped(): void
    {
        $this->mock->addResponse($this->okBody());
        $this->connector()->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, [new \Thinwrap\Llm\DTO\Chat\TextPart(''), new \Thinwrap\Llm\DTO\Chat\TextPart('hi')])]));

        /** @var array<string,mixed> $sent */
        $sent = json_decode((string) $this->mock->getRequests()[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([['text' => 'hi']], $sent['messages'][0]['content']);
    }

    public function testToolUseNestedEmptyObjectSurvivesInArguments(): void
    {
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'application/json'], '{"output":{"message":{"content":[{"toolUse":{"toolUseId":"t","name":"f","input":{"o":{}}}}]}},"stopReason":"tool_use","usage":{}}'));
        $res = $this->connector()->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')]));
        self::assertNotNull($res->message->toolCalls);
        self::assertSame('{"o":{}}', $res->message->toolCalls[0]->function->arguments);
    }

    public function testOverflowPhrasesAreTheDocumentedOnes(): void
    {
        $cases = [
            ['prompt is too long: 250000 tokens > 200000 maximum', ProviderCode::ContextLengthExceeded],
            ["This model's maximum context length is 128000 tokens", ProviderCode::ContextLengthExceeded],
            ['input length and `max_tokens` exceed context limit', ProviderCode::ContextLengthExceeded],
            ['The context window setting is not supported', ProviderCode::InvalidRequest],
        ];
        foreach ($cases as [$message, $expected]) {
            $this->mock->addResponse($this->json(400, ['message' => $message]));
            try {
                $this->connector()->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')]));
                self::fail('expected ConnectorError');
            } catch (ConnectorError $e) {
                self::assertSame($expected, $e->providerCode, $message);
            }
        }
    }

    public function testDebugOutputRedactsCredentials(): void
    {
        $config = new BedrockConfig(region: 'us-east-1', accessKeyId: 'AKIASECRET', secretAccessKey: 'SECRETKEY', sessionToken: 'SECRETTOKEN');
        $connector = new BedrockConnector($config, $this->mock, $this->factory, $this->factory);
        foreach ([$config, $connector] as $subject) {
            self::assertStringNotContainsString('SECRET', print_r($subject, true));
        }
    }

    public function testRawStaysPlainArraysWhileArgumentsKeepObjects(): void
    {
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'application/json'], '{"output":{"message":{"content":[{"toolUse":{"toolUseId":"t","name":"f","input":{"o":{}}}}]}},"stopReason":"tool_use","usage":{},"metrics":{}}'));
        $res = $this->connector()->complete(new ChatInput(model: 'm', messages: [new ChatMessage(ChatRole::User, 'hi')]));
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

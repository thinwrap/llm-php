# thinwrap/llm

> ⚠️ **Work in progress (v0).** A faithful PHP port of [`@thinwrap/llm`](https://github.com/thinwrap/llm-ts) (TypeScript): the shared OpenAI-compatible chat connector across the 15 first-class providers, plus native adapters for Anthropic, Bedrock, and Gemini, and embeddings.

Unified, **SDK-free** PHP facade over LLM chat-completion & embeddings providers. One typed `Chat` facade; switch vendor by changing the provider id + model. Stateless, bring your own PSR-18 HTTP client, no vendor SDKs — the in-process, zero-egress complement to an LLM gateway.

## Install

```bash
composer require thinwrap/llm
```

Requires PHP ≥8.2. A PSR-18 HTTP client + PSR-17 factories are auto-discovered via
`php-http/discovery` — if you don't already have one installed:

```bash
composer require guzzlehttp/guzzle guzzlehttp/psr7
```

## End-to-end example

```php
use Thinwrap\Llm\Chat;
use Thinwrap\Llm\DTO\Chat\ChatInput;
use Thinwrap\Llm\DTO\Chat\ChatMessage;
use Thinwrap\Llm\Enum\ChatRole;
use Thinwrap\Llm\Enum\LlmProviderId;
use Thinwrap\Llm\Exception\ConnectorError;
use Thinwrap\Llm\Providers\Shared\OpenAiCompatConfig;

$chat = new Chat(LlmProviderId::OpenAI, new OpenAiCompatConfig(apiKey: getenv('OPENAI_API_KEY')));

try {
    $res = $chat->complete(new ChatInput(
        model: 'gpt-4o-mini',
        messages: [new ChatMessage(ChatRole::User, 'Say hi in one word.')],
    ));
    echo $res->message->content;                              // → the reply
    echo $res->usage->inputTokens . '/' . $res->usage->outputTokens;
} catch (ConnectorError $e) {
    error_log($e->providerCode->value . ': ' . ($e->providerMessage ?? ''));
}
```

## Switching providers

Change the `LlmProviderId` case, the config class, and the model; the input and
output shape stay identical.

```php
use Thinwrap\Llm\Providers\Anthropic\AnthropicConfig;
use Thinwrap\Llm\Providers\Gemini\GeminiConfig;
use Thinwrap\Llm\Providers\Bedrock\BedrockConfig;

$anthropic = new Chat(LlmProviderId::Anthropic, new AnthropicConfig(apiKey: getenv('ANTHROPIC_API_KEY')));
$gemini    = new Chat(LlmProviderId::Gemini,    new GeminiConfig(apiKey: getenv('GEMINI_API_KEY')));
$bedrock   = new Chat(LlmProviderId::Bedrock,   new BedrockConfig(
    region: 'us-east-1',
    accessKeyId: getenv('AWS_ACCESS_KEY_ID'),
    secretAccessKey: getenv('AWS_SECRET_ACCESS_KEY'),
));
// same ->complete($input) shape, same ChatResult
```

The 15 first-class OpenAI-compatible providers all take `OpenAiCompatConfig`:
`openai`, `azure-openai`, `openrouter`, `groq`, `together`, `fireworks`, `deepseek`,
`xai`, `mistral`, `perplexity`, `deepinfra`, `cloudflare`, `vllm`, `ollama`, `lmstudio`.
`anthropic`, `bedrock`, and `gemini` are native adapters.

Per-provider docs (auth, config, quirks, rate-limit links, error mapping) live in
[`src/Providers/`](src/Providers) — one README per provider.

## Streaming

```php
foreach ($chat->stream($input) as $delta) {
    if ($delta->contentDelta !== null) {
        echo $delta->contentDelta;
    }
}
```

`stream()` returns a `\Generator<ChatStreamDelta>`. It reads the provider's
`text/event-stream` response off the PSR-7 body incrementally. Bedrock (whose
stream uses AWS binary event-stream framing) falls back to a single Converse call
replayed as deltas.

## Embeddings

```php
use Thinwrap\Llm\Embeddings;
use Thinwrap\Llm\DTO\Embeddings\EmbeddingsInput;

$emb = new Embeddings(LlmProviderId::OpenAI, new OpenAiCompatConfig(apiKey: getenv('OPENAI_API_KEY')));
$res = $emb->create(new EmbeddingsInput(model: 'text-embedding-3-small', input: ['hello', 'world']));
$res->embeddings; // list<list<float>>, one vector per input, in input order
```

## Bring your own PSR-18 client

Inject any PSR-18 client (and, optionally, PSR-17 factories) as constructor
arguments — useful for tracing, retries, mocking, or proxying through
`symfony/http-client`.

```php
$chat = new Chat(
    LlmProviderId::OpenAI,
    new OpenAiCompatConfig(apiKey: getenv('OPENAI_API_KEY')),
    $myPsr18Client,          // ?ClientInterface
    $myRequestFactory,       // ?RequestFactoryInterface
    $myStreamFactory,        // ?StreamFactoryInterface
);
```

The wrapper holds no state — no cache, no connection pool, no retry buffer. For an
already-built connector (a custom provider, say), use `Chat::fromConnector($connector)`.

## `_passthrough` escape valve

When the normalized input doesn't expose a vendor-specific field, forward arbitrary
keys via `_passthrough` on the input DTO. The connector deep-merges `body`, and merges
`headers` / `query`; consumer values win on collision. Keys are forwarded verbatim.

```php
new ChatInput(
    model: 'gpt-4o-mini',
    messages: [new ChatMessage(ChatRole::User, 'hi')],
    _passthrough: ['body' => ['logprobs' => true], 'headers' => ['X-Trace' => 'abc']],
);
```

## Error handling

Every failure surfaces as `ConnectorError` with a typed `ProviderCode`
(`rate_limited`, `auth_failed`, `provider_unavailable`, `invalid_request`,
`context_length_exceeded`, `content_filtered`, `unknown`). There is **no** top-level
`retryAfterSeconds` field — the raw `Retry-After` and its parsed seconds ride in
`$e->cause`. The wrapper performs no automatic retry.

MIT © Dmitry Polyanovsky

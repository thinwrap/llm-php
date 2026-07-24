# Fireworks AI — [`thinwrap/llm`](../../../README.md) (PHP)

First-class OpenAI-compatible provider — served by the shared `OpenAiCompatConnector` via a row in `src/Providers/Shared/Specs.php` (no per-provider connector class). It emits the identical normalized `ChatResult` / `ChatStreamDelta` / `ConnectorError` as every other connector, so switching to/from `fireworks` is just the provider id + `model`.

## Quick start

```php
use Thinwrap\Llm\Chat;
use Thinwrap\Llm\DTO\Chat\ChatInput;
use Thinwrap\Llm\DTO\Chat\ChatMessage;
use Thinwrap\Llm\Enum\ChatRole;
use Thinwrap\Llm\Enum\LlmProviderId;
use Thinwrap\Llm\Providers\Shared\OpenAiCompatConfig;

$chat = new Chat(LlmProviderId::Fireworks, new OpenAiCompatConfig(apiKey: getenv('FIREWORKS_API_KEY')));
$res = $chat->complete(new ChatInput(
    model: 'accounts/fireworks/models/llama-v3p3-70b-instruct',
    messages: [new ChatMessage(ChatRole::User, 'Say hi in one word.')],
));
```

## Configuration

`OpenAiCompatConfig`:

| Field | Type | Required | Notes |
|---|---|---|---|
| `apiKey` | `string` | Yes | Sent as `Authorization: Bearer <apiKey>`. |
| `baseUrl` | `string` | No | Overrides the default `https://api.fireworks.ai/inference/v1`. |
| `headers` | `array` | No | Merged onto every request. |

Bring-your-own PSR-18 client + PSR-17 factories via the `Chat`/`Embeddings` constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

Create a key at <https://fireworks.ai/account/api-keys>. It is sent as `Authorization: Bearer <apiKey>`.

## Chat

### Endpoint

`POST https://api.fireworks.ai/inference/v1/chat/completions`

### Notes / quirks

- The OpenAI-compatible endpoint **is** the native API (a superset).
- Model ids are account-namespaced (`accounts/fireworks/models/…`); bare OpenAI names won't resolve.
- Reasoning chain-of-thought is exposed via `reasoning_content` (in `raw`).

## Embeddings

Supported (served by the shared `OpenAiCompatEmbeddingsConnector`).

```php
use Thinwrap\Llm\Embeddings;
use Thinwrap\Llm\DTO\Embeddings\EmbeddingsInput;
use Thinwrap\Llm\Enum\LlmProviderId;
use Thinwrap\Llm\Providers\Shared\OpenAiCompatConfig;

$emb = new Embeddings(LlmProviderId::Fireworks, new OpenAiCompatConfig(apiKey: getenv('FIREWORKS_API_KEY')));
$res = $emb->create(new EmbeddingsInput(model: 'nomic-ai/nomic-embed-text-v1.5', input: ['hello', 'world']));
// $res->embeddings — list<list<float>>, one vector per input in input order
```

`POST https://api.fireworks.ai/inference/v1/embeddings` with `encoding_format: "float"`. `dimensions` maps to `dimensions` when set. Vectors are returned in input order on `$res->embeddings`.

## Error mapping & passthrough

- Non-2xx responses throw `ConnectorError` with a 7-value `providerCode` enum (`auth_failed` / `rate_limited` / `provider_unavailable` / `invalid_request` / `context_length_exceeded` / `content_filtered` / `unknown`; read the string via `$e->providerCode->value`). The raw vendor body is on `$e->cause['raw']`; retry hints (when present) on `$e->cause['retryAfter']` / `$e->cause['retryAfterSeconds']`.
- Sub-baseline features — `topK`, `seed`, `frequency`/`presence_penalty`, `logitBias`, `n`, reasoning control beyond `reasoning.effort`, prompt caching — ride through `_passthrough` (request) / `raw` (result). They are never emulated.
- Rate-limit guidance: <https://docs.fireworks.ai/guides/quotas_usage/rate-limits>.

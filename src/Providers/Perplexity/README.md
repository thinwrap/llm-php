# Perplexity (Sonar) — [`thinwrap/llm`](../../../README.md) (PHP)

First-class OpenAI-compatible provider — served by the shared `OpenAiCompatConnector` via a row in `src/Providers/Shared/Specs.php` (no per-provider connector class). It emits the identical normalized `ChatResult` / `ChatStreamDelta` / `ConnectorError` as every other connector, so switching to/from `perplexity` is just the provider id + `model`.

## Quick start

```php
use Thinwrap\Llm\Chat;
use Thinwrap\Llm\DTO\Chat\ChatInput;
use Thinwrap\Llm\DTO\Chat\ChatMessage;
use Thinwrap\Llm\Enum\ChatRole;
use Thinwrap\Llm\Enum\LlmProviderId;
use Thinwrap\Llm\Providers\Shared\OpenAiCompatConfig;

$chat = new Chat(LlmProviderId::Perplexity, new OpenAiCompatConfig(apiKey: getenv('PERPLEXITY_API_KEY')));
$res = $chat->complete(new ChatInput(
    model: 'sonar',
    messages: [new ChatMessage(ChatRole::User, 'Say hi in one word.')],
));
```

## Configuration

`OpenAiCompatConfig`:

| Field | Type | Required | Notes |
|---|---|---|---|
| `apiKey` | `string` | Yes | Sent as `Authorization: Bearer <apiKey>`. |
| `baseUrl` | `string` | No | Overrides the default `https://api.perplexity.ai` (no `/v1` segment). |
| `headers` | `array` | No | Merged onto every request. |

Bring-your-own PSR-18 client + PSR-17 factories via the `Chat`/`Embeddings` constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

Create an API key at <https://www.perplexity.ai/settings/api>. It is sent as `Authorization: Bearer <apiKey>`.

## Chat

### Endpoint

`POST https://api.perplexity.ai/chat/completions` (the default base URL has no `/v1` prefix).

### Notes / quirks

- **Baseline-exception (handled):** the Sonar chat surface has **no tool/function calling** (`tools` are not sent); tools live on Perplexity's separate Agent API.
- Sampling surface is stripped (no `top_k`/penalties/`seed`/`n` on Sonar).
- Model ids are `sonar` / `sonar-pro` / `sonar-reasoning-pro` / `sonar-deep-research`.

## Embeddings

Perplexity exposes no OpenAI-float embeddings surface, so there is no `Embeddings` support — `new Embeddings(LlmProviderId::Perplexity, ...)` throws `ConnectorError` (`invalid_request`).

## Error mapping & passthrough

- Non-2xx responses throw `ConnectorError` with a 7-value `providerCode` enum (`auth_failed` / `rate_limited` / `provider_unavailable` / `invalid_request` / `context_length_exceeded` / `content_filtered` / `unknown`; read the string via `$e->providerCode->value`). The raw vendor body is on `$e->cause['raw']`; retry hints (when present) on `$e->cause['retryAfter']` / `$e->cause['retryAfterSeconds']`.
- Sub-baseline features — `topK`, `seed`, `frequency`/`presence_penalty`, `logitBias`, `n`, reasoning control beyond `reasoning.effort`, prompt caching — ride through `_passthrough` (request) / `raw` (result). They are never emulated.
- Rate-limit guidance: <https://docs.perplexity.ai/guides/usage-tiers>.

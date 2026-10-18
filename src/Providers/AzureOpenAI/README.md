# Azure OpenAI — [`thinwrap/llm`](../../../README.md) (PHP)

First-class OpenAI-compatible provider — served by the shared `OpenAiCompatConnector` via a row in `src/Providers/Shared/Specs.php` (no per-provider connector class). It emits the identical normalized `ChatResult` / `ChatStreamDelta` / `ConnectorError` as every other connector, so switching to/from `azure-openai` is just the provider id + `model`.

## Quick start

```php
use Thinwrap\Llm\Chat;
use Thinwrap\Llm\DTO\Chat\ChatInput;
use Thinwrap\Llm\DTO\Chat\ChatMessage;
use Thinwrap\Llm\Enum\ChatRole;
use Thinwrap\Llm\Enum\LlmProviderId;
use Thinwrap\Llm\Providers\Shared\OpenAiCompatConfig;

$chat = new Chat(LlmProviderId::AzureOpenAI, new OpenAiCompatConfig(
    apiKey: getenv('AZURE_OPENAI_API_KEY'),
    baseUrl: '<resource URL — see quirks>',
));
$res = $chat->complete(new ChatInput(
    model: '<your-deployment-name>',
    messages: [new ChatMessage(ChatRole::User, 'Say hi in one word.')],
));
```

## Configuration

`OpenAiCompatConfig`:

| Field | Type | Required | Notes |
|---|---|---|---|
| `apiKey` | `string` | Yes | Sent as the `api-key: <apiKey>` header (Microsoft Entra OAuth is also accepted on the v1 GA API). |
| `baseUrl` | `string` | Yes | Required — no public default (`https://<resource>.openai.azure.com/openai/v1`; see Chat quirks). |
| `headers` | `array` | No | Merged onto every request. |

Bring-your-own PSR-18 client + PSR-17 factories via the `Chat`/`Embeddings` constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

Get the key and resource endpoint from the Azure portal. It is sent as the `api-key` header; Microsoft Entra OAuth is also accepted on the v1 GA API (supply that bearer token via `headers` instead).

## Chat

### Endpoint

`POST https://<resource>.openai.azure.com/openai/v1/chat/completions`

### Notes / quirks

- **`baseUrl` is required**: `https://<resource>.openai.azure.com/openai/v1` (v1 GA API, opt-in Aug 2025 — no `api-version` needed).
- The `model` field is the per-resource **deployment name**, not a portable global model id.
- Auth is dual-mode: `api-key` header or an Entra bearer token (supply the latter via `headers`).
- `maxOutputTokens` is sent as `max_completion_tokens` — Azure reasoning deployments reject `max_tokens` on Chat Completions; the cap includes reasoning tokens. `max_completion_tokens` exists on the v1 API and on dated `api-version`s from `2024-09-01-preview` on (Microsoft's API version changelog); an older `api-version` passed through `_passthrough['query']` predates it.
- **Function tools with reasoning on current models.** gpt-5.4, gpt-5.4-mini, gpt-5.5 and the gpt-5.6 deployments reject function `tools` combined with reasoning on Chat Completions with a 400 (`invalid_request`); Microsoft documents that gpt-5.6 supports Chat Completions and function tools "but not both at the same time unless `reasoning_effort` is `none`", even with `reasoning` unset. The workaround is `_passthrough: ['body' => ['reasoning_effort' => 'none']]` (accepted on 5.4 and later; gpt-5-mini and o4-mini reject `'none'`). Moving to the Responses API is planned.

## Embeddings

Supported (served by the shared `OpenAiCompatEmbeddingsConnector`).

```php
use Thinwrap\Llm\Embeddings;
use Thinwrap\Llm\DTO\Embeddings\EmbeddingsInput;
use Thinwrap\Llm\Enum\LlmProviderId;
use Thinwrap\Llm\Providers\Shared\OpenAiCompatConfig;

$emb = new Embeddings(LlmProviderId::AzureOpenAI, new OpenAiCompatConfig(
    apiKey: getenv('AZURE_OPENAI_API_KEY'),
    baseUrl: '<resource URL — see quirks>',
));
$res = $emb->create(new EmbeddingsInput(model: '<embeddings-deployment>', input: ['hello', 'world']));
// $res->embeddings — list<list<float>>, one vector per input in input order
```

`POST https://<resource>.openai.azure.com/openai/v1/embeddings` with `encoding_format: "float"`. `dimensions` maps to `dimensions` when set. Vectors are returned in input order on `$res->embeddings`.

## Error mapping & passthrough

- Non-2xx responses throw `ConnectorError` with a 7-value `providerCode` enum (`auth_failed` / `rate_limited` / `provider_unavailable` / `invalid_request` / `context_length_exceeded` / `content_filtered` / `unknown`; read the string via `$e->providerCode->value`). The raw vendor body is on `$e->cause['raw']`; retry hints (when present) on `$e->cause['retryAfter']` / `$e->cause['retryAfterSeconds']`.
- Sub-baseline features — `topK`, `seed`, `frequency`/`presence_penalty`, `logitBias`, `n`, reasoning control beyond `reasoning.effort`, prompt caching — ride through `_passthrough` (request) / `raw` (result). They are never emulated.
- Rate-limit guidance: <https://learn.microsoft.com/azure/ai-services/openai/quotas-limits>.

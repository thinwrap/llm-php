# vLLM (self-host) — [`thinwrap/llm`](../../../README.md) (PHP)

First-class OpenAI-compatible provider — served by the shared `OpenAiCompatConnector` via a row in `src/Providers/Shared/Specs.php` (no per-provider connector class). It emits the identical normalized `ChatResult` / `ChatStreamDelta` / `ConnectorError` as every other connector, so switching to/from `vllm` is just the provider id + `model`.

## Quick start

```php
use Thinwrap\Llm\Chat;
use Thinwrap\Llm\DTO\Chat\ChatInput;
use Thinwrap\Llm\DTO\Chat\ChatMessage;
use Thinwrap\Llm\Enum\ChatRole;
use Thinwrap\Llm\Enum\LlmProviderId;
use Thinwrap\Llm\Providers\Shared\OpenAiCompatConfig;

$chat = new Chat(LlmProviderId::VLLM, new OpenAiCompatConfig(apiKey: getenv('VLLM_API_KEY')));
$res = $chat->complete(new ChatInput(
    model: '<served-model-name>',
    messages: [new ChatMessage(ChatRole::User, 'Say hi in one word.')],
));
```

## Configuration

`OpenAiCompatConfig`:

| Field | Type | Required | Notes |
|---|---|---|---|
| `apiKey` | `string` | No | Optional — sent as `Authorization: Bearer <apiKey>` only if set (open if the server has no `--api-key`). |
| `baseUrl` | `string` | No | Overrides the default `http://localhost:8000/v1`. |
| `headers` | `array` | No | Merged onto every request. |

Bring-your-own PSR-18 client + PSR-17 factories via the `Chat`/`Embeddings` constructor (`$client`, `$requestFactory`, `$streamFactory`); auto-discovered via `php-http/discovery` when omitted.

## Auth setup

Open by default. If the server was launched with `--api-key`, pass that value as `apiKey`; it is sent as `Authorization: Bearer <apiKey>`. When `apiKey` is empty, no auth header is sent.

## Chat

### Endpoint

`POST http://localhost:8000/v1/chat/completions` (override `baseUrl` for a remote server).

### Notes / quirks

- Self-host: override `baseUrl` for a remote server; the compat server is vLLM's first-class interface.
- Model ids are operator-defined (the HF repo id or `--served-model-name`).
- `tool_choice: 'auto'` requires the server launched with `--enable-auto-tool-choice` + a model-matched `--tool-call-parser`.

## Embeddings

Supported (served by the shared `OpenAiCompatEmbeddingsConnector`).

```php
use Thinwrap\Llm\Embeddings;
use Thinwrap\Llm\DTO\Embeddings\EmbeddingsInput;
use Thinwrap\Llm\Enum\LlmProviderId;
use Thinwrap\Llm\Providers\Shared\OpenAiCompatConfig;

$emb = new Embeddings(LlmProviderId::VLLM, new OpenAiCompatConfig(apiKey: getenv('VLLM_API_KEY')));
$res = $emb->create(new EmbeddingsInput(model: '<served-embedding-model>', input: ['hello', 'world']));
// $res->embeddings — list<list<float>>, one vector per input in input order
```

`POST http://localhost:8000/v1/embeddings` with `encoding_format: "float"`. `dimensions` maps to `dimensions` when set. Vectors are returned in input order on `$res->embeddings`.

## Error mapping & passthrough

- Non-2xx responses throw `ConnectorError` with a 7-value `providerCode` enum (`auth_failed` / `rate_limited` / `provider_unavailable` / `invalid_request` / `context_length_exceeded` / `content_filtered` / `unknown`; read the string via `$e->providerCode->value`). The raw vendor body is on `$e->cause['raw']`; retry hints (when present) on `$e->cause['retryAfter']` / `$e->cause['retryAfterSeconds']`.
- Sub-baseline features — `topK`, `seed`, `frequency`/`presence_penalty`, `logitBias`, `n`, reasoning control beyond `reasoning.effort`, prompt caching — ride through `_passthrough` (request) / `raw` (result). They are never emulated.
- Rate-limit guidance: <https://docs.vllm.ai/en/latest/serving/online_serving/openai_compatible_server/>.

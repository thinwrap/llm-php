# Anthropic (Claude) — [`thinwrap/llm`](../../../README.md) (PHP)

Native adapter over the Anthropic **Messages API** (`POST /v1/messages`). Anthropic does not speak OpenAI's Chat Completions shape, so this connector is a full translation layer — it produces the identical normalized `ChatResult` / `ChatStreamDelta` / `ConnectorError` as every other connector, so switching to/from `anthropic` is still just the provider id + `model`.

## Quick start

```php
use Thinwrap\Llm\Chat;
use Thinwrap\Llm\DTO\Chat\ChatInput;
use Thinwrap\Llm\DTO\Chat\ChatMessage;
use Thinwrap\Llm\Enum\ChatRole;
use Thinwrap\Llm\Enum\LlmProviderId;
use Thinwrap\Llm\Providers\Anthropic\AnthropicConfig;

$chat = new Chat(LlmProviderId::Anthropic, new AnthropicConfig(apiKey: getenv('ANTHROPIC_API_KEY')));
$res = $chat->complete(new ChatInput(
    model: 'claude-sonnet-4-5',
    messages: [new ChatMessage(ChatRole::User, 'Say hi in one word.')],
));
```

## Configuration

| Field | Required | Default | Notes |
|---|---|---|---|
| `apiKey` | yes | — | Sent as `x-api-key` (not `Authorization: Bearer`). |
| `baseUrl` | no | `https://api.anthropic.com/v1` | Override for proxies / gateways. |
| `anthropicVersion` | no | `2023-06-01` | `anthropic-version` header. |
| `defaultMaxTokens` | no | `4096` | Anthropic **requires** `max_tokens`; used when `maxOutputTokens` is omitted. |
| HTTP client | no | auto-discovered | BYO PSR-18 client + PSR-17 factories via the `Chat` constructor (`php-http/discovery`). |

## Auth setup

Create an API key in the Anthropic Console. It is sent as the `x-api-key` header along with `anthropic-version`. No environment inference — pass `apiKey` explicitly.

## Chat

### Endpoint

`POST <baseUrl>/messages` (default `https://api.anthropic.com/v1/messages`).

### Notes / quirks

How the normalized shape maps:

- **System message** → hoisted to the top-level `system` field (multiple system messages are concatenated).
- **`ChatRole::Tool` messages** → carried inside a `user` turn as `tool_result` blocks; consecutive tool messages coalesce into one turn (native Anthropic requirement).
- **Assistant `toolCalls`** → `tool_use` content blocks; **`tools`** → `{ name, description, input_schema }` (JSON Schema in `input_schema`, not nested under `function`).
- **`toolChoice`** → `auto`→`{type:'auto'}`, `required`→`{type:'any'}`, `none`→`{type:'none'}`, `{function:{name}}`→`{type:'tool', name}`.
- **Images** → `{ type:'image', source:{ type:'base64', media_type, data } }`.
- **`stop`** → `stop_sequences`. **`reasoning.effort`** → `thinking:{ type:'enabled', budget_tokens }` (low/medium/high ≈ 1024/4096/12288; sampling params are dropped while thinking is enabled, per Anthropic's constraint).
- **Usage** → `{ inputTokens, outputTokens, totalTokens }` (Anthropic reports no total; it is summed).
- **`stop_reason`** → `finishReason` (`end_turn`/`stop_sequence`→`stop`, `max_tokens`→`length`, `tool_use`→`tool_calls`, `refusal`→`content_filter`).

Not normalized (passthrough / raw — never emulated):

- **`responseFormat`** — Anthropic has no `response_format`; use tool-based structured output via `_passthrough`. This connector does **not** apply `responseFormat`.
- **Reasoning CoT output** — `thinking` blocks are surfaced only in `ChatResult::$raw` (D2: reasoning output is not normalized).
- **Prompt caching** — pass `cache_control` markers via `_passthrough['body']`; cached-token counts appear in `raw`.

## Embeddings

Anthropic exposes no OpenAI-float embeddings surface (its embeddings partner is Voyage), so there is no `Embeddings` support — `new Embeddings(LlmProviderId::Anthropic, ...)` throws `ConnectorError` (`invalid_request`).

## Error mapping & passthrough

- `$e->providerCode`: `401/403`→`auth_failed`, `429`→`rate_limited`, `500/502/503/529`→`provider_unavailable`, `400/404/413/422`→`invalid_request` (or `context_length_exceeded` when the message indicates a context overflow). Read the string via `$e->providerCode->value`. The raw error body is on `$e->cause['raw']`; `Retry-After` (when present) on `$e->cause['retryAfter']` / `$e->cause['retryAfterSeconds']`.
- Provider-specific request fields go through `_passthrough['body']` / `['headers']` / `['query']`; vendor response extras stay on `ChatResult::$raw`.
- Rate-limit guidance: <https://docs.anthropic.com/en/api/rate-limits>.

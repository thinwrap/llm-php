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
    model: 'claude-opus-5-5',
    messages: [new ChatMessage(ChatRole::User, 'Say hi in one word.')],
));
```

## Configuration

| Field | Required | Default | Notes |
|---|---|---|---|
| `apiKey` | yes | — | Sent as `x-api-key` (not `Authorization: Bearer`). |
| `baseUrl` | no | `https://api.anthropic.com/v1` | Override for proxies / gateways. |
| `anthropicVersion` | no | `2023-06-01` | `anthropic-version` header. |
| `defaultMaxTokens` | no | `16000` | Anthropic **requires** `max_tokens`; used when `maxOutputTokens` is omitted. Thinking counts toward it, and current models think by default, so a small value ends at `length` before any text. It is a ceiling, not a charge. |
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
- **`stop`** → `stop_sequences`. **`reasoning.effort`** → `thinking:{ type:'adaptive' }` + `output_config:{ effort }` (`low`/`medium`/`high`) on every model. Adaptive thinking has no token budget: the model reasons within `max_tokens` (the caller's `maxOutputTokens`, else `defaultMaxTokens`). `temperature` / `top_p` are not sent while `reasoning` is set. The `_passthrough['body']` merge is shallow: a passthrough `output_config` replaces the connector's whole `output_config`, effort included, so restate `effort` in it.
- **Usage** → `inputTokens` = `input_tokens` + `cache_read_input_tokens` + `cache_creation_input_tokens` (all billed input), `outputTokens` = `output_tokens`, `totalTokens` = their sum (Anthropic reports no total).
- **`stop_reason`** → `finishReason` (`end_turn`/`stop_sequence`→`stop`, `max_tokens`/`model_context_window_exceeded`→`length`, `tool_use`→`tool_calls`, `refusal`→`content_filter`; `pause_turn` and anything new → `unknown`, raw value on `raw`).
- **Streaming tool calls** → `ToolCallDelta::$index` is the tool call's position among tool calls, not Anthropic's content-block index.
- **Streaming usage** → merged field by field across `message_start` and `message_delta`, as the official SDK does: a field the delta omits or sends as `null` keeps its earlier value.

### Thinking blocks in a tool loop (`providerMetadata`)

Every result carries the response's content blocks verbatim — thinking, redacted thinking, text (with citations) and tool use, in order — as `$res->message->providerMetadata['anthropic']['content']`. A stream rebuilds the same array from its events and puts it only on the terminal delta (the one with `finishReason`). Pass it back as `ChatMessage::$providerMetadata` on the assistant turn, and the connector replays those blocks unchanged, so Claude keeps its earlier reasoning across tool calls. JSON objects keep their shape: an empty `{}` in a tool input stays `{}`.

The blocks are replayed only while they still match the message: their `tool_use` ids, in order, must equal the message's `toolCalls` ids, each `tool_use.input` must equal that call's `arguments` (compared as JSON values), and their text blocks joined must equal its text. Otherwise — or with no metadata — the turn is rebuilt from `content` + `toolCalls` without its thinking blocks, and loses its earlier reasoning.

Each thinking block's signature also covers the thinking before it, so Anthropic rejects a replayed block once an earlier one is gone (enforced on accounts with preserved thinking). The connector therefore rebuilds **every** assistant turn after the first one that is rebuilt, even if a later turn's metadata still matches. Keep `providerMetadata` on every turn of a history to keep its reasoning.

### Model rules that pass through

These are the vendor's rules. The connector neither emulates nor works around them; each surfaces as a 400 → `invalid_request`:

- **`temperature` / `top_p`** are rejected on Claude Opus 4.7 and later, on every 5.x model and on Fable (on Sonnet 5.5 and Haiku 5.5, any non-default value).
- **Forced `toolChoice`** (`required`, or a named tool) is rejected on Claude Opus 5.5, Sonnet 5.5 and Fable 5.1. Use `auto` or `none`.
- **Models before Claude 4.6** (verified on Haiku 4.5) reject adaptive thinking, and `output_config.effort` even on its own. Leave `reasoning` unset on these models and send a budget through passthrough instead — `_passthrough: ['body' => ['thinking' => ['type' => 'enabled', 'budget_tokens' => 2048]]]` — with `maxOutputTokens` above the budget and no `temperature`.

Not normalized (passthrough / raw — never emulated):

- **`responseFormat`** — Anthropic has no `response_format`; use tool-based structured output via `_passthrough`. This connector does **not** apply `responseFormat`.
- **Reasoning CoT output** — `thinking` blocks are not part of `content`; read them from `ChatResult::$raw` (or `providerMetadata`, which exists to replay them).
- **Prompt caching** — pass `cache_control` markers via `_passthrough['body']`; cached tokens count toward `inputTokens`, and the read/write split stays on `raw`.

## Embeddings

Anthropic exposes no OpenAI-float embeddings surface (its embeddings partner is Voyage), so there is no `Embeddings` support — `new Embeddings(LlmProviderId::Anthropic, ...)` throws `ConnectorError` (`invalid_request`).

## Error mapping & passthrough

- `$e->providerCode`: `401/403`→`auth_failed`, `429`→`rate_limited`, any `5xx` (incl. `529` overloaded) and `408`→`provider_unavailable`, `402 billing_error`→`rate_limited` (exhausted credit, as OpenAI and Gemini report it), every other `4xx`→`invalid_request` — including `413 request_too_large` (the 32 MB byte limit). A `400` whose message is Anthropic's context overflow ("prompt is too long…", or "…exceed context limit" on older models) → `context_length_exceeded`. A mid-stream `error` event maps by its `error.type` the same way (an unrecognised type → `unknown`); its `statusCode` is the status the stream arrived with, normally `200`. Read the string via `$e->providerCode->value`. The raw error body is on `$e->cause['raw']`; `Retry-After` (when present) on `$e->cause['retryAfter']` / `$e->cause['retryAfterSeconds']`.
- Provider-specific request fields go through `_passthrough['body']` / `['headers']` / `['query']`; vendor response extras stay on `ChatResult::$raw`.
- Rate-limit guidance: <https://docs.anthropic.com/en/api/rate-limits>.

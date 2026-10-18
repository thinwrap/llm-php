# Google Gemini (AI Studio) — [`thinwrap/llm`](../../../README.md) (PHP)

Native adapter for the Gemini `generateContent` API (`generativelanguage.googleapis.com/v1beta`). Gemini uses `contents[]`/`parts[]` (roles `user`/`model`), a top-level `systemInstruction`, and `functionDeclarations`, so this is a full translation layer emitting the identical normalized `ChatResult`/`ChatStreamDelta`/`ConnectorError`. **Streaming is real SSE** (`:streamGenerateContent?alt=sse`) — genuinely incremental, unlike the Bedrock v1 fallback.

> This is the **AI Studio** Gemini API (`gemini` provider id). Vertex AI Gemini is a separate provider (`vertex`, a future connector) with different auth (GCP OAuth/ADC) and endpoints.

## Quick start

```php
use Thinwrap\Llm\Chat;
use Thinwrap\Llm\DTO\Chat\ChatInput;
use Thinwrap\Llm\DTO\Chat\ChatMessage;
use Thinwrap\Llm\Enum\ChatRole;
use Thinwrap\Llm\Enum\LlmProviderId;
use Thinwrap\Llm\Providers\Gemini\GeminiConfig;

$chat = new Chat(LlmProviderId::Gemini, new GeminiConfig(apiKey: getenv('GEMINI_API_KEY')));
$res = $chat->complete(new ChatInput(
    model: 'gemini-3.8-flash',
    messages: [new ChatMessage(ChatRole::User, 'Say hi in one word.')],
));
```

## Configuration

| Field | Required | Default | Notes |
|---|---|---|---|
| `apiKey` | yes | — | Sent as `x-goog-api-key`. |
| `baseUrl` | no | `https://generativelanguage.googleapis.com/v1beta` | Override for proxies. |
| `defaultMaxTokens` | no | — | `generationConfig.maxOutputTokens` when `maxOutputTokens` is omitted. |
| HTTP client | no | auto-discovered | BYO PSR-18 client + PSR-17 factories via the `Chat` constructor (`php-http/discovery`). |

## Auth setup

Create an API key in Google AI Studio. It is sent as the `x-goog-api-key` header (the key is not placed in the query string). No environment inference — pass `apiKey` explicitly.

## Chat

### Endpoint

`POST <baseUrl>/models/{model}:generateContent` (non-streaming) and `POST <baseUrl>/models/{model}:streamGenerateContent?alt=sse` (streaming). The model is path-escaped. Default base URL `https://generativelanguage.googleapis.com/v1beta`.

### Notes / quirks

How the normalized shape maps:

- **System message** → top-level `systemInstruction`.
- **Roles** → `assistant`→`model`, `user`→`user`.
- **`ChatRole::Tool` messages** → `functionResponse` parts inside a `user` turn, keyed by function **NAME**. The name is resolved from the nearest prior assistant tool call with that `toolCallId`, or the message's `name`, falling back to `toolCallId`. Consecutive tool messages coalesce into one turn.
- **Tool-call ids** → Gemini's own `functionCall.id` becomes `ToolCall::$id` and is echoed back as `functionCall.id` / `functionResponse.id`. When Gemini sends none, the connector numbers the calls `call_0`, `call_1`, … within the response (stream and non-stream alike, so parallel calls to one function never share an id); those stand-ins are never sent back as ids.
- **Assistant `toolCalls`** → `functionCall` parts; **`tools`** → `[{ functionDeclarations: [...] }]` with the JSON Schema in `parametersJsonSchema` (full JSON Schema, `additionalProperties` included — the older `parameters` field takes only Google's OpenAPI subset and 400s on it).
- **`toolChoice`** → `functionCallingConfig.mode`: `auto`→`AUTO`, `required`→`ANY`, `none`→`NONE`, `{function:{name}}`→`ANY` + `allowedFunctionNames`.
- **Images** → `{ inlineData: { mimeType, data } }`.
- **Sampling** → `generationConfig` `{ maxOutputTokens, temperature, topP, stopSequences }`.
- **`responseFormat`** → **mapped** (unlike Anthropic): `json_object`→`responseMimeType: application/json`; `json_schema`→ that plus `responseJsonSchema` (JSON Schema; the deprecated `responseSchema` is not used).
- **`reasoning.effort`** → `generationConfig.thinkingConfig.thinkingBudget` (low/medium/high ≈ 1024/8192/24576).
- **Usage** → `inputTokens` = `promptTokenCount` + `toolUsePromptTokenCount`, `outputTokens` = `candidatesTokenCount` + `thoughtsTokenCount` (thoughts are billed as output), `totalTokens` = their sum (which is what Gemini's `totalTokenCount` counts). A stream reports usage once, on its terminal delta (Gemini repeats a cumulative count on every chunk).
- **`finishReason`** (google-genai's `FinishReason` enum) → `STOP`→`stop`, `MAX_TOKENS`/`CONTINUATION`→`length`, `SAFETY`/`RECITATION`/`BLOCKLIST`/`PROHIBITED_CONTENT`/`SPII`/`IMAGE_SAFETY`/`IMAGE_PROHIBITED_CONTENT`/`IMAGE_RECITATION`→`content_filter`, a `functionCall` part → `tool_calls`; everything else (`LANGUAGE`, `MALFORMED_FUNCTION_CALL`, `UNEXPECTED_TOOL_CALL`, `TOO_MANY_TOOL_CALLS`, `OTHER`, …) → `unknown`, raw value on `raw`.

### Thought signatures in a tool loop (`providerMetadata`)

Gemini 3 signs its function calls and rejects (400) a replayed `functionCall` turn that lost the signature. Each `ToolCall` (and streamed `ToolCallDelta`) carries the part's signature as `providerMetadata['gemini']['thoughtSignature']`; only the first call of a parallel batch has one. Keep the `ToolCall` objects as they came back, and the connector puts each signature back on its `functionCall` part.

If no call in an assistant turn carries a signature — a hand-built history, or one from another provider — the connector sets Google's documented bypass value `skip_thought_signature_validator` on that turn's first `functionCall` part, so the loop still runs. Other providers' `providerMetadata` keys are ignored and never sent.

Not normalized (passthrough / raw):

- **Reasoning CoT output** (`thought: true` parts) → `raw` only.
- **Prompt caching** (`cachedContent`), safety settings, and video params → `_passthrough['body']`.

## Embeddings

Gemini's native embeddings use a different (non-OpenAI-float) shape, so there is no `Embeddings` support here — `new Embeddings(LlmProviderId::Gemini, ...)` throws `ConnectorError` (`invalid_request`). A dedicated embeddings surface is a future connector.

## Error mapping & passthrough

- `$e->providerCode`: `401/403`→`auth_failed` — as is a `400` whose `ErrorInfo` reason is `API_KEY_INVALID` (Gemini's answer to a bad key) — `429`/`402`→`rate_limited`, `5xx` and `408`→`provider_unavailable`, every other `4xx`→`invalid_request`, or `context_length_exceeded` for a `400` that "exceeds the maximum number of tokens". A mid-stream `{"error": …}` frame maps by its `error.code` or `error.status` name (neither → `unknown`); its `statusCode` is the status the stream arrived with, normally `200`. Read the string via `$e->providerCode->value`. Gemini emits **no rate-limit headers** — retry timing is parsed from the 429 body's `RetryInfo.retryDelay` into `$e->cause['retryAfterSeconds']`. Raw body on `$e->cause['raw']`.
- Provider-specific request fields go through `_passthrough['body']` / `['headers']` / `['query']`; vendor response extras stay on `ChatResult::$raw`. The body merge is shallow: a passthrough `generationConfig` replaces the connector's whole object.
- Rate-limit guidance: <https://ai.google.dev/gemini-api/docs/rate-limits>.

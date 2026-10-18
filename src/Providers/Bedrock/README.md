# AWS Bedrock — [`thinwrap/llm`](../../../README.md) (PHP)

Native adapter over Amazon Bedrock's unified **Converse API** (`POST /model/{modelId}/converse`). Requests are signed with **hand-rolled AWS SigV4** using PHP's built-in `hash`/`hash_hmac` — no AWS SDK, no third-party crypto (same zero-dep approach as the notifications SES/SNS connectors). Emits the identical normalized `ChatResult` / `ChatStreamDelta` / `ConnectorError` as every other connector.

## Quick start

```php
use Thinwrap\Llm\Chat;
use Thinwrap\Llm\DTO\Chat\ChatInput;
use Thinwrap\Llm\DTO\Chat\ChatMessage;
use Thinwrap\Llm\Enum\ChatRole;
use Thinwrap\Llm\Enum\LlmProviderId;
use Thinwrap\Llm\Providers\Bedrock\BedrockConfig;

$chat = new Chat(LlmProviderId::Bedrock, new BedrockConfig(
    region: 'us-east-1',
    accessKeyId: getenv('AWS_ACCESS_KEY_ID'),
    secretAccessKey: getenv('AWS_SECRET_ACCESS_KEY'),
    // sessionToken: getenv('AWS_SESSION_TOKEN'),  // for temporary creds
));
$res = $chat->complete(new ChatInput(
    model: 'us.anthropic.claude-sonnet-5', // US cross-Region inference profile (AWS model card: Claude Sonnet 5)
    messages: [new ChatMessage(ChatRole::User, 'Say hi in one word.')],
));
```

## Configuration

| Field | Required | Default | Notes |
|---|---|---|---|
| `region` | yes | — | e.g. `us-east-1`. No environment inference. Must be a bare region name — lowercase letters and digits in hyphen-separated groups; anything else throws `invalid_request` at construction, since the region is part of the signed host. |
| `accessKeyId` / `secretAccessKey` | yes | — | SigV4 credentials. |
| `sessionToken` | no | — | STS temporary-credential token → `X-Amz-Security-Token`. |
| `baseUrl` | no | `https://bedrock-runtime.<region>.amazonaws.com` | Override origin (VPC endpoint / proxy). |
| `defaultMaxTokens` | no | `4096` | Converse `inferenceConfig.maxTokens` when `maxOutputTokens` is omitted. |
| HTTP client | no | auto-discovered | BYO PSR-18 client + PSR-17 factories via the `Chat` constructor (`php-http/discovery`). |

## Auth setup

Uses standard AWS SigV4 (service `bedrock`) hand-rolled on PHP's built-in `hash`/`hash_hmac` — no AWS SDK. Supply `accessKeyId` / `secretAccessKey` (and `sessionToken` for temporary credentials). The body is serialized once and those exact bytes are signed together with the canonical path so URL, body, and signature all agree.

## Chat

### Endpoint

`POST <origin>/model/{modelId}/converse` where `origin` defaults to `https://bedrock-runtime.<region>.amazonaws.com`. The model id is percent-encoded for the path (e.g. `...-v2:0` → `...-v2%3A0`) so the SigV4 canonical URI matches the wire URL exactly.

### Notes / quirks

How the normalized shape maps:

- **System message** → top-level `system: [{ text }]`.
- **`ChatRole::Tool` messages** → `toolResult` blocks inside a `user` turn; consecutive tool messages coalesce into one turn.
- **Assistant `toolCalls`** → `toolUse` blocks; **`tools`** → `toolConfig.tools[].toolSpec` with the JSON Schema under `inputSchema.json`.
- **`toolChoice`** → `auto`→`{auto:{}}`, `required`→`{any:{}}`, `{function:{name}}`→`{tool:{name}}`. Converse has no `none`, so `none` omits `toolConfig` (tools included) entirely. Bedrock requires a `toolConfig` whenever the history holds `toolUse` / `toolResult` blocks, so `none` after a tool loop (a history with `toolUse` / `toolResult` blocks) may get Bedrock's 400 (`invalid_request`).
- **Images** → `{ image: { format, source: { bytes } } }` (`format` derived from `mediaType`). Empty text parts are dropped: Converse rejects an empty text block.
- **Sampling** → `inferenceConfig` `{ maxTokens, temperature, topP, stopSequences }`.
- **Usage** → `inputTokens` = `inputTokens` + `cacheReadInputTokens` + `cacheWriteInputTokens` (all billed input), `outputTokens` = `outputTokens`, `totalTokens` = their sum.
- **`stopReason`** → `finishReason` (`end_turn`/`stop_sequence`→`stop`, `max_tokens`/`model_context_window_exceeded`→`length`, `tool_use`→`tool_calls`, `content_filtered`/`guardrail_intervened`→`content_filter`; `malformed_model_output`/`malformed_tool_use` → `unknown`).

v1 limitations (documented, not silent):

- **Streaming is non-incremental in v1.** Bedrock's `converse-stream` uses AWS's binary event-stream framing (`application/vnd.amazon.eventstream`), not SSE. v1 does not parse that binary protocol — `stream()` issues one Converse call and yields the full result as deltas. A true incremental binary-event-stream parser is a planned follow-up.
- **Reasoning is not auto-mapped.** Reasoning control on Bedrock is model-dependent; pass `additionalModelRequestFields` via `_passthrough['body']`.
- **`responseFormat`** is not mapped (use tool-based structured output via `_passthrough`); **prompt caching** via `_passthrough` (`cachePoint` blocks).
- **SigV4 signature is not yet pinned to an external cross-verified vector** (structural tests only); pinning a known AWS test vector is a follow-up, matching the notifications SES/SNS specs.

## Embeddings

Bedrock has no OpenAI-float embeddings surface (Amazon Titan embeddings are not a Converse/OpenAI-float shape), so there is no `Embeddings` support — `new Embeddings(LlmProviderId::Bedrock, ...)` throws `ConnectorError` (`invalid_request`).

## Error mapping & passthrough

- `$e->providerCode`: `401/403`→`auth_failed`, `429`/`402`→`rate_limited`, `5xx` plus `408` (`ModelTimeoutException`) and `424` (`ModelErrorException`)→`provider_unavailable`, every other `4xx`→`invalid_request` (a `400` is `rate_limited` when it reports throttling, and `context_length_exceeded` when the message says "input is too long", "prompt is too long", "too many input tokens", "maximum context length" or "exceed context limit" — Bedrock's own wording plus the model provider's, which Bedrock relays). Read the string via `$e->providerCode->value`. Raw body on `$e->cause['raw']`; `Retry-After` (when present) on `$e->cause['retryAfter']` / `$e->cause['retryAfterSeconds']`.
- Provider-specific request fields go through `_passthrough['body']` / `['headers']` / `['query']` (headers and query are SigV4-signed); vendor response extras stay on `ChatResult::$raw`.
- Rate-limit / quota guidance: <https://docs.aws.amazon.com/bedrock/latest/userguide/quotas.html>.

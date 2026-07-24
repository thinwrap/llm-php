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
    model: 'anthropic.claude-3-5-sonnet-20241022-v2:0',
    messages: [new ChatMessage(ChatRole::User, 'Say hi in one word.')],
));
```

## Configuration

| Field | Required | Default | Notes |
|---|---|---|---|
| `region` | yes | — | e.g. `us-east-1`. No environment inference. |
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
- **`toolChoice`** → `auto`→`{auto:{}}`, `required`→`{any:{}}`, `{function:{name}}`→`{tool:{name}}` (`none` → `toolConfig` omitted).
- **Images** → `{ image: { format, source: { bytes } } }` (`format` derived from `mediaType`).
- **Sampling** → `inferenceConfig` `{ maxTokens, temperature, topP, stopSequences }`.
- **Usage** → Converse `usage` maps directly to `{ inputTokens, outputTokens, totalTokens }`.
- **`stopReason`** → `finishReason` (`end_turn`/`stop_sequence`→`stop`, `max_tokens`→`length`, `tool_use`→`tool_calls`, `content_filtered`/`guardrail_intervened`→`content_filter`).

v1 limitations (documented, not silent):

- **Streaming is non-incremental in v1.** Bedrock's `converse-stream` uses AWS's binary event-stream framing (`application/vnd.amazon.eventstream`), not SSE. v1 does not parse that binary protocol — `stream()` issues one Converse call and yields the full result as deltas. A true incremental binary-event-stream parser is a planned follow-up.
- **Reasoning is not auto-mapped.** Reasoning control on Bedrock is model-dependent; pass `additionalModelRequestFields` via `_passthrough['body']`.
- **`responseFormat`** is not mapped (use tool-based structured output via `_passthrough`); **prompt caching** via `_passthrough` (`cachePoint` blocks).
- **SigV4 signature is not yet pinned to an external cross-verified vector** (structural tests only); pinning a known AWS test vector is a follow-up, matching the notifications SES/SNS specs.

## Embeddings

Bedrock has no OpenAI-float embeddings surface (Amazon Titan embeddings are not a Converse/OpenAI-float shape), so there is no `Embeddings` support — `new Embeddings(LlmProviderId::Bedrock, ...)` throws `ConnectorError` (`invalid_request`).

## Error mapping & passthrough

- `$e->providerCode`: `401/403`→`auth_failed`, `429`→`rate_limited`, `5xx`→`provider_unavailable`, `400/404/422`→`invalid_request` (or `rate_limited` for throttling, `context_length_exceeded` on context overflow). Read the string via `$e->providerCode->value`. Raw body on `$e->cause['raw']`; `Retry-After` (when present) on `$e->cause['retryAfter']` / `$e->cause['retryAfterSeconds']`.
- Provider-specific request fields go through `_passthrough['body']` / `['headers']` / `['query']`; vendor response extras stay on `ChatResult::$raw`.
- Rate-limit / quota guidance: <https://docs.aws.amazon.com/bedrock/latest/userguide/quotas.html>.

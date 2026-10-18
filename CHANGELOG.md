# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.1.0] — 2026-10-18

### Added

- `providerMetadata` on `ToolCall`, `ChatMessage`, `AssistantMessage`, `ToolCallDelta` and the terminal `ChatStreamDelta` carries provider state between turns.
- Gemini tool loops replay each call's `thoughtSignature`, and fall back to Google's validator bypass for unsigned histories.
- Anthropic tool loops replay the turn's thinking blocks verbatim while they still match the message.

### Changed

- **Breaking:** Anthropic `reasoning` sends adaptive thinking with `output_config.effort` instead of a token budget; models before Claude 4.6 now return their 400 and take a budget through `_passthrough`.
- **Breaking:** Bedrock `toolChoice: 'none'` omits `toolConfig` (tools included), as documented; after a tool loop Bedrock may answer 400.
- **Breaking:** Azure OpenAI `maxOutputTokens` (sent as `max_completion_tokens`) needs the v1 API or an `api-version` from `2024-09-01-preview` on.
- Anthropic's default `max_tokens` is 16000 instead of 4096, so always-thinking models stop ending at `length` with no text.
- Gemini sends tool schemas as `parametersJsonSchema` and `json_schema` output as `responseJsonSchema`, so full JSON Schema is accepted.
- Usage counts cached input on Anthropic and Bedrock, tool-use prompt and thought tokens on Gemini, and `totalTokens` is input + output on all three.
- An error inside a 2xx body or a mid-stream frame keeps the arrival status as `statusCode` and is `unknown` when it names no code or type.

### Fixed

- OpenAI and Azure OpenAI send `maxOutputTokens` as `max_completion_tokens`, so reasoning models stop rejecting it.
- A 2xx carrying an `error` (OpenRouter mid-generation failures) or missing its payload now throws instead of returning an empty success.
- Embeddings with the wrong vector count or a null/non-numeric cell now throw instead of misaligning or coercing to `0.0`.
- Mistral reasoning responses no longer drop the answer when `content` arrives as a list of chunks.
- API keys, AWS credentials and request headers are `#[\SensitiveParameter]`, so they stay out of exception stack traces.
- A header value with CR, LF or NUL (a key with a stray newline) raises `invalid_request` before the request is built, with any PSR-7 library.
- Ordinary 400s from Anthropic, Gemini and Bedrock are no longer misread as `context_length_exceeded`.
- Gemini `API_KEY_INVALID` maps to `auth_failed`.
- Current stop reasons (`model_context_window_exceeded`, `pause_turn`, Gemini's full `FinishReason` enum) map to the closest finish reason.
- 402 (exhausted credit) maps to `rate_limited` and 408 to `provider_unavailable` on every connector, other unlisted 4xx to `invalid_request`, Bedrock 424 to `provider_unavailable`, and mid-stream error events by their type.
- A stream that is not `text/event-stream`, or that ends before the provider's terminal event, throws `provider_unavailable` instead of ending silently.
- `_passthrough['query']` reaches the wire on Anthropic, Gemini, Bedrock (signed) and embeddings.
- Mistral embeddings send `dimensions` as `output_dimension`.
- Bedrock rejects a `region` that is not a bare region name, and drops empty text parts it would reject.
- Anthropic streamed `ToolCallDelta::$index` counts tool calls, not content blocks.
- Gemini synthetic tool-call ids are unique per call, and Gemini's own ids are echoed back.
- A single CRLF inside an SSE event no longer splits it, which dropped CRLF multi-line events.
- `Retry-After` accepts only whole seconds or an RFC 9110 HTTP-date; `1.5`, `-5` or `tomorrow` are ignored instead of read as a delay.
- A missing PSR-17 factory raises `ConnectorError` instead of a discovery exception.
- An empty JSON object (`{"opts":{}}`) in tool arguments or a replayed Anthropic turn stays `{}` instead of becoming `[]`.
- Anthropic replays a turn's thinking only while its tool arguments still match, and rebuilds every later turn once one is rebuilt, which signed thinking requires.
- Anthropic stream usage merges field by field, so a `message_delta` without cache counts no longer drops them.
- Anthropic streams keep citations on the rebuilt text blocks and put `providerMetadata` only on the terminal delta.
- OpenAI-compatible errors mentioning `content_policy` or `moderation` map to `content_filtered`.
- Embeddings classify in-body string codes (`insufficient_quota`) as chat does.
- `print_r()` / `var_dump()` of a config, connector or facade shows `[redacted]` for credentials.
- Release signatures cover a `git archive` of the verified commit, uploaded next to them.

### Documentation

- `_passthrough['body']` is a shallow merge, not the deep merge the README claimed.
- Guzzle buffers streams unless built with `stream => true`.
- OpenAI and Azure docs: current GPT-5.x models reject function tools with reasoning on Chat Completions; `reasoning_effort: "none"` via passthrough is the workaround.

## [1.0.1] — 2026-08-08

### Fixed

- A PSR-18 client that throws on a non-2xx no longer collapses every provider error into
  `provider_unavailable` with no status — for an LLM caller, a rate limit that read as an outage.

### Documentation

- The PSR-18 non-2xx contract is now stated in the README, not only in `.ai/`.

## [1.0.0] — 2026-07-24

### Added

- Initial release of `thinwrap/llm` (PHP): a `Chat` facade (`complete` + streaming
  `stream`) and an `Embeddings` facade over 18 providers behind one normalized
  `ChatInput` / `ChatResult` / `ChatStreamDelta` surface, with a single typed
  `ConnectorError`.
- Fifteen first-class OpenAI-compatible providers share one connector driven by a
  per-provider spec registry; three natives — Anthropic (Messages), Bedrock
  (Converse, SigV4), Gemini (generateContent) — have bespoke wire adapters that
  emit the identical normalized shapes.
- No vendor SDKs: bring-your-own PSR-18 client, with `php-http/discovery` as the
  only runtime dependency (auto-wires a client when none is injected); Bedrock
  SigV4 is hand-rolled on the standard library.

[1.0.0]: https://github.com/thinwrap/llm-php/releases/tag/v1.0.0

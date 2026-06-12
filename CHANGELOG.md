# Changelog

All notable changes to `meirdick/laravel-cf-workersai` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.0] - 2026-06-11

laravel/ai `^0.8` support and alignment with the SDK's current conventions. Verified against laravel/ai v0.7.0 (Laravel 12) and v0.8.1 (Laravel 13) — no contract changes between those versions touch this package's surface.

### Added

- **laravel/ai `^0.8` support.** The composer constraint is now `^0.7 || ^0.8`. With `^0.8`, MCP client/server tools returned from an agent's `tools()` work through this provider automatically — laravel/ai's `GeneratesText::resolveTool()` wraps them into `Tool` instances, which the gateway's generic tool mapping already serializes.
- **Streamed usage is summed across tool-call steps.** The final `StreamEnd` event now carries `Usage` accumulated over every step of a streamed tool-call loop instead of only the last step, matching laravel/ai's accumulation direction for multi-step streams (Bedrock-style). The truncation heuristic still evaluates only the current step's completion tokens so accumulation cannot misreport `FinishReason::Length`.
- **`502` and `504` now map to `ProviderOverloadedException`** (in addition to `503`) once the retry policy is exhausted, since Cloudflare's edge surfaces transient capacity problems as gateway errors. This makes laravel/ai failover react to them.

### Fixed

- **Transfer timeouts are no longer retried.** The retry policy treated cURL 28 transfer timeouts ("Operation timed out after Xms") as retryable, so a request that exceeded the configured timeout was re-run twice more — turning a 60s timeout into ~3 minutes of wall time before failing. Timed-out requests now fail after a single attempt; connect-phase failures (refused, DNS, reset, connect timeout) and transient 502/503/504 responses remain retried with backoff. Verified live: a 3s timeout now fails in ~3.0s instead of ~10.5s.
- **A forced `tool_choice` is relaxed to `auto` on tool-result follow-up turns.** Re-sending `tool_choice: required` (or a forced specific function) after tool results forces the model to call a tool again instead of producing the final answer, looping until max-steps and returning empty text (verified live on llama-4-scout). `none` is preserved.
- **A trailing all-zero usage chunk no longer erases real streamed usage.** The live Workers AI endpoint reports usage on the finish chunk, then emits a usage-only chunk whose counts are all zero (observed against the production API, 2026-06-11). The previous last-write-wins assignment zeroed out the stream's `Usage`; an all-zero payload now only sticks when no usage was captured yet.
- **Credentials documented as `api_key` never worked.** The HTTP client reads laravel/ai's canonical `key` credential, but the README and test harness showed `api_key` — following the docs produced an undefined-array-key error at runtime. `WorkersAiProvider` now resolves `key` first (the convention every first-party provider uses), falls back to `api_key`, and throws an actionable `AiException` naming the fix when neither is set. Docs now show `key`.

### Changed

- **Default smartest text model bumped to `@cf/moonshotai/kimi-k2.6`** (was `kimi-k2.5`, which no longer appears in the Workers AI catalog — it still serves today, but the listed successor is the safe default for `#[UseSmartestModel]`). Override via `models.text.smartest` in the provider config.
- `processTextStream()` and `handleStreamingToolCalls()` gained an optional trailing `?Usage $accumulatedUsage` parameter. Subclasses overriding these protected hooks may need to update.
- Dev dependencies widened (`orchestra/testbench ^10 || ^11`, `pestphp/pest ^3 || ^4`) so the suite runs on Laravel 12 and 13.
- Added an opt-in live integration suite (`tests/Integration`) that exercises text, streaming, embeddings, and the `api_key` fallback against the real Workers AI API — directly and through AI Gateway. Skipped unless `WORKERS_AI_E2E_TOKEN`/`WORKERS_AI_E2E_ACCOUNT` (and `WORKERS_AI_E2E_GATEWAY` for the gateway tests) are set.
- Added a live stress sweep (`WORKERS_AI_E2E_STRESS=1`) repeatedly exercising the production failure modes: silently-empty responses, budget truncation flagged as `Length`, structured output validity, reasoning models under tight budgets, tool-call loops, long streams, and fail-fast timeouts — direct and through AI Gateway.
- README: documented tool-calling model support on Workers AI (llama-3.3-70b never tool-calls on `/v1`; use llama-4-scout or gpt-oss-120b with `tool_choice: required`) and timeout guidance for slow models (`#[Timeout]`).

## [0.2.0] - 2026-05-25

Defuses two Cloudflare `/v1/chat/completions` footguns that quietly truncate structured output.

### Added

- **`default_max_tokens` provider config** (defaults to `4096`). Cloudflare's `/v1/chat/completions` defaults to **256 tokens** when `max_completion_tokens` is omitted — far too small for any non-trivial structured output. The package now sends `4096` by default. Set the value in your `config/ai.php` provider block to override, or `null` to fall back to Cloudflare's endpoint default. Per-call `#[MaxTokens]` (or `TextGenerationOptions::$maxTokens`) still takes precedence.
- **Truncation heuristic in finish-reason mapping.** Cloudflare misreports truncated completions as `finish_reason: "stop"` when it should be `"length"`. When `completion_tokens` is at or above the requested budget, the package now normalizes the reason to `FinishReason::Length` so laravel/ai's length-aware retry / continuation primitives can react. Applies to both the non-streaming response path and the streaming `StreamEnd` event.

### Changed

- `extractFinishReason()` signature gained two optional parameters (`?int $completionTokens`, `?int $requestedMaxTokens`). Subclasses overriding this protected hook may need to update.

## [0.1.0] - 2026-05-25

Initial release. Native `laravel/ai` gateway for Cloudflare Workers AI with AI Gateway support.

### Added

- Native `laravel/ai` gateway for Cloudflare Workers AI (text, embeddings, structured output, tools, streaming).
- AI Gateway routing via `account_id` + `gateway` config.
- Direct Workers AI API routing via `account_id` only.
- Raw `url` escape hatch for `/compat` endpoints or custom Cloudflare paths.
- Reasoning content replay across tool-call turns via `providerContentBlocks`.
- Strict JSON schema opt-in via the `#[Strict]` attribute (laravel/ai v0.7+).
- Provider options pass-through for both text generation and embeddings.
- Sub-agent tools via `CanActAsTool` — dynamic names resolved through `ToolNameResolver`.
- Retry policy with exponential backoff for transient 5xx errors.
- Session affinity for cached AI Gateway responses.
- Image attachment support on vision-capable endpoints (`image/jpeg`, `image/png`, `image/gif`, `image/webp`).
- Provider keys: `workers-ai` (primary) and `workersai` (alias).

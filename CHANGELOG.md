# Changelog

All notable changes to `meirdick/laravel-cf-workersai` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Ships as `dev-main` (branch alias `1.0.x-dev`) until laravel/ai tags 1.0; this package tags 1.0.0 then.

Audit against laravel/ai's `1.x` branch (9de5156, 103 commits past v0.11.2, no 1.0 tag yet). Nothing this package works around is fixed upstream: the loop still never branches on `FinishReason::Length`, `reasoning_effort` still appears nowhere, `Usage` and `Meta` still have no extensible field, 429 is still not retried, and 408 still falls through as a raw `RequestException`. The existing suite passed unchanged on `1.x-dev`. What follows aligns the package with the upstream additions that do touch a gateway.

### Added

- **`laravel/ai ^1.0` is allowed** and the CI matrix runs `1.x-dev` alongside `^0.9`, `^0.10` and `^0.11`.
- **The `headers` connection key is honoured.** laravel/ai 0.10.3 gave every provider a `headers` array; 1.x's `Provider::withHeaders()` and the `ai_sdk_extra_headers` provider option write into it. This client never read it, so a configured header, and on 1.x every per-call header, was silently dropped. Configured headers now layer over the package's own with the same rule as laravel/ai's `CreatesClient`: case-insensitive names, last writer wins. Sent on chat, streaming and embeddings.
- **`StepResponse::$reasoning` is filled** on laravel/ai 1.x (PR #975), on both the non-streaming and streaming paths, from the same field the package already replays as `reasoning_content`. Guarded by `property_exists()` so earlier versions are unaffected.
- **Per-call provider options** from 1.x's `TextGenerationOptions::withProviderOptions()` reach the request body. No code change was needed; the package already reads `$options->providerOptions()`, which 1.x merges. Noted here so nobody goes looking.

### Changed

- **`Usage::$promptTokens` now excludes cached tokens.** laravel/ai 0.11.1 redefined the field on every OpenAI-shaped provider as the uncached count, with `cacheReadInputTokens` holding the cached remainder (laravel/ai#909, #924). The package reported the wire `prompt_tokens` unchanged, so a consumer switching from the built-in driver saw prompt counts jump by the cache hit. The split now matches on every supported laravel/ai version, clamped at zero. `UsageTokens::rawPromptTokens()` returns the wire figure, and `WorkersAiUsageReported::$promptTokens` follows `Usage`. Guess: Cloudflare's `prompt_tokens` includes cached tokens, as OpenAI's does. Not measured live.
- **Array tool results are encoded with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`**, matching laravel/ai 1.x's `ToolResult::text()` (PR #997), which is used directly where it exists. A model no longer reads `https:\/\/` inside a replayed MCP result.
- **`reasoning_tokens` is also read from `completion_tokens_details`**, where laravel/ai's OpenAI-compatible gateway reads it. The top-level key still wins.

### Verified

- laravel/ai v0.9.1: 200 passed, 49 skipped. v0.10.3: 203 passed, 46 skipped. v0.11.2: 203 passed, 46 skipped. `1.x-dev` at 9de5156: 206 passed, 43 skipped.

## [0.8.2] - 2026-09-09

### Added

- **A complete status-code matrix test.** Every HTTP status the gateway realistically returns is now asserted for both the exception it becomes and whether it was retried: 400, 401, 402, 403, 404, 408, 410, 413, 429, 500, 502, 503, 504, 520, 522, 524. Previously the six transient codes were covered only as a retry *decision* in `RetryPolicyTest`, while the end-to-end mapping was asserted for just 408, 429, 503 and the three Cloudflare edge codes — 402 was a comment rather than a test, and 502 and 504 were never exercised through the gateway at all. Two lists have to agree for failover to work (`RetryPolicy::RETRYABLE_STATUSES` and `WorkersAiGateway::overloadedStatusCodes()`), they drifted apart once already in 0.7.0, and a passing suite did not notice.
- Companion assertions that every transient failure carries laravel/ai's `FailoverableException` marker — a mapping with the right class but no marker fails over silently, which is to say not at all — and that a transient status which clears on the next attempt never reaches the caller.

## [0.8.1] - 2026-09-09

### Fixed

- **The HTTP response was not carried onto the step, so `$response->raw` was always null.** laravel/ai's own OpenAI and OpenAI-compatible gateways both call `withRawResponse()`; this one never did. `StepResponse::$raw` is the only route to anything the SDK's typed response objects have no field for — including Cloudflare's `usage.neurons` and the transfer stats used for per-call latency. A consuming application reading `$response->raw?->json('usage.neurons')` got null and recorded a cost of zero, which is a worse failure than an error because nothing looks wrong. Found while migrating a real pipeline onto the package, which had been reading exactly that off the built-in driver. Each step of a tool loop now carries its own response.

  `StepResponse` only gained `HasRawResponse` after laravel/ai 0.9, so the call is guarded by `method_exists()` rather than assumed — on `^0.9` the raw response stays unavailable, as it was before 0.8.1, instead of every call fataling on an undefined method. Verified against v0.9.1, v0.10.3 and v0.11.2.

## [0.8.0] - 2026-09-09

Closes the two gaps a consuming project hit in production and this package previously only documented: HTTP 408 on long generations and HTTP 429 under fan-out. Adds a third that the same investigation uncovered.

### Added

- **HTTP 429 is now retried with `Retry-After`-aware backoff.** laravel/ai maps a 429 to `RateLimitedException` and marks it failoverable, but never retries it — so under a wide fan-out one 429 is one lost request, which is exactly what the reporting project measured (roughly ten of twenty-eight in the same second). The package now backs off exponentially (500ms, 1s, 2s…, capped at 20s) and honours a `Retry-After` header when present, in both its legal forms — delay-seconds and HTTP-date. A past date clamps to zero; a delay longer than the cap is clamped rather than parking a worker for minutes. Only once the attempts are spent does it surface as `RateLimitedException` for failover. Set `retry_rate_limited => false` to restore fail-fast.
- **`retry_attempts` provider config** (default `3`, counting the first attempt). `retry => false` still disables retrying entirely.
- **`GatewayTimeoutException` for HTTP 408.** A gateway 408 means the request reached Cloudflare and the *generation* outran the gateway's patience — not a network failure, which arrives as cURL 28. It previously fell through laravel/ai's failover mapping as a raw `RequestException` naming nothing useful. It now throws a named exception that implements `FailoverableException`, so a configured fallback provider gets a chance. It is still attempted exactly once: the generation that took 709 seconds takes 709 seconds on the retry too.

### Fixed

- **Cloudflare's own edge error codes were being dropped.** `overloadedStatusCodes()` returned `[502, 503, 504]` — *narrower* than laravel/ai's own `[502, 503, 504, 520, 522, 524]`. A Cloudflare provider was silently excluding Cloudflare's 520 (unknown error), 522 (connection timed out) and 524 (origin timeout), leaving them unretried and unfailoverable. Every request here crosses Cloudflare's edge twice, once to the gateway and once to the model runner, so those codes are more likely for this provider than for a generic OpenAI-compatible one. The list now derives from `RetryPolicy::RETRYABLE_STATUSES` so the retry set and the failover set cannot drift apart again.
- **CI was red on six of nine jobs at v0.7.0.** `streaming error event stops stream` asserted laravel/ai 0.11's `StreamErrorException` behaviour (laravel/ai#870), which 0.9 and 0.10 do not have. Latent since v0.6.1, which updated the assertion while the CI matrix still named `laravel/ai ^0.7 || ^0.8` — versions the package could no longer resolve, so those jobs never reached a test. Correcting the matrix in v0.7.0 exposed it. The assertion is now version-gated. Tests are export-ignored, so the published v0.7.0 distribution was never affected.

### Changed

- `RetryPolicy::defaults()` takes `retryRateLimited` and `attempts` parameters and now returns a backoff *closure* rather than a fixed integer delay, matching Illuminate's `PendingRequest::retry()` signature. `RetryPolicy::shouldRetry()`, `backoffMilliseconds()` and `retryAfterMilliseconds()` are public so a subclass or a consuming app can reuse the decisions.
- Verified against `laravel/ai` v0.9.1, v0.10.3 and v0.11.2: 161 tests pass on each.

## [0.7.0] - 2026-09-09

Re-measures the package's Cloudflare assumptions against a live AI Gateway and corrects the ones that were wrong, then adds the two controls the measurements argued for: `reasoning_effort` and failure on a non-answer. Verified against `laravel/ai` v0.11.2.

Every measurement cited below was taken on 2026-09-09 against Cloudflare AI Gateway `/compat` on a Workers AI account.

### Added

- **`reasoning_effort` is now first-class.** Cloudflare's `/compat` endpoint accepts OpenAI's `reasoning_effort`, and nothing in laravel/ai sends it (the string does not appear anywhere in v0.11.2). Left unset, Workers AI's reasoning models over-think badly: on `@cf/openai/gpt-oss-120b`, one two-sentence question cost 1,564 reasoning characters and 7.4s at `high` against 20 characters and 1.4s at `low` — a 5x latency difference on one field, with no loss of answer quality. Set it per provider with `reasoning_effort => 'low'`, or per agent with the new `#[ReasoningEffort]` attribute; an explicit value from `providerOptions()` beats both. Only `low`, `medium` and `high` are accepted — Cloudflare answers 400 for OpenAI's `none` and `minimal`, so the package rejects them at the call site.
  - Not universally honoured, and the README now says so per model: `gpt-oss-120b` grades it correctly, `glm-5.3-flash` treats any value as "off", and `glm-4.7-flash` ignores it (`low` produced *more* reasoning than unset, 6,012 vs 4,003 characters, both ~29s).
- **Empty-response detection, on by default.** A reasoning model that spends its whole completion budget thinking returns HTTP 200 with `content: null` — reproduced on `@cf/openai/gpt-oss-120b` under a 16-token cap. laravel/ai's `TextGenerationLoop` has no opinion about it: `$response->text` is `''`, a structured `toArray()` is `[]`, no exception. A step that returns neither text nor tool calls now throws `Meirdick\WorkersAi\Exceptions\EmptyResponseException`. Set `throw_on_empty_response => false` for the old pass-through. A tool-calling turn with no text is exempt.
- **Opt-in truncation failure.** `throw_on_truncation => true` turns any `FinishReason::Length` into `Meirdick\WorkersAi\Exceptions\TruncatedResponseException`. Off by default, because partial data is sometimes wanted and the finish reason is now known to be accurate enough to branch on yourself. Both exceptions extend `Laravel\Ai\Exceptions\AiException`, so existing catch blocks already cover them.
- **Cloudflare's `neurons` figure is surfaced.** `/compat` returns a per-call `usage.neurons` — the unit Cloudflare actually meters — on every endpoint shape. `Laravel\Ai\Responses\Data\Usage` is five fixed int counters with no extensible field and `Meta` has no arbitrary bag, so there is nowhere in the SDK's response objects to put it. The new `Meirdick\WorkersAi\Events\WorkersAiUsageReported` event carries it, one per model call. Anything costing Workers AI from token counts alone is costing the wrong number.
- **Authenticated Gateway support.** A Cloudflare AI Gateway created with `authentication: true` requires a gateway-issued token in `cf-aig-authorization` alongside the Workers AI token in `Authorization`. The package could not send that header at all, so an authenticated gateway was unusable. Set `gateway_token` in the provider config; omit it and the header is not sent.
- `HANDOFF.md`: the end-to-end brief for someone picking the package up cold, including an honest account of when to use laravel/ai's built-in `openai-compatible` driver instead.

### Fixed

- **The AI Gateway config could fail on embeddings.** `account_id` + `gateway` resolved to `.../<gw>/workers-ai/v1`. On a gateway with **Authenticated Gateway** enabled, that path answers `401 Authentication error` on `/embeddings` while chat completions on the same gateway succeed; two different API tokens behaved identically, so it tracks the gateway's configuration rather than the credential. On a gateway with the setting off, the provider path served embeddings fine. Since `/compat` worked on every gateway and token combination tested, the gateway shape now resolves to it. Set `gateway_path => 'workers-ai/v1'` to opt back in.
- **A bare model ID on `/compat` is no longer rejected.** `ModelPrefix` threw an exception for `@cf/...` without the `workers-ai/` prefix on a `/compat` endpoint. Measured: a bare ID posted to `/compat` returns 200 for both chat and embeddings — the package was refusing a working configuration. Bare IDs are now normalized to the prefixed form instead, so routing on the multi-provider endpoint stays explicit and callers keep writing bare `@cf/...` everywhere. The reverse direction is a genuine mismatch (`400 No such model workers-ai/@cf/...` on the direct API) and still throws.
- **`#[UseCheapestModel]` pointed at a deprecated model.** `cheapestTextModel()` defaulted to `@cf/meta/llama-3.1-8b-instruct`, which Cloudflare now answers with `410 Model has been deprecated` — every `#[UseCheapestModel]` call failed. The default is now `@cf/meta/llama-3.2-3b-instruct`. Override with `models.text.cheapest`.
- **The CI matrix tested versions the package no longer supports.** It ran `laravel/ai ^0.7 || ^0.8` while composer required `^0.9 || ^0.10 || ^0.11`, so the build proved nothing. Now `^0.9`, `^0.10`, `^0.11`.
- `.wrangler/cache/wrangler-account.json` is no longer tracked, and `.wrangler/` is now git-ignored and export-ignored. It carried a Cloudflare account ID and account name into every Composer dist archive. It is not a credential, but it does not belong in a public package. Tags already published still contain it; only future releases are affected.

### Changed

- **BREAKING: the `stop`-at-budget truncation heuristic is gone.** Through 0.6.1 the package believed Cloudflare misreported truncation as `finish_reason: "stop"` and coerced it to `FinishReason::Length` when completion tokens reached the requested budget. Re-measured, that is not what happens: `@cf/meta/llama-3.3-70b-instruct-fp8-fast`, `@cf/openai/gpt-oss-120b` and `@cf/zai-org/glm-5.3-flash` each returned `finish_reason: "length"` under a 16-token cap, and so did every model truncated at Cloudflare's 256-token default. **Truncation is reported correctly.** The coercion was redundant and could misfire on a model that legitimately finished on its last budgeted token — which now matters, because `throw_on_truncation` turns a `Length` finish into an exception. The raw reason is trusted on both the non-streaming and streaming paths. `extractFinishReason()` keeps its two trailing parameters so subclass overrides still compile; they are ignored.
- **BREAKING: `throw_on_empty_response` defaults to `true`.** A silently empty answer is the worst failure mode in an unattended pipeline, so it is a failure by default. Set it to `false` to restore 0.6.1 behaviour.
- **BREAKING: the `gateway` config resolves to `/compat`.** See Fixed. Model IDs are prefixed automatically, so agent code does not change; a consumer pinning the old URL should set `gateway_path => 'workers-ai/v1'`. Verified against four consuming applications before release — see Notes.
- The 256-token default is documented more precisely. Verified: with no cap, `llama-3.3-70b-instruct-fp8-fast` and `gpt-oss-120b` both stop at exactly 256 completion tokens with `finish_reason: "length"`. The cap is **not** universal — `glm-5.3-flash` with no cap ran to 8,190 tokens over 369 seconds, which is a second reason to always send a budget.
- `chat_template_kwargs.thinking` is documented as model-specific rather than the general reasoning switch. Verified on `@cf/zai-org/glm-5.3-flash`: with the flag set false, reasoning still came back at 1,172 characters against 1,240 unset. It works on the Kimi chat template; `reasoning_effort` is the general mechanism. The 2,048-token completion floor it triggers is unchanged.
- HTTP 408 is documented as explicitly non-retryable. Reproduced: `glm-5.3-flash` with `max_tokens: 24000` returned `408 Request timeout` after **709 seconds**. Retrying costs another 709 seconds to reach the same failure. This was already the behaviour; it is now deliberate and tested.
- README gained a "Do you need this package?" section that says plainly when laravel/ai's built-in `openai-compatible` driver is the better choice, and an "Operational limits" section for the failures the package documents but does not solve.
- Reasoning field names are documented per model (`reasoning` vs `reasoning_content` vs both vs neither). Normalization behaviour is unchanged — this is documentation catching up to the code.

### Notes

- **`#[CacheInstructions]` is inert for this provider.** Confirmed at source in laravel/ai v0.11.2: the attribute is resolved onto `TextGenerationOptions` for every provider, but consumed only by `Gateway\Anthropic\Concerns\BuildsTextRequests` and `Gateway\Bedrock\BedrockTextGateway`. No OpenAI-compatible provider reads it. Workers AI's prefix cache is driven by `session_affinity`; its hits arrive in `usage.prompt_tokens_details.cached_tokens`, already mapped to `Usage::$cacheReadInputTokens`.
- **HTTP 429 under wide fan-out was not reproduced.** 28 and then 40 concurrent small requests to one account each returned all-200 with no rate limiting. Rate limits are account- and model-dependent; treat any specific concurrency width as folklore.
- `@cf/zai-org/glm-4.7-flash` returns `content` normally, contrary to earlier notes. Its problem is that it ignores `reasoning_effort` and takes ~29 seconds regardless.
- **Verified against four consuming applications** before release, each compared to its own pre-upgrade baseline: two on `laravel/ai ^0.7` + package `^0.2` (full migration), one on `^0.9` + `^0.6` (package-only bump, laravel/ai held at 0.9.0 to exercise the lower bound), one on `^0.11` + `^0.6.1`. No test-count or assertion-count change attributable to this package in any of them. The one app that did regress did so on the laravel/ai 0.9 → 0.11 jump alone — its `agent_conversations` table lacks the `participant_type` column laravel/ai 0.11 expects — and reproduces identically with this package pinned back to 0.6.1. See "Upgrading to 0.7.0" in the README.
- **The embeddings 401 is gateway-scoped, not path-scoped.** An earlier draft of these notes claimed the provider path "cannot serve embeddings". It can — on a gateway without Authenticated Gateway. The corrected claim is the narrower one above. The exact enforcement mechanism is unresolved: an authenticated gateway also rejects `/compat` requests missing `cf-aig-authorization`, so it is evidently not applied uniformly across sub-paths.

## [0.6.1] - 2026-08-26

### Changed

- Widened the composer constraint to `laravel/ai ^0.9 || ^0.10 || ^0.11`. The gateway contract is unchanged across these releases; the one behaviour change is laravel/ai#870 — a stream that ends on an error event now throws `StreamErrorException` instead of ending the run silently.

## [0.6.0] - 2026-07-10

Migrates the package from laravel/ai `^0.8` to `^0.9`. (The constraint was later widened to `^0.9 || ^0.10 || ^0.11` in 0.6.1.) For `^0.7 || ^0.8` stay on `^0.5` of this package.

### Changed

- **`WorkersAiGateway` now implements `StepTextGateway`** (laravel/ai 0.9 removed the `TextGateway` contract). The gateway performs exactly one model turn per call via `generateTextStep()` / `generateStreamStep()`; `generateText()` / `streamText()` are gone. If you called the gateway directly, go through the provider's `textGenerationLoop()` instead (`WorkersAiProvider` gets it from `HasTextGateway`).
- **The multi-step tool loop moved to laravel/ai core.** Tool invocation, tool-result message replay, step accumulation, cross-step streamed-usage summation, and the final `StreamEnd` event are now owned by `Laravel\Ai\Gateway\TextGenerationLoop`. The package-side recursion in `ParsesTextResponses` (`processResponse` / `continueWithToolResults` / `executeToolCalls`) and `HandlesTextStreaming` (`handleStreamingToolCalls`, follow-up requests, `StreamEnd` emission) was deleted. Everything Cloudflare-specific survives inside the single step:
  - AI Gateway / direct base-URL routing, `@cf/` model-name validation, and the `x-session-affinity` header;
  - Cloudflare error envelopes (OpenAI shape + AI Gateway shape) on the text, streaming, and embeddings paths;
  - the 4096-token `default_max_tokens` guard and the truncation heuristic (`stop`-at-budget → `FinishReason::Length`), which still judges each step's own completion tokens so loop-level accumulation cannot misreport `Length`;
  - reasoning-model support: `reasoning` / `reasoning_content` capture (now returned through `StepResponse::$providerContentBlocks`, which the core loop replays into follow-up assistant turns), streaming reasoning events, and the 2048 thinking-token floor;
  - the streamed tool-call accumulator and the trailing all-zero usage chunk tolerance;
  - the retry policy and the 502/503/504 → `ProviderOverloadedException` mapping;
  - a forced `tool_choice` is still relaxed to `auto` on follow-up turns, now keyed off `StepContext::$stepNumber`.
- **The structured-output validate + bounded re-ask now runs inside `generateTextStep()`.** A re-ask is a same-step retry against Workers AI's best-effort JSON mode — not a tool step — so it no longer consumes the loop's step budget. `structured_output_retries` config semantics are unchanged.
- Structured output is decoded with core's `DecodesStructuredOutput`, so a JSON response wrapped in markdown code fences now parses instead of yielding an empty object.
- `WorkersAiProvider::textGateway()` returns `StepTextGateway` (was `TextGateway`).
- Embeddings tests/docs use `withProviderOptions()` (laravel/ai 0.9 renamed the embeddings builder method from `providerOptions()`).

### Behavior notes (inherited from laravel/ai 0.9's loop)

- A tool call naming a tool the agent has not registered now throws `NoSuchToolException` (the old package-side loop skipped it silently).
- When a streamed tool-call loop exhausts its step budget, the final `StreamEnd` reports the real finish reason (`tool_calls`) instead of a synthesized `stop`.
- `Agent::fake()` responses run through the real `TextGenerationLoop` (see laravel/ai's UPGRADE.md for the four test-visible differences).

## [0.5.0] - 2026-06-12

### Added

- **Structured-output validate + bounded re-ask.** Workers AI's JSON mode is best-effort — it does not guarantee the response satisfies the requested schema. The gateway now validates each structured response (missing/empty required fields, out-of-enum values) and, on failure, feeds the validation error back and re-asks the model, bounded by the new `structured_output_retries` provider config (default `2`, set `0` to disable). Truncated (`Length`) responses are not re-asked since a token-budget problem cannot be fixed by asking again.

## [0.4.0] - 2026-06-12

Hardens reasoning-model support so thinking-capable models (Kimi K2.6, QwQ, Gemma) behave predictably. Reasoning stays controlled through `HasProviderOptions` — the laravel/ai convention for Anthropic `thinking` / Gemini `thinkingConfig` — so there is no new attribute to learn; these are robustness fixes for when it is enabled.

### Added

- **Reasoning-aware completion-token floor.** When an agent enables reasoning (`chat_template_kwargs.thinking => true`) but the resolved `max_completion_tokens` is below `2048`, the budget is raised to that floor (never lowered) so the model isn't starved of answer tokens after spending its budget thinking. Verified live on Kimi K2.6: a tight budget returns `content: null` / `finish_reason: "length"`. A `Log::warning` records each adjustment.
- **README "Reasoning models" section** documenting the `HasProviderOptions` convention for Workers AI, when to disable reasoning (structured output, extraction) versus enable it (free-form judgment), and the token-floor / timeout pairing.

### Fixed

- **Kimi K2.6 `reasoning` response field is now captured on the non-streaming path.** K2.6 renamed the reasoning field from `reasoning_content` to `reasoning`; Cloudflare's `/compat` layer has surfaced both across model versions. The text-response parser now accepts either and normalizes to the canonical `reasoning_content` block that `MapsMessages` replays on tool-call follow-up turns — so multi-step tool loops with K2.6 keep their chain of thought. The streaming path already handled both fields; this brings the non-streaming path to parity.

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

# Handoff — meirdick/laravel-cf-workersai

For someone competent who has never seen this code. It assumes you know Laravel and have read `laravel/ai`'s README, and nothing else.

Everything stated as a measurement here was taken against a live Cloudflare AI Gateway on a Workers AI account, on the date given. Where an earlier version of this package's documentation said something different, the correction is called out.

## 1. What this is

A `laravel/ai` provider (`workers-ai`, alias `workersai`) for Cloudflare Workers AI. It implements `Laravel\Ai\Contracts\Gateway\StepTextGateway` and `EmbeddingGateway`, so text generation, streaming, tool calling, structured output and embeddings all go through laravel/ai's normal surfaces — `agent()`, `Embeddings::for()`, `#[MaxTokens]`, `#[Timeout]`, `Ai::fakeAgent()` and the rest work unchanged.

The multi-step tool loop is **not** in this package. Since 0.6.0 it lives in laravel/ai's `TextGenerationLoop`; this gateway performs exactly one model turn per call. Everything Cloudflare-specific happens inside that one turn.

## 2. When to use it, and when not to

laravel/ai ships an `openai-compatible` driver. Point it at a Cloudflare AI Gateway `/compat` URL and it works. If you generate text from one model and your agents carry `#[MaxTokens]`, use that and stop reading.

**A real consuming project evaluated switching to this package and decided against it.** Their reasoning was sound and you should understand it before you assume the package is the answer:

- The failure they cared about most — truncation — is already detectable through the built-in driver, because Workers AI reports `finish_reason: "length"` correctly (see §5). They wrote a dozen lines to check it themselves and got what they needed.
- The failures they actually hit in production were HTTP 408s on long generations and HTTP 429s under fan-out. As of 0.8.0 the package **does** handle both (§11) — that gap is closed, and it was closed because of their report.

So the honest case for the package is the accumulation of smaller things, not one headline feature:

| What it adds | Why it matters |
|---|---|
| Endpoint and model-name resolution | The AI Gateway provider path 401s on embeddings under some gateway settings while chat on the same gateway works (§4), and an authenticated gateway needs a header the package could not send before 0.7.0. You will not discover either from Cloudflare's docs. |
| `default_max_tokens = 4096` | The built-in driver sends no token cap unless the agent has `#[MaxTokens]`, and Cloudflare then caps most models at 256 (§5). |
| Empty-response detection | HTTP 200 with `content: null` is a silent failure the SDK has no opinion about (§6). |
| `reasoning_effort` | laravel/ai has no concept of it. 5x latency on the models measured (§7). |
| Neuron accounting | Tokens are not what Cloudflare bills (§8). |
| Cloudflare error envelopes | Gateway-level errors otherwise collapse to "Unknown error". |
| Workers AI response quirks | Explicit-null `usage` fields, explicit-null `tool_calls`, three different reasoning field names, a trailing all-zero usage chunk on streams. Each of these is a production crash or a silent data loss if unhandled. |
| Structured-output re-ask | Workers AI's JSON mode is best-effort. The gateway validates and re-asks, bounded. |
| Forced `tool_choice` relaxation | Re-sending `tool_choice: required` after tool results loops the model until max-steps with empty text. |

## 3. Configuration, end to end

```php
// config/ai.php
'providers' => [
    'workers-ai' => [
        'driver'     => 'workers-ai',
        'key'        => env('CLOUDFLARE_AI_API_TOKEN'),  // Workers AI: Read
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'gateway'    => env('CLOUDFLARE_AI_GATEWAY'),    // optional

        // Behaviour
        'default_max_tokens'       => 4096,   // null to send no cap
        'reasoning_effort'         => 'low',  // low | medium | high | null
        'throw_on_empty_response'  => true,
        'throw_on_truncation'      => false,
        'structured_output_retries' => 2,
        'retry'                    => true,
        'session_affinity'         => null,
        'gateway_path'             => null,   // 'workers-ai/v1' to opt out of /compat

        'models' => [
            'text' => [
                'default'  => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
                'cheapest' => '@cf/meta/llama-3.2-3b-instruct',
                'smartest' => '@cf/moonshotai/kimi-k2.6',
            ],
            'embeddings' => ['default' => '@cf/baai/bge-large-en-v1.5', 'dimensions' => 1024],
        ],
    ],
],
```

The credential key is `key`, matching every first-party laravel/ai provider. `api_key` is accepted as a fallback for configs written against this package's own pre-0.3 docs.

The service provider auto-registers through package discovery. There is nothing to publish and no migration.

## 4. The endpoint, and the trap in it

Cloudflare exposes Workers AI at three URLs, and they behave differently. Measured 2026-09-09:

| Path | Chat | Embeddings | Model ID form |
|---|---|---|---|
| `gateway.ai.cloudflare.com/v1/<acct>/<gw>/compat` | 200 | 200 | prefixed **or** bare |
| `gateway.ai.cloudflare.com/v1/<acct>/<gw>/workers-ai/v1` | 200 | 200 **or 401** | bare only |
| `api.cloudflare.com/client/v4/accounts/<acct>/ai/v1` | 200 | 200 | bare only (prefixed → **400**) |

The middle row is the trap, and it took two gateways to see it properly. Measured across two AI Gateways on one account:

- On a gateway with **Authenticated Gateway** on (`authentication: true`), `/workers-ai/v1/embeddings` returned `401 Authentication error` while chat completions on the same gateway succeeded, and `/compat` served both. Swapping in a second API token changed nothing, so it is the gateway's configuration, not the credential.
- On a gateway with the setting off, the provider path served embeddings normally.

I could not fully pin the mechanism down — an authenticated gateway also rejects `/compat` calls that omit `cf-aig-authorization`, so enforcement is clearly not uniform across sub-paths, and I was not able to create throwaway gateways to isolate it. **Do not repeat the earlier claim that the provider path "cannot serve embeddings". It can, on some gateways.** What holds is narrower and sufficient: `/compat` worked on every gateway and token combination tested, so it is the default as of 0.7.0. Set `gateway_path => 'workers-ai/v1'` to opt back in.

**Authenticated Gateway needs `gateway_token`.** A gateway with `authentication: true` requires a gateway-issued token in `cf-aig-authorization` alongside the Workers AI token in `Authorization`. Before 0.7.0 the package could not send that header at all, so an authenticated gateway was simply unusable. Set `gateway_token` in the provider config; omit it and the header is not sent.

`/compat` is Cloudflare's multi-provider routing surface, so model IDs there take a `workers-ai/` prefix: `workers-ai/@cf/openai/gpt-oss-120b`. **You do not have to write that.** The package normalizes bare `@cf/...` IDs to the prefixed form when the endpoint is `/compat`, and leaves them alone otherwise. Write bare IDs everywhere.

Two corrections to earlier behaviour, both measured:

- A **bare** ID on `/compat` returns 200. It resolves fine. Through 0.6.1 the package threw an exception on this, rejecting a working configuration. It now prefixes instead.
- A **prefixed** ID on the direct API returns `400 No such model workers-ai/@cf/...`. That is a genuine mismatch and still throws, with the fix named.

## 5. The 256-token cap, and what truncation actually looks like

Omit the completion-token cap and Cloudflare imposes **256**. Verified 2026-09-09 with a "write 900 words" prompt and no `max_tokens`:

| Model | Stopped at | `finish_reason` |
|---|---|---|
| `@cf/meta/llama-3.3-70b-instruct-fp8-fast` | 256 tokens | `length` |
| `@cf/openai/gpt-oss-120b` | 256 tokens | `length` |
| `@cf/zai-org/glm-5.3-flash` | **8,190 tokens, 369 seconds** | `stop` |

laravel/ai's built-in `openai-compatible` driver sends `max_tokens` only when the agent carries `#[MaxTokens]`. Without one, every call hits this. On a reasoning model the whole 256 goes to the chain of thought and `content` comes back null. This package sends 4096 by default.

Note the third row: the cap is not universal, and an uncapped `glm-5.3-flash` is a six-minute request. Always send a budget.

### Truncation is reported correctly. The old README was wrong.

Through 0.6.1 this package's README stated that Cloudflare "misreports truncated completions as `finish_reason: stop`", and the code coerced `stop`-at-budget into `FinishReason::Length` to compensate.

Re-measured on AI Gateway `/compat` with `max_tokens: 16`:

| Model | `finish_reason` | `content` |
|---|---|---|
| `@cf/meta/llama-3.3-70b-instruct-fp8-fast` | `length` | `"Here it goes:\nOne, Two, ..."` |
| `@cf/openai/gpt-oss-120b` | `length` | `null` |
| `@cf/zai-org/glm-5.3-flash` | `length` | `""` |

**Truncation is reported accurately**, on both the 16-token cap and the 256-token default. The coercion was redundant. Worse, it could misfire on a model that legitimately finished on its last budgeted token, and that now matters because a `Length` finish can be configured to throw. It was removed in 0.7.0 and the raw reason is trusted. `extractFinishReason()` keeps its two trailing parameters so subclass overrides still compile; they are ignored.

Note what rows two and three show: the reason is right, but the *answer* is empty. That is the real failure, and it is §6.

## 6. The silent non-answer

`Laravel\Ai\Gateway\TextGenerationLoop` never branches on `FinishReason::Length` — grep laravel/ai v0.11.2 and you will find no reference to it in the loop. A truncated or empty step ends the loop and is returned as an ordinary successful response. `$response->text` is `''`. On a structured agent, `toArray()` is `[]`. There is no exception and the HTTP status is 200.

For an unattended drafting or extraction pipeline that is indistinguishable from a genuine short answer, and it is the most expensive failure mode Workers AI has. Two config switches address it:

- **`throw_on_empty_response`** (default **true**) — `Meirdick\WorkersAi\Exceptions\EmptyResponseException` when a step returns neither text nor tool calls. A tool-calling turn with no text is explicitly exempt, or every tool loop would break.
- **`throw_on_truncation`** (default **false**) — `Meirdick\WorkersAi\Exceptions\TruncatedResponseException` on any `FinishReason::Length`. Off by default because partial data is sometimes wanted and the finish reason is accurate enough to branch on yourself. Turn it on for extraction and drafting.

Both extend `Laravel\Ai\Exceptions\AiException`, so existing `catch (AiException)` blocks already cover them. Both name the requested model and the token counts.

## 7. `reasoning_effort`

The single highest-leverage parameter on this platform, and laravel/ai does not know it exists — the string appears nowhere in v0.11.2.

Measured on `@cf/openai/gpt-oss-120b`, one two-sentence question, 2026-09-09:

| Setting | Reasoning | Answer | Wall time | Neurons |
|---|---|---|---|---|
| `low` | 20 chars | 363 chars | **1.4s** | 9.6 |
| unset | 104 chars | 433 chars | 2.6s | 11.3 |
| `high` | 1,564 chars | 296 chars | **7.4s** | 31.4 |

`low` was 5x faster than `high` and the answer was not worse. On `@cf/zai-org/glm-5.3-flash` the same prompt spent 1,240 reasoning characters and 7.5s unset, against 0 characters and 5.1s at `low`.

Resolution order, lowest to highest precedence:

1. `reasoning_effort` in the provider config.
2. `#[ReasoningEffort(ReasoningEffort::LOW)]` on the agent class.
3. `reasoning_effort` returned from the agent's `providerOptions()`.

Only `low`, `medium`, `high` are valid. Cloudflare answers 400 for OpenAI's `none` and `minimal`; the package rejects them locally with a message naming the allowed set, so you find out at the call site rather than in a gateway log.

**It is not universally honoured.** Do not assume.

| Model | Behaviour |
|---|---|
| `@cf/openai/gpt-oss-120b` | Graded correctly across all three levels. |
| `@cf/zai-org/glm-5.3-flash` | Binary. Any value turns reasoning off; `low` and `high` both produced zero reasoning. |
| `@cf/zai-org/glm-4.7-flash` | Ignored. `low` produced *more* reasoning than unset — 6,012 vs 4,003 characters — and both calls took ~29 seconds. |

### `chat_template_kwargs.thinking` is not the general lever

Earlier documentation for this package presented `chat_template_kwargs: {thinking: false}` as the way to control reasoning on Workers AI. It is not. Verified on `@cf/zai-org/glm-5.3-flash`: with the flag set false, reasoning came back at 1,172 characters against 1,240 unset. No effect.

It works on the Kimi chat template and models sharing it. Treat it as model-specific and reach for `reasoning_effort` first. It is still honoured where it works, and it still triggers the 2,048-token completion floor (§10).

## 8. Neurons

Cloudflare meters and bills Workers AI in **neurons**, and `/compat` returns a figure per call under `usage.neurons`. Every endpoint shape returns it, including the direct API.

`Laravel\Ai\Responses\Data\Usage` is five fixed integer token counters with no extensible field. `Meta` has `provider`, `model`, `citations` and no arbitrary bag. There is nowhere in laravel/ai's response objects to put a neuron count, so the package dispatches an event:

```php
use Meirdick\WorkersAi\Events\WorkersAiUsageReported;

Event::listen(WorkersAiUsageReported::class, function (WorkersAiUsageReported $event) {
    // $event->provider, ->model, ->neurons, ->promptTokens, ->completionTokens, ->reasoningTokens
});
```

One event per model call, so a two-step tool loop dispatches two. Nothing is dispatched when the response omits the field.

Order of magnitude: a 16-token completion on llama-3.3-70b cost 4.48 neurons. The same two-sentence prompt on gpt-oss-120b cost 9.6 neurons at `low` effort and 31.4 at `high` — reasoning is billed. A full pipeline run in the consuming project measured 17,242 neurons.

## 9. Reasoning field names

The reasoning text arrives under a different key depending on the model. Measured 2026-09-09:

| Model | `content` | `reasoning` | `reasoning_content` |
|---|---|---|---|
| `@cf/zai-org/glm-5.3-flash` | string | absent | string |
| `@cf/openai/gpt-oss-120b` | string | string | string (identical) |
| `@cf/zai-org/glm-4.7-flash` | string | string | string (identical) |
| `@cf/meta/llama-3.3-70b-instruct-fp8-fast` | string | explicit `null` | absent |
| `@cf/qwen/qwq-32b` | string | absent | absent (reasoning inline in `content`) |

The package reads `reasoning_content ?? reasoning` on both the streaming and non-streaming paths and normalizes to `reasoning_content`, which is what `MapsMessages` replays into follow-up assistant turns so a multi-step tool loop keeps its chain of thought. The explicit-`null` case matters: `data_get($x, 'k', 0)` returns null unchanged for an explicit null, and feeding that to a typed `int` parameter is a production TypeError. `Cloudflare\UsageTokens` exists entirely to absorb that.

One correction: earlier notes said `@cf/zai-org/glm-4.7-flash` returns `content: null` always and is therefore unusable as an agent model. Not reproduced — it returned normal content on every call tested. Its real problem is that it ignores `reasoning_effort` and takes ~29 seconds regardless.

## 10. Other behaviour worth knowing

- **Reasoning token floor.** When `chat_template_kwargs.thinking` is `true` and the resolved budget is under 2,048, the package raises it to 2,048 and logs a warning. Reasoning and the answer share one budget; a tight budget produces `content: null`.
- **Forced `tool_choice` is relaxed to `auto` after step 0.** Re-sending `required` after tool results forces another tool call instead of an answer, looping to max-steps with empty text. `none` is preserved.
- **Structured output is validated and re-asked**, bounded by `structured_output_retries` (default 2). A `Length` finish is never re-asked — a budget problem is not fixed by asking again. The re-ask runs inside the step, so it does not consume the loop's step budget.
- **Timeouts.** laravel/ai defaults to 60 seconds. That is not enough for several Workers AI models. A transfer timeout (cURL 28) is not retried — before 0.3.0 it was, turning a 60s timeout into ~3 minutes.
- **502/503/504** are retried with backoff and then mapped to `ProviderOverloadedException` so laravel/ai failover reacts.

## 11. Failure modes, and what the package does about them

| Wire result | Retried? | Surfaces as | Failoverable |
|---|---|---|---|
| cURL 6/7/56, connect-phase 28 | yes, backoff | `ProviderConnectionException` | yes |
| cURL 28 mid-transfer | **no** | `ProviderConnectionException` | yes |
| 402 | no | `InsufficientCreditsException` | yes |
| **408** | **no** | **`GatewayTimeoutException`** | yes |
| **429** | **yes, `Retry-After`-aware** | `RateLimitedException` | yes |
| 502 / 503 / 504 | yes, backoff | `ProviderOverloadedException` | yes |
| **520 / 522 / 524** | **yes, backoff** | `ProviderOverloadedException` | yes |
| 200 + empty content | n/a | `EmptyResponseException` | no |
| 200 + `finish_reason: length` | n/a | `TruncatedResponseException` (opt-in) | no |

The bolded rows are 0.8.0. All three came from one consuming project's production experience rather than from reading Cloudflare's docs.

**408 is never retried, and that is deliberate.** Reproduced 2026-09-09: `@cf/zai-org/glm-5.3-flash`, `max_tokens: 24000` -> `408 Request timeout` after **709 seconds**. The generation that took 709 seconds will take 709 seconds on the retry. Three attempts is thirty-five minutes to learn nothing. It is failoverable instead, so a fallback provider can answer. With no fallback configured, lower the cap or split the work.

**429 is retried because laravel/ai does not retry it.** The SDK maps it to a failoverable `RateLimitedException` and stops there. Under a fan-out that means one 429 is one lost request — which is what the consuming project measured, roughly ten of twenty-eight in the same second. The package now backs off (500ms, 1s, 2s..., capped at 20s) and honours `Retry-After` in both legal forms, clamping a past date to zero and an absurd delay to the cap. `retry_rate_limited => false` restores fail-fast.

**520/522/524 were missing.** Through 0.7.0 `overloadedStatusCodes()` returned `[502, 503, 504]` — *narrower* than laravel/ai's own `[502, 503, 504, 520, 522, 524]`. A Cloudflare package silently dropping Cloudflare's three edge error codes. Every request here crosses the edge twice, once to the gateway and once to the model runner, so they are more likely here, not less. The list now comes from `RetryPolicy::RETRYABLE_STATUSES` so the retry set and the failover set cannot drift apart again.

### Still not solved

**Gateway retry multiplies against your client timeout.** An AI Gateway with `retry_max_attempts: 3` retries *inside* your single HTTP request, so its retries multiply against this package's and against your timeout. It also cannot retry a 200-with-null-content, which is the failure you actually hit. Pick one layer; if you pick the gateway, set `retry => false` here.

**No concurrency limiter.** The package retries your 429s; it will not stop you creating them. Throttling the fan-out belongs in the consuming application, which is the only thing that knows which half of the work is urgent.

## 12. Upgrade evidence

0.7.0 was tested against four consuming applications, each against its own pre-upgrade baseline, before release:

| App | laravel/ai | Package | Result |
|---|---|---|---|
| A | 0.11.0 → 0.11.2 | 0.6.1 → 0.7.0 | 764 → 764 passed, same assertions |
| B | held at 0.9.0 | 0.6.0 → 0.7.0 | 1639 → 1639 passed, same assertions |
| B | 0.9.0 → 0.11.2 | 0.7.0 | 3 failed — **not this package**, see below |
| C | 0.7.0 → 0.11.2 | 0.2.0 → 0.7.0 | 317 → 317 passed |
| D | 0.7.0 → 0.11.2 | 0.2.0 → 0.7.0 | unchanged (70 pre-existing Vite failures both sides) |

Row two is the one that matters most for the composer constraint: the package was bumped while laravel/ai was deliberately held at **0.9.0**, the declared floor. Identical results, so `^0.9` is a real lower bound rather than an optimistic one.

Row three's three failures are `table agent_conversations has no column named participant_type` — laravel/ai's own schema moved between 0.9 and 0.11. Pinning the package back to 0.6.1 on laravel/ai 0.11.2 reproduces them exactly, which is how they were attributed. Do not let this one scare you off a release; do check the app's published laravel/ai migrations.

## 13. Tests

```bash
composer install
vendor/bin/pest                                   # whole suite
vendor/bin/pest tests/Gateway/ReasoningEffortTest.php
vendor/bin/pest --filter='truncated'
```

161 tests pass; 43 skip without live credentials. There is no static analysis and no formatter configured — do not add one as a drive-by.

`tests/Gateway/` covers the unit surface against `Http::fake()`:

| File | Covers |
|---|---|
| `BaseUrlTest` | All three endpoint shapes, `gateway_path`, model-prefix normalization in both directions, headers |
| `TruncationHeuristicTest` | Finish-reason mapping (that `stop` is no longer coerced), `throw_on_truncation` on and off, streaming parity |
| `EmptyResponseTest` | `throw_on_empty_response`, the tool-call exemption, the opt-out |
| `ReasoningEffortTest` | Resolution order across config / attribute / provider options, value validation |
| `NeuronUsageTest` | Event dispatch, per-step dispatch in a tool loop, absence handling |
| `RequestMappingTest`, `MessageMappingTest`, `ToolMappingTest` | Body construction |
| `StructuredOutputTest`, `StructuredOutputReaskTest` | JSON mode and the bounded re-ask |
| `StreamingTest`, `StreamUsageAccumulationTest` | SSE parsing, the trailing all-zero usage chunk |
| `ReasoningCaptureTest`, `ThinkingTokenFloorTest` | Reasoning field normalization, the 2,048 floor |
| `ResilienceTest` | 408 -> GatewayTimeoutException and attempted once, 429 retry + Retry-After, `retry_attempts` / `retry_rate_limited`, Cloudflare 520/522/524 |
| `ErrorHandlingTest`, `RetryPolicyTest` | Cloudflare error envelopes, retry decisions, backoff and `Retry-After` parsing |
| `ToolCallLoopTest`, `SubAgentTest` | Tool loop and `CanActAsTool` |
| `CredentialsTest` | `key` / `api_key` resolution |

`tests/Integration/` runs against the real API and skips unless `WORKERS_AI_E2E_TOKEN` and `WORKERS_AI_E2E_ACCOUNT` are set (plus `WORKERS_AI_E2E_GATEWAY` for the gateway tests, `WORKERS_AI_E2E_STRESS=1` for the stress sweep). These cost money and take minutes.

CI runs PHP 8.3/8.4/8.5 against laravel/ai `^0.9`, `^0.10`, `^0.11`.

## 14. Deliberately not handled

- **Concurrency limiting and request splitting.** 408 and 429 are handled (§11), but nothing throttles a fan-out or splits an over-large generation. Those belong in the consuming application, which knows the shape and urgency of the work.
- **Streaming does not enforce the empty/truncation guards.** They run on `generateTextStep()` only. A stream surfaces the finish reason on `StreamEnd` and the caller decides.
- **No neuron budgeting.** The figure is reported; nothing enforces a ceiling.
- **`#[CacheInstructions]` is inert here.** Verified in laravel/ai v0.11.2: it is resolved onto `TextGenerationOptions` for every provider, but consumed only by the Anthropic gateway (`applyPromptCacheBreakpoints`) and the Bedrock gateway (`cachePoint`). No OpenAI-compatible provider, this one included, does anything with it. Workers AI's prefix cache is driven by `session_affinity` instead, and its hits show up in `usage.prompt_tokens_details.cached_tokens`, which the package maps to `Usage::$cacheReadInputTokens`.
- **No model catalog.** Model IDs are strings you supply. Nothing validates that a model exists, is current, or supports tools. Cloudflare returns `410 Model has been deprecated` for a retired model and `403 This account is not allowed to access` for one your plan does not cover; both surface as readable errors, and that is as far as this goes. Both were hit while testing 0.7.0 — `@cf/meta/llama-3.1-8b-instruct` is retired (it was the `#[UseCheapestModel]` default through 0.6.1, so that attribute was broken) and `@cf/zai-org/glm-5.3` is not enabled on every account. **The defaults in `WorkersAiProvider` will rot. Check them when a release feels overdue.**
- **Structured-output validation is deliberately shallow.** It checks required fields and enums, not a full JSON Schema. It targets the failure Workers AI actually produces — a well-formed object with an empty required field — without pulling in a validator dependency.
- **No Prism support in this package.** `Cloudflare\BaseUrl` and `ModelPrefix` carry comments about "the Prism path" because they were extracted from a codebase that had one. Only the laravel/ai path ships here.

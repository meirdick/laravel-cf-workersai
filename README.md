# laravel-cf-workersai

A native [Laravel AI](https://github.com/laravel/ai) provider for [Cloudflare Workers AI](https://developers.cloudflare.com/workers-ai/) with first-class support for [Cloudflare AI Gateway](https://developers.cloudflare.com/ai-gateway/).

- Text generation, embeddings, structured output, tool calling, streaming.
- Three URL shapes: direct Workers AI, AI Gateway routed, or arbitrary `/compat` endpoint.
- Reasoning content replay across tool-call turns.
- `#[Strict]` JSON schema opt-in.
- Provider options pass-through.
- Sub-agent tools and MCP tools.
- Streamed usage summed across tool-call steps.
- `reasoning_effort` as a first-class knob, per provider and per agent.
- Empty- and truncated-response detection, so a silent HTTP 200 with no answer becomes a catchable exception.
- Cloudflare's `neurons` billing figure surfaced as an event.
- Retry policy and AI Gateway session affinity.
- Failover-ready: 429/402/502/503/504 map to laravel/ai's failoverable exceptions.

## Requirements

- PHP `^8.3`
- `laravel/ai ^0.9 || ^0.10 || ^0.11` (tested against v0.11.2)

Need `laravel/ai ^0.7 || ^0.8`? Use `meirdick/laravel-cf-workersai ^0.5`.

## Do you need this package?

Be honest with yourself first. laravel/ai's built-in `openai-compatible` driver points at Cloudflare's `/compat` endpoint and works. If all you do is generate text from one model with an explicit `#[MaxTokens]`, use it and skip this package.

Reach for this package when you want:

- **The endpoint and model-name shapes resolved for you**, including an AI Gateway path that 401s on embeddings under some gateway settings, and Authenticated Gateway support (see below).
- **A sane completion-token default.** The built-in driver sends `max_tokens` only when the agent carries `#[MaxTokens]`; without it Cloudflare caps most models at 256 tokens.
- **Failure on a non-answer.** A reasoning model that burns its budget thinking returns HTTP 200, `content: null`. Nothing in laravel/ai treats that as an error. This package throws.
- **`reasoning_effort`.** laravel/ai has no concept of it; it is a 5x latency difference on the models measured.
- **Neuron accounting.** Token counts are not what Cloudflare bills.
- **Embeddings, tool loops, streaming and structured output** against Workers AI's quirks, rather than the generic OpenAI shape.

What it does **not** solve: HTTP 408s from the gateway on very long generations, and HTTP 429 under wide fan-out. Those are yours to handle. See [Operational limits](#operational-limits).

## Installation

```bash
composer require meirdick/laravel-cf-workersai
```

The service provider auto-registers via package discovery. No manual wiring needed.

## Configuration

Add a `workers-ai` provider to `config/ai.php`:

```php
'providers' => [
    'workers-ai' => [
        'key'                 => env('CLOUDFLARE_AI_API_TOKEN'),
        'account_id'          => env('CLOUDFLARE_ACCOUNT_ID'),
        'gateway'             => env('CLOUDFLARE_AI_GATEWAY'),  // optional
        // 'url'              => env('CLOUDFLARE_AI_URL'),      // optional escape hatch
        // 'default_max_tokens' => 4096,                        // override the package default
    ],
],
```

`key` is a Cloudflare API token with the `Workers AI: Read` permission, matching the credential key name every first-party laravel/ai provider uses. The `api_key` name from earlier releases of this package is still accepted as a fallback.

### `default_max_tokens`

Cloudflare caps a completion at **256 tokens** when `max_completion_tokens` is omitted — far too small for any non-trivial structured output, which then arrives mid-JSON. Verified live on 2026-09-09: with no cap, `@cf/meta/llama-3.3-70b-instruct-fp8-fast` and `@cf/openai/gpt-oss-120b` both stopped at exactly 256 completion tokens. laravel/ai's built-in `openai-compatible` driver sends `max_tokens` only when the agent carries `#[MaxTokens]`, so an agent without one runs into this on every call.

The package sends `4096` by default. Override it per provider, or set it to `null` to fall back to Cloudflare's endpoint default. Per-call `#[MaxTokens(...)]` (or `TextGenerationOptions::$maxTokens`) always wins.

The cap is not universal, which is a second reason to always send a budget: `@cf/zai-org/glm-5.3-flash` with no cap ran to 8,190 completion tokens over **369 seconds**.

> **Correction to earlier releases.** Through v0.6.1 this README claimed Cloudflare misreports truncation as `finish_reason: "stop"`, and the package coerced `stop`-at-budget into `FinishReason::Length` to compensate. Re-measured on AI Gateway `/compat`: that is not what happens. `@cf/meta/llama-3.3-70b-instruct-fp8-fast`, `@cf/openai/gpt-oss-120b` and `@cf/zai-org/glm-5.3-flash` all returned `finish_reason: "length"` under a 16-token cap, as did every model truncated at the 256-token default. **Truncation is reported correctly.** The coercion was removed in 0.7.0 — it was redundant and could misfire on a model that legitimately finished on its last budgeted token.

### Endpoint resolution

There are three ways to configure the endpoint, in priority order:

1. **`url`** (explicit). All requests go to this URL. Use it for `/compat` or a self-hosted gateway.
2. **`account_id` + `gateway`**. Routes through `https://gateway.ai.cloudflare.com/v1/<account_id>/<gateway>/compat/...` — AI Gateway's caching, retries, cost tracking and request logs.
3. **`account_id` only**. Hits the direct Workers AI API at `https://api.cloudflare.com/client/v4/accounts/<account_id>/ai/v1/...`.

#### `/compat` versus the provider path

Cloudflare's AI Gateway exposes Workers AI two ways, and they are not interchangeable:

| Path | Chat completions | Embeddings | Model ID |
|---|---|---|---|
| `<gateway>/compat` | 200 | 200 | `workers-ai/@cf/...` or bare |
| `<gateway>/workers-ai/v1` | 200 | 200 **or 401**, see below | bare `@cf/...` |
| direct `/client/v4/accounts/<id>/ai/v1` | 200 | 200 | bare `@cf/...` |

Measured 2026-09-09 across two AI Gateways on the same account. On the gateway with **Authenticated Gateway** enabled (`authentication: true`), `/workers-ai/v1/embeddings` answered `401 Authentication error` — while chat completions on that same gateway, and both operations on `/compat`, succeeded. Two different API tokens gave identical results, so the failure tracks the gateway's configuration, not the credential. On the gateway with the setting off, the provider path served embeddings fine.

The mechanism is not fully pinned down: an authenticated gateway also rejects `/compat` requests that omit `cf-aig-authorization`, so enforcement is evidently not uniform across sub-paths. What is clear is that **`/compat` was the only shape that worked on every gateway tested**, which is why the `gateway` config resolves to it as of 0.7.0. Set `gateway_path => 'workers-ai/v1'` to opt back in.

#### Authenticated Gateway

If your gateway has Authenticated Gateway turned on, Cloudflare needs a gateway-issued token in `cf-aig-authorization` **in addition to** your Workers AI token. Set `gateway_token`:

```php
'workers-ai' => [
    'key'           => env('CLOUDFLARE_AI_API_TOKEN'),
    'account_id'    => env('CLOUDFLARE_ACCOUNT_ID'),
    'gateway'       => env('CLOUDFLARE_AI_GATEWAY'),
    'gateway_token' => env('CLOUDFLARE_AI_GATEWAY_TOKEN'),
],
```

Omit it for an unauthenticated gateway and the header is not sent. Without it on an authenticated gateway you get a bare `{"code":10000,"message":"Authentication error"}` that names neither the gateway nor the missing header — the package could not send this header at all before 0.7.0.

`/compat` is a multi-provider routing endpoint, so model IDs there carry a `workers-ai/` prefix. The package adds it automatically: keep writing bare `@cf/...` IDs everywhere and the right form goes on the wire. (A bare ID posted to `/compat` does in fact resolve — through 0.6.1 this package incorrectly threw on it. A prefixed ID posted to a bare path genuinely fails with `400 No such model`, and still throws.)

### Reasoning effort

`/compat` accepts OpenAI's `reasoning_effort` field. laravel/ai does not send it — the string does not appear anywhere in v0.11.2 — and left unset, Workers AI's reasoning models choose their own effort and choose it badly.

Measured on `@cf/openai/gpt-oss-120b`, one two-sentence question, 2026-09-09:

| `reasoning_effort` | Reasoning | Answer | Wall time | Neurons |
|---|---|---|---|---|
| `low` | 20 chars | 363 chars | 1.4s | 9.6 |
| unset | 104 chars | 433 chars | 2.6s | 11.3 |
| `high` | 1,564 chars | 296 chars | 7.4s | 31.4 |

The `low` answer was not worse. Set it per provider:

```php
'workers-ai' => [
    // ...
    'reasoning_effort' => 'low',   // low | medium | high
],
```

Or per agent, which wins over the provider default:

```php
use Meirdick\WorkersAi\Attributes\ReasoningEffort;

#[ReasoningEffort(ReasoningEffort::LOW)]
class DraftingAgent implements Agent { /* ... */ }
```

An explicit `reasoning_effort` returned from `providerOptions()` beats both.

Only `low`, `medium` and `high` are accepted — Cloudflare answers `400` for OpenAI's `none` and `minimal`, so the package rejects them before the request.

**Not every model honours it.** Measured:

| Model | Behaviour |
|---|---|
| `@cf/openai/gpt-oss-120b` | Graded properly. The only model measured whose reasoning stays proportionate to the prompt. |
| `@cf/zai-org/glm-5.3-flash` | Binary: any value suppresses reasoning entirely (1,240 chars unset → 0 at `low` *and* `high`). |
| `@cf/zai-org/glm-4.7-flash` | Ignored. `low` produced *more* reasoning than unset (6,012 vs 4,003 chars); both calls took ~29s. |

### Detecting a non-answer

laravel/ai's `TextGenerationLoop` never branches on `FinishReason::Length` — a truncated answer is returned as an ordinary success, and a `content: null` response arrives as an empty string with `toArray()` yielding `[]`. In an unattended pipeline that is indistinguishable from a real short answer, which makes it the most expensive failure Workers AI has.

```php
'workers-ai' => [
    // ...
    'throw_on_empty_response' => true,   // default: true
    'throw_on_truncation'     => false,  // default: false
],
```

- **`throw_on_empty_response`** (default **on**) throws `Meirdick\WorkersAi\Exceptions\EmptyResponseException` when a step returns neither text nor tool calls. Reproduced live on `@cf/openai/gpt-oss-120b`: a 16-token budget gives `finish_reason: "length"`, `content: null`, HTTP 200. A tool-calling turn with no text is not affected.
- **`throw_on_truncation`** (default **off**) throws `Meirdick\WorkersAi\Exceptions\TruncatedResponseException` on any `FinishReason::Length`. Turn it on for extraction and drafting, where half an answer is worse than none.

Both exceptions extend `Laravel\Ai\Exceptions\AiException` and name the model and token counts.

### Tracking real cost

Cloudflare meters Workers AI in **neurons**, not tokens, and returns one figure per call under `usage.neurons` on every endpoint shape. `Laravel\Ai\Responses\Data\Usage` is five fixed integer counters with no extensible field and `Meta` has no arbitrary bag, so there is nowhere in the SDK's response objects to put it. The package dispatches it instead:

```php
use Meirdick\WorkersAi\Events\WorkersAiUsageReported;

Event::listen(WorkersAiUsageReported::class, function (WorkersAiUsageReported $event) {
    Spend::record($event->model, $event->neurons);
});
```

One event per model call, including each step of a tool loop. Anything costing Workers AI from token counts alone is costing the wrong number.

## Quickstart

```php
use function Laravel\Ai\agent;

$response = agent('helper')->prompt('Say hi.', provider: 'workers-ai');
echo $response->text;
```

Use any [Workers AI model](https://developers.cloudflare.com/workers-ai/models/) — pass it as `model:`:

```php
agent('helper')
    ->prompt('Summarize this in one sentence.', provider: 'workers-ai', model: '@cf/meta/llama-3.3-70b-instruct-fp8-fast');
```

## Embeddings

```php
use Laravel\Ai\Embeddings;

$vectors = Embeddings::for(['hello', 'world'])
    ->generate(provider: 'workers-ai', model: '@cf/baai/bge-base-en-v1.5');
```

Forward arbitrary fields with `withProviderOptions` (named `providerOptions()` before laravel/ai 0.9):

```php
Embeddings::for(['hello'])
    ->withProviderOptions(['encoding_format' => 'base64'])
    ->generate(provider: 'workers-ai');
```

## Streaming

```php
foreach (agent('helper')->streamed('Tell me a story.', provider: 'workers-ai') as $event) {
    if ($event instanceof \Laravel\Ai\Events\TextDelta) {
        echo $event->text;
    }
}
```

Reasoning-capable models emit `ReasoningStart` → `ReasoningDelta` → `ReasoningEnd` events before text.

Reasoning arrives under a different key depending on the model, and the package normalizes all of them. Measured 2026-09-09:

| Model | Reasoning field |
|---|---|
| `@cf/zai-org/glm-5.3-flash` | `reasoning_content` |
| `@cf/openai/gpt-oss-120b` | both `reasoning` and `reasoning_content`, identical |
| `@cf/zai-org/glm-4.7-flash` | both, identical |
| `@cf/meta/llama-3.3-70b-instruct-fp8-fast` | `reasoning`, always explicit `null` |
| `@cf/qwen/qwq-32b` | neither — reasoning is inline in `content` |

## Tools

```php
use Laravel\Ai\Attributes\Tool;

#[Tool(description: 'Look up the current weather.')]
function getWeather(string $city): string
{
    return "Sunny in {$city}.";
}

agent('helper')->withTools([getWeather(...)])->prompt('Weather in Tokyo?', provider: 'workers-ai');
```

Reasoning content from the tool-call turn is preserved and replayed in the follow-up automatically (`providerContentBlocks`).

### Model choice matters for tool calling

Verified live against the production API (2026-06-11): **`@cf/meta/llama-3.3-70b-instruct-fp8-fast` — the package's default text model — does not emit tool calls** on the `/v1` endpoint; it answers in prose instead. `@cf/meta/llama-4-scout-17b-16e-instruct` and `@cf/openai/gpt-oss-120b` tool-call correctly, but under `tool_choice: auto` open-weight models only *choose* to call a tool some of the time. When the tool must run, force it via provider options:

```php
public function providerOptions(Lab|string $provider): array
{
    // Custom drivers arrive as a plain string, not a Lab enum case.
    return $provider === 'workers-ai' ? ['tool_choice' => 'required'] : [];
}
```

The package automatically relaxes a forced `tool_choice` back to `auto` on tool-result follow-up turns — otherwise the model is forced to call a tool again instead of answering, looping until max-steps with empty text.

## Timeouts

laravel/ai resolves a **60-second timeout** by default. Large models, structured output, and reasoning models on Workers AI can exceed it — observed live: a structured `llama-3.3-70b` request taking 60s+, and `kimi-k2.6` taking 45s on a small prompt. Raise it per agent or per call:

```php
use Laravel\Ai\Attributes\Timeout;

#[Timeout(120)]
class ExtractionAgent implements Agent { /* ... */ }

// or per call:
$agent->prompt('...', provider: 'workers-ai', timeout: 120);
```

A request that exceeds the timeout fails after a single attempt with a `ConnectionException`. (Before v0.3.0 the retry policy re-ran timed-out requests, turning a 60s timeout into ~3 minutes of wall time before failing.) Connect-phase failures and transient 502/503/504 responses are still retried with backoff.

## Structured output

```php
use Laravel\Ai\Attributes\Strict;

#[Strict] // opt-in to strict JSON schema enforcement
final class TaskAgent extends \Laravel\Ai\Agent {}
```

When `#[Strict]` is applied, `strict: true` is forwarded to Workers AI's `/compat` endpoint and the generated JSON schema requires all properties.

## Reasoning models

Many Workers AI models emit a chain of thought before their answer. **The general mechanism is [`reasoning_effort`](#reasoning-effort)** — reach for that first.

`chat_template_kwargs.thinking` is the older, narrower lever, and it is **model-specific, not a general switch**. Through v0.6.1 this README presented it as the way to control reasoning on Workers AI. It is not: verified on `@cf/zai-org/glm-5.3-flash`, `chat_template_kwargs: {thinking: false}` left reasoning at 1,172 characters against 1,240 unset — no effect at all. It works on the Kimi chat template and models that share it. Use it only when you know the model reads it.

It is passed through `HasProviderOptions`, the laravel/ai convention also used for Anthropic `thinking` and Gemini `thinkingConfig`. The returned options are merged into the request body verbatim:

```php
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;

class AnalysisAgent implements Agent, HasProviderOptions
{
    public function providerOptions(Lab|string $provider): array
    {
        // Workers AI is a custom driver, so $provider arrives as the string.
        return $provider === 'workers-ai'
            ? ['chat_template_kwargs' => ['thinking' => false]]
            : [];
    }
}
```

**On models that read it**, disabling reasoning is the right default for structured-output and extraction work: reasoning competes with `response_format` for the token budget and roughly triples latency. Verified on Kimi K2.6 — structured calls return valid JSON in ~4s with thinking off versus busting both the schema and the 60s timeout with it on. On models that ignore the flag, `#[ReasoningEffort('low')]` is the lever that actually works.

**Enabling reasoning** (`thinking => true`) suits free-form, latency-tolerant judgment tasks. The package then:

- captures the model's reasoning (under either the `reasoning_content` or the K2.6 `reasoning` field) and replays it across tool-call turns so multi-step tool loops stay coherent;
- raises `max_completion_tokens` to a **2048 floor** when it would otherwise be lower, so the model isn't starved of answer tokens after reasoning (a small budget returns `content: null` / `finish_reason: "length"`). Pair it with a raised `#[Timeout]` (see above).

## Operational limits

Measured against one Workers AI account through AI Gateway. Numbers are indicative, not contractual.

**Gateway-level retry multiplies against your client timeout.** An AI Gateway configured with `retry_max_attempts: 3` retries *inside* your single HTTP request. A call that errors late — an HTTP 408 after 200 seconds — becomes 600-plus seconds from the client's view, and is then cut by your own timeout, so you see a cURL 28 and never learn what the gateway saw. Gateway retry also cannot retry a 200-with-null-content, which is the failure you actually hit. Do not stack a third retry layer on top of the gateway's and this package's; pick one.

**HTTP 408 from the gateway on very long generations.** Distinct from a client timeout, which surfaces as cURL error 28. Reproduced 2026-09-09: `@cf/zai-org/glm-5.3-flash` with `max_tokens: 24000` and a 15-minute client timeout returned `408 Request timeout` after **709 seconds**. The package does not retry a 408 — retrying costs another 709 seconds to reach the same failure. Lower the token cap or split the work.

**Concurrency.** A 40-wide fan-out of small requests to one account returned 40× HTTP 200 with no rate limiting. Rate limits do exist and are account- and model-dependent; if you see 429s, narrow the fan-out rather than assuming a fixed ceiling. The package offers no concurrency helper, so this is your call to make.

**Model notes.**

- `@cf/openai/gpt-oss-120b` — the only model measured whose reasoning stays proportionate to the prompt. Honours `reasoning_effort` properly. The safe default for agent work.
- `@cf/zai-org/glm-5.3-flash` — capable on short inputs, but does not obey `reasoning_effort` as a gradient and ignores `chat_template_kwargs.thinking`. With no token cap it ran 8,190 tokens over 369 seconds. Always cap it.
- `@cf/zai-org/glm-4.7-flash` — ignores `reasoning_effort` entirely and spent ~29 seconds on a two-sentence question in every configuration tested. It does return `content` normally, contrary to some earlier reports.
- `@cf/zai-org/glm-5.3` — not enabled on every account; returns `403 This account is not allowed to access` where it is not.

## AI Gateway

Set the `gateway` config key to route through Cloudflare AI Gateway. You get free caching, retries, cost analytics, and request logs in the Cloudflare dashboard. As of 0.7.0 this resolves to the gateway's `/compat` path — see [Endpoint resolution](#compat-versus-the-provider-path).

```php
'workers-ai' => [
    'key'        => env('CLOUDFLARE_AI_API_TOKEN'),
    'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
    'gateway'    => 'my-gateway',
],
```

A session-affinity header is sent automatically so successive related requests hit the same cache shard.

## Models

Workers AI hosts dozens of open-weight models. See the [Cloudflare Workers AI models catalog](https://developers.cloudflare.com/workers-ai/models/) for current options. Common prefixes:

- `@cf/meta/...` — Llama variants
- `@cf/openai/...` — OpenAI open-weight models on Cloudflare
- `@cf/google/...` — Gemma
- `@cf/qwen/...`, `@cf/mistralai/...`, `@cf/microsoft/...`, etc.
- `@cf/baai/...` — embedding models

Model IDs are strings you supply; nothing validates that one exists or is current. Cloudflare answers `410 Model has been deprecated` for a retired model and `403 This account is not allowed to access` for one your plan does not cover. Both were hit during 0.7.0 testing — `@cf/meta/llama-3.1-8b-instruct` is retired (it was this package's `#[UseCheapestModel]` default through 0.6.1) and `@cf/zai-org/glm-5.3` is not enabled on every account.

## Provider keys

The provider can be referenced as `workers-ai` (primary) or `workersai` (alias).

## Upgrading to 0.7.0

Verified against four consuming applications before release. Three upgraded with no test changes at all; the fourth surfaced a laravel/ai issue unrelated to this package.

**From 0.6.x** — a drop-in bump. Check three things:

1. **If you use `account_id` + `gateway`,** your requests move from `.../<gw>/workers-ai/v1` to `.../<gw>/compat`. Model IDs are prefixed for you, so no code changes. Your AI Gateway dashboard will show the traffic under the `compat` route instead of `workers-ai`. Set `gateway_path => 'workers-ai/v1'` to keep the old shape.
2. **`throw_on_empty_response` is now on.** If you were tolerating empty responses deliberately, set it to `false`.
3. **If you rely on `#[UseCheapestModel]`,** the default model changed because the old one was retired.

**From 0.5.x or earlier** — you must bump `laravel/ai` in the same operation. This package needs `^0.9`, so requiring it alone fails with a clear resolution error:

```
meirdick/laravel-cf-workersai v0.7.0 requires laravel/ai ^0.9 || ^0.10 || ^0.11
  -> found laravel/ai[v0.9.0, ..., v0.11.2] but it conflicts with your root
     composer.json require (^0.7).
```

Bump both together:

```bash
composer require "laravel/ai:^0.11" "meirdick/laravel-cf-workersai:^0.7" -W
```

Two things to expect on that path, neither caused by this package:

- **laravel/ai's own schema moved between 0.9 and 0.11.** Its conversation table gained `participant_type` / `participant_id`. If you published its migrations, republish or reconcile them, or you get `table agent_conversations has no column named participant_type` at runtime. Confirmed to be a laravel/ai concern: the identical failures occur with this package pinned back to 0.6.1.
- **If your app declares `"php": "^8.2"`,** composer will still resolve — it checks the PHP you are running, not your declared floor. But this package requires `^8.3`, so your `composer.json` then understates your real minimum and `composer install` fails on an 8.2 deployment target. Bump your own `php` constraint to `^8.3`.

## Handoff

[`HANDOFF.md`](HANDOFF.md) is the end-to-end brief for someone picking this up cold: what the package is for, when not to use it, every trap with its measured number, how the tests are organized, and what is deliberately unhandled.

## Versioning

This package follows [Semantic Versioning](https://semver.org/). Version 0.6+ requires `laravel/ai ^0.9` (the single-step `StepTextGateway` contract); use `^0.5` of this package for `laravel/ai ^0.7 || ^0.8`.

## License

MIT

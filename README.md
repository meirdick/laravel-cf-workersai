# laravel-cf-workersai

A [Laravel AI](https://github.com/laravel/ai) provider for [Cloudflare Workers AI](https://developers.cloudflare.com/workers-ai/). Supports [Cloudflare AI Gateway](https://developers.cloudflare.com/ai-gateway/).

What it does:

- Text generation, embeddings, structured output, tool calls, streaming.
- Three endpoint shapes: the direct Workers AI API, an AI Gateway, or any `/compat` URL.
- Replays reasoning text across tool-call turns.
- `#[Strict]` JSON schema.
- Passes provider options through to the request body.
- Sub-agent tools and MCP tools.
- Sums streamed usage across tool-call steps.
- `reasoning_effort` per provider and per agent.
- Throws on an empty response. Can throw on a truncated response.
- Reports Cloudflare's `neurons` billing figure as an event.
- Retries transient failures. Sends the AI Gateway session-affinity header.
- Maps 402, 408, 429, 502, 503, 504, 520, 522 and 524 to laravel/ai failover exceptions.
- Retries 429 with `Retry-After` backoff. Never retries 408.

## Requirements

- PHP `^8.3`
- `laravel/ai ^0.9 || ^0.10 || ^0.11 || ^1.0`. Tested against v0.9.1, v0.10.3, v0.11.2 and the `1.x` branch at 9de5156.

For `laravel/ai ^0.7 || ^0.8`, use `meirdick/laravel-cf-workersai ^0.5`.

## Do you need this package?

laravel/ai has a built-in `openai-compatible` driver. Point it at Cloudflare's `/compat` URL and it works. If you generate text from one model and your agents have `#[MaxTokens]`, use that driver.

Use this package when you want:

- Endpoint and model-name resolution. One AI Gateway path returns 401 on embeddings under some gateway settings. An authenticated gateway needs an extra header. See [Endpoint resolution](#endpoint-resolution).
- A default token cap. The built-in driver sends `max_tokens` only when the agent has `#[MaxTokens]`. Without it, Cloudflare caps most models at 256 tokens.
- An exception on a non-answer. A reasoning model can spend its whole token cap on thinking and return HTTP 200 with `content: null`. laravel/ai does not treat that as an error. This package throws.
- `reasoning_effort`. laravel/ai does not send it. It made a 5x latency difference on the models measured.
- Neuron accounting. A neuron is Cloudflare's billing unit for Workers AI. Cloudflare bills neurons, not tokens.
- Handling for 408 and 429. A gateway 408 becomes a named failover exception. A 429 is retried with backoff.

The package does not limit your own request concurrency. It retries your 429s. It does not stop you from causing them. See [Operational limits](#operational-limits).

## Installation

```bash
composer require meirdick/laravel-cf-workersai
```

Laravel package discovery registers the service provider. No manual step.

## Configuration

Add a `workers-ai` provider to `config/ai.php`:

```php
'providers' => [
    'workers-ai' => [
        'key'                 => env('CLOUDFLARE_AI_API_TOKEN'),
        'account_id'          => env('CLOUDFLARE_ACCOUNT_ID'),
        'gateway'             => env('CLOUDFLARE_AI_GATEWAY'),  // optional
        // 'url'              => env('CLOUDFLARE_AI_URL'),      // optional, overrides both
        // 'default_max_tokens' => 4096,                        // package default
    ],
],
```

`key` is a Cloudflare API token with the `Workers AI: Read` permission. Every first-party laravel/ai provider uses the name `key`. The old name `api_key` still works.

### `default_max_tokens`

Cloudflare caps a completion at 256 tokens when the request omits `max_completion_tokens`. That is too small for most structured output. Verified 2026-09-09: with no cap, `@cf/meta/llama-3.3-70b-instruct-fp8-fast` and `@cf/openai/gpt-oss-120b` both stopped at 256 completion tokens. The built-in `openai-compatible` driver sends `max_tokens` only when the agent has `#[MaxTokens]`.

The package sends `4096` by default. Set `default_max_tokens` per provider to change it. Set it to `null` to send no cap. A per-call `#[MaxTokens(...)]` or `TextGenerationOptions::$maxTokens` always wins.

The 256 cap does not apply to every model. `@cf/zai-org/glm-5.3-flash` with no cap ran to 8,190 completion tokens in 369 seconds. Always send a cap.

> Correction. Through v0.6.1 this README said Cloudflare reports a truncated completion as `finish_reason: "stop"`. The package changed `stop` to `FinishReason::Length` when the completion reached the cap. Re-measured on AI Gateway `/compat` with a 16-token cap: `@cf/meta/llama-3.3-70b-instruct-fp8-fast`, `@cf/openai/gpt-oss-120b` and `@cf/zai-org/glm-5.3-flash` all returned `finish_reason: "length"`. So did every model truncated at the 256 cap. Cloudflare reports truncation correctly. Version 0.7.0 removed the change.

### Endpoint resolution

Three config shapes, in priority order:

1. `url`. All requests go to this URL. Use it for a `/compat` URL or a self-hosted gateway.
2. `account_id` + `gateway`. Requests go to `https://gateway.ai.cloudflare.com/v1/<account_id>/<gateway>/compat/...`. You get AI Gateway caching, retries, cost tracking and request logs.
3. `account_id` only. Requests go to the direct Workers AI API at `https://api.cloudflare.com/client/v4/accounts/<account_id>/ai/v1/...`.

#### `/compat` and the provider path

AI Gateway exposes Workers AI on two paths. They do not behave the same. The provider path is `<gateway>/workers-ai/v1`.

| Path | Chat completions | Embeddings | Model ID |
|---|---|---|---|
| `<gateway>/compat` | 200 | 200 | `workers-ai/@cf/...` or bare `@cf/...` |
| `<gateway>/workers-ai/v1` (provider path) | 200 | 200 or 401 | bare `@cf/...` |
| direct API | 200 | 200 | bare `@cf/...` |

Measured 2026-09-09 on two AI Gateways on one account:

- Gateway with Authenticated Gateway on (`authentication: true`): `/workers-ai/v1/embeddings` returned `401 Authentication error`. Chat completions on the same gateway returned 200. Both operations on `/compat` returned 200. A second API token gave the same result. The cause is the gateway setting, not the token.
- Gateway with the setting off: the provider path served embeddings.

The exact rule is not known. An authenticated gateway also rejects `/compat` requests without `cf-aig-authorization`. `/compat` worked on every gateway and token tested. Since 0.7.0 the `gateway` config uses `/compat`. Set `gateway_path => 'workers-ai/v1'` to use the provider path.

#### Authenticated Gateway

A gateway with Authenticated Gateway on needs a gateway token in the `cf-aig-authorization` header, together with your Workers AI token in `Authorization`. Set `gateway_token`:

```php
'workers-ai' => [
    'key'           => env('CLOUDFLARE_AI_API_TOKEN'),
    'account_id'    => env('CLOUDFLARE_ACCOUNT_ID'),
    'gateway'       => env('CLOUDFLARE_AI_GATEWAY'),
    'gateway_token' => env('CLOUDFLARE_AI_GATEWAY_TOKEN'),
],
```

Omit `gateway_token` and the package does not send the header. Without it, an authenticated gateway returns `{"code":10000,"message":"Authentication error"}`. That message does not name the gateway or the header. Before 0.7.0 the package could not send this header.

#### Model ID prefix

`/compat` routes on a `workers-ai/` prefix. Write bare `@cf/...` IDs everywhere. The package adds the prefix on `/compat` and sends the bare ID on the other paths.

A bare ID on `/compat` also works. Through 0.6.1 the package threw on it. A prefixed ID on the direct API returns `400 No such model`. The package still throws on that.

### Reasoning effort

`/compat` accepts OpenAI's `reasoning_effort` field. laravel/ai does not send it. The string does not appear in v0.11.2 or on the `1.x` branch. With no value, a Workers AI reasoning model picks its own effort, and picks a high one.

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

Or per agent. The agent value wins over the provider value:

```php
use Meirdick\WorkersAi\Attributes\ReasoningEffort;

#[ReasoningEffort(ReasoningEffort::LOW)]
class DraftingAgent implements Agent { /* ... */ }
```

A `reasoning_effort` key returned from `providerOptions()` wins over both.

Only `low`, `medium` and `high` are valid. Cloudflare returns `400` for OpenAI's `none` and `minimal`. The package rejects them before the request.

Not every model obeys it. Measured:

| Model | Result |
|---|---|
| `@cf/openai/gpt-oss-120b` | Obeys all three levels. |
| `@cf/zai-org/glm-5.3-flash` | Any value turns reasoning off. 1,240 chars unset. 0 chars at `low` and at `high`. |
| `@cf/zai-org/glm-4.7-flash` | Ignores it. `low` gave more reasoning than unset (6,012 vs 4,003 chars). Both calls took about 29s. |

### Non-answer detection

laravel/ai's `TextGenerationLoop` never checks for `FinishReason::Length`. It returns a truncated answer as a success. It returns a `content: null` response as an empty string, and `toArray()` gives `[]`. In an unattended pipeline you cannot tell that from a real short answer.

```php
'workers-ai' => [
    // ...
    'throw_on_empty_response' => true,   // default: true
    'throw_on_truncation'     => false,  // default: false
],
```

- `throw_on_empty_response` (default on) throws `Meirdick\WorkersAi\Exceptions\EmptyResponseException` when a step returns no text and no tool calls. Reproduced on `@cf/openai/gpt-oss-120b`: a 16-token cap gives `finish_reason: "length"`, `content: null`, HTTP 200. A tool-call turn with no text does not throw.
- `throw_on_truncation` (default off) throws `Meirdick\WorkersAi\Exceptions\TruncatedResponseException` on any `FinishReason::Length`. Turn it on for extraction and drafting work.

Both exceptions extend `Laravel\Ai\Exceptions\AiException`. Both name the model and the token counts.

### Cost tracking

Cloudflare bills Workers AI in neurons. Every endpoint shape returns the figure under `usage.neurons`. `Laravel\Ai\Responses\Data\Usage` has five integer token fields and no other field. `Meta` has no free-form field. The SDK response objects have no place for a neuron count. The package sends an event instead:

```php
use Meirdick\WorkersAi\Events\WorkersAiUsageReported;

Event::listen(WorkersAiUsageReported::class, function (WorkersAiUsageReported $event) {
    Spend::record($event->model, $event->neurons);
});
```

One event per model call. A two-step tool loop sends two. A cost model based on token counts gives the wrong number.

The raw HTTP response is also on the response and on each step:

```php
$response = agent('helper')->prompt('...', provider: 'workers-ai');

$neurons = $response->raw?->json('usage.neurons');
$latency = $response->raw?->transferStats?->getTransferTime();
```

### Token counts

`Usage::$promptTokens` is the uncached prompt count. `Usage::$cacheReadInputTokens` is the cached count. The two add up to the wire `prompt_tokens`. laravel/ai 0.11.1 adopted this split for every OpenAI-shaped provider. The package applies it on every laravel/ai version it supports.

Measured 2026-09-16 on `@cf/zai-org/glm-5.3-flash`, two identical calls under one `x-session-affinity`:

| Call | Wire `prompt_tokens` | Wire `cached_tokens` | `promptTokens` | `cacheReadInputTokens` | Neurons |
|---|---|---|---|---|---|
| 1 | 1221 | 0 | 1221 | 0 | 17.8 |
| 2 | 1221 | 1216 | 5 | 1216 | 8.3 |

The prefix cache is model-specific. `glm-5.3-flash` and `kimi-k2.6` hit. `llama-3.3-70b`, `llama-4-scout` and `gpt-oss-120b` did not hit in any test.

### Custom headers

The package sends the `headers` array from the provider config. Every first-party laravel/ai provider does the same since 0.10.3. A configured header replaces a package header with the same name. Names match without case.

```php
'workers-ai' => [
    // ...
    'headers' => ['X-Trace-Id' => env('TRACE_ID')],
],
```

On laravel/ai 1.x, the `ai_sdk_extra_headers` provider option and `Provider::withHeaders()` write to the same array. Per-call headers reach Workers AI.

## Quickstart

```php
use function Laravel\Ai\agent;

$response = agent('helper')->prompt('Say hi.', provider: 'workers-ai');
echo $response->text;
```

Pass any [Workers AI model](https://developers.cloudflare.com/workers-ai/models/) as `model:`:

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

Pass extra request fields with `withProviderOptions`. Before laravel/ai 0.9 the method was `providerOptions()`.

```php
Embeddings::for(['hello'])
    ->withProviderOptions(['encoding_format' => 'base64'])
    ->generate(provider: 'workers-ai');
```

## Streaming

```php
use Laravel\Ai\Streaming\Events\TextDelta;

foreach (agent('helper')->stream('Tell me a story.', provider: 'workers-ai') as $event) {
    if ($event instanceof TextDelta) {
        echo $event->delta;
    }
}
```

A reasoning model sends `ReasoningStart`, `ReasoningDelta` and `ReasoningEnd` events before the text events.

Models return reasoning text under different keys. The package reads all of them. Measured 2026-09-09:

| Model | Reasoning key |
|---|---|
| `@cf/zai-org/glm-5.3-flash` | `reasoning_content` |
| `@cf/openai/gpt-oss-120b` | `reasoning` and `reasoning_content`, same text |
| `@cf/zai-org/glm-4.7-flash` | both, same text |
| `@cf/meta/llama-3.3-70b-instruct-fp8-fast` | `reasoning`, always `null` |
| `@cf/qwen/qwq-32b` | none. Reasoning is inside `content`. |

## Tools

```php
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Laravel\Ai\Tools\Request;

class GetWeather implements Tool
{
    public function description(): string
    {
        return 'Look up the current weather.';
    }

    public function handle(Request $request): string
    {
        return "Sunny in {$request['city']}.";
    }

    public function schema(JsonSchema $schema): array
    {
        return ['city' => $schema->string()->required()];
    }
}

class WeatherAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'Use the tool to answer weather questions.';
    }

    public function tools(): iterable
    {
        return [new GetWeather];
    }
}

(new WeatherAgent)->prompt('Weather in Tokyo?', provider: 'workers-ai');
```

The package keeps the reasoning text from the tool-call turn and replays it on the next turn through `providerContentBlocks`.

### Model choice for tool calls

Verified 2026-06-11 on the direct API: `@cf/meta/llama-3.3-70b-instruct-fp8-fast`, the package's default text model, does not emit tool calls on the `/v1` endpoint. It answers in prose. `@cf/meta/llama-4-scout-17b-16e-instruct` and `@cf/openai/gpt-oss-120b` emit tool calls. Under `tool_choice: auto`, open-weight models call a tool only some of the time. When the tool must run, force it with provider options:

```php
public function providerOptions(Lab|string $provider): array
{
    // A custom driver arrives as a string, not a Lab enum case.
    return $provider === 'workers-ai' ? ['tool_choice' => 'required'] : [];
}
```

On the turn after a tool result, the package changes a forced `tool_choice` to `auto`. If it did not, the model would call a tool again instead of answering, until max-steps, with empty text.

## Timeouts

laravel/ai uses a 60-second timeout by default. Large models, structured output and reasoning models on Workers AI can take longer. Observed: a structured `llama-3.3-70b` request over 60s, and `kimi-k2.6` at 45s on a small prompt. Raise it per agent or per call:

```php
use Laravel\Ai\Attributes\Timeout;

#[Timeout(120)]
class ExtractionAgent implements Agent { /* ... */ }

// or per call:
$agent->prompt('...', provider: 'workers-ai', timeout: 120);
```

A request that passes the timeout fails after one attempt. On laravel/ai 0.11 and later it throws `ProviderConnectionException`. On 0.9 and 0.10 it throws Illuminate's `ConnectionException`. Before v0.3.0 the package retried a timed-out request, which turned a 60s timeout into about 3 minutes. The package still retries connect-phase failures and transient 502, 503 and 504 responses.

## Structured output

```php
use Laravel\Ai\Attributes\Strict;

#[Strict] // strict JSON schema
final class TaskAgent extends \Laravel\Ai\Agent {}
```

With `#[Strict]`, the request sends `strict: true` to `/compat` and the JSON schema requires all properties.

Workers AI JSON mode is best-effort. The package checks the result for required fields and enum values. If the check fails, it asks again, up to `structured_output_retries` times (default 2). It does not ask again after a `Length` finish. A larger token cap fixes that, not a re-ask.

## Reasoning models

Many Workers AI models emit reasoning text before the answer. The general control is [`reasoning_effort`](#reasoning-effort). Use that first.

`chat_template_kwargs.thinking` is an older control. It is model-specific. Through v0.6.1 this README presented it as the general control on Workers AI. It is not. Verified on `@cf/zai-org/glm-5.3-flash`: `chat_template_kwargs: {thinking: false}` gave 1,172 chars of reasoning against 1,240 unset. No effect. It works on the Kimi chat template and on models that share it. Use it only when you know the model reads it.

Pass it through `HasProviderOptions`. laravel/ai uses the same interface for Anthropic `thinking` and Gemini `thinkingConfig`. The package merges the returned array into the request body:

```php
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;

class AnalysisAgent implements Agent, HasProviderOptions
{
    public function providerOptions(Lab|string $provider): array
    {
        // Workers AI is a custom driver, so $provider arrives as a string.
        return $provider === 'workers-ai'
            ? ['chat_template_kwargs' => ['thinking' => false]]
            : [];
    }
}
```

On models that read it, `thinking: false` is the correct default for structured output and extraction. Reasoning and `response_format` share one token cap, and reasoning about triples latency. Verified on Kimi K2.6: structured calls returned valid JSON in about 4s with thinking off. With thinking on, they broke the schema and passed the 60s timeout. On models that ignore the flag, use `#[ReasoningEffort(ReasoningEffort::LOW)]`.

Set `thinking: true` for free-form tasks that can wait. The package then:

- keeps the reasoning text (from the `reasoning_content` key or the K2.6 `reasoning` key) and replays it across tool-call turns;
- raises `max_completion_tokens` to 2048 when it is lower. Reasoning and the answer share one token cap. A small cap returns `content: null` and `finish_reason: "length"`. Raise `#[Timeout]` too.

## Operational limits

Measured on one Workers AI account through AI Gateway. The numbers show the order of magnitude. They are not a contract.

**HTTP 408.** The generation took longer than the gateway allows. This is not a client timeout. A client timeout arrives as cURL error 28. Reproduced 2026-09-09: `@cf/zai-org/glm-5.3-flash` with `max_tokens: 24000` returned `408 Request timeout` after 709 seconds.

Since 0.8.0 the package throws `Meirdick\WorkersAi\Exceptions\GatewayTimeoutException`. It implements laravel/ai's `FailoverableException`, so a fallback provider gets a chance. The package tries once. A retry of a 709-second generation costs another 709 seconds and fails the same way. Lower the token cap, lower `reasoning_effort`, or split the work.

**HTTP 429.** laravel/ai maps a 429 to `RateLimitedException` and marks it failoverable. It does not retry it. In a wide fan-out, one 429 is one lost request.

Since 0.8.0 the package retries a 429 with exponential backoff. It reads a `Retry-After` header in both forms, delay-seconds and HTTP-date. After the last attempt it throws `RateLimitedException` for failover.

```php
'workers-ai' => [
    // ...
    'retry'              => true,   // false turns retrying off
    'retry_attempts'     => 3,      // total attempts, including the first
    'retry_rate_limited' => true,   // false makes a 429 fail over at once
],
```

Backoff is 500ms, 1s, 2s and so on, capped at 20s. A `Retry-After` above the cap is cut to the cap.

**Cloudflare edge errors.** 520 is unknown error, 522 is connection timed out, 524 is origin timeout. The package retries all three. It then maps them to `ProviderOverloadedException`, the same as 502, 503 and 504. Each request crosses Cloudflare's edge twice, once to the gateway and once to the model runner. These codes are more likely here than on a typical OpenAI-compatible provider. Through 0.7.0 the package retried only 502, 503 and 504.

**Gateway retry and client timeout.** An AI Gateway with `retry_max_attempts: 3` retries inside your one HTTP request. A late error makes the request several times longer. Your own timeout then cuts it. You see cURL error 28 and never see what the gateway saw. Gateway retry also cannot retry a 200 with `content: null`, which is the failure you hit most. Use one retry layer. If you use the gateway's, set `retry => false` here.

**Concurrency.** A fan-out of 40 small requests to one account returned 40 HTTP 200s. A different account and model lost about 10 of 28 requests to 429s in one second. Limits depend on the account and the model. Do not trust a specific width. The package retries the 429s. It does not limit the fan-out. Limit it in your application.

**Model notes.**

- `@cf/openai/gpt-oss-120b`. The only model measured whose reasoning stays in proportion to the prompt. Obeys `reasoning_effort`. A safe default for agent work.
- `@cf/zai-org/glm-5.3-flash`. Good on short inputs. Treats `reasoning_effort` as on/off. Ignores `chat_template_kwargs.thinking`. With no token cap it ran 8,190 tokens in 369 seconds. Always cap it.
- `@cf/zai-org/glm-4.7-flash`. Ignores `reasoning_effort`. Took about 29 seconds on a two-sentence question in every test. Returns `content` normally.
- `@cf/zai-org/glm-5.3`. Not enabled on every account. Returns `403 This account is not allowed to access` where it is not.

## AI Gateway

Set `gateway` to route through Cloudflare AI Gateway. You get caching, retries, cost analytics and request logs in the Cloudflare dashboard. Since 0.7.0 this uses the gateway's `/compat` path. See [Endpoint resolution](#endpoint-resolution).

```php
'workers-ai' => [
    'key'        => env('CLOUDFLARE_AI_API_TOKEN'),
    'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
    'gateway'    => 'my-gateway',
],
```

Set `session_affinity` to send the `x-session-affinity` header. Related requests then reach the same cache shard.

## Models

Workers AI hosts many open-weight models. See the [model catalog](https://developers.cloudflare.com/workers-ai/models/). Common prefixes:

- `@cf/meta/...`: Llama
- `@cf/openai/...`: OpenAI open-weight models
- `@cf/google/...`: Gemma
- `@cf/qwen/...`, `@cf/mistralai/...`, `@cf/microsoft/...`
- `@cf/baai/...`: embedding models

Model IDs are strings you supply. The package does not check that a model exists. Cloudflare returns `410 Model has been deprecated` for a retired model. It returns `403 This account is not allowed to access` for a model your plan does not include. Both came up in 0.7.0 testing. `@cf/meta/llama-3.1-8b-instruct` is retired. It was the `#[UseCheapestModel]` default through 0.6.1. `@cf/zai-org/glm-5.3` is not enabled on every account.

## Provider keys

Use `workers-ai` (primary) or `workersai` (alias).

## Upgrading to 0.7.0

Verified on four consuming applications before release. Three upgraded with no test changes. The fourth hit a laravel/ai issue, not a package issue.

**From 0.6.x.** Check three things:

1. If you use `account_id` + `gateway`, requests move from `.../<gw>/workers-ai/v1` to `.../<gw>/compat`. The package adds the model prefix. No code change. The AI Gateway dashboard shows the traffic under `compat`. Set `gateway_path => 'workers-ai/v1'` to keep the old path.
2. `throw_on_empty_response` is now on. If you want empty responses, set it to `false`.
3. `#[UseCheapestModel]` points at a new model. The old one is retired.

**From 0.5.x or earlier.** Bump `laravel/ai` in the same command. This package needs `^0.9`. Requiring it alone fails:

```
meirdick/laravel-cf-workersai v0.7.0 requires laravel/ai ^0.9 || ^0.10 || ^0.11
  -> found laravel/ai[v0.9.0, ..., v0.11.2] but it conflicts with your root
     composer.json require (^0.7).
```

Bump both:

```bash
composer require "laravel/ai:^0.11" "meirdick/laravel-cf-workersai:^0.7" -W
```

Two things to expect. Neither is caused by this package:

- laravel/ai's database schema changed between 0.9 and 0.11. The conversation table gained `participant_type` and `participant_id`. If you published its migrations, publish them again or reconcile them. If not, you get `table agent_conversations has no column named participant_type` at runtime. The same failures occur with this package pinned to 0.6.1.
- If your app declares `"php": "^8.2"`, composer still resolves, because it checks the PHP you run, not your declared floor. This package needs `^8.3`. Your `composer.json` then understates your real minimum, and `composer install` fails on an 8.2 target. Set your own `php` constraint to `^8.3`.

## Handoff

[`HANDOFF.md`](HANDOFF.md) is the full brief for a new maintainer. It covers what the package is for, when not to use it, each trap with its measured number, the test layout, and what the package does not handle on purpose.

## Versioning

This package follows [Semantic Versioning](https://semver.org/).

- `laravel/ai ^1.0` support is on `main`. The branch alias is `1.0.x-dev`. Install with `"meirdick/laravel-cf-workersai": "1.0.x-dev"`. It tags as 1.0.0 when laravel/ai tags 1.0.
- Version 0.6 and later need `laravel/ai ^0.9`, the single-step `StepTextGateway` contract.
- Use `^0.5` of this package for `laravel/ai ^0.7 || ^0.8`.

## License

MIT

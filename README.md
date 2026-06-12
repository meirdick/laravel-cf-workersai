# laravel-cf-workersai

A native [Laravel AI](https://github.com/laravel/ai) provider for [Cloudflare Workers AI](https://developers.cloudflare.com/workers-ai/) with first-class support for [Cloudflare AI Gateway](https://developers.cloudflare.com/ai-gateway/).

- Text generation, embeddings, structured output, tool calling, streaming.
- Three URL shapes: direct Workers AI, AI Gateway routed, or arbitrary `/compat` endpoint.
- Reasoning content replay across tool-call turns.
- `#[Strict]` JSON schema opt-in.
- Provider options pass-through.
- Sub-agent tools, and MCP tools on laravel/ai `^0.8`.
- Streamed usage summed across tool-call steps.
- Retry policy and AI Gateway session affinity.
- Failover-ready: 429/402/502/503/504 map to laravel/ai's failoverable exceptions.

## Requirements

- PHP `^8.3`
- `laravel/ai ^0.7 || ^0.8`

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

Cloudflare's `/v1/chat/completions` defaults to **256 tokens** when `max_completion_tokens` is omitted — far too small for any non-trivial structured output, which then arrives mid-JSON with a misreported `finish_reason: "stop"`. The package sends `4096` by default to defuse this. Override the value per-provider, or set it to `null` to fall back to Cloudflare's endpoint default. Per-call `#[MaxTokens(...)]` (or `TextGenerationOptions::$maxTokens`) always wins.

The package also normalizes Cloudflare's misreported `stop`-at-budget into `FinishReason::Length` so laravel/ai's length-aware retry primitives can react to truncated completions.

### Endpoint resolution

There are three ways to configure the endpoint, in priority order:

1. **`url`** (explicit). When set, all requests go to this URL. Useful for `/compat` or self-hosted gateways.
2. **`account_id` + `gateway`**. Routes through `https://gateway.ai.cloudflare.com/v1/<account_id>/<gateway>/workers-ai/v1/...` — get AI Gateway's caching, retries, cost tracking.
3. **`account_id` only**. Hits the direct Workers AI API at `https://api.cloudflare.com/client/v4/accounts/<account_id>/ai/v1/...`.

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

Forward arbitrary fields with `providerOptions`:

```php
Embeddings::for(['hello'])
    ->generate(provider: 'workers-ai', providerOptions: ['encoding_format' => 'base64']);
```

## Streaming

```php
foreach (agent('helper')->streamed('Tell me a story.', provider: 'workers-ai') as $event) {
    if ($event instanceof \Laravel\Ai\Events\TextDelta) {
        echo $event->text;
    }
}
```

Reasoning-capable models (e.g. Kimi K2.5) emit `ReasoningStart` → `ReasoningDelta` → `ReasoningEnd` events before text.

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

## AI Gateway

Set the `gateway` config key to route through Cloudflare AI Gateway. You get free caching, retries, cost analytics, and request logs in the Cloudflare dashboard.

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

## Provider keys

The provider can be referenced as `workers-ai` (primary) or `workersai` (alias).

## Versioning

This package follows [Semantic Versioning](https://semver.org/). Compatible with `laravel/ai ^0.7 || ^0.8` — the test suite runs against both bounds.

## License

MIT

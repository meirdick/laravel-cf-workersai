# laravel-cf-workersai

A native [Laravel AI](https://github.com/laravel/ai) provider for [Cloudflare Workers AI](https://developers.cloudflare.com/workers-ai/) with first-class support for [Cloudflare AI Gateway](https://developers.cloudflare.com/ai-gateway/).

- Text generation, embeddings, structured output, tool calling, streaming.
- Three URL shapes: direct Workers AI, AI Gateway routed, or arbitrary `/compat` endpoint.
- Reasoning content replay across tool-call turns.
- `#[Strict]` JSON schema opt-in.
- Provider options pass-through.
- Sub-agent tools.
- Retry policy and AI Gateway session affinity.

## Requirements

- PHP `^8.3`
- `laravel/ai ^0.7`

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
        'api_key'    => env('CLOUDFLARE_AI_API_KEY'),
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'gateway'    => env('CLOUDFLARE_AI_GATEWAY'), // optional
        // 'url'     => env('CLOUDFLARE_AI_URL'),     // optional escape hatch
    ],
],
```

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
    'api_key'    => env('CLOUDFLARE_AI_API_KEY'),
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

This package follows [Semantic Versioning](https://semver.org/). Pinned to `laravel/ai ^0.7`.

## License

MIT

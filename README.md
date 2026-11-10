# laravel-cf-workersai

[Cloudflare Workers AI](https://developers.cloudflare.com/workers-ai/) provider for the [Laravel AI SDK](https://github.com/laravel/ai): text, embeddings, reranking, images, speech, transcription, [Clef](https://developers.cloudflare.com/workers-ai/models/clef/) decisions and [Clairic](https://developers.cloudflare.com/workers-ai/models/clairic/) typed extraction, with [AI Gateway](https://developers.cloudflare.com/ai-gateway/) routing.

Docs: [laravel-cf-workersai-docs.mdick85.workers.dev/docs](https://laravel-cf-workersai-docs.mdick85.workers.dev/docs/)

## Install

```bash
composer require meirdick/laravel-cf-workersai
```

It needs PHP `^8.3` and `laravel/ai` `^0.9 || ^0.10 || ^0.11 || ^1.0`, and it's tested on v0.9.1, v0.10.3, v0.11.2, v1.0.0 and v1.3.0. If you're on `laravel/ai` 0.7 or 0.8, use this package at `^0.5`.

Laravel's package discovery registers the provider, so there's nothing to publish. Add your credentials to your application's `.env` file:

```ini
CLOUDFLARE_AI_API_TOKEN=
CLOUDFLARE_ACCOUNT_ID=
CLOUDFLARE_AI_GATEWAY=        # optional
```

Then add the provider to `config/ai.php`, which reads those values:

```php
'providers' => [
    'workers-ai' => [
        'driver'     => 'workers-ai',
        'key'        => env('CLOUDFLARE_AI_API_TOKEN'),
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'gateway'    => env('CLOUDFLARE_AI_GATEWAY'), // optional
    ],
],
```

The token needs the Account, Workers AI, Read permission. With `account_id` alone, the package calls Workers AI directly. Add `gateway` to send the same calls through an AI Gateway. `url` replaces both, and you can't set it together with `account_id`. If your gateway has authentication turned on, it also needs `gateway_token`. See [Direct and gateway routes](https://laravel-cf-workersai-docs.mdick85.workers.dev/docs/concepts/routes/).

## Text

```php
use function Laravel\Ai\agent;

$response = agent('You are a helpful assistant.')
    ->prompt('Say hi.', provider: 'workers-ai');

echo $response->text;
```

The default model is `@cf/meta/llama-3.3-70b-instruct-fp8-fast`, and you can pass `model:` to use another. When the call sets no token cap, the package sends 4096, because Cloudflare's own default is 256.

## Decisions and extraction

Clef is Cloudflare's decision model. It answers Laravel AI classification questions with a probability, and it needs `laravel/ai` 1.0 or later.

```php
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;

$response = Classification::of($ticket)
    ->question('urgent', new Boolean('Does this need a human now?'))
    ->classify(provider: 'workers-ai');

$response['urgent']->isTrue();
```

The default model is `@cf/cloudflare/clef`. You can also use `clef-flash` and `clef-omni`.

Clairic is Cloudflare's extraction model. Instead of picking from answers you list, it returns a typed value for each field you ask for, or null when the input doesn't say. It works on every supported `laravel/ai` version.

```php
use Meirdick\WorkersAi\Extraction;
use Meirdick\WorkersAi\Extraction\Number;
use Meirdick\WorkersAi\Extraction\Text;

$invoice = Extraction::of($text)
    ->fields([
        'invoice_number' => new Text,
        'total' => new Number(decimals: 2, unit: 'USD'),
    ])
    ->extract();

$invoice['total']->value();
```

Laravel AI has no extraction API, so the package adds `Extraction`. It works like `Classification`, with `Extraction::fake()`, `assertExtracted()`, events and provider failover. Clairic is still a Cloudflare preview model.

Both models work on the direct route and through a gateway, with the same provider entry. A `url` that ends in `/compat` can't serve them, so the package throws.

## Images, audio, reranking, documents and search

These use Laravel AI's own APIs, plus a small addition where Laravel AI has none (`Markdown` and `CloudflareWebSearch`). They work on the direct route and through a gateway.

```php
use Laravel\Ai\Audio;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Image;
use Laravel\Ai\Reranking;
use Laravel\Ai\Transcription;
use Meirdick\WorkersAi\Markdown;
use Meirdick\WorkersAi\Tools\CloudflareWebSearch;

Image::of('A green apple.')->landscape()->generate(provider: 'workers-ai');
Audio::of('Your order has shipped.')->generate(provider: 'workers-ai');
Transcription::fromPath($path)->generate(provider: 'workers-ai');
Reranking::of($documents)->limit(5)->rerank($query, provider: 'workers-ai');
Markdown::of(Document::fromPath('invoice.pdf'))->convert();
agent(tools: [new CloudflareWebSearch])->prompt($question, provider: 'workers-ai', model: '@cf/openai/gpt-oss-120b');
```

## Why this package

Laravel AI's `openai-compatible` driver can already call a Workers AI `/compat` URL. That's enough if you only send text to one model and every agent sets `#[MaxTokens]`.

Use this package when you also want a default token cap, an exception when a reasoning model returns nothing, `reasoning_effort`, neuron figures (Cloudflare bills Workers AI in neurons, not tokens), retries on HTTP 429 and Cloudflare edge errors, Authenticated Gateway, or Clef and Clairic.

The rest is in the docs:

- [Quickstart](https://laravel-cf-workersai-docs.mdick85.workers.dev/docs/quickstart/)
- [Clef](https://laravel-cf-workersai-docs.mdick85.workers.dev/docs/system-one/clef/) and [Clairic](https://laravel-cf-workersai-docs.mdick85.workers.dev/docs/system-one/clairic/)
- [Direct and gateway routes](https://laravel-cf-workersai-docs.mdick85.workers.dev/docs/concepts/routes/)
- [Reranking](https://laravel-cf-workersai-docs.mdick85.workers.dev/docs/guides/reranking/), [images](https://laravel-cf-workersai-docs.mdick85.workers.dev/docs/guides/images/), [speech and transcription](https://laravel-cf-workersai-docs.mdick85.workers.dev/docs/guides/audio/), [documents](https://laravel-cf-workersai-docs.mdick85.workers.dev/docs/guides/documents/), [web search](https://laravel-cf-workersai-docs.mdick85.workers.dev/docs/guides/web-search/)
- [AI Gateway](https://laravel-cf-workersai-docs.mdick85.workers.dev/docs/guides/ai-gateway/)
- [Configuration](https://laravel-cf-workersai-docs.mdick85.workers.dev/docs/reference/configuration/)
- [Upgrade to 1.0](https://laravel-cf-workersai-docs.mdick85.workers.dev/docs/guides/upgrade/)

## License

MIT

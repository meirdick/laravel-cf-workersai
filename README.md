# laravel-cf-workersai

A [Laravel AI](https://github.com/laravel/ai) provider for [Cloudflare Workers AI](https://developers.cloudflare.com/workers-ai/). Chat, embeddings, tools, and the System One models Cloudflare hosts: [Clef](https://developers.cloudflare.com/workers-ai/models/clef/) for decisions and [Clairic](https://developers.cloudflare.com/workers-ai/models/clairic/) for typed extraction. Works against the direct Workers AI API and through [AI Gateway](https://developers.cloudflare.com/ai-gateway/).

Docs: [laravel-cf-workersai-docs.mdick85.workers.dev/docs](https://laravel-cf-workersai-docs.mdick85.workers.dev/docs/)

## Install

```bash
composer require meirdick/laravel-cf-workersai
```

PHP `^8.3`. `laravel/ai` `^0.9 || ^0.10 || ^0.11 || ^1.0`, tested on v0.9.1, v0.10.3, v0.11.2, v1.0.0 and v1.3.0. For `laravel/ai` 0.7 or 0.8, use this package `^0.5`.

Package discovery registers the provider. Add it to `config/ai.php`:

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

`key` is a Cloudflare API token with the `Workers AI: Read` permission. `account_id` alone calls Workers AI directly. Add `gateway` to send the same calls through an AI Gateway. `url` replaces both, and cannot be set with `account_id`.

## Text

```php
use function Laravel\Ai\agent;

$response = agent('You are a helpful assistant.')
    ->prompt('Say hi.', provider: 'workers-ai');

echo $response->text;
```

The default model is `@cf/meta/llama-3.3-70b-instruct-fp8-fast`. Pass `model:` to use another. The package sends a 4096 token cap when the call sets none. Cloudflare's own default is 256.

## Decisions and extraction

Clef answers laravel/ai classification questions. Needs `laravel/ai` 1.0 or later.

```php
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;

$response = Classification::of($ticket)
    ->question('urgent', new Boolean('Does this need a human now?'))
    ->classify(provider: 'workers-ai');

$response['urgent']->isTrue();
```

The default model is `@cf/cloudflare/clef`. Also `clef-flash` and `clef-omni`.

Clairic is not a classification model. It returns a typed value per field, or null. This works on every supported `laravel/ai` version.

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

Both post to `POST /accounts/{account}/ai/run/{model}`. A configured gateway is the `cf-aig-gateway-id` header on that URL. A `/compat` URL does not serve these models, and the package throws if that is all you configured.

## Why this package

laravel/ai's `openai-compatible` driver can call a Workers AI `/compat` URL. Use it if you send text to one model and every agent has `#[MaxTokens]`.

Use this package when you also want a default token cap, an exception on an empty reasoning response, `reasoning_effort`, neuron figures, 429 and edge-error retries, Authenticated Gateway, or Clef and Clairic.

The rest of the reference is in the docs:

- [Quickstart](https://laravel-cf-workersai-docs.mdick85.workers.dev/docs/quickstart/)
- [Decisions and extraction](https://laravel-cf-workersai-docs.mdick85.workers.dev/docs/guides/decisions/)
- [AI Gateway](https://laravel-cf-workersai-docs.mdick85.workers.dev/docs/guides/ai-gateway/)
- [Configuration](https://laravel-cf-workersai-docs.mdick85.workers.dev/docs/reference/configuration/)
- [Upgrade to 1.0](https://laravel-cf-workersai-docs.mdick85.workers.dev/docs/guides/upgrade/)

## License

MIT

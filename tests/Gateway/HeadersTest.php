<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Providers\Provider;
use Tests\Fixtures\Agents\AssistantAgent;

use function Laravel\Ai\agent;

/*
|--------------------------------------------------------------------------
| Configured and per-call HTTP headers
|--------------------------------------------------------------------------
|
| laravel/ai 0.10.3 added a `headers` key to every provider's connection
| config. 1.x builds on it: `Provider::withHeaders()` clones the provider
| with extra headers merged into that same key, and the `ai_sdk_extra_headers`
| provider option rides on it. A gateway that ignores `headers` silently
| drops both. Through 0.8.2 this one did.
|
*/
beforeEach(function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'key' => 'test-key',
        'account_id' => 'test-account',
        'headers' => ['X-Trace' => 'abc-123'],
    ]]);
});

test('configured headers are sent on chat completions', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    agent()->prompt('Hello', provider: 'workersai');

    Http::assertSent(fn (Request $r) => $r->hasHeader('X-Trace', 'abc-123')
        && $r->hasHeader('Authorization', 'Bearer test-key'));
});

test('configured headers are sent on streamed chat completions', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::response(
            $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'content' => 'Hi']),
                $this->chatChunkFinish('stop'),
                '[DONE]',
            ]),
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $this->collectStreamEvents(new AssistantAgent);

    Http::assertSent(fn (Request $r) => $r->hasHeader('X-Trace', 'abc-123'));
});

test('configured headers are sent on embeddings', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2]]],
        'model' => '@cf/baai/bge-large-en-v1.5',
        'usage' => ['prompt_tokens' => 3, 'total_tokens' => 3],
    ])]);

    Embeddings::for(['hello'])->generate(provider: 'workersai');

    Http::assertSent(fn (Request $r) => $r->hasHeader('X-Trace', 'abc-123'));
});

test('configured headers do not displace the package headers', function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'gateway' => 'my-gateway',
        'gateway_token' => 'aig-token',
        'session_affinity' => 'session-1',
    ]]);

    Http::fake(['gateway.ai.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    agent()->prompt('Hello', provider: 'workersai');

    Http::assertSent(fn (Request $r) => $r->hasHeader('X-Trace', 'abc-123')
        && $r->hasHeader('cf-aig-authorization', 'Bearer aig-token')
        && $r->hasHeader('x-session-affinity', 'session-1')
        && $r->hasHeader('Authorization', 'Bearer test-key'));
});

test('a configured header overrides the package header of the same name, case-insensitively', function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'session_affinity' => 'from-config-key',
        'headers' => ['X-Session-Affinity' => 'from-headers'],
    ]]);

    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    agent()->prompt('Hello', provider: 'workersai');

    Http::assertSent(fn (Request $r) => $r->header('x-session-affinity') === ['from-headers']);
});

test('per-call headers from Provider::withHeaders() are sent', function () {
    if (! method_exists(Provider::class, 'withHeaders')) {
        $this->markTestSkipped('laravel/ai '.\Composer\InstalledVersions::getPrettyVersion('laravel/ai').' has no Provider::withHeaders().');
    }

    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    $provider = app(\Laravel\Ai\AiManager::class)->instance('workersai')->withHeaders(['X-Request' => 'r-1']);

    $provider->textGateway()->generateTextStep(
        $provider, '@cf/meta/llama-3.2-3b-instruct', null,
        [new \Laravel\Ai\Messages\UserMessage('Hello')], [], null, null, null,
        new \Laravel\Ai\Gateway\StepContext,
    );

    Http::assertSent(fn (Request $r) => $r->hasHeader('X-Request', 'r-1')
        && $r->hasHeader('X-Trace', 'abc-123'));
});

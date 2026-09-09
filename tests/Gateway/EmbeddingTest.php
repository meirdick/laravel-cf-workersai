<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;

beforeEach(function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'key' => 'test-key',
        'account_id' => 'test-account',
    ]]);
});

test('embeddings request includes model and input', function () {
    Http::fake(['api.cloudflare.com/*' => fakeWorkersAiEmbeddingsResponse()]);

    Embeddings::for(['Hello world'])->generate(provider: 'workersai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === '@cf/baai/bge-large-en-v1.5'
            && $body['input'] === ['Hello world']
            && str_ends_with($request->url(), '/embeddings');
    });
});

test('embeddings response is correctly parsed', function () {
    Http::fake(['api.cloudflare.com/*' => fakeWorkersAiEmbeddingsResponse()]);

    $response = Embeddings::for(['Hello world'])->generate(provider: 'workersai');

    expect($response->embeddings)->toHaveCount(1)
        ->and($response->embeddings[0])->toHaveCount(3)
        ->and($response->tokens)->toBe(10)
        ->and($response->meta->provider)->toBe('workersai');
});

test('multiple inputs return multiple embeddings', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response([
        'object' => 'list',
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
            ['object' => 'embedding', 'index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
        ],
        'model' => '@cf/baai/bge-large-en-v1.5',
        'usage' => ['total_tokens' => 20],
    ])]);

    $response = Embeddings::for(['Hello', 'World'])->generate(provider: 'workersai');

    expect($response->embeddings)->toHaveCount(2);
});

test('embeddings request uses correct base url', function () {
    Http::fake(['api.cloudflare.com/*' => fakeWorkersAiEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'workersai');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.cloudflare.com/client/v4/accounts/test-account/ai/v1/embeddings';
    });
});

test('embeddings request sends bearer token', function () {
    Http::fake(['api.cloudflare.com/*' => fakeWorkersAiEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'workersai');

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('Authorization', 'Bearer test-key');
    });
});

/*
 * Embeddings are the reason the AI Gateway config defaults to `/compat`: the
 * provider-specific gateway path served them on one gateway and answered 401
 * on another (measured 2026-09-09), while `/compat` worked on both. The model
 * ID is therefore prefixed for the `/compat` route rather than rejected.
 */
test('embeddings prefix the model name on a compat endpoint', function () {
    // Explicit url-only config — must not include account_id, since the
    // shared BaseUrl resolver rejects ambiguous configs that mix both.
    config(['ai.providers.workersai' => [
        'driver' => 'workersai',
        'name' => 'workersai',
        'key' => 'test-key',
        'url' => 'https://gateway.ai.cloudflare.com/v1/abc/gw/compat',
    ]]);

    Http::fake(['gateway.ai.cloudflare.com/*' => fakeWorkersAiEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'workersai');

    Http::assertSent(function (Request $request) {
        $model = json_decode($request->body(), true)['model'];

        return str_starts_with($model, 'workers-ai/@cf/')
            && $request->url() === 'https://gateway.ai.cloudflare.com/v1/abc/gw/compat/embeddings';
    });
});

test('embeddings forward caller providerOptions into the request body', function () {
    // laravel/ai 0.9 renamed the embeddings builder method from
    // providerOptions() to withProviderOptions() (see UPGRADE.md).
    Http::fake(['api.cloudflare.com/*' => fakeWorkersAiEmbeddingsResponse()]);

    Embeddings::for(['Hello world'])
        ->withProviderOptions(['encoding_format' => 'base64', 'custom_dim' => 256])
        ->generate(provider: 'workersai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['encoding_format'] === 'base64'
            && $body['custom_dim'] === 256
            && $body['model'] === '@cf/baai/bge-large-en-v1.5'
            && $body['input'] === ['Hello world'];
    });
});

test('embeddings reject reserved providerOptions keys (model, input)', function () {
    Http::fake(['api.cloudflare.com/*' => fakeWorkersAiEmbeddingsResponse()]);

    Embeddings::for(['Hello'])
        ->withProviderOptions(['model' => '@cf/evil/swap', 'input' => ['nope'], 'safe' => 'kept'])
        ->generate(provider: 'workersai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        // Reserved keys are stripped — gateway owns the request shape.
        return $body['model'] === '@cf/baai/bge-large-en-v1.5'
            && $body['input'] === ['Hello']
            && $body['safe'] === 'kept';
    });
});

function fakeWorkersAiEmbeddingsResponse()
{
    return Http::response([
        'object' => 'list',
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
        ],
        'model' => '@cf/baai/bge-large-en-v1.5',
        'usage' => ['total_tokens' => 10],
    ]);
}

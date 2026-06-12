<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Tests\Fixtures\Agents\AssistantAgent;

test('key is sent as the bearer token', function () {
    config(['ai.providers.workersai' => [
        'driver' => 'workersai',
        'key' => 'canonical-key',
        'account_id' => 'test-account',
    ]]);

    Http::fake(['api.cloudflare.com/*' => fakeWorkersAiResponse()]);

    (new AssistantAgent)->prompt('Hello', provider: 'workersai');

    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer canonical-key'));
});

test('api_key is accepted as a fallback for key', function () {
    config(['ai.providers.workersai' => [
        'driver' => 'workersai',
        'api_key' => 'legacy-key',
        'account_id' => 'test-account',
    ]]);

    Http::fake(['api.cloudflare.com/*' => fakeWorkersAiResponse()]);

    (new AssistantAgent)->prompt('Hello', provider: 'workersai');

    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer legacy-key'));
});

test('key wins when both key and api_key are set', function () {
    config(['ai.providers.workersai' => [
        'driver' => 'workersai',
        'key' => 'canonical-key',
        'api_key' => 'legacy-key',
        'account_id' => 'test-account',
    ]]);

    Http::fake(['api.cloudflare.com/*' => fakeWorkersAiResponse()]);

    (new AssistantAgent)->prompt('Hello', provider: 'workersai');

    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer canonical-key'));
});

test('missing credentials throw an actionable error', function () {
    config(['ai.providers.workersai' => [
        'driver' => 'workersai',
        'account_id' => 'test-account',
    ]]);

    Http::fake(['api.cloudflare.com/*' => fakeWorkersAiResponse()]);

    (new AssistantAgent)->prompt('Hello', provider: 'workersai');
})->throws(AiException::class, 'Workers AI requires an API token');

test('api_key does not leak into additional configuration', function () {
    config(['ai.providers.workersai' => [
        'driver' => 'workersai',
        'api_key' => 'legacy-key',
        'account_id' => 'test-account',
    ]]);

    $provider = app(Laravel\Ai\AiManager::class)->textProvider('workersai');

    expect($provider->additionalConfiguration())->not->toHaveKey('api_key')
        ->and($provider->providerCredentials())->toBe(['key' => 'legacy-key']);
});

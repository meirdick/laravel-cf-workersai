<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\ThinkingAgent;

beforeEach(function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'key' => 'test-key',
        'account_id' => 'test-account',
    ]]);

    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);
});

function sentBody(): array
{
    return Http::recorded()[0][0]->data();
}

test('thinking enabled with a low budget is raised to the reasoning floor', function () {
    config(['ai.providers.workersai.default_max_tokens' => 100]);

    (new ThinkingAgent(thinking: true))->prompt('Hello', provider: 'workersai');

    expect(sentBody()['max_completion_tokens'])->toBe(2048)
        ->and(sentBody()['chat_template_kwargs']['thinking'])->toBeTrue();
});

test('thinking enabled with no resolved budget is granted the floor', function () {
    // User opts out of the package default — without the guard the field would
    // be omitted and Cloudflare's 256-token default would starve reasoning.
    config(['ai.providers.workersai.default_max_tokens' => null]);

    (new ThinkingAgent(thinking: true))->prompt('Hello', provider: 'workersai');

    expect(sentBody()['max_completion_tokens'])->toBe(2048);
});

test('thinking enabled with a budget already above the floor is left untouched', function () {
    config(['ai.providers.workersai.default_max_tokens' => 8192]);

    (new ThinkingAgent(thinking: true))->prompt('Hello', provider: 'workersai');

    expect(sentBody()['max_completion_tokens'])->toBe(8192);
});

test('thinking disabled never triggers the floor', function () {
    config(['ai.providers.workersai.default_max_tokens' => 100]);

    (new ThinkingAgent(thinking: false))->prompt('Hello', provider: 'workersai');

    expect(sentBody()['max_completion_tokens'])->toBe(100)
        ->and(sentBody()['chat_template_kwargs']['thinking'])->toBeFalse();
});

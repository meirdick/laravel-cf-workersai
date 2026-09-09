<?php

use Illuminate\Support\Facades\Http;
use Meirdick\WorkersAi\Attributes\ReasoningEffort;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\HighEffortWithOptionsAgent;
use Tests\Fixtures\Agents\LowEffortAgent;

use function Laravel\Ai\agent;

/*
|--------------------------------------------------------------------------
| reasoning_effort
|--------------------------------------------------------------------------
|
| Cloudflare's /compat endpoint accepts OpenAI's `reasoning_effort`. Nothing
| in laravel/ai sends it (grep for it across v0.11.2 returns nothing), and
| left unset the models on Workers AI over-think badly: measured 2026-09-09
| on @cf/openai/gpt-oss-120b, one two-sentence question produced 1,564
| reasoning characters in 7.4s at `high` versus 20 characters in 1.4s at
| `low`. These tests cover the resolution order, not the model behaviour.
|
*/
beforeEach(function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'key' => 'test-key',
        'account_id' => 'test-account',
    ]]);

    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);
});

test('no reasoning_effort is sent when nothing configures one', function () {
    (new AssistantAgent)->prompt('Hello', provider: 'workersai');

    expect(lastRequestBody())->not->toHaveKey('reasoning_effort');
});

test('the provider config default is sent', function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'reasoning_effort' => 'low',
    ]]);

    (new AssistantAgent)->prompt('Hello', provider: 'workersai');

    expect(lastRequestBody()['reasoning_effort'])->toBe('low');
});

test('the agent attribute overrides the provider config default', function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'reasoning_effort' => 'high',
    ]]);

    (new LowEffortAgent)->prompt('Hello', provider: 'workersai');

    expect(lastRequestBody()['reasoning_effort'])->toBe('low');
});

test('provider options override the agent attribute', function () {
    (new HighEffortWithOptionsAgent)->prompt('Hello', provider: 'workersai');

    expect(lastRequestBody()['reasoning_effort'])->toBe('medium');
});

test('an invalid provider config value is rejected before the request', function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'reasoning_effort' => 'none',
    ]]);

    agent()->prompt('Hello', provider: 'workersai');
})->throws(InvalidArgumentException::class, 'must be one of: low, medium, high');

test('an invalid value smuggled through provider options is rejected', function () {
    ReasoningEffort::validate('minimal');
})->throws(InvalidArgumentException::class, 'not available on Workers AI');

test('the attribute rejects an unsupported level at construction', function () {
    new ReasoningEffort('ultra');
})->throws(InvalidArgumentException::class);

test('all three supported levels construct', function (string $level) {
    expect((new ReasoningEffort($level))->value)->toBe($level);
})->with(['low', 'medium', 'high']);

<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Meirdick\WorkersAi\Cloudflare\UsageTokens;
use Meirdick\WorkersAi\Events\WorkersAiUsageReported;

use function Laravel\Ai\agent;

/*
|--------------------------------------------------------------------------
| usage.neurons
|--------------------------------------------------------------------------
|
| Neurons are the unit Cloudflare meters Workers AI in, and every endpoint
| shape returns one per call under `usage.neurons` (verified live 2026-09-09
| on /compat, the AI Gateway provider path and the direct API). laravel/ai's
| Usage is five fixed int counters with no extensible field and Meta has no
| arbitrary bag, so an event is the only place the figure can survive to a
| consumer tracking spend.
|
*/
beforeEach(function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'key' => 'test-key',
        'account_id' => 'test-account',
    ]]);
});

test('neurons are dispatched as an event', function () {
    Event::fake([WorkersAiUsageReported::class]);

    $payload = workersAiTextResponse();
    $payload['usage']['neurons'] = 4.476930618286133;

    Http::fake(['api.cloudflare.com/*' => Http::response($payload)]);

    agent()->prompt('Hello', provider: 'workersai');

    Event::assertDispatched(WorkersAiUsageReported::class, function ($event) {
        return $event->neurons === 4.476930618286133
            && $event->model === '@cf/meta/llama-3.3-70b-instruct-fp8-fast'
            && $event->promptTokens === 10
            && $event->completionTokens === 5;
    });
});

test('no event is dispatched when the response omits neurons', function () {
    Event::fake([WorkersAiUsageReported::class]);

    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    agent()->prompt('Hello', provider: 'workersai');

    Event::assertNotDispatched(WorkersAiUsageReported::class);
});

test('one event is dispatched per step of a tool loop', function () {
    Event::fake([WorkersAiUsageReported::class]);

    $toolCall = fakeWorkersAiToolCallResponse();
    $toolCall['usage']['neurons'] = 12.5;

    $final = workersAiTextResponse('The number is 72019');
    $final['usage']['neurons'] = 3.25;

    Http::fake([
        'api.cloudflare.com/*' => Http::sequence([
            Http::response($toolCall),
            Http::response($final),
        ]),
    ]);

    (new Tests\Fixtures\Agents\ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'workersai',
    );

    Event::assertDispatchedTimes(WorkersAiUsageReported::class, 2);
});

test('UsageTokens reads fractional neurons and tolerates absence', function () {
    expect(UsageTokens::neurons(['neurons' => 1.0272728204727173]))->toBe(1.0272728204727173)
        ->and(UsageTokens::neurons(['neurons' => 4]))->toBe(4.0)
        ->and(UsageTokens::neurons([]))->toBeNull()
        ->and(UsageTokens::neurons(['neurons' => null]))->toBeNull()
        ->and(UsageTokens::neurons(null))->toBeNull();
});

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

/*
|--------------------------------------------------------------------------
| Streamed neurons
|--------------------------------------------------------------------------
|
| Measured 2026-11-02 on the direct API and /compat: every chunk carries
| `usage.neurons`. Early chunks hold the per-chunk increment, a zero chunk
| precedes the last, and the last finish chunk holds the call total.
|
*/
test('a streamed call dispatches the final neuron total once', function () {
    Event::fake([WorkersAiUsageReported::class]);

    Http::fake([
        'api.cloudflare.com/*' => Http::response(
            body: $this->ssePayload([
                [...$this->chatChunk(['role' => 'assistant', 'content' => 'Hi']), 'usage' => [
                    'prompt_tokens' => 37, 'completion_tokens' => 0, 'neurons' => 0.9867,
                ]],
                [...$this->chatChunk(['content' => '!']), 'usage' => [
                    'prompt_tokens' => 0, 'completion_tokens' => 1, 'neurons' => 0.2048,
                ]],
                $this->chatChunkFinish('stop', [
                    'prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0, 'neurons' => 0,
                ]),
                $this->chatChunkFinish('stop', [
                    'prompt_tokens' => 37, 'completion_tokens' => 10, 'total_tokens' => 47,
                    'prompt_tokens_details' => ['cached_tokens' => 7], 'neurons' => 3.0347,
                ]),
                '[DONE]',
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $this->collectStreamEvents();

    Event::assertDispatchedTimes(WorkersAiUsageReported::class, 1);
    Event::assertDispatched(WorkersAiUsageReported::class, fn ($event) => $event->neurons === 3.0347
        && $event->model === '@cf/meta/llama-3.3-70b-instruct-fp8-fast'
        && $event->promptTokens === 30
        && $event->completionTokens === 10);
});

test('a streamed call without neurons dispatches nothing', function () {
    Event::fake([WorkersAiUsageReported::class]);

    Http::fake([
        'api.cloudflare.com/*' => Http::response(
            body: $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'content' => 'Hi']),
                $this->chatChunkFinish('stop', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
                '[DONE]',
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $this->collectStreamEvents();

    Event::assertNotDispatched(WorkersAiUsageReported::class);
});

test('a streamed tool loop dispatches one event per step', function () {
    Event::fake([WorkersAiUsageReported::class]);

    Http::fake([
        'api.cloudflare.com/*' => Http::sequence([
            Http::response(
                body: $this->ssePayload([
                    $this->chatChunkToolCallStart(0, 'call_123', 'FixedNumberGenerator'),
                    $this->chatChunkToolCallDelta(0, '{}'),
                    $this->chatChunkFinish('tool_calls', ['prompt_tokens' => 20, 'completion_tokens' => 10, 'neurons' => 2.5]),
                    '[DONE]',
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
            Http::response(
                body: $this->ssePayload([
                    $this->chatChunk(['role' => 'assistant', 'content' => 'The number is 72019']),
                    $this->chatChunkFinish('stop', ['prompt_tokens' => 30, 'completion_tokens' => 5, 'neurons' => 1.5]),
                    '[DONE]',
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]),
    ]);

    $this->collectStreamEvents(new Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent);

    Event::assertDispatchedTimes(WorkersAiUsageReported::class, 2);
});

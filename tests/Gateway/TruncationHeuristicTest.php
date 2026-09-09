<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Meirdick\WorkersAi\Exceptions\TruncatedResponseException;
use Tests\Gateway\WorkersAiHelpers;

use function Laravel\Ai\agent;

uses(WorkersAiHelpers::class);

beforeEach(function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'key' => 'test-key',
        'account_id' => 'test-account',
    ]]);
});

/*
|--------------------------------------------------------------------------
| Finish-reason mapping
|--------------------------------------------------------------------------
|
| Through v0.6.1 the package assumed Cloudflare misreported truncation as
| `finish_reason: "stop"` and coerced `stop`-at-budget into `Length`. Measured
| live against AI Gateway /compat on 2026-09-09, Workers AI reports `length`
| correctly: llama-3.3-70b, gpt-oss-120b and glm-5.3-flash all returned
| `"length"` under a 16-token cap, as did every model truncated at
| Cloudflare's 256-token default. The coercion is gone and the raw reason is
| trusted, so a model that legitimately finishes on its last budgeted token is
| no longer misreported as truncated.
|
*/
test('explicit length finish_reason maps to Length', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::response(
            workersAiTruncatedResponse('partial', completionTokens: 4096),
        ),
    ]);

    $response = agent()->prompt('Hello', provider: 'workersai');

    expect($response->steps->last()->finishReason)->toBe(FinishReason::Length);
});

test('stop at the completion-token budget is no longer coerced to Length', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::response([
            'id' => 'chatcmpl-exact',
            'object' => 'chat.completion',
            'model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'A complete answer.'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 4096],
        ]),
    ]);

    $response = agent()->prompt('Hello', provider: 'workersai');

    expect($response->steps->last()->finishReason)->toBe(FinishReason::Stop);
});

test('stop well under the budget stays as Stop', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    $response = agent()->prompt('Hello', provider: 'workersai');

    expect($response->steps->last()->finishReason)->toBe(FinishReason::Stop);
});

test('streaming maps the finish chunk reason as sent', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::response(
            body: $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'content' => '{"foo":']),
                $this->chatChunkFinish('length', ['prompt_tokens' => 10, 'completion_tokens' => 4096]),
                '[DONE]',
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $streamEnd = array_values(array_filter($events, fn ($e) => $e instanceof StreamEnd))[0];

    expect($streamEnd->reason)->toBe(FinishReason::Length->value);
});

test('streaming stop at budget is not coerced to Length', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::response(
            body: $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'content' => 'Done.']),
                $this->chatChunkFinish('stop', ['prompt_tokens' => 10, 'completion_tokens' => 4096]),
                '[DONE]',
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $streamEnd = array_values(array_filter($events, fn ($e) => $e instanceof StreamEnd))[0];

    expect($streamEnd->reason)->toBe(FinishReason::Stop->value);
});

/*
|--------------------------------------------------------------------------
| throw_on_truncation
|--------------------------------------------------------------------------
|
| laravel/ai's TextGenerationLoop never branches on FinishReason::Length, so a
| truncated answer reaches the caller looking like a complete one. Opting in
| turns it into a catchable failure.
|
*/
test('a truncated step throws when throw_on_truncation is enabled', function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'throw_on_truncation' => true,
    ]]);

    Http::fake([
        'api.cloudflare.com/*' => Http::response(
            workersAiTruncatedResponse('{"name":"Ada","occupa', completionTokens: 4096),
        ),
    ]);

    agent()->prompt('Hello', provider: 'workersai');
})->throws(TruncatedResponseException::class, 'truncated the response');

test('the truncation exception names the model and both token counts', function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'throw_on_truncation' => true,
    ]]);

    Http::fake([
        'api.cloudflare.com/*' => Http::response(
            workersAiTruncatedResponse('partial', completionTokens: 4096),
        ),
    ]);

    try {
        agent()->prompt('Hello', provider: 'workersai');
    } catch (TruncatedResponseException $e) {
        expect($e->getMessage())
            ->toContain('@cf/meta/llama-3.3-70b-instruct-fp8-fast')
            ->toContain('completion_tokens: 4096')
            ->toContain('max_completion_tokens: 4096');

        return;
    }

    $this->fail('Expected a TruncatedResponseException.');
});

test('truncation does not throw by default', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::response(
            workersAiTruncatedResponse('partial', completionTokens: 4096),
        ),
    ]);

    $response = agent()->prompt('Hello', provider: 'workersai');

    expect($response->text)->toBe('partial')
        ->and($response->steps->last()->finishReason)->toBe(FinishReason::Length);
});

test('a complete answer never throws even with throw_on_truncation enabled', function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'throw_on_truncation' => true,
    ]]);

    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    $response = agent()->prompt('Hello', provider: 'workersai');

    expect($response->text)->toBe('Hello from Workers AI');
});

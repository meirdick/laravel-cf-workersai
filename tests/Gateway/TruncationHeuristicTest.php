<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Streaming\Events\StreamEnd;
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

/**
 * Cloudflare's `/v1/chat/completions` misreports truncated completions as
 * `finish_reason: "stop"` with `completion_tokens` equal to the requested
 * `max_completion_tokens`. The package detects this pattern and normalizes
 * the finish reason to `Length` so laravel/ai's length-aware retry primitives
 * can fire. Without this signal, agents quietly receive truncated JSON.
 */
test('stop at max_completion_tokens budget is normalized to Length', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::response([
            'id' => 'chatcmpl-trunc',
            'object' => 'chat.completion',
            'model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => '{"foo":'],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 4096,
            ],
        ]),
    ]);

    $response = agent()->prompt('Hello', provider: 'workersai');

    expect($response->steps->last()->finishReason)->toBe(FinishReason::Length);
});

test('stop well under the budget stays as Stop', function () {
    // Default fixture: completion_tokens=5, package default max=4096
    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    $response = agent()->prompt('Hello', provider: 'workersai');

    expect($response->steps->last()->finishReason)->toBe(FinishReason::Stop);
});

test('stop with no resolved max_tokens budget stays as Stop', function () {
    // User opts out of the package default — the heuristic can't fire without
    // a budget to compare against.
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'default_max_tokens' => null,
    ]]);

    Http::fake([
        'api.cloudflare.com/*' => Http::response([
            'id' => 'chatcmpl-no-budget',
            'object' => 'chat.completion',
            'model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Hi'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 9999],
        ]),
    ]);

    $response = agent()->prompt('Hello', provider: 'workersai');

    expect($response->steps->last()->finishReason)->toBe(FinishReason::Stop);
});

test('streaming normalizes stop-at-budget to length', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::response(
            body: $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'content' => '{"foo":']),
                $this->chatChunkFinish('stop', ['prompt_tokens' => 10, 'completion_tokens' => 4096]),
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

test('explicit length finish_reason still maps to Length', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::response([
            'id' => 'chatcmpl-len',
            'object' => 'chat.completion',
            'model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'partial'],
                'finish_reason' => 'length',
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 4096],
        ]),
    ]);

    $response = agent()->prompt('Hello', provider: 'workersai');

    expect($response->steps->last()->finishReason)->toBe(FinishReason::Length);
});

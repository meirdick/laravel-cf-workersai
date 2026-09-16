<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;

use function Laravel\Ai\agent;

/*
|--------------------------------------------------------------------------
| Usage mapping parity with laravel/ai's OpenAI-compatible gateway
|--------------------------------------------------------------------------
|
| laravel/ai 0.11.1 changed what `Usage::$promptTokens` means on every
| OpenAI-shaped provider: cached tokens are subtracted, so `promptTokens`
| is the uncached count and `cacheReadInputTokens` the cached one, and the
| two add up to the wire figure. A consumer switching between the built-in
| driver and this package must see the same split.
|
*/
beforeEach(function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'key' => 'test-key',
        'account_id' => 'test-account',
    ]]);
});

test('cached tokens are excluded from the prompt token count', function () {
    $payload = workersAiTextResponse();
    $payload['usage'] = [
        'prompt_tokens' => 100,
        'completion_tokens' => 5,
        'prompt_tokens_details' => ['cached_tokens' => 60],
    ];

    Http::fake(['api.cloudflare.com/*' => Http::response($payload)]);

    $response = agent()->prompt('Hello', provider: 'workersai');

    expect($response->usage->promptTokens)->toBe(40)
        ->and($response->usage->cacheReadInputTokens)->toBe(60);
});

test('cached tokens are excluded from the streamed prompt token count', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::response(
            $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'content' => 'Hi']),
                $this->chatChunkFinish('stop', [
                    'prompt_tokens' => 100,
                    'completion_tokens' => 5,
                    'prompt_tokens_details' => ['cached_tokens' => 60],
                ]),
                '[DONE]',
            ]),
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents(new AssistantAgent);
    $end = collect($events)->whereInstanceOf(\Laravel\Ai\Streaming\Events\StreamEnd::class)->last();

    expect($end->usage->promptTokens)->toBe(40)
        ->and($end->usage->cacheReadInputTokens)->toBe(60);
});

test('a cached count larger than the prompt count clamps to zero instead of going negative', function () {
    $payload = workersAiTextResponse();
    $payload['usage'] = [
        'prompt_tokens' => 10,
        'completion_tokens' => 5,
        'prompt_tokens_details' => ['cached_tokens' => 12],
    ];

    Http::fake(['api.cloudflare.com/*' => Http::response($payload)]);

    expect(agent()->prompt('Hello', provider: 'workersai')->usage->promptTokens)->toBe(0);
});

test('reasoning tokens are read from completion_tokens_details when the top-level key is absent', function () {
    $payload = workersAiTextResponse();
    $payload['usage'] = [
        'prompt_tokens' => 10,
        'completion_tokens' => 50,
        'completion_tokens_details' => ['reasoning_tokens' => 30],
    ];

    Http::fake(['api.cloudflare.com/*' => Http::response($payload)]);

    expect(agent()->prompt('Hello', provider: 'workersai')->usage->reasoningTokens)->toBe(30);
});

test('a top-level reasoning_tokens key still wins', function () {
    $payload = workersAiTextResponse();
    $payload['usage'] = [
        'prompt_tokens' => 10,
        'completion_tokens' => 50,
        'reasoning_tokens' => 20,
        'completion_tokens_details' => ['reasoning_tokens' => 30],
    ];

    Http::fake(['api.cloudflare.com/*' => Http::response($payload)]);

    expect(agent()->prompt('Hello', provider: 'workersai')->usage->reasoningTokens)->toBe(20);
});

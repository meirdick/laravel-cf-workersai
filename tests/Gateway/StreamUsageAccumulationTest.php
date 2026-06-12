<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;

beforeEach(function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'key' => 'test-key',
        'account_id' => 'test-account',
    ]]);
});

test('streamed usage is summed across tool-call steps', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::sequence([
            Http::response(
                body: $this->ssePayload([
                    $this->chatChunkToolCallStart(0, 'call_123', 'FixedNumberGenerator'),
                    $this->chatChunkToolCallDelta(0, '{}'),
                    $this->chatChunkFinish('tool_calls', ['prompt_tokens' => 20, 'completion_tokens' => 10]),
                    '[DONE]',
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
            Http::response(
                body: $this->ssePayload([
                    $this->chatChunk(['role' => 'assistant', 'content' => 'The number is 72019']),
                    $this->chatChunkFinish('stop', ['prompt_tokens' => 30, 'completion_tokens' => 5]),
                    '[DONE]',
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]),
    ]);

    $events = $this->collectStreamEvents(new ProviderOptionsWithToolsAgent);

    $streamEnds = array_values(array_filter($events, fn ($e) => $e instanceof StreamEnd));

    expect($streamEnds)->toHaveCount(1)
        ->and($streamEnds[0]->usage->promptTokens)->toBe(50)
        ->and($streamEnds[0]->usage->completionTokens)->toBe(15);
});

test('a trailing all-zero usage chunk does not clobber real usage', function () {
    // The live Workers AI endpoint reports usage on the finish chunk, then
    // emits a usage-only chunk with all-zero counts (observed 2026-06-11).
    Http::fake([
        'api.cloudflare.com/*' => Http::response(
            body: $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'content' => 'OK']),
                $this->chatChunkFinish('stop', ['prompt_tokens' => 37, 'completion_tokens' => 1, 'total_tokens' => 38]),
                [
                    'id' => 'chatcmpl-123',
                    'object' => 'chat.completion.chunk',
                    'model' => '@cf/meta/llama-3.1-8b-instruct',
                    'choices' => [['index' => 0, 'delta' => (object) [], 'finish_reason' => 'stop']],
                    'usage' => [
                        'prompt_tokens' => 0,
                        'completion_tokens' => 0,
                        'total_tokens' => 0,
                        'prompt_tokens_details' => ['cached_tokens' => 0],
                    ],
                ],
                '[DONE]',
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $streamEnd = $events[count($events) - 1];

    expect($streamEnd)->toBeInstanceOf(StreamEnd::class)
        ->and($streamEnd->usage->promptTokens)->toBe(37)
        ->and($streamEnd->usage->completionTokens)->toBe(1);
});

test('streamed usage without tool calls is unchanged', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::response(
            body: $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'content' => 'Hello']),
                $this->chatChunkFinish('stop', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
                '[DONE]',
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $streamEnd = $events[count($events) - 1];

    expect($streamEnd)->toBeInstanceOf(StreamEnd::class)
        ->and($streamEnd->usage->promptTokens)->toBe(10)
        ->and($streamEnd->usage->completionTokens)->toBe(5);
});

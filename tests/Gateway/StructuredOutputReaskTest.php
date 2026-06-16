<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\NonStrictAgent;

/**
 * Workers AI's JSON mode is best-effort — it does NOT guarantee the response
 * satisfies the requested schema. The gateway validates each structured
 * response and, on a missing/empty required field, invalid enum, or malformed
 * JSON, feeds the error back and re-asks (bounded). This is the driver-level
 * safety net for the platform's missing conformance guarantee.
 */
beforeEach(function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'key' => 'test-key',
        'account_id' => 'test-account',
    ]]);
});

function structuredEnvelope(string $content): array
{
    return [
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => $content],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ];
}

test('a valid structured response is returned without re-asking', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(structuredEnvelope('{"answer":"42"}'))]);

    $response = (new NonStrictAgent)->prompt('What is the answer?', provider: 'workersai');

    expect($response['answer'])->toBe('42');
    Http::assertSentCount(1);
});

test('a missing required field triggers a bounded re-ask and returns the corrected output', function () {
    Http::fake(['api.cloudflare.com/*' => Http::sequence()
        ->push(structuredEnvelope('{"wrong":"x"}'))   // missing required `answer`
        ->push(structuredEnvelope('{"answer":"42"}')), // corrected on re-ask
    ]);

    $response = (new NonStrictAgent)->prompt('What is the answer?', provider: 'workersai');

    expect($response['answer'])->toBe('42');
    Http::assertSentCount(2);
});

test('an empty required field also triggers a re-ask', function () {
    Http::fake(['api.cloudflare.com/*' => Http::sequence()
        ->push(structuredEnvelope('{"answer":"   "}')) // present but blank
        ->push(structuredEnvelope('{"answer":"42"}')),
    ]);

    $response = (new NonStrictAgent)->prompt('What is the answer?', provider: 'workersai');

    expect($response['answer'])->toBe('42');
    Http::assertSentCount(2);
});

test('re-asking stops at the configured limit', function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'structured_output_retries' => 1,
    ]]);

    Http::fake(['api.cloudflare.com/*' => Http::response(structuredEnvelope('{"wrong":"x"}'))]);

    (new NonStrictAgent)->prompt('What is the answer?', provider: 'workersai');

    // 1 initial attempt + 1 bounded re-ask = 2, then it gives up.
    Http::assertSentCount(2);
});

test('re-asking can be disabled with structured_output_retries = 0', function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'structured_output_retries' => 0,
    ]]);

    Http::fake(['api.cloudflare.com/*' => Http::response(structuredEnvelope('{"wrong":"x"}'))]);

    (new NonStrictAgent)->prompt('What is the answer?', provider: 'workersai');

    Http::assertSentCount(1);
});

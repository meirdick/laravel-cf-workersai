<?php

use Illuminate\Support\Facades\Http;
use Meirdick\WorkersAi\Exceptions\EmptyResponseException;
use Tests\Fixtures\Agents\ToolUsingAgent;

use function Laravel\Ai\agent;

/*
|--------------------------------------------------------------------------
| Empty-response detection
|--------------------------------------------------------------------------
|
| The failure this guards: a reasoning model spends its whole completion
| budget thinking and returns HTTP 200 with `content: null`. Reproduced live
| on @cf/openai/gpt-oss-120b under a 16-token cap — finish_reason "length",
| content null, status 200. laravel/ai surfaces that as an empty string and an
| empty toArray(), which is indistinguishable downstream from a real answer.
|
*/
beforeEach(function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'key' => 'test-key',
        'account_id' => 'test-account',
    ]]);
});

function emptyContentResponse(string $finishReason = 'length', int $reasoningTokens = 256): array
{
    return [
        'id' => 'chatcmpl-empty',
        'object' => 'chat.completion',
        'model' => '@cf/openai/gpt-oss-120b',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'reasoning_content' => 'Let me think about this at length...',
            ],
            'finish_reason' => $finishReason,
        ]],
        'usage' => [
            'prompt_tokens' => 77,
            'completion_tokens' => 256,
            'completion_tokens_details' => ['reasoning_tokens' => $reasoningTokens],
            'reasoning_tokens' => $reasoningTokens,
        ],
    ];
}

test('a null-content response throws by default', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(emptyContentResponse())]);

    agent()->prompt('Hello', provider: 'workersai');
})->throws(EmptyResponseException::class, 'returned an empty response');

test('the exception names the model, finish reason and reasoning tokens', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(emptyContentResponse())]);

    try {
        agent()->prompt('Hello', provider: 'workersai');
    } catch (EmptyResponseException $e) {
        // The model named is the one that was *requested* — the value the
        // caller controls and would change to fix the failure.
        expect($e->getMessage())
            ->toContain('@cf/meta/llama-3.3-70b-instruct-fp8-fast')
            ->toContain('finish_reason: length')
            ->toContain('reasoning_tokens: 256');

        return;
    }

    $this->fail('Expected an EmptyResponseException.');
});

test('an empty string content throws too', function () {
    $payload = emptyContentResponse('stop');
    $payload['choices'][0]['message']['content'] = '';

    Http::fake(['api.cloudflare.com/*' => Http::response($payload)]);

    agent()->prompt('Hello', provider: 'workersai');
})->throws(EmptyResponseException::class);

test('throw_on_empty_response false restores the pass-through behaviour', function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'throw_on_empty_response' => false,
    ]]);

    Http::fake(['api.cloudflare.com/*' => Http::response(emptyContentResponse())]);

    $response = agent()->prompt('Hello', provider: 'workersai');

    expect($response->text)->toBe('');
});

test('a tool-calling turn with no text is not treated as empty', function () {
    // fakeWorkersAiToolCallResponse() has `content: null` and no text — the
    // guard must not fire on it, or every tool loop breaks.
    Http::fake([
        'api.cloudflare.com/*' => Http::sequence([
            Http::response(fakeWorkersAiToolCallResponse()),
            Http::response(workersAiTextResponse('The number is 72019')),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'workersai',
    );

    expect($response->text)->toBe('The number is 72019');
});

test('a normal answer is unaffected', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    expect(agent()->prompt('Hello', provider: 'workersai')->text)
        ->toBe('Hello from Workers AI');
});

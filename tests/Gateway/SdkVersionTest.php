<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiManager;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\TextUsage;
use Meirdick\WorkersAi\Cloudflare\SdkCompat;
use Tests\Fixtures\Agents\AssistantAgent;

use function Laravel\Ai\agent;

/*
|--------------------------------------------------------------------------
| Shapes that differ between laravel/ai 0.x and 1.x
|--------------------------------------------------------------------------
|
| 1.x: TextUsage with inclusive counts (`inputTokens` is the wire
| `prompt_tokens`, cached tokens included), `replayBlocks`, and an
| EmbeddingsResponse that carries a Usage. 0.x: Usage with a prompt count net
| of cache, `providerContentBlocks`, and an int token count. Each test runs on
| the major it describes and skips on the other.
|
*/
beforeEach(function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'key' => 'test-key',
        'account_id' => 'test-account',
    ]]);
});

function cachedUsagePayload(): array
{
    $payload = workersAiTextResponse();
    $payload['usage'] = [
        'prompt_tokens' => 100,
        'completion_tokens' => 5,
        'prompt_tokens_details' => ['cached_tokens' => 60],
    ];

    return $payload;
}

test('1.x: inputTokens is the wire prompt_tokens and the cache hit is set', function () {
    if (! laravelAiIsV1()) {
        $this->markTestSkipped('Needs laravel/ai 1.x.');
    }

    Http::fake(['api.cloudflare.com/*' => Http::response(cachedUsagePayload())]);

    $usage = agent()->prompt('Hello', provider: 'workersai')->usage;

    expect($usage)->toBeInstanceOf(TextUsage::class)
        ->and($usage->inputTokens)->toBe(100)
        ->and($usage->outputTokens)->toBe(5)
        ->and($usage->cacheReadInputTokens)->toBe(60);
});

test('1.x: a response without a cache field leaves cacheReadInputTokens null', function () {
    if (! laravelAiIsV1()) {
        $this->markTestSkipped('Needs laravel/ai 1.x.');
    }

    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    $usage = agent()->prompt('Hello', provider: 'workersai')->usage;

    expect($usage->inputTokens)->toBe(10)
        ->and($usage->cacheReadInputTokens)->toBeNull();
});

test('1.x: streamed inputTokens is the wire prompt_tokens', function () {
    if (! laravelAiIsV1()) {
        $this->markTestSkipped('Needs laravel/ai 1.x.');
    }

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

    $end = collect($this->collectStreamEvents(new AssistantAgent))
        ->whereInstanceOf(\Laravel\Ai\Streaming\Events\StreamEnd::class)->last();

    expect($end->usage->inputTokens)->toBe(100)
        ->and($end->usage->cacheReadInputTokens)->toBe(60);
});

test('0.x: promptTokens stays net of the cache hit', function () {
    if (laravelAiIsV1()) {
        $this->markTestSkipped('Needs laravel/ai 0.x.');
    }

    Http::fake(['api.cloudflare.com/*' => Http::response(cachedUsagePayload())]);

    $usage = agent()->prompt('Hello', provider: 'workersai')->usage;

    expect($usage->promptTokens)->toBe(40)
        ->and($usage->cacheReadInputTokens)->toBe(60);
});

test('embeddings usage carries the token count on every version', function () {
    Http::fake(['api.cloudflare.com/*' => fakeWorkersAiEmbeddingsResponse()]);

    $response = Embeddings::for(['Hello world'])->generate(provider: 'workersai');

    if (laravelAiIsV1()) {
        expect($response->usage->inputTokens)->toBe(10);
    } else {
        expect($response->tokens)->toBe(10);
    }
});

test('1.x: reasoning is stored as a replay block and replayed on the next request', function () {
    if (! laravelAiIsV1()) {
        $this->markTestSkipped('Needs laravel/ai 1.x.');
    }

    $payload = workersAiTextResponse('42');
    $payload['choices'][0]['message']['reasoning'] = 'Six times seven.';

    Http::fake(['api.cloudflare.com/*' => Http::response($payload)]);

    $provider = app(AiManager::class)->instance('workersai');

    $step = $provider->textGateway()->generateTextStep(
        $provider, '@cf/moonshotai/kimi-k2.6', null,
        [new UserMessage('6 x 7?')], [], null, null, null,
        new StepContext,
    );

    expect($step->replayBlocks)->toBe([['type' => 'reasoning', 'reasoning_content' => 'Six times seven.']]);

    $assistant = new AssistantMessage('42');
    $assistant->replayBlocks = $step->replayBlocks;

    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse('ok'))]);

    $provider->textGateway()->generateTextStep(
        $provider, '@cf/moonshotai/kimi-k2.6', null,
        [new UserMessage('6 x 7?'), $assistant, new UserMessage('again')], [], null, null, null,
        new StepContext,
    );

    $sent = collect(Http::recorded()->last()[0]->data()['messages'])->firstWhere('role', 'assistant');

    expect($sent['reasoning_content'])->toBe('Six times seven.');
});

test('0.x: reasoning is stored in providerContentBlocks', function () {
    if (laravelAiIsV1()) {
        $this->markTestSkipped('Needs laravel/ai 0.x.');
    }

    expect(SdkCompat::replayBlocks('Six times seven.'))->toBe(['reasoning_content' => 'Six times seven.'])
        ->and(SdkCompat::replayBlocksKey())->toBe('providerContentBlocks');
});

test('a persistent 429 or 503 with a short Retry-After makes exactly 3 attempts', function (int $status) {
    // laravel/ai 1.2.1 retries once more on a short Retry-After. The gateway
    // turns that off, so its own sequence of 3 is the whole budget.
    Http::fake(['api.cloudflare.com/*' => Http::response(
        ['error' => ['message' => 'busy']], $status, ['Retry-After' => '0'],
    )]);

    try {
        agent()->prompt('Hello', provider: 'workersai');
        $this->fail('Expected an exception.');
    } catch (\Laravel\Ai\Exceptions\FailoverableException) {
        expect(Http::recorded())->toHaveCount(3);
    }
})->with([429, 503]);

<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Meirdick\WorkersAi\Exceptions\GatewayTimeoutException;

use function Laravel\Ai\agent;

/*
|--------------------------------------------------------------------------
| 408 and 429 end to end
|--------------------------------------------------------------------------
|
| The two failure modes a real Workers AI workload hits that laravel/ai does
| not handle usefully on its own: a gateway 408 arrives as an opaque
| RequestException, and a 429 is mapped to a failoverable exception but never
| retried, so under a fan-out one 429 is one lost request.
|
*/
beforeEach(function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'key' => 'test-key',
        'account_id' => 'test-account',
    ]]);
});

test('a gateway 408 throws GatewayTimeoutException, not a raw RequestException', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(
        ['name' => 'AiError', 'httpCode' => 408, 'message' => 'AiError: Request timeout'],
        408,
    )]);

    agent()->prompt('Hello', provider: 'workersai');
})->throws(GatewayTimeoutException::class, 'timed out while generating');

test('the 408 exception is failoverable so laravel/ai can move on', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response([], 408)]);

    try {
        agent()->prompt('Hello', provider: 'workersai');
    } catch (GatewayTimeoutException $e) {
        expect($e)->toBeInstanceOf(\Laravel\Ai\Exceptions\FailoverableException::class);

        return;
    }

    $this->fail('Expected a GatewayTimeoutException.');
});

/*
 * Retrying a 408 would cost the same wall time again — the generation that
 * took 709 seconds takes 709 seconds on the retry too. One attempt only.
 */
test('a 408 is attempted exactly once', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response([], 408)]);

    try {
        agent()->prompt('Hello', provider: 'workersai');
    } catch (GatewayTimeoutException) {
        // expected
    }

    expect(Http::recorded())->toHaveCount(1);
});

test('a 429 is retried and succeeds when the limit clears', function () {
    Http::fake(['api.cloudflare.com/*' => Http::sequence([
        Http::response(['error' => ['message' => 'rate limited']], 429, ['Retry-After' => '0']),
        Http::response(workersAiTextResponse('recovered')),
    ])]);

    $response = agent()->prompt('Hello', provider: 'workersai');

    expect($response->text)->toBe('recovered')
        ->and(Http::recorded())->toHaveCount(2);
});

test('a persistent 429 still surfaces as RateLimitedException once attempts are spent', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(
        ['error' => ['message' => 'rate limited']], 429, ['Retry-After' => '0'],
    )]);

    try {
        agent()->prompt('Hello', provider: 'workersai');
        $this->fail('Expected a RateLimitedException.');
    } catch (RateLimitedException) {
        expect(Http::recorded())->toHaveCount(3);
    }
});

test('retry_rate_limited false restores fail-fast behaviour on 429', function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'retry_rate_limited' => false,
    ]]);

    Http::fake(['api.cloudflare.com/*' => Http::response([], 429)]);

    try {
        agent()->prompt('Hello', provider: 'workersai');
        $this->fail('Expected a RateLimitedException.');
    } catch (RateLimitedException) {
        expect(Http::recorded())->toHaveCount(1);
    }
});

test('retry_attempts controls how many times a transient failure is tried', function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'retry_attempts' => 2,
    ]]);

    Http::fake(['api.cloudflare.com/*' => Http::response([], 503)]);

    try {
        agent()->prompt('Hello', provider: 'workersai');
        $this->fail('Expected a ProviderOverloadedException.');
    } catch (ProviderOverloadedException) {
        expect(Http::recorded())->toHaveCount(2);
    }
});

/*
 * Cloudflare's own edge errors. Every request here crosses the edge twice, so
 * these matter more than for a typical OpenAI-compatible provider. Through
 * 0.7.0 the package narrowed laravel/ai's default list and left them
 * unretried and unfailoverable.
 */
test('cloudflare edge errors are retried and then fail over', function (int $status) {
    Http::fake(['api.cloudflare.com/*' => Http::response([], $status)]);

    try {
        agent()->prompt('Hello', provider: 'workersai');
        $this->fail("Expected a ProviderOverloadedException for {$status}.");
    } catch (ProviderOverloadedException) {
        expect(Http::recorded())->toHaveCount(3);
    }
})->with([520, 522, 524]);

test('a recovered edge error does not surface at all', function () {
    Http::fake(['api.cloudflare.com/*' => Http::sequence([
        Http::response([], 522),
        Http::response(workersAiTextResponse('fine now')),
    ])]);

    expect(agent()->prompt('Hello', provider: 'workersai')->text)->toBe('fine now');
});

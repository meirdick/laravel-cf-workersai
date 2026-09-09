<?php

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
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
|--------------------------------------------------------------------------
| The complete status-code matrix
|--------------------------------------------------------------------------
|
| Every HTTP status the gateway realistically returns, asserted for both the
| exception it becomes and whether it was retried. Two things drift apart
| easily and neither is visible from a passing suite otherwise: the retry set
| (RetryPolicy::RETRYABLE_STATUSES) and the failover set
| (WorkersAiGateway::overloadedStatusCodes()). Through 0.7.0 they did drift —
| the package narrowed laravel/ai's own list and silently stopped retrying
| Cloudflare's three edge codes.
|
| `retry_attempts => 2` keeps the real backoff in play while halving what the
| suite spends sleeping; 2-versus-1 still distinguishes retried from not.
|
*/
test('every gateway status maps to the right exception and retry behaviour', function (int $status, string $expected, int $attempts) {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'retry_attempts' => 2,
    ]]);

    Http::fake(['api.cloudflare.com/*' => Http::response(['error' => ['message' => 'x']], $status)]);

    try {
        agent()->prompt('Hello', provider: 'workersai');
        $this->fail("Expected {$expected} for HTTP {$status}.");
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf($expected)
            ->and(Http::recorded())->toHaveCount($attempts);
    }
})->with([
    // Client errors pass through untouched: nothing to retry, nothing to fail
    // over to. 410 is what Cloudflare returns for a retired model and 413 for
    // an over-large payload; both are the caller's problem, not the edge's.
    '400 bad request' => [400, RequestException::class, 1],
    '401 bad token' => [401, RequestException::class, 1],
    '403 model not entitled' => [403, RequestException::class, 1],
    '404 unknown route' => [404, RequestException::class, 1],
    '410 model deprecated' => [410, RequestException::class, 1],
    '413 payload too large' => [413, RequestException::class, 1],

    // 402 is mapped upstream by laravel/ai's HandlesFailoverErrors. Asserted
    // here rather than assumed, because this gateway overrides
    // withErrorHandling and could shadow it.
    '402 out of credit' => [402, InsufficientCreditsException::class, 1],

    // The generation outran the gateway. Failoverable, never retried.
    '408 gateway timeout' => [408, GatewayTimeoutException::class, 1],

    // Retried with backoff first, then failoverable.
    '429 rate limited' => [429, RateLimitedException::class, 2],

    // 500 is not in the retryable set: a model runner that returned a real
    // internal error is not a transient edge blip and repeating the request
    // is unlikely to change it.
    '500 internal error' => [500, RequestException::class, 1],

    '502 bad gateway' => [502, ProviderOverloadedException::class, 2],
    '503 unavailable' => [503, ProviderOverloadedException::class, 2],
    '504 gateway timeout' => [504, ProviderOverloadedException::class, 2],

    // Cloudflare's own edge errors. Every request here crosses the edge twice,
    // once to the gateway and once to the model runner, so these are likelier
    // for this provider than for a generic OpenAI-compatible one.
    '520 cloudflare unknown' => [520, ProviderOverloadedException::class, 2],
    '522 cloudflare conn timeout' => [522, ProviderOverloadedException::class, 2],
    '524 cloudflare origin timeout' => [524, ProviderOverloadedException::class, 2],
]);

/*
 * The failoverable set, asserted as a set. laravel/ai only moves to the next
 * provider for exceptions carrying this marker, so a mapping that produced the
 * right class but missed the interface would fail over silently — that is,
 * not at all.
 */
test('every transient failure is failoverable so laravel/ai moves on', function (int $status) {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'retry_attempts' => 1,
    ]]);

    Http::fake(['api.cloudflare.com/*' => Http::response([], $status)]);

    try {
        agent()->prompt('Hello', provider: 'workersai');
        $this->fail("Expected a failoverable exception for HTTP {$status}.");
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf(FailoverableException::class);
    }
})->with([402, 408, 429, 502, 503, 504, 520, 522, 524]);

/*
 * The other half of the retry contract: a transient status that clears on the
 * next attempt must never reach the caller at all.
 */
test('a transient status that clears is invisible to the caller', function (int $status) {
    Http::fake(['api.cloudflare.com/*' => Http::sequence([
        Http::response([], $status),
        Http::response(workersAiTextResponse('recovered')),
    ])]);

    expect(agent()->prompt('Hello', provider: 'workersai')->text)->toBe('recovered');
})->with([429, 502, 503, 504, 520, 522, 524]);



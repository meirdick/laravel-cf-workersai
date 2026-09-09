<?php

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Meirdick\WorkersAi\Cloudflare\RetryPolicy;

function retryDecider(): callable
{
    return RetryPolicy::defaults()[2];
}

test('transfer timeouts are not retried', function () {
    $exception = new ConnectionException(
        'cURL error 28: Operation timed out after 60000 milliseconds with 0 bytes received'
    );

    expect(retryDecider()($exception))->toBeFalse();
});

test('connect-phase timeouts are retried', function () {
    $exception = new ConnectionException(
        'cURL error 28: Connection timed out after 5001 milliseconds'
    );

    expect(retryDecider()($exception))->toBeTrue();
});

test('connection failures are retried', function (string $message) {
    expect(retryDecider()(new ConnectionException($message)))->toBeTrue();
})->with([
    'refused' => 'cURL error 7: Failed to connect to gateway.ai.cloudflare.com port 443',
    'dns' => 'cURL error 6: Could not resolve host: gateway.ai.cloudflare.com',
    'reset' => 'cURL error 56: Recv failure: Connection reset by peer',
]);

/*
 * 408 belongs with the client errors, not the retryable ones. Cloudflare's
 * gateway returns it when the *generation* ran too long rather than when the
 * network blipped — measured live 2026-09-09, a 24,000-token cap on
 * @cf/zai-org/glm-5.3-flash produced `408 Request timeout` after 709 seconds.
 * Retrying costs another 709 seconds to reach the same failure.
 */
function requestExceptionWithStatus(int $status, array $headers = []): RequestException
{
    return new RequestException(new Response(new Psr7Response($status, $headers, '{}')));
}

test('transient gateway statuses are retried, client errors are not', function (int $status, bool $expected) {
    expect(retryDecider()(requestExceptionWithStatus($status)))->toBe($expected);
})->with([
    '502' => [502, true],
    '503' => [503, true],
    '504' => [504, true],
    '400' => [400, false],
    '401' => [401, false],
    '408' => [408, false],
    '429' => [429, true],
    '520 cloudflare unknown error' => [520, true],
    '522 cloudflare connection timed out' => [522, true],
    '524 cloudflare origin timeout' => [524, true],
]);

/*
|--------------------------------------------------------------------------
| Rate limiting
|--------------------------------------------------------------------------
|
| laravel/ai maps a 429 to RateLimitedException, which is failoverable — but
| it never retries it, so under a fan-out one 429 is one lost request. The
| package retries with backoff first and only lets the failure through once
| the attempts are spent.
|
*/
test('a 429 is retried by default', function () {
    expect(RetryPolicy::shouldRetry(requestExceptionWithStatus(429)))->toBeTrue();
});

test('retry_rate_limited false lets a 429 fail over immediately', function () {
    expect(RetryPolicy::shouldRetry(requestExceptionWithStatus(429), retryRateLimited: false))->toBeFalse();
});

test('backoff grows exponentially from the base delay', function () {
    $e = requestExceptionWithStatus(503);

    expect(RetryPolicy::backoffMilliseconds(1, $e))->toBe(500)
        ->and(RetryPolicy::backoffMilliseconds(2, $e))->toBe(1000)
        ->and(RetryPolicy::backoffMilliseconds(3, $e))->toBe(2000);
});

test('backoff is capped', function () {
    expect(RetryPolicy::backoffMilliseconds(20, requestExceptionWithStatus(503)))
        ->toBe(RetryPolicy::MAX_BACKOFF_MS);
});

/*
 * A server naming its own retry delay is better information than our guess,
 * so Retry-After wins over the exponential schedule. Both legal forms are
 * accepted: delay-seconds and an HTTP date.
 */
test('a numeric Retry-After header overrides the exponential backoff', function () {
    $e = requestExceptionWithStatus(429, ['Retry-After' => '2']);

    expect(RetryPolicy::backoffMilliseconds(1, $e))->toBe(2000);
});

test('an HTTP-date Retry-After header is honoured', function () {
    $e = requestExceptionWithStatus(429, ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 3)]);

    expect(RetryPolicy::backoffMilliseconds(1, $e))->toBeGreaterThanOrEqual(1000)
        ->and(RetryPolicy::backoffMilliseconds(1, $e))->toBeLessThanOrEqual(4000);
});

test('a Retry-After date in the past clamps to zero rather than going negative', function () {
    $e = requestExceptionWithStatus(429, ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() - 120)]);

    expect(RetryPolicy::backoffMilliseconds(1, $e))->toBe(0);
});

test('an absurd Retry-After is capped so a worker is not parked for minutes', function () {
    $e = requestExceptionWithStatus(429, ['Retry-After' => '3600']);

    expect(RetryPolicy::backoffMilliseconds(1, $e))->toBe(RetryPolicy::MAX_BACKOFF_MS);
});

test('an unparseable Retry-After falls back to the exponential schedule', function () {
    $e = requestExceptionWithStatus(429, ['Retry-After' => 'whenever you feel like it']);

    expect(RetryPolicy::backoffMilliseconds(2, $e))->toBe(1000);
});

test('defaults() returns the tuple Illuminate retry() expects', function () {
    [$times, $sleep, $when, $throw] = RetryPolicy::defaults();

    expect($times)->toBe(RetryPolicy::DEFAULT_ATTEMPTS)
        ->and($sleep)->toBeCallable()
        ->and($when)->toBeCallable()
        ->and($throw)->toBeTrue()
        ->and($sleep(1, requestExceptionWithStatus(503)))->toBe(500)
        ->and($when(requestExceptionWithStatus(429)))->toBeTrue();
});

test('defaults() honours a custom attempt count and rate-limit opt-out', function () {
    [$times, , $when] = RetryPolicy::defaults(retryRateLimited: false, attempts: 5);

    expect($times)->toBe(5)
        ->and($when(requestExceptionWithStatus(429)))->toBeFalse();
});

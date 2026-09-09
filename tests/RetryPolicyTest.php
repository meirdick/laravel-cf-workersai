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
test('transient gateway statuses are retried, client errors are not', function (int $status, bool $expected) {
    $exception = new RequestException(
        new Response(new Psr7Response($status, [], '{}'))
    );

    expect(retryDecider()($exception))->toBe($expected);
})->with([
    '502' => [502, true],
    '503' => [503, true],
    '504' => [504, true],
    '400' => [400, false],
    '401' => [401, false],
    '408' => [408, false],
    '429' => [429, false],
]);

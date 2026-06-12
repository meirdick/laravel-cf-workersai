<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Cloudflare;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * The package's default retry policy for the Cloudflare AI Gateway.
 *
 * The AI Gateway intermittently terminates long-running requests with cURL 6/7/
 * 28/56 (DNS / connect / timeout / connection-reset) and serves transient HTTP
 * 502/503/504 during regional load shifts. Both are recoverable on retry — the
 * upstream Workers AI service itself is rarely the source. Surfacing those as
 * hard failures forces every consumer to wrap their AI calls in retry logic;
 * baking the retry into the HTTP client lets feature code stay clean.
 *
 * Tuple shape: `[$times, $sleepMilliseconds, $when, $throw]` — the exact
 * positional arguments accepted by Illuminate's `PendingRequest::retry()`.
 */
final class RetryPolicy
{
    /**
     * @return array{int, int, callable(Throwable): bool, bool}
     */
    public static function defaults(): array
    {
        return [
            3,
            500,
            function (Throwable $exception): bool {
                if ($exception instanceof ConnectionException) {
                    // cURL 28 during transfer ("Operation timed out after Xms
                    // with Y bytes received") means the request ran and was cut
                    // by the caller's timeout. Retrying re-runs a request
                    // expected to take just as long — three attempts turn a
                    // 60s timeout into ~3 minutes of wall time before failing.
                    // Fail fast so the configured timeout means what it says.
                    // Connect-phase failures ("Connection timed out after",
                    // "Failed to connect", "Could not resolve host") remain
                    // retryable — those are the transient blips the policy
                    // exists for.
                    return ! str_contains($exception->getMessage(), 'Operation timed out');
                }

                if ($exception instanceof RequestException) {
                    return in_array($exception->response->getStatusCode(), [502, 503, 504], true);
                }

                return false;
            },
            true,
        ];
    }
}

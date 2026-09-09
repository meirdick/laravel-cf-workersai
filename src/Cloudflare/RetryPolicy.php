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
     * Default attempts, including the first. Three attempts, two retries.
     */
    public const DEFAULT_ATTEMPTS = 3;

    /**
     * Base backoff, doubled per attempt.
     */
    public const BASE_BACKOFF_MS = 500;

    /**
     * Ceiling for a single sleep, including one derived from `Retry-After`.
     *
     * A server may name a retry delay far longer than a request is worth
     * waiting for. Capping keeps a rate-limited call from silently parking a
     * worker for minutes; past this, failing over is the better answer.
     */
    public const MAX_BACKOFF_MS = 20_000;

    /**
     * Transient HTTP statuses worth retrying.
     *
     * 520/522/524 are Cloudflare's own edge errors (unknown error, connection
     * timed out, origin timeout). They matter more here than for a typical
     * OpenAI-compatible provider because every request crosses Cloudflare's
     * edge twice — once to the gateway, once to the model runner.
     *
     * 408 is deliberately absent: it means the generation ran too long, not
     * that the network blipped, and the retry would cost the same wall time
     * again. See GatewayTimeoutException.
     *
     * @var list<int>
     */
    public const RETRYABLE_STATUSES = [502, 503, 504, 520, 522, 524];

    /**
     * @param  bool  $retryRateLimited  retry HTTP 429 with backoff before letting it fail over
     * @return array{int, callable(int, Throwable): int, callable(Throwable): bool, bool}
     */
    public static function defaults(bool $retryRateLimited = true, int $attempts = self::DEFAULT_ATTEMPTS): array
    {
        return [
            max(1, $attempts),
            fn (int $attempt, Throwable $exception): int => self::backoffMilliseconds($attempt, $exception),
            fn (Throwable $exception): bool => self::shouldRetry($exception, $retryRateLimited),
            true,
        ];
    }

    /**
     * Decide whether an exception is worth another attempt.
     */
    public static function shouldRetry(Throwable $exception, bool $retryRateLimited = true): bool
    {
        if ($exception instanceof ConnectionException) {
            // cURL 28 during transfer ("Operation timed out after Xms with Y
            // bytes received") means the request ran and was cut by the
            // caller's timeout. Retrying re-runs a request expected to take
            // just as long — three attempts turn a 60s timeout into ~3 minutes
            // of wall time before failing. Fail fast so the configured timeout
            // means what it says. Connect-phase failures ("Connection timed
            // out after", "Failed to connect", "Could not resolve host")
            // remain retryable — those are the transient blips the policy
            // exists for.
            return ! str_contains($exception->getMessage(), 'Operation timed out');
        }

        if ($exception instanceof RequestException && $exception->response !== null) {
            $status = $exception->response->status();

            if ($status === 429) {
                return $retryRateLimited;
            }

            return in_array($status, self::RETRYABLE_STATUSES, true);
        }

        return false;
    }

    /**
     * How long to wait before the next attempt.
     *
     * Exponential from BASE_BACKOFF_MS, unless the response names a
     * `Retry-After`, which wins — a server telling you when to come back is
     * better information than a guess. Both are capped at MAX_BACKOFF_MS.
     */
    public static function backoffMilliseconds(int $attempt, ?Throwable $exception = null): int
    {
        $retryAfter = self::retryAfterMilliseconds($exception);

        if ($retryAfter !== null) {
            return min($retryAfter, self::MAX_BACKOFF_MS);
        }

        // attempt is 1-based: 500ms, 1000ms, 2000ms, ...
        $backoff = self::BASE_BACKOFF_MS * (2 ** max(0, $attempt - 1));

        return (int) min($backoff, self::MAX_BACKOFF_MS);
    }

    /**
     * Read a `Retry-After` header, in either of its two legal forms: a
     * delay in seconds, or an HTTP date. Returns null when absent or
     * unparseable, and clamps a past date to zero.
     */
    public static function retryAfterMilliseconds(?Throwable $exception): ?int
    {
        if (! $exception instanceof RequestException || $exception->response === null) {
            return null;
        }

        $header = trim((string) $exception->response->header('Retry-After'));

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return (int) $header * 1000;
        }

        $timestamp = strtotime($header);

        if ($timestamp === false) {
            return null;
        }

        return (int) max(0, ($timestamp - time()) * 1000);
    }
}

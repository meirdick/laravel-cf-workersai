<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Exceptions;

use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\FailoverableException;

/**
 * Thrown when Cloudflare's AI Gateway answers HTTP 408.
 *
 * This is not a network timeout — a client-side timeout surfaces as cURL 28
 * and Laravel's ConnectionException. A gateway 408 means the request reached
 * Cloudflare and the *generation* ran longer than the gateway would wait.
 * Measured 2026-09-09: `@cf/zai-org/glm-5.3-flash` with `max_tokens: 24000`
 * returned `408 Request timeout` after 709 seconds.
 *
 * It is deliberately not retried. The work that took 709 seconds will take
 * about 709 seconds again, and a retry policy that re-runs it turns one slow
 * failure into three. It is marked failoverable instead, so laravel/ai moves
 * to the next provider in the chain — which is the only response that both
 * answers the user and finishes this decade.
 *
 * If you have no failover provider configured, lower the token cap or split
 * the work.
 */
class GatewayTimeoutException extends AiException implements FailoverableException
{
    public static function forProvider(string $provider, int $code = 0, ?\Throwable $previous = null): self
    {
        return new self(
            "The [{$provider}] AI Gateway timed out while generating (HTTP 408). "
            .'The request reached Cloudflare and the generation ran longer than the gateway would wait. '
            .'This is not retried — the same work would take the same time again. '
            .'Lower `max_completion_tokens`, reduce `reasoning_effort`, split the work, '
            .'or configure a failover provider.',
            $code,
            $previous,
        );
    }
}

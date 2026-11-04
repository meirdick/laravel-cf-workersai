<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Gateway\Concerns;

/**
 * laravel/ai's failover mapping, without its own Retry-After retry.
 *
 * The HTTP client already retries. Leaving the upstream retry on re-runs an
 * exhausted sequence once more whenever the last response carries a short
 * `Retry-After`. The anonymous class exists so this trait can be used beside
 * another trait that also defines `retryAfterSeconds()`.
 */
trait MapsFailoverErrors
{
    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    protected function mapFailoverErrors(string $providerName, \Closure $callback): mixed
    {
        $handler = new class
        {
            use \Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors {
                withErrorHandling as public handle;
            }

            protected function retryAfterSeconds(\Throwable $exception): ?int
            {
                return null;
            }
        };

        return $handler->handle($providerName, $callback);
    }
}

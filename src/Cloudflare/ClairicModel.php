<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Cloudflare;

/**
 * Clairic's model ID and the selector its body requires.
 *
 * The path is `@cf/cloudflare/clairic`. The body selector is `clairic`.
 * The schema also accepts the path ID as the selector; the short form is
 * what Cloudflare's own examples send.
 */
final class ClairicModel
{
    public const ID = '@cf/cloudflare/clairic';

    public const SELECTOR = 'clairic';

    public static function is(string $model): bool
    {
        return RunEndpoint::bareModel($model) === self::ID;
    }
}

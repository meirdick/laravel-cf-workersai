<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Cloudflare;

use InvalidArgumentException;

/**
 * Maps a Clef model ID to the `model` selector Cloudflare requires in the body.
 *
 * The path carries `@cf/cloudflare/clef`. The body must also say `clef`.
 * Sending the path ID, or nothing, is not what the documented schema accepts.
 */
final class ClefModel
{
    public const DEFAULT = '@cf/cloudflare/clef';

    public const CHEAPEST = '@cf/cloudflare/clef-flash';

    /** @var array<string, string> */
    private const SELECTORS = [
        '@cf/cloudflare/clef' => 'clef',
        '@cf/cloudflare/clef-flash' => 'clef-flash',
        '@cf/cloudflare/clef-omni' => 'clef-omni',
    ];

    public static function is(string $model): bool
    {
        return array_key_exists(self::bare($model), self::SELECTORS);
    }

    public static function selector(string $model): string
    {
        $bare = self::bare($model);

        return self::SELECTORS[$bare]
            ?? throw new InvalidArgumentException(
                "Workers AI model '{$model}' is not a Clef decision model. "
                .'Use @cf/cloudflare/clef, @cf/cloudflare/clef-flash or @cf/cloudflare/clef-omni.'
            );
    }

    public static function bare(string $model): string
    {
        return RunEndpoint::bareModel($model);
    }
}

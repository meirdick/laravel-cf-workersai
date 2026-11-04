<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Extraction;

/**
 * Drops null keys so an unset optional is absent on the wire.
 */
final class FieldSpec
{
    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    public static function filter(array $spec): array
    {
        return array_filter($spec, fn (mixed $value): bool => $value !== null);
    }
}

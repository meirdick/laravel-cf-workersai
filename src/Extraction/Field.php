<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Extraction;

use Illuminate\Contracts\Support\Arrayable;

/**
 * One typed field in a Clairic request.
 *
 * @extends Arrayable<string, mixed>
 */
interface Field extends Arrayable
{
    /**
     * The field spec Clairic expects under `fields`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}

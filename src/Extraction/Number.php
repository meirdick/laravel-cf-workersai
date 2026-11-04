<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Extraction;

/**
 * A number field. Set `decimals` for a fixed scale, `integer` to forbid one.
 */
final readonly class Number implements Field
{
    public function __construct(
        public ?string $instructions = null,
        public ?string $unit = null,
        public ?bool $integer = null,
        public ?int $decimals = null,
        public ?float $nullThreshold = null,
    ) {}

    public function toArray(): array
    {
        return FieldSpec::filter([
            'type' => 'number',
            'instructions' => $this->instructions,
            'unit' => $this->unit,
            'integer' => $this->integer,
            'decimals' => $this->decimals,
            'null_threshold' => $this->nullThreshold,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Extraction;

/**
 * A value predicted on a grid between `min` and `max`.
 *
 * Clairic returns a median, a mean and an 80% interval, not a single span.
 * `min` and `max` are numbers, or ISO dates for a date estimate.
 */
final readonly class Estimate implements Field
{
    /**
     * @param  int|float|string  $min
     * @param  int|float|string  $max
     * @param  int|float|string|null  $step  a positive number, or "month" / "year" for dates
     */
    public function __construct(
        public int|float|string $min,
        public int|float|string $max,
        public ?string $instructions = null,
        public int|float|string|null $step = null,
        public ?string $scale = null,
        public ?string $unit = null,
        public ?float $nullThreshold = null,
    ) {}

    public function toArray(): array
    {
        return FieldSpec::filter([
            'type' => 'estimate',
            'min' => $this->min,
            'max' => $this->max,
            'instructions' => $this->instructions,
            'step' => $this->step,
            'scale' => $this->scale,
            'unit' => $this->unit,
            'null_threshold' => $this->nullThreshold,
        ]);
    }
}

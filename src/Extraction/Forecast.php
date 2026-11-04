<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Extraction;

/**
 * A sequence of estimates, one per step out to `horizon`.
 */
final readonly class Forecast implements Field
{
    /**
     * @param  int|float|string  $min
     * @param  int|float|string  $max
     * @param  int|float|string|null  $step
     */
    public function __construct(
        public int|float|string $min,
        public int|float|string $max,
        public int $horizon,
        public string $interval,
        public ?string $instructions = null,
        public int|float|string|null $step = null,
        public ?string $scale = null,
        public ?string $unit = null,
        public ?float $nullThreshold = null,
    ) {}

    public function toArray(): array
    {
        return FieldSpec::filter([
            'type' => 'forecast',
            'min' => $this->min,
            'max' => $this->max,
            'horizon' => $this->horizon,
            'interval' => $this->interval,
            'instructions' => $this->instructions,
            'step' => $this->step,
            'scale' => $this->scale,
            'unit' => $this->unit,
            'null_threshold' => $this->nullThreshold,
        ]);
    }
}

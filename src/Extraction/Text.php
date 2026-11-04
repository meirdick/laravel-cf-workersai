<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Extraction;

/**
 * A text field. Clairic returns a span of the state, or null.
 */
final readonly class Text implements Field
{
    public function __construct(
        public ?string $instructions = null,
        public ?float $nullThreshold = null,
    ) {}

    public function toArray(): array
    {
        return FieldSpec::filter([
            'type' => 'text',
            'instructions' => $this->instructions,
            'null_threshold' => $this->nullThreshold,
        ]);
    }
}

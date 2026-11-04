<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Extraction;

/**
 * A list of text, number or date items.
 */
final readonly class ListField implements Field
{
    public function __construct(
        public string $items = 'text',
        public ?string $instructions = null,
        public ?string $unit = null,
        public ?int $decimals = null,
        public ?float $nullThreshold = null,
    ) {}

    public static function of(string $items, ?string $instructions = null): self
    {
        return new self($items, $instructions);
    }

    public function toArray(): array
    {
        return FieldSpec::filter([
            'type' => 'list',
            'items' => $this->items,
            'instructions' => $this->instructions,
            'unit' => $this->unit,
            'decimals' => $this->decimals,
            'null_threshold' => $this->nullThreshold,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Extraction;

/**
 * A date, time, datetime or duration field.
 *
 * The class is not named `Date` so a `use` import does not collide with
 * PHP's `DateTime` family in calling code that already imports those.
 */
final readonly class DateField implements Field
{
    public const DATE = 'date';

    public const TIME = 'time';

    public const DATETIME = 'datetime';

    public const DURATION = 'duration';

    public function __construct(
        public string $type = self::DATE,
        public ?string $instructions = null,
        public ?string $unit = null,
        public ?bool $integer = null,
        public ?int $decimals = null,
        public ?float $nullThreshold = null,
    ) {}

    public static function date(?string $instructions = null): self
    {
        return new self(self::DATE, $instructions);
    }

    public static function time(?string $instructions = null): self
    {
        return new self(self::TIME, $instructions);
    }

    public static function dateTime(?string $instructions = null): self
    {
        return new self(self::DATETIME, $instructions);
    }

    /**
     * @param  string|null  $unit  seconds, minutes, hours, days, weeks, months or years
     */
    public static function duration(?string $instructions = null, ?string $unit = null, ?bool $integer = null): self
    {
        return new self(self::DURATION, $instructions, $unit, $integer);
    }

    public function toArray(): array
    {
        return FieldSpec::filter([
            'type' => $this->type,
            'instructions' => $this->instructions,
            'unit' => $this->unit,
            'integer' => $this->integer,
            'decimals' => $this->decimals,
            'null_threshold' => $this->nullThreshold,
        ]);
    }
}

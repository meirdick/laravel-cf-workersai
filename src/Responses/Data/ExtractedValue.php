<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * One Clairic answer.
 *
 * `value` is null when the input does not determine it. `pNull` is the
 * probability of that. `confidence` is how sure the model is of the guess.
 * An estimate also carries `median`, `mean`, `p10` and `p90`. A text answer
 * carries `span`, a character offset pair into the state.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class ExtractedValue implements Arrayable, JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(public array $raw) {}

    public function type(): ?string
    {
        $type = $this->raw['type'] ?? null;

        return is_string($type) ? $type : null;
    }

    public function value(): mixed
    {
        return $this->raw['value'] ?? null;
    }

    public function isNull(): bool
    {
        return $this->value() === null;
    }

    /**
     * Probability that the input does not determine the value.
     */
    public function pNull(): ?float
    {
        return $this->float('p_null');
    }

    public function confidence(): ?float
    {
        return $this->float('confidence');
    }

    /**
     * True when the value is present and `pNull` is at or below the threshold.
     *
     * Clairic already applies its own null threshold (default 0.5) before
     * setting `value`. This lets a caller demand a stricter one.
     */
    public function isPresent(float $threshold = 0.5): bool
    {
        if ($this->isNull()) {
            return false;
        }

        $pNull = $this->pNull();

        return $pNull === null || $pNull <= $threshold;
    }

    /**
     * Character offsets of a text answer, `[start, end]`, or null.
     *
     * @return array{0: int, 1: int}|null
     */
    public function span(): ?array
    {
        $span = $this->raw['span'] ?? null;

        if (! is_array($span) || count($span) < 2) {
            return null;
        }

        return [(int) $span[0], (int) $span[1]];
    }

    public function median(): mixed
    {
        return $this->raw['median'] ?? null;
    }

    public function mean(): mixed
    {
        return $this->raw['mean'] ?? null;
    }

    public function p10(): mixed
    {
        return $this->raw['p10'] ?? null;
    }

    public function p90(): mixed
    {
        return $this->raw['p90'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private function float(string $key): ?float
    {
        $value = $this->raw[$key] ?? null;

        return is_int($value) || is_float($value) ? (float) $value : null;
    }
}

<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Responses;

use ArrayAccess;
use Countable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Collection;
use IteratorAggregate;
use JsonSerializable;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use LogicException;
use Meirdick\WorkersAi\Responses\Data\ExtractedValue;
use Traversable;

/**
 * Clairic's answers, keyed by field name.
 *
 * Read a field with `$response['invoice_number']` or `value()`. `truncated`
 * is true when the state did not fit and Clairic cut it. `stateText` is the
 * text Clairic read, when the call sets the `return_state_text` option.
 *
 * @implements ArrayAccess<string, ExtractedValue>
 * @implements IteratorAggregate<string, ExtractedValue>
 */
class ExtractionResponse implements Arrayable, ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /**
     * The raw HTTP response. Set when this laravel/ai install already exposes
     * raw responses on its own response objects (0.10 and later).
     */
    public ?HttpResponse $raw = null;

    /**
     * @param  array<string, ExtractedValue>  $answers
     */
    public function __construct(
        public readonly array $answers,
        public readonly bool $truncated,
        public readonly Usage $usage,
        public readonly Meta $meta,
        public readonly ?string $stateText = null,
    ) {}

    public function withRawResponse(?HttpResponse $response): self
    {
        $this->raw = $response;

        return $this;
    }

    public function value(string $field): ExtractedValue
    {
        return $this->answers[$field] ?? throw new \InvalidArgumentException(
            "Clairic returned no answer for field [{$field}]."
        );
    }

    /**
     * @return Collection<string, ExtractedValue>
     */
    public function collect(): Collection
    {
        return new Collection($this->answers);
    }

    public function count(): int
    {
        return count($this->answers);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'answers' => array_map(fn (ExtractedValue $answer): array => $answer->toArray(), $this->answers),
            'truncated' => $this->truncated,
            'state_text' => $this->stateText,
            'usage' => $this->usage->toArray(),
            'meta' => $this->meta->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @return Traversable<string, ExtractedValue>
     */
    public function getIterator(): Traversable
    {
        return new \ArrayIterator($this->answers);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->answers[$offset]);
    }

    public function offsetGet(mixed $offset): ExtractedValue
    {
        return $this->value((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Clairic answers are read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Clairic answers are read-only.');
    }

    /**
     * Drop the HTTP response. It is not serializable, and a queued job that
     * carries this object would fail on it.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $properties = get_object_vars($this);
        unset($properties['raw']);

        return $properties;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        foreach ($data as $key => $value) {
            $this->{$key} = $value;
        }

        $this->raw = null;
    }
}

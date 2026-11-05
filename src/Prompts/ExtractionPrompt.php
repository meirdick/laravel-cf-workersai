<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Prompts;

use Countable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Ai\Files\File;
use Laravel\Ai\Providers\Provider;
use Meirdick\WorkersAi\Extraction\Field;

/**
 * One Clairic call, as events and `Extraction::fake()` assertions see it.
 *
 * The same shape as laravel/ai's `ClassificationPrompt`.
 */
class ExtractionPrompt implements Countable
{
    /**
     * @param  array<string, Field>  $fields
     * @param  array<string, mixed>  $providerOptions
     * @param  array<int|string, File|UploadedFile>  $attachments
     */
    public function __construct(
        public readonly string $state,
        public readonly array $fields,
        public readonly Provider $provider,
        public readonly string $model,
        public readonly int $timeout = 30,
        public readonly array $providerOptions = [],
        public readonly array $attachments = [],
        public readonly ?string $referenceDate = null,
    ) {}

    public function contains(string $string): bool
    {
        return Str::contains($this->state, $string);
    }

    public function asks(string $field): bool
    {
        return array_key_exists($field, $this->fields);
    }

    public function count(): int
    {
        return count($this->fields);
    }
}

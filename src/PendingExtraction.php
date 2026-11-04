<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Laravel\Ai\AiManager;
use Laravel\Ai\Files\File;
use Meirdick\WorkersAi\Extraction\Field;
use Meirdick\WorkersAi\Providers\WorkersAiProvider;
use Meirdick\WorkersAi\Responses\ExtractionResponse;

/**
 * A Clairic extraction that has not been sent.
 *
 * ```php
 * Extraction::of($invoice)
 *     ->field('total', new Number(instructions: 'Amount due', decimals: 2))
 *     ->extract();
 * ```
 */
class PendingExtraction
{
    /** @var array<string, Field> */
    protected array $fields = [];

    /** @var array<string, mixed> */
    protected array $providerOptions = [];

    protected ?string $referenceDate = null;

    protected ?string $provider = null;

    protected ?string $model = null;

    protected int $timeout = 30;

    /**
     * @param  array<int|string, File|UploadedFile>  $attachments
     */
    public function __construct(
        protected string $state,
        protected array $attachments = [],
    ) {}

    public function field(string $name, Field $field): self
    {
        $this->fields[$name] = $field;

        return $this;
    }

    /**
     * @param  array<string, Field>  $fields
     */
    public function fields(array $fields): self
    {
        foreach ($fields as $name => $field) {
            $this->field((string) $name, $field);
        }

        return $this;
    }

    /**
     * ISO date that anchors dates written without a year.
     */
    public function referenceDate(string $date): self
    {
        $this->referenceDate = $date;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function withProviderOptions(array $options): self
    {
        $this->providerOptions = [...$this->providerOptions, ...$options];

        return $this;
    }

    public function provider(string $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    public function model(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function timeout(int $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $providerOptions
     */
    public function extract(?string $provider = null, ?string $model = null, int $timeout = 30, array $providerOptions = []): ExtractionResponse
    {
        if ($this->fields === []) {
            throw new InvalidArgumentException('Clairic needs at least one field. Call field() or fields() before extract().');
        }

        $resolved = $this->resolveProvider($provider ?? $this->provider);

        return $resolved->extract(
            $this->state,
            $this->fields,
            $model ?? $this->model,
            $timeout === 30 ? $this->timeout : $timeout,
            [...$this->providerOptions, ...$providerOptions],
            $this->attachments,
            $this->referenceDate,
        );
    }

    protected function resolveProvider(?string $name): WorkersAiProvider
    {
        $manager = app(AiManager::class);

        $instance = $manager->instance($name ?? $this->defaultProviderName());

        if (! $instance instanceof WorkersAiProvider) {
            throw new InvalidArgumentException(
                'Clairic extraction needs the workers-ai provider. '
                ."[{$instance->name()}] is a ".class_basename($instance).'. '
                .'Pass provider: \'workers-ai\', or set ai.default to workers-ai.'
            );
        }

        return $instance;
    }

    protected function defaultProviderName(): string
    {
        $configured = config('ai.default');

        if (is_string($configured) && in_array($configured, [WorkersAiKey::PRIMARY, WorkersAiKey::ALIAS], true)) {
            return $configured;
        }

        if (config('ai.providers.'.WorkersAiKey::PRIMARY) !== null) {
            return WorkersAiKey::PRIMARY;
        }

        return WorkersAiKey::ALIAS;
    }
}

<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Traits\Conditionable;
use InvalidArgumentException;
use Laravel\Ai\AiManager;
use Laravel\Ai\Events\ProviderFailedOver;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Files\File;
use LogicException;
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
    use Conditionable;

    /** @var array<string, Field> */
    protected array $fields = [];

    /** @var array<string, mixed>|Closure(WorkersAiProvider): ?array<string, mixed> */
    protected array|Closure $providerOptions = [];

    /** @var array<string, string> */
    protected array $headers = [];

    protected ?string $referenceDate = null;

    /** @var string|array<int|string, mixed>|null */
    protected string|array|null $provider = null;

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
     * Add body options, or give a closure that returns them for a provider.
     *
     * @param  array<string, mixed>|Closure(WorkersAiProvider): ?array<string, mixed>  $options
     */
    public function withProviderOptions(array|Closure $options): self
    {
        $this->providerOptions = is_array($options) && is_array($this->providerOptions)
            ? [...$this->providerOptions, ...$options]
            : $options;

        return $this;
    }

    /**
     * Add HTTP headers for this call. Needs laravel/ai 1.x, which added
     * `Provider::withHeaders()`. On 0.9 to 0.11 use the `headers` config key.
     *
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): self
    {
        $this->headers = [...$this->headers, ...$headers];

        return $this;
    }

    /**
     * @param  string|array<int|string, mixed>  $provider
     */
    public function provider(string|array $provider): self
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
     * Send the extraction.
     *
     * Give a list of providers to fail over, the same way
     * `Classification::of()->classify()` does: `['workers-ai', 'backup']`, or
     * `['workers-ai' => '@cf/cloudflare/clairic']` to name a model for each.
     *
     * @param  string|array<int|string, mixed>|null  $provider
     * @param  array<string, mixed>  $providerOptions
     *
     * @throws FailoverableException if every provider fails.
     */
    public function extract(string|array|null $provider = null, ?string $model = null, ?int $timeout = null, array $providerOptions = []): ExtractionResponse
    {
        if ($this->fields === []) {
            throw new InvalidArgumentException('Clairic needs at least one field. Call field() or fields() before extract().');
        }

        $lastException = null;

        foreach ($this->providerList($provider ?? $this->provider, $model ?? $this->model) as $name => $candidateModel) {
            $resolved = $this->resolveProvider($name);

            try {
                return $this->withCallHeaders($resolved)->extract(
                    $this->state,
                    $this->fields,
                    $candidateModel,
                    $timeout ?? $this->timeout,
                    [...$this->resolveProviderOptions($resolved), ...$providerOptions],
                    $this->attachments,
                    $this->referenceDate,
                );
            } catch (FailoverableException $exception) {
                $lastException = $exception;

                event(new ProviderFailedOver($resolved, $candidateModel ?? $resolved->defaultExtractionModel(), $exception));
            }
        }

        throw $lastException;
    }

    /**
     * @param  string|array<int|string, mixed>|null  $provider
     * @return array<string, ?string>
     */
    protected function providerList(string|array|null $provider, ?string $model): array
    {
        if (is_array($provider)) {
            $list = [];

            foreach ($provider as $key => $value) {
                is_int($key) ? $list[(string) $value] = $model : $list[$key] = $value;
            }

            return $list;
        }

        return [$provider ?? $this->defaultProviderName() => $model];
    }

    protected function withCallHeaders(WorkersAiProvider $provider): WorkersAiProvider
    {
        if ($this->headers === []) {
            return $provider;
        }

        if (! method_exists($provider, 'withHeaders')) {
            throw new LogicException('withHeaders() needs laravel/ai 1.x. On earlier versions, set the `headers` config key.');
        }

        return $provider->withHeaders($this->headers);
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveProviderOptions(WorkersAiProvider $provider): array
    {
        return $this->providerOptions instanceof Closure
            ? (($this->providerOptions)($provider) ?: [])
            : $this->providerOptions;
    }

    protected function resolveProvider(string $name): WorkersAiProvider
    {
        $instance = app(AiManager::class)->instance($name);

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

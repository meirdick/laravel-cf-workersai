<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Gateway;

use Closure;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\Meta;
use Meirdick\WorkersAi\Cloudflare\SdkCompat;
use Meirdick\WorkersAi\Extraction\Field;
use Meirdick\WorkersAi\Prompts\ExtractionPrompt;
use Meirdick\WorkersAi\Responses\Data\ExtractedValue;
use Meirdick\WorkersAi\Responses\ExtractionResponse;

/**
 * Answers Clairic calls without a request. `Extraction::fake()` installs it.
 *
 * A response is a map of field name to value, an `ExtractedValue`, or a whole
 * `ExtractionResponse`. A field with no value given is null, as a real answer
 * is when the input does not determine it.
 */
class FakeExtractionGateway extends WorkersAiExtractionGateway
{
    /** @var list<ExtractionPrompt> */
    public array $recorded = [];

    protected int $next = 0;

    /**
     * @param  Closure(ExtractionPrompt): mixed|array<int, mixed>  $responses
     */
    public function __construct(protected Closure|array $responses = [])
    {
        //
    }

    public function extract(
        Provider $provider,
        string $model,
        string $state,
        array $fields,
        int $timeout = 30,
        array $providerOptions = [],
        array $attachments = [],
        ?string $referenceDate = null,
    ): ExtractionResponse {
        $prompt = new ExtractionPrompt($state, $fields, $provider, $model, $timeout, $providerOptions, $attachments, $referenceDate);

        $this->recorded[] = $prompt;

        $response = is_array($this->responses)
            ? ($this->responses[$this->next] ?? [])
            : ($this->responses)($prompt);

        $this->next++;

        if ($response instanceof ExtractionResponse) {
            return $response;
        }

        $answers = [];

        foreach ($fields as $name => $field) {
            $answers[$name] = $this->answer($field, is_array($response) ? ($response[$name] ?? null) : null);
        }

        return new ExtractionResponse($answers, false, SdkCompat::textUsage([]), new Meta($provider->name(), $model));
    }

    protected function answer(Field $field, mixed $value): ExtractedValue
    {
        if ($value instanceof ExtractedValue) {
            return $value;
        }

        $type = $field->toArray()['type'] ?? null;

        return new ExtractedValue([
            'type' => $type,
            'value' => $value,
            'p_null' => $value === null ? 1.0 : 0.0,
            'confidence' => 1.0,
        ]);
    }
}

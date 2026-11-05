<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Gateway;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Laravel\Ai\Files\File;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\Meta;
use Meirdick\WorkersAi\Cloudflare\ClairicModel;
use Meirdick\WorkersAi\Cloudflare\RunEndpoint;
use Meirdick\WorkersAi\Cloudflare\SdkCompat;
use Meirdick\WorkersAi\Extraction\Field;
use Meirdick\WorkersAi\Gateway\Concerns\MapsFailoverErrors;
use Meirdick\WorkersAi\Gateway\Concerns\PostsWorkersAiRun;
use Meirdick\WorkersAi\Responses\Data\ExtractedValue;
use Meirdick\WorkersAi\Responses\ExtractionResponse;

/**
 * One Clairic call.
 *
 * The body is `{model, state, fields}` plus optional images and a reference
 * date. The response is one answer per field.
 */
class WorkersAiExtractionGateway
{
    use MapsFailoverErrors;
    use PostsWorkersAiRun;

    public function __construct(protected Dispatcher $events)
    {
        //
    }

    /**
     * @param  array<string, Field>  $fields
     * @param  array<string, mixed>  $providerOptions
     * @param  array<int|string, File|UploadedFile>  $attachments
     */
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
        $model = RunEndpoint::bareModel($model);

        if (! ClairicModel::is($model)) {
            throw new InvalidArgumentException(
                "Workers AI extraction uses Clairic. '{$model}' is not Clairic. "
                .'Use @cf/cloudflare/clairic, or set models.extraction.default.'
            );
        }

        $body = array_merge($this->callerOptions($providerOptions), [
            'model' => ClairicModel::SELECTOR,
            'state' => $state,
            'fields' => array_map(fn (Field $field): array => $field->toArray(), $fields),
        ]);

        if ($referenceDate !== null) {
            $body['reference_date'] = $referenceDate;
        }

        if ($attachments !== []) {
            $body['images'] = $this->runImages($attachments, 'Clairic');
        }

        [$data, $response] = $this->postRun($provider, $model, $timeout, $body);

        $answers = [];

        foreach ($data['answers'] as $name => $answer) {
            if (is_array($answer)) {
                $answers[(string) $name] = new ExtractedValue($answer);
            }
        }

        $extraction = new ExtractionResponse(
            $answers,
            (bool) ($data['truncated'] ?? false),
            SdkCompat::textUsage($this->usageWire(is_array($data['usage'] ?? null) ? $data['usage'] : null)),
            new Meta($provider->name(), is_string($data['model'] ?? null) ? $data['model'] : $model),
            is_string($data['state_text'] ?? null) ? $data['state_text'] : null,
        );

        // 0.9's response objects have no raw response. Attaching one there
        // would be a property nothing on that line reads, and a later
        // serialize of the HTTP response can fail. Match the chat path.
        if (method_exists(\Laravel\Ai\Gateway\StepResponse::class, 'withRawResponse')) {
            $extraction->withRawResponse($response);
        }

        return $extraction;
    }

    /**
     * Caller options may add `options` or other Clairic fields. They may not
     * replace the model, state, fields or images this method built.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function callerOptions(array $options): array
    {
        unset($options['model'], $options['state'], $options['fields'], $options['images']);

        return $options;
    }

    /**
     * Clairic reports `input_tokens`, not the chat `prompt_tokens` key.
     *
     * @param  array<string, mixed>|null  $usage
     * @return array<string, mixed>
     */
    protected function usageWire(?array $usage): array
    {
        if ($usage === null) {
            return [];
        }

        return [
            'prompt_tokens' => $usage['input_tokens'] ?? 0,
            'completion_tokens' => $usage['output_tokens'] ?? 0,
        ];
    }
}

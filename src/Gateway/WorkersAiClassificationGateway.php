<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Gateway;

if (! interface_exists(\Laravel\Ai\Contracts\Gateway\ClassificationGateway::class)) {
    return;
}


use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;
use Laravel\Ai\Contracts\Gateway\ClassificationGateway;
use Laravel\Ai\Contracts\Providers\ClassificationProvider;
use Laravel\Ai\Contracts\Question;
use Laravel\Ai\Files\File;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ScoreAnswer;
use Laravel\Ai\Responses\Data\TextUsage;
use Meirdick\WorkersAi\Cloudflare\ClefModel;
use Meirdick\WorkersAi\Gateway\Concerns\MapsFailoverErrors;
use Meirdick\WorkersAi\Gateway\Concerns\PostsWorkersAiRun;

/**
 * Clef through laravel/ai's classification API.
 *
 * `AnswersQuestions` already writes the System One wire format Clef accepts
 * (`noul`, `choice`, `score`) and reads the answer objects it returns. This
 * gateway changes where the request goes, swaps the path ID for Clef's body
 * selector, and adds Clef's `images` field. Jev sends images as named assets
 * inside the state. Clef rejects that. It wants up to four inline images.
 */
class WorkersAiClassificationGateway implements ClassificationGateway
{
    use MapsFailoverErrors;
    use PostsWorkersAiRun;

    public function __construct(protected Dispatcher $events)
    {
        //
    }

    /**
     * @param  string|array<string, mixed>  $state
     * @param  array<string, Question>  $questions
     * @param  array<string, mixed>  $providerOptions
     * @param  array<int|string, File|UploadedFile>  $attachments
     */
    public function classify(
        ClassificationProvider $provider,
        string $model,
        string|array $state,
        array $questions,
        int $timeout = 30,
        array $providerOptions = [],
        array $attachments = [],
    ): ClassificationResponse {
        $model = ClefModel::bare($model);

        if (! ClefModel::is($model)) {
            throw new InvalidArgumentException(
                "Workers AI classification uses Clef. '{$model}' is not a Clef model. "
                .'Use @cf/cloudflare/clef, @cf/cloudflare/clef-flash or @cf/cloudflare/clef-omni, '
                .'or set models.classification.default.'
            );
        }

        if ($attachments !== [] && ! $this->attachmentsReachTheGateway()) {
            throw new InvalidArgumentException(
                'Clef image attachments need laravel/ai 1.2 or later. This install does not pass attachments to classify().'
            );
        }

        $options = $attachments === []
            ? $providerOptions
            : [...$providerOptions, 'images' => $this->runImages($attachments, 'Clef')];

        [$data] = $this->postRun($provider, $model, $timeout, array_merge($options, [
            'model' => ClefModel::selector($model),
            'state' => $state,
            'questions' => array_map($this->mapQuestion(...), $questions),
        ]));

        return $this->classificationResponse($provider, $model, $questions, $data);
    }

    /**
     * @param  array<string, Question>  $questions
     * @param  array<string, mixed>  $data
     */
    protected function classificationResponse(
        ClassificationProvider $provider,
        string $model,
        array $questions,
        array $data,
    ): ClassificationResponse {
        $answers = [];

        foreach ($data['answers'] as $key => $answer) {
            if ($mapped = $this->mapAnswer($answer, $questions[$key] ?? null)) {
                $answers[$key] = $mapped;
            }
        }

        return new ClassificationResponse(
            $answers,
            new TextUsage(
                inputTokens: $data['usage']['input_tokens'] ?? 0,
                outputTokens: $data['usage']['output_tokens'] ?? 0,
            ),
            new Meta($provider->name(), is_string($data['model'] ?? null) ? $data['model'] : ClefModel::selector($model)),
        );
    }

    /**
     * The System One question shape Clef shares with Jev.
     *
     * Kept here rather than imported from laravel/ai's `AnswersQuestions`.
     * That trait does not exist on 0.9 to 0.11, and a `use` of it would make
     * this file fatal to load on those versions even though nothing calls it.
     *
     * @return array<string, mixed>
     */
    protected function mapQuestion(Question $question): array
    {
        return match (true) {
            $question instanceof Boolean => array_filter([
                'type' => 'noul',
                'instructions' => $question->instructions,
                'criteria' => $question->criteria,
            ], fn (mixed $value): bool => $value !== null),
            $question instanceof Choice => [
                'type' => 'choice',
                'instructions' => $question->instructions,
                'criteria' => $question->options,
            ],
            $question instanceof Score => [
                'type' => 'score',
                'instructions' => $question->instructions,
                'criteria' => $question->levels,
            ],
            default => $question->toArray(),
        };
    }

    /**
     * @return \Laravel\Ai\Responses\Data\Answer|null
     */
    protected function mapAnswer(array $answer, ?Question $question = null): mixed
    {
        return match ($answer['type'] ?? null) {
            'noul' => new BooleanAnswer((float) $answer['noul']),
            'choice' => new ChoiceAnswer(
                (string) $answer['choice'],
                $answer['probabilities'] ?? [],
                isset($answer['confidence']) ? (float) $answer['confidence'] : null,
            ),
            'score' => new ScoreAnswer(
                (float) $answer['score'],
                $this->integerKeys($answer['probabilities'] ?? []),
                $this->integerKeys($answer['legend'] ?? ($question instanceof Score ? $question->levels : [])),
                isset($answer['confidence']) ? (float) $answer['confidence'] : null,
            ),
            default => null,
        };
    }

    /**
     * Clef returns score level keys as strings. ScoreAnswer indexes them as ints.
     *
     * @param  array<int|string, mixed>  $levels
     * @return array<int, mixed>
     */
    protected function integerKeys(array $levels): array
    {
        $result = [];

        foreach ($levels as $level => $value) {
            $result[(int) $level] = $value;
        }

        ksort($result);

        return $result;
    }

    /**
     * laravel/ai 1.0 and 1.1 call `classify()` with no attachments argument.
     * A gateway that requires the argument fatals on those versions.
     */
    protected function attachmentsReachTheGateway(): bool
    {
        $method = new \ReflectionMethod(ClassificationGateway::class, 'classify');

        return $method->getNumberOfParameters() >= 7;
    }
}

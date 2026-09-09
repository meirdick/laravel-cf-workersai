<?php

namespace Meirdick\WorkersAi\Gateway\Concerns;

use Generator;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Responses\Data\FinishReason;
use Meirdick\WorkersAi\Exceptions\EmptyResponseException;
use Meirdick\WorkersAi\Exceptions\TruncatedResponseException;
use Meirdick\WorkersAi\Providers\WorkersAiProvider;

/**
 * Workers AI's counterpart to laravel/ai's
 * `OpenAiCompatible\Concerns\PerformsChatCompletionSteps`. A package-local
 * variant is kept (instead of adopting the core trait) because Cloudflare
 * needs three hooks the core trait doesn't expose:
 *
 * 1. Model-name/endpoint validation (`@cf/` prefix rules) before the request.
 * 2. The structured-output validate + bounded re-ask INSIDE the step — a
 *    re-ask is a same-step retry against Workers AI's best-effort JSON mode,
 *    not a tool step, so it must not consume the loop's step budget.
 * 3. `TextGenerationOptions` threaded into stream processing so the
 *    truncation heuristic can compare completion tokens against the
 *    requested budget.
 */
trait PerformsChatCompletionSteps
{
    /**
     * Generate text for a single Chat Completions step.
     */
    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        $model = $this->resolveModelName($provider, $model);

        $body = $this->buildStepBody($provider, $model, $instructions, $messages, $tools, $schema, $options, $stepContext);

        $result = $this->performTextStep($provider, $body, $options, $timeout, filled($schema));

        // Workers AI JSON mode does NOT guarantee the response satisfies the
        // requested schema — a model can omit a required field, emit an
        // out-of-enum value, or return malformed JSON. Feed the validation
        // error back and re-ask (bounded). Truncation (Length) is a token-
        // budget problem re-asking can't fix, so it's left to the caller.
        $reasks = 0;

        while (filled($schema)
            && $result->finishReason !== FinishReason::Length
            && filled($errors = $this->structuredOutputErrors($result->structured, $schema))
            && $reasks < $this->maxStructuredRetries($provider)) {
            $reasks++;

            $body = $this->appendReaskTurn($body, $result, $errors);

            $result = $this->performTextStep($provider, $body, $options, $timeout, true);
        }

        $this->guardAgainstUnusableStep($provider, $result, $body);

        return $result;
    }

    /**
     * Reject a step that came back HTTP 200 but carries nothing usable.
     *
     * laravel/ai's TextGenerationLoop does not branch on `FinishReason::Length`
     * at all (verified against v0.11.2) — a truncated answer is returned as an
     * ordinary success, and a `content: null` reasoning response arrives as an
     * empty string with `toArray()` yielding `[]`. In an unattended pipeline
     * that is indistinguishable from a genuine short answer, which makes it
     * the most expensive failure mode Workers AI has. Both checks look at the
     * step the model actually returned, so a tool-calling turn with no text is
     * never mistaken for an empty answer.
     *
     * @param  array<string, mixed>  $body  the request body that produced $result
     *
     * @throws EmptyResponseException
     * @throws TruncatedResponseException
     */
    protected function guardAgainstUnusableStep(TextProvider $provider, StepResponse $result, array $body): void
    {
        if (! $provider instanceof WorkersAiProvider) {
            return;
        }

        $hasNothing = blank($result->text) && blank($result->toolCalls);

        if ($hasNothing && $provider->throwOnEmptyResponse()) {
            throw EmptyResponseException::forStep(
                $body['model'] ?? null,
                $result->finishReason->value,
                $result->usage->reasoningTokens,
            );
        }

        if ($result->finishReason === FinishReason::Length && $provider->throwOnTruncation()) {
            throw TruncatedResponseException::forStep(
                $result->usage->completionTokens,
                $body['max_completion_tokens'] ?? null,
                $body['model'] ?? null,
            );
        }
    }

    /**
     * Stream text for a single Chat Completions step.
     */
    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        $model = $this->resolveModelName($provider, $model);

        $body = $this->buildStepBody($provider, $model, $instructions, $messages, $tools, $schema, $options, $stepContext);

        $body['stream'] = true;
        $body['stream_options'] = ['include_usage' => true];

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)
                ->withOptions(['stream' => true])
                ->post('chat/completions', $body),
        );

        return yield from $this->processTextStream(
            $invocationId,
            $provider,
            $model,
            $options,
            $response->getBody(),
        );
    }

    /**
     * Perform one Chat Completions request and parse it into a StepResponse.
     */
    protected function performTextStep(
        TextProvider $provider,
        array $body,
        ?TextGenerationOptions $options,
        ?int $timeout,
        bool $structured,
    ): StepResponse {
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('chat/completions', $body),
        );

        $data = $response->json();

        $this->validateTextResponse($data);

        return $this->parseTextResponse($data, $provider, $structured, $options);
    }

    /**
     * Append the model's schema-invalid turn plus a corrective user message so
     * the re-ask keeps the failed attempt in the transcript and the model can
     * self-correct. A forced tool_choice is relaxed so the re-ask can't be
     * pushed back into a tool call instead of the corrected JSON object.
     *
     * @param  array<string, mixed>  $body
     * @param  list<string>  $errors
     * @return array<string, mixed>
     */
    protected function appendReaskTurn(array $body, StepResponse $result, array $errors): array
    {
        $body['messages'][] = [
            'role' => 'assistant',
            'content' => $result->text,
        ];

        $body['messages'][] = [
            'role' => 'user',
            'content' => 'Your previous response did not satisfy the required JSON schema: '
                .implode('; ', $errors)
                .'. Reply again with a single valid JSON object that fills every required field with an appropriate value. Output only the JSON object, with no surrounding text.',
        ];

        return $this->relaxForcedToolChoice($body);
    }
}

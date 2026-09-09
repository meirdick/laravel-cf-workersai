<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Exceptions;

use Laravel\Ai\Exceptions\AiException;

/**
 * Thrown when Workers AI stopped generating because it hit the completion
 * budget rather than because it was finished.
 *
 * laravel/ai records `FinishReason::Length` on the step and returns the
 * partial text as an ordinary successful response — `TextGenerationLoop`
 * never branches on it (verified against laravel/ai v0.11.2). For a drafting
 * or extraction pipeline that means a half-written answer flows downstream
 * looking exactly like a complete one. Opt in with `throw_on_truncation` in
 * the provider config to convert it into a failure you can catch.
 */
class TruncatedResponseException extends AiException
{
    public static function forStep(?int $completionTokens, ?int $requestedMaxTokens, ?string $model): self
    {
        return new self(sprintf(
            'Workers AI truncated the response at the completion-token budget '
            .'(model: %s, completion_tokens: %s, max_completion_tokens: %s). '
            .'Raise the budget with #[MaxTokens] or the `default_max_tokens` provider '
            .'config, or lower `reasoning_effort` so less of the budget goes to the '
            .'chain of thought. Set `throw_on_truncation => false` to receive the '
            .'partial text instead.',
            $model ?? 'unknown',
            $completionTokens ?? 'unknown',
            $requestedMaxTokens ?? 'unset',
        ));
    }
}

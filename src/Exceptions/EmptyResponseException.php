<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Exceptions;

use Laravel\Ai\Exceptions\AiException;

/**
 * Thrown when Workers AI returned HTTP 200 with neither text nor tool calls.
 *
 * The failure mode this exists for: a reasoning model spends its entire
 * completion budget on the chain of thought and returns `content: null`.
 * Verified live on `@cf/openai/gpt-oss-120b` — a 16-token budget produced
 * `finish_reason: "length"`, `content: null`, and a perfectly ordinary
 * HTTP 200. Nothing in laravel/ai treats that as an error: `$response->text`
 * is an empty string and `toArray()` on a structured response is `[]`.
 *
 * A silent empty answer is the worst outcome for an unattended pipeline, so
 * this is on by default. Set `throw_on_empty_response => false` to restore
 * the old pass-through behaviour.
 */
class EmptyResponseException extends AiException
{
    public static function forStep(?string $model, ?string $finishReason, ?int $reasoningTokens): self
    {
        return new self(sprintf(
            'Workers AI returned an empty response (model: %s, finish_reason: %s, '
            .'reasoning_tokens: %s) — no text and no tool calls. This usually means a '
            .'reasoning model spent its whole completion budget thinking. Raise the '
            .'budget with #[MaxTokens], set #[ReasoningEffort(\'low\')], or set '
            .'`throw_on_empty_response => false` to receive the empty response instead.',
            $model ?? 'unknown',
            $finishReason ?? 'unknown',
            $reasoningTokens ?? 'unknown',
        ));
    }
}

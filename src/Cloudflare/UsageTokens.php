<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Cloudflare;

/**
 * Pulls token counts out of a Workers AI `usage` subtree, tolerating the
 * /compat endpoint's habit of emitting explicit nulls instead of omitting
 * absent keys.
 *
 * Reasoning models on /compat (Kimi K2.5, K2.6, Gemma 4) frequently return
 * `"prompt_tokens": null` rather than dropping the field. `data_get($x, 'k', 0)`
 * only substitutes the default for *missing* keys — it returns explicit null
 * unchanged. Feeding that null into a typed `int` constructor parameter
 * (Prism's `Usage`, laravel/ai's `Usage`) raises TypeError in production.
 *
 * All accessors here coalesce explicit nulls to a sane default (0 for required
 * counts, null for optional ones).
 */
final class UsageTokens
{
    /**
     * Prompt tokens the model actually processed, net of any prefix-cache hit.
     *
     * laravel/ai 0.11.1 redefined `Usage::$promptTokens` on every
     * OpenAI-shaped provider as the *uncached* count, with
     * `cacheReadInputTokens` holding the cached remainder, so the two add up
     * to the wire figure. This follows that split so a consumer sees the same
     * numbers here as on the built-in `openai-compatible` driver. Clamped at
     * zero in case a response reports more cached tokens than prompt tokens.
     *
     * @param  array<string, mixed>|null  $usage  the `usage` subtree, not the full response
     */
    public static function promptTokens(?array $usage): int
    {
        return max(0, self::rawPromptTokens($usage) - (self::cachedTokens($usage) ?? 0));
    }

    /**
     * Prompt tokens as reported on the wire, cached tokens included.
     *
     * @param  array<string, mixed>|null  $usage
     */
    public static function rawPromptTokens(?array $usage): int
    {
        return (int) (data_get($usage, 'prompt_tokens') ?? 0);
    }

    /**
     * @param  array<string, mixed>|null  $usage
     */
    public static function completionTokens(?array $usage): int
    {
        return (int) (data_get($usage, 'completion_tokens') ?? 0);
    }

    /**
     * @param  array<string, mixed>|null  $usage
     */
    public static function totalTokens(?array $usage): int
    {
        $total = data_get($usage, 'total_tokens');

        if ($total !== null) {
            return (int) $total;
        }

        return self::rawPromptTokens($usage) + self::completionTokens($usage);
    }

    /**
     * Cached prompt tokens reported by Workers AI's prefix cache (paired with
     * session affinity). Returns null when the API doesn't include the field
     * — callers should preserve null rather than zero so consumers can
     * distinguish "no cache hit" from "cache hit, zero tokens reused".
     *
     * @param  array<string, mixed>|null  $usage
     */
    public static function cachedTokens(?array $usage): ?int
    {
        $cached = data_get($usage, 'prompt_tokens_details.cached_tokens');

        return $cached === null ? null : (int) $cached;
    }

    /**
     * Reasoning/thinking tokens for models that surface them. Optional; null
     * when absent so non-reasoning models don't get a misleading 0.
     *
     * Read from the top-level `reasoning_tokens` first, then from OpenAI's
     * `completion_tokens_details.reasoning_tokens`, which is where
     * laravel/ai's OpenAI-compatible gateway reads it.
     *
     * @param  array<string, mixed>|null  $usage
     */
    public static function reasoningTokens(?array $usage): ?int
    {
        $reasoning = data_get($usage, 'reasoning_tokens')
            ?? data_get($usage, 'completion_tokens_details.reasoning_tokens');

        return $reasoning === null ? null : (int) $reasoning;
    }

    /**
     * Cloudflare's own billing unit for the call, returned by `/compat` under
     * `usage.neurons`. Fractional — a 16-token llama-3.3-70b completion
     * measured 4.4769 neurons. Reported on all three endpoint shapes
     * (`/compat`, the AI Gateway provider path, and the direct API);
     * null when a response omits it.
     *
     * @param  array<string, mixed>|null  $usage
     */
    public static function neurons(?array $usage): ?float
    {
        $neurons = data_get($usage, 'neurons');

        return is_numeric($neurons) ? (float) $neurons : null;
    }
}

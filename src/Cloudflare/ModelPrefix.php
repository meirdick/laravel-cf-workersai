<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Cloudflare;

use InvalidArgumentException;

/**
 * Validates that a model ID matches the endpoint it's being sent to.
 *
 * Cloudflare exposes Workers AI under three URL shapes (direct API, AI
 * Gateway provider path, AI Gateway `/compat`). `/compat` is a multi-provider
 * routing endpoint that uses a `workers-ai/` prefix to pick the upstream; the
 * other two take bare model IDs (`@cf/meta/llama-3.3-...`).
 *
 * The two directions are NOT symmetric, measured live (2026-09-09):
 *   - bare model on `/compat`        → HTTP 200. It resolves fine. Through
 *     v0.6.1 this package threw on it, rejecting a working configuration.
 *     It is now normalized to the prefixed form instead, so routing stays
 *     explicit and multi-provider gateways behave predictably.
 *   - prefixed model on the direct API → HTTP 400 "No such model
 *     workers-ai/@cf/meta/...". A genuine mismatch, so it still throws with
 *     the fix named rather than surfacing Cloudflare's opaque error.
 */
final class ModelPrefix
{
    public const PREFIX = 'workers-ai/';

    /**
     * Throws if `$model` cannot work against the endpoint at `$url`.
     *
     * Only the prefixed-model-on-a-bare-path direction is fatal; a bare model
     * on `/compat` is resolved by `normalize()` instead.
     *
     * @throws InvalidArgumentException
     */
    public static function validate(string $url, string $model): void
    {
        $isCompat = BaseUrl::isCompatEndpoint($url);
        $hasPrefix = str_starts_with($model, self::PREFIX);

        if (! $isCompat && ! self::isWorkersAiModel($model)) {
            throw new InvalidArgumentException(
                "Model '{$model}' is not a Workers AI model, and the configured endpoint is the direct Workers AI API. "
                .'Third-party models such as `openai/gpt-6-luna` route through AI Gateway. Set `gateway` '
                .'(or a `/compat` `url`) so the request goes to the multi-provider endpoint.'
            );
        }

        if (! $isCompat && $hasPrefix) {
            $bare = substr($model, strlen(self::PREFIX));

            throw new InvalidArgumentException(
                "Workers AI model '{$model}' has the `workers-ai/` prefix, but the configured endpoint expects bare "
                ."model IDs. Drop the prefix (use '{$bare}'), or switch to the `/compat` endpoint by setting `url` "
                .'explicitly. Bare IDs are the right form when using `account_id` (direct API) or `account_id`+`gateway` '
                .'(AI Gateway provider path).'
            );
        }
    }

    /**
     * Add the `workers-ai/` prefix on `/compat` for a Workers AI model that
     * does not already have it.
     *
     * `/compat` is a multi-provider endpoint. It routes `workers-ai/@cf/...`
     * to Workers AI and `openai/gpt-…` to OpenAI. Prefixing every name would
     * send the OpenAI call to Workers AI. A name that is not a Workers AI
     * model ID is returned unchanged, so one provider can send both.
     */
    public static function normalize(string $url, string $model): string
    {
        if (! BaseUrl::isCompatEndpoint($url) || ! self::isWorkersAiModel($model)) {
            return $model;
        }

        return str_starts_with($model, self::PREFIX) ? $model : self::PREFIX.$model;
    }

    /**
     * A Workers AI model ID is `@cf/...`, or that ID already prefixed.
     *
     * Anything else on `/compat` is another provider's model, such as
     * `openai/gpt-6-luna`, and must not gain the prefix.
     */
    public static function isWorkersAiModel(string $model): bool
    {
        $bare = str_starts_with($model, self::PREFIX)
            ? substr($model, strlen(self::PREFIX))
            : $model;

        return str_starts_with($bare, '@cf/');
    }
}

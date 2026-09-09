<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Cloudflare;

use InvalidArgumentException;

/**
 * Resolves the Workers AI base URL from a config array.
 *
 * Single source of truth for the three supported config shapes — both the
 * Prism path and the Laravel AI gateway use this. Validates the `account_id`
 * and `gateway` formats up front so paste-errors (URL pasted into a slug
 * field, etc.) surface as actionable messages instead of mangled URLs that
 * 404 silently against Cloudflare.
 *
 * Supported shapes:
 *   - ['url' => '...']                                    — explicit override
 *   - ['account_id' => '...', 'gateway' => '...']         — Cloudflare AI Gateway
 *   - ['account_id' => '...']                             — direct Workers AI API
 *
 * The AI Gateway shape resolves to the gateway's `/compat` path, NOT the
 * provider-specific `/workers-ai/v1` path, because `/compat` is the shape that
 * worked on every gateway tested. Measured 2026-09-09 across two gateways on
 * one account: on a gateway with Authenticated Gateway enabled
 * (`authentication: true`), `/workers-ai/v1/embeddings` answered HTTP 401
 * "Authentication error" while chat completions on that same gateway and both
 * operations on `/compat` succeeded, with two different tokens. On a gateway
 * with the setting off, the provider path served embeddings fine. The failure
 * therefore tracks gateway configuration, not the token and not the path
 * alone — but `/compat` was uniformly reliable, so it is the default. Set
 * `gateway_path => 'workers-ai/v1'` to opt back in.
 *
 * Specifying both `url` and `account_id` is rejected — silently picking one
 * over the other has caused real production confusion when users override a
 * URL during debugging and forget to remove the account_id.
 */
final class BaseUrl
{
    /**
     * The AI Gateway sub-path used when `gateway_path` is not configured.
     *
     * `/compat` is Cloudflare's multi-provider OpenAI-compatible surface, and
     * the only gateway path observed to serve both chat and embeddings across
     * every gateway configuration tested. That is why it is the default
     * despite requiring `workers-ai/`-prefixed model IDs — ModelPrefix adds
     * the prefix automatically.
     */
    public const DEFAULT_GATEWAY_PATH = 'compat';

    /**
     * @param  array<string, mixed>  $config  the provider's `additionalConfiguration()` (Laravel AI) or the prism.providers.<key> array
     */
    public static function build(array $config): string
    {
        $url = self::nonEmpty($config['url'] ?? null);
        $accountId = self::nonEmpty($config['account_id'] ?? null);
        $gateway = self::nonEmpty($config['gateway'] ?? null);

        if ($url !== null && $accountId !== null) {
            throw new InvalidArgumentException(
                'Workers AI config has both `url` and `account_id` set — pick one. '
                .'Use `url` for full control over the endpoint, or `account_id` '
                .'(+ optional `gateway`) for automatic URL construction. Setting both '
                .'is ambiguous — drop whichever you do not need.'
            );
        }

        if ($url !== null) {
            return rtrim($url, '/');
        }

        if ($accountId === null) {
            throw new InvalidArgumentException(
                "Workers AI requires an `account_id` or explicit `url` in your provider configuration. "
                .'Set CLOUDFLARE_ACCOUNT_ID in your .env file (and optionally CLOUDFLARE_AI_GATEWAY '
                .'to route through the AI Gateway), or set `url` to an explicit endpoint.'
            );
        }

        self::validateAccountId($accountId);

        if ($gateway !== null) {
            self::validateGatewaySlug($gateway);

            $path = self::nonEmpty($config['gateway_path'] ?? null) ?? self::DEFAULT_GATEWAY_PATH;

            return "https://gateway.ai.cloudflare.com/v1/{$accountId}/{$gateway}/".trim($path, '/');
        }

        return "https://api.cloudflare.com/client/v4/accounts/{$accountId}/ai/v1";
    }

    /**
     * Whether the URL targets the `/compat` endpoint. `/compat` is the
     * multi-provider surface, so it routes on a `workers-ai/` model prefix;
     * ModelPrefix uses this to decide whether to add or reject one.
     */
    public static function isCompatEndpoint(string $url): bool
    {
        return str_ends_with(rtrim($url, '/'), '/compat');
    }

    private static function validateAccountId(string $accountId): void
    {
        // Order matters: a pasted URL contains both `://` and `/`, so check the
        // more-specific URL shape first to give the user the right fix.
        if (str_contains($accountId, '://')) {
            throw new InvalidArgumentException(
                "Workers AI `account_id` looks like a URL: '{$accountId}'. "
                .'Use only the account ID itself (a 32-char hex string), e.g. `1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d`. '
                .'Find it in your Cloudflare dashboard URL after `/accounts/`.'
            );
        }

        if (str_contains($accountId, '/') || str_contains($accountId, ' ')) {
            throw new InvalidArgumentException(
                "Workers AI `account_id` looks malformed: '{$accountId}'. "
                .'It must be the bare Cloudflare account ID (a 32-char hex string from '
                .'your dashboard URL), not a path or URL. Strip any `https://`, slashes, '
                .'or trailing segments.'
            );
        }
    }

    private static function validateGatewaySlug(string $gateway): void
    {
        if (str_contains($gateway, '://')) {
            throw new InvalidArgumentException(
                "Workers AI `gateway` looks like a URL: '{$gateway}'. "
                .'Use only the gateway slug (e.g. `production-llm`). If you want to set the full URL, '
                .'use the `url` config key instead.'
            );
        }

        if (str_contains($gateway, '/') || str_contains($gateway, ' ')) {
            throw new InvalidArgumentException(
                "Workers AI `gateway` looks malformed: '{$gateway}'. "
                .'It must be the gateway slug only (e.g. `my-gateway`), not a path or URL. '
                .'If you have a full gateway URL, set `url` instead and drop `account_id`/`gateway`.'
            );
        }
    }

    private static function nonEmpty(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}

<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Cloudflare;

use InvalidArgumentException;

/**
 * Resolves the Workers AI `/ai/run` URL.
 *
 * Chat and embeddings use the OpenAI-compatible `/ai/v1` surface. Clef and
 * Clairic do not. Cloudflare documents both on `POST /ai/run/{model}`, with
 * the model schema as the body. AI Gateway reaches the same path through the
 * `cf-aig-gateway-id` header, not through `/compat`.
 *
 * An explicit `url` is the chat base (`…/ai/v1` or `…/compat`). This class
 * derives the run base from it. `…/ai/v1` becomes `…/ai/run`. A `/compat` URL
 * cannot host a run, so a configured gateway slug is required there.
 */
final class RunEndpoint
{
    /**
     * @param  array<string, mixed>  $config  the provider's additionalConfiguration()
     * @return array{url: string, gateway: ?string}
     */
    public static function resolve(array $config, string $model): array
    {
        // Same rules and messages as chat: `url` with `account_id` throws, and
        // a pasted URL in `account_id` or `gateway` throws.
        BaseUrl::build($config);

        $model = self::bareModel($model);
        $accountId = self::nonEmpty($config['account_id'] ?? null);
        $gateway = self::nonEmpty($config['gateway'] ?? null);
        $url = self::nonEmpty($config['url'] ?? null);

        if ($accountId !== null) {
            return [
                'url' => "https://api.cloudflare.com/client/v4/accounts/{$accountId}/ai/run/{$model}",
                'gateway' => $gateway,
            ];
        }

        $base = rtrim($url, '/');

        if (BaseUrl::isCompatEndpoint($base)) {
            throw new InvalidArgumentException(
                'Clef and Clairic use the Workers AI `/ai/run` API, not `/compat`. '
                .'Set `account_id` (and `gateway` to route through an AI Gateway). '
                .'A `/compat` `url` has no account to build the run URL from.'
            );
        }

        if (str_ends_with($base, '/ai/v1')) {
            return [
                'url' => substr($base, 0, -strlen('/ai/v1'))."/ai/run/{$model}",
                'gateway' => $gateway,
            ];
        }

        return [
            'url' => $base.'/run/'.$model,
            'gateway' => $gateway,
        ];
    }

    /**
     * Drop a `workers-ai/` prefix. `/ai/run` takes the bare `@cf/...` ID in
     * the path; the prefix is a `/compat` routing token and 400s here.
     */
    public static function bareModel(string $model): string
    {
        $model = trim($model);

        if (str_starts_with($model, ModelPrefix::PREFIX)) {
            return substr($model, strlen(ModelPrefix::PREFIX));
        }

        return $model;
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

<?php

namespace Meirdick\WorkersAi\Gateway\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Providers\Provider;
use Meirdick\WorkersAi\Cloudflare\BaseUrl;
use Meirdick\WorkersAi\Cloudflare\ModelPrefix;
use Meirdick\WorkersAi\Cloudflare\RetryPolicy;

trait CreatesWorkersAiClient
{
    /**
     * Get an HTTP client for the Workers AI API.
     *
     * Retries transient gateway failures per Cloudflare\RetryPolicy: connect-
     * phase cURL errors (6/7/28/56), the transient HTTP statuses 502/503/504,
     * Cloudflare's own edge errors 520/522/524, and — since 0.8.0 — HTTP 429
     * with `Retry-After`-aware exponential backoff.
     *
     * Config knobs, all on the provider block:
     *   - `retry => false`            disable retrying entirely
     *   - `retry_attempts => 3`       total attempts, including the first
     *   - `retry_rate_limited => false` let a 429 fail over immediately
     *
     * A note on stacking: Cloudflare AI Gateway has its own retry setting,
     * and it retries *inside* your single HTTP request. If your gateway is
     * configured with `retry_max_attempts`, its retries multiply against
     * these, and against your client timeout. Pick one layer.
     *
     * Headers are layered the way laravel/ai's own `CreatesClient` layers
     * them: the package's own headers first, then the provider config's
     * `headers` array on top, matched case-insensitively so a configured
     * header replaces a package header of the same name. That `headers`
     * key is what laravel/ai 0.10.3 introduced for every provider, and
     * what 1.x's `Provider::withHeaders()` and the `ai_sdk_extra_headers`
     * provider option write into. Through 0.8.2 this client never read it,
     * so both were silently dropped here.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $additionalConfig = $provider->additionalConfiguration();

        $client = Http::baseUrl($this->baseUrl($provider))
            ->withToken($provider->providerCredentials()['key'])
            ->timeout($timeout ?? 60)
            ->throw();

        if (($additionalConfig['retry'] ?? true) !== false) {
            $client = $client->retry(...RetryPolicy::defaults(
                retryRateLimited: ($additionalConfig['retry_rate_limited'] ?? true) !== false,
                attempts: (int) ($additionalConfig['retry_attempts'] ?? RetryPolicy::DEFAULT_ATTEMPTS),
            ));
        }

        $headers = [];

        if (! empty($additionalConfig['session_affinity'])) {
            $headers['x-session-affinity'] = $additionalConfig['session_affinity'];
        }

        // Authenticated Gateway. When an AI Gateway is created with
        // `authentication: true`, Cloudflare requires a gateway-issued token
        // in `cf-aig-authorization` *in addition to* the provider credential
        // in `Authorization`. Without it the gateway rejects the request
        // before it reaches Workers AI, and the error it returns is a bare
        // Cloudflare `{"code":10000,"message":"Authentication error"}` that
        // names neither the gateway nor the missing header.
        if (! empty($additionalConfig['gateway_token'])) {
            $headers['cf-aig-authorization'] = 'Bearer '.$additionalConfig['gateway_token'];
        }

        return $client->withHeaders($this->mergeConfiguredHeaders(
            $headers,
            is_array($additionalConfig['headers'] ?? null) ? $additionalConfig['headers'] : [],
        ));
    }

    /**
     * Merge the provider config's `headers` over the package's own headers.
     *
     * Same rule as laravel/ai's `CreatesClient::createClient()`: names match
     * case-insensitively, the last writer wins, and the first spelling seen
     * is the one sent. That trait only exists from 0.10.3 and the package
     * still supports 0.9, so the rule is applied here rather than imported.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $configuredHeaders
     * @return array<string, string>
     */
    protected function mergeConfiguredHeaders(array $headers, array $configuredHeaders): array
    {
        return collect($headers)
            ->merge($configuredHeaders)
            ->groupBy(fn (mixed $value, string $name): string => strtolower($name), preserveKeys: true)
            ->mapWithKeys(fn (Collection $group): array => [$group->keys()->first() => $group->last()])
            ->all();
    }

    /**
     * Get the base URL for the Workers AI API.
     *
     * Delegates to Meirdick\WorkersAi\Cloudflare\BaseUrl so the Prism path and
     * the Laravel AI path go through the same resolver and surface the same
     * validation errors. Wraps InvalidArgumentException as AiException so
     * laravel/ai's normal error path handles it.
     */
    protected function baseUrl(Provider $provider): string
    {
        try {
            return BaseUrl::build($provider->additionalConfiguration());
        } catch (InvalidArgumentException $e) {
            throw new AiException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate model-name/endpoint pairing in both directions.
     *
     * Delegates to ModelPrefix so both integrations enforce the same rule.
     */
    protected function validateModelName(Provider $provider, string $model): void
    {
        try {
            ModelPrefix::validate($this->baseUrl($provider), $model);
        } catch (InvalidArgumentException $e) {
            throw new AiException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate the model name, then return it in the form the configured
     * endpoint expects.
     *
     * `/compat` routes on a `workers-ai/` prefix. Bare IDs resolve there too
     * (measured), but agents declare bare `@cf/...` IDs and the package's own
     * `defaultTextModel()` / `cheapestTextModel()` / `smartestTextModel()`
     * return bare IDs, so the prefix is added here rather than pushed onto
     * every caller. On the direct API and the gateway provider path the name
     * is returned unchanged.
     */
    protected function resolveModelName(Provider $provider, string $model): string
    {
        $this->validateModelName($provider, $model);

        return ModelPrefix::normalize($this->baseUrl($provider), $model);
    }
}

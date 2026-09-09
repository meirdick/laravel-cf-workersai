<?php

namespace Meirdick\WorkersAi\Gateway\Concerns;

use Illuminate\Http\Client\PendingRequest;
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
     * Retries transient gateway failures (cURL 6/7/28/56, HTTP 502/503/504) per
     * Cloudflare\RetryPolicy — the same policy the Prism path uses, so both
     * integrations share a single retry surface. Set `retry => false` in the
     * provider config to disable.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $additionalConfig = $provider->additionalConfiguration();

        $client = Http::baseUrl($this->baseUrl($provider))
            ->withToken($provider->providerCredentials()['key'])
            ->timeout($timeout ?? 60)
            ->throw();

        if (($additionalConfig['retry'] ?? true) !== false) {
            $client = $client->retry(...RetryPolicy::defaults());
        }

        if (! empty($additionalConfig['session_affinity'])) {
            $client->withHeaders(['x-session-affinity' => $additionalConfig['session_affinity']]);
        }

        // Authenticated Gateway. When an AI Gateway is created with
        // `authentication: true`, Cloudflare requires a gateway-issued token
        // in `cf-aig-authorization` *in addition to* the provider credential
        // in `Authorization`. Without it the gateway rejects the request
        // before it reaches Workers AI, and the error it returns is a bare
        // Cloudflare `{"code":10000,"message":"Authentication error"}` that
        // names neither the gateway nor the missing header.
        if (! empty($additionalConfig['gateway_token'])) {
            $client->withHeaders([
                'cf-aig-authorization' => 'Bearer '.$additionalConfig['gateway_token'],
            ]);
        }

        return $client;
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

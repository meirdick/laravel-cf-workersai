<?php

namespace Meirdick\WorkersAi\Gateway;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Illuminate\Http\Client\RequestException;
use Meirdick\WorkersAi\Cloudflare\ErrorEnvelope;
use Meirdick\WorkersAi\Cloudflare\RetryPolicy;
use Meirdick\WorkersAi\Cloudflare\UsageTokens;
use Meirdick\WorkersAi\Exceptions\GatewayTimeoutException;

/**
 * Single-step Workers AI gateway (laravel/ai ^0.9).
 *
 * Implements StepTextGateway: each call performs exactly one model turn. The
 * multi-step tool loop, tool invocation, message replay, and step/usage
 * accumulation are owned by laravel/ai's TextGenerationLoop, which the
 * provider exposes via HasTextGateway::textGenerationLoop().
 */
class WorkersAiGateway implements EmbeddingGateway, StepTextGateway
{
    use Concerns\BuildsTextRequests;
    use Concerns\CreatesWorkersAiClient;
    use Concerns\HandlesTextStreaming;
    use Concerns\MapsAttachments;
    use Concerns\MapsMessages;
    use Concerns\MapsTools;
    use Concerns\ParsesTextResponses;
    use Concerns\PerformsChatCompletionSteps;
    use HandlesFailoverErrors {
        withErrorHandling as protected handleFailoverErrors;
    }
    use ParsesServerSentEvents;

    public function __construct(protected Dispatcher $events)
    {
        //
    }

    /**
     * {@inheritdoc}
     *
     * We forward any caller-supplied keys into the Workers AI request body so
     * users can pass through Cloudflare-specific or future OpenAI-compatible
     * fields (e.g. `encoding_format`) without a package change. Reserved keys
     * (`model`, `input`) are stripped to keep the gateway in charge of the
     * request shape it built.
     */
    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        int $timeout = 30,
        array $providerOptions = [],
    ): EmbeddingsResponse {
        $model = $this->resolveModelName($provider, $model);

        $body = array_merge(
            $this->sanitizeEmbeddingProviderOptions($providerOptions),
            ['model' => $model, 'input' => $inputs],
        );

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('embeddings', $body),
        );

        $data = $response->json();

        $this->validateEmbeddingsResponse($data);

        return new EmbeddingsResponse(
            collect($data['data'] ?? [])->pluck('embedding')->all(),
            UsageTokens::totalTokens($data['usage'] ?? []),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Intercept HTTP 408 before laravel/ai's generic failover mapping.
     *
     * A gateway 408 is Cloudflare saying the generation outran its patience,
     * not that the network failed — it is neither an overload nor a rate
     * limit, and laravel/ai's mapping would let it through as a raw
     * RequestException naming nothing useful. GatewayTimeoutException says
     * what happened and is failoverable, so a configured fallback provider
     * gets a chance instead of the caller getting an opaque 408.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    protected function withErrorHandling(string $providerName, \Closure $callback): mixed
    {
        try {
            return $this->handleFailoverErrors($providerName, $callback);
        } catch (RequestException $exception) {
            if ($exception->response?->status() === 408) {
                throw GatewayTimeoutException::forProvider(
                    $providerName, $exception->getCode(), $exception,
                );
            }

            throw $exception;
        }
    }

    /**
     * The status codes that indicate the provider is transiently unavailable.
     *
     * Cloudflare fronts Workers AI with its edge network, so transient
     * capacity problems surface as 502/504 from the gateway layer as often as
     * 503 from the model runner — and, uniquely for a Cloudflare-hosted
     * provider, as the edge's own 520 (unknown error), 522 (connection timed
     * out) and 524 (origin timeout). Every request here crosses the edge
     * twice, once to the gateway and once to the model runner, so those three
     * are more likely than for a typical OpenAI-compatible provider, not less.
     * Through 0.7.0 this method returned only [502, 503, 504], which
     * *narrowed* laravel/ai's own default and left the Cloudflare-specific
     * codes unretried and unfailoverable.
     *
     * RetryPolicy retries these; once retries are exhausted, mapping them to
     * ProviderOverloadedException lets laravel/ai's failover move on.
     *
     * @return list<int>
     */
    protected function overloadedStatusCodes(): array
    {
        return RetryPolicy::RETRYABLE_STATUSES;
    }

    /**
     * Strip reserved keys from caller-supplied embedding provider options.
     * `model` and `input` are owned by the gateway — letting callers override
     * them would break the contract the public method built.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function sanitizeEmbeddingProviderOptions(array $options): array
    {
        unset($options['model'], $options['input']);

        return $options;
    }

    /**
     * Surface gateway-level errors on the embeddings path under both the
     * OpenAI and Cloudflare AI Gateway envelope shapes — same rule the text
     * path applies in ParsesTextResponses::validateTextResponse.
     *
     * @throws AiException
     */
    protected function validateEmbeddingsResponse(?array $data): void
    {
        if (ErrorEnvelope::isErrorPayload($data)) {
            throw new AiException(sprintf(
                'Workers AI Error: [%s] %s',
                ErrorEnvelope::extractType($data),
                ErrorEnvelope::extract($data),
            ));
        }
    }
}

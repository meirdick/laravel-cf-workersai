<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Tools\Request;
use Meirdick\WorkersAi\Cloudflare\RunEndpoint;
use Meirdick\WorkersAi\Gateway\Concerns\CreatesWorkersAiClient;
use Meirdick\WorkersAi\Gateway\Concerns\MapsFailoverErrors;
use Meirdick\WorkersAi\Providers\WorkersAiProvider;
use Meirdick\WorkersAi\WorkersAiKey;

/**
 * Web search as a normal tool, with Cloudflare's Web Search API.
 *
 * Cloudflare has no web search that runs inside a chat call, so laravel/ai's
 * `WebSearch` provider tool cannot run on Workers AI. This tool runs in your
 * app, and any model that calls tools can use it. Measured 2026-11-05: the
 * `default` gateway and one named gateway returned results. Two other named
 * gateways returned 400 `web_search_byok_not_configured`. The reason is not
 * known, so `default` is the default here even when the provider sets `gateway`.
 */
class CloudflareWebSearch implements Tool
{
    use CreatesWorkersAiClient;
    use MapsFailoverErrors;

    /**
     * @param  int  $limit  1 to 10 results.
     * @param  string|null  $searchProvider  `ceramic`, `exa` or `linkup`. Cloudflare's default is `ceramic`.
     * @param  string  $gateway  The AI Gateway that runs and bills the search.
     * @param  string|null  $byokAlias  The alias of a provider key stored on the gateway.
     * @param  string|null  $provider  The Laravel AI provider that holds the credentials.
     */
    public function __construct(
        protected int $limit = 5,
        protected ?string $searchProvider = null,
        protected string $gateway = 'default',
        protected ?string $byokAlias = null,
        protected ?string $provider = null,
    ) {}

    public function description(): string
    {
        return 'Search the web. Returns a JSON list of results with a url, title and description.';
    }

    public function handle(Request $request): string
    {
        return (string) json_encode(
            $this->search((string) $request['query']),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Run a search without a model.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $query): array
    {
        $provider = $this->resolveProvider();

        $url = RunEndpoint::accountBase($provider->additionalConfiguration()).'/websearch/';

        try {
            $response = $this->mapFailoverErrors($provider->name(), fn () => $this->client($provider, 30)
                ->baseUrl('')
                ->post($url, array_filter([
                    'query' => $query,
                    'limit' => $this->limit,
                    'provider' => $this->searchProvider,
                    'byokAlias' => $this->byokAlias,
                    'options' => ['gateway' => ['id' => $this->gateway]],
                ], fn (mixed $value): bool => $value !== null)));
        } catch (RequestException $exception) {
            throw new AiException('Workers AI Error: Web search failed. '.$this->errorMessage($exception->response->json()), $exception->getCode(), $exception);
        }

        $data = $response->json();
        $items = is_array($data) ? ($data['items'] ?? $data['result']['items'] ?? null) : null;

        if (! is_array($items)) {
            throw new AiException('Workers AI Error: Web search failed. '.$this->errorMessage($data));
        }

        return $items;
    }

    protected function errorMessage(mixed $data): string
    {
        return data_get($data, 'error.message') ?? data_get($data, 'errors.0.message') ?? 'Cloudflare returned no results.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('What to search for.')->required(),
        ];
    }

    protected function resolveProvider(): Provider
    {
        $name = $this->provider ?? (config('ai.providers.'.WorkersAiKey::PRIMARY) !== null ? WorkersAiKey::PRIMARY : WorkersAiKey::ALIAS);
        $instance = app(AiManager::class)->instance($name);

        if (! $instance instanceof WorkersAiProvider) {
            throw new InvalidArgumentException("Cloudflare web search needs a workers-ai provider. [{$name}] is a ".class_basename($instance).'.');
        }

        return $instance;
    }
}

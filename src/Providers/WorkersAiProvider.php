<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Providers\Provider;
use Meirdick\WorkersAi\Gateway\WorkersAiGateway;

/**
 * Laravel AI provider for Cloudflare Workers AI.
 *
 * Constructor signature mirrors XaiProvider/MistralProvider et al — drops the
 * Gateway parameter that the abstract Provider expected pre-v0.6 since we
 * now own the gateway and lazy-instantiate it. This is the BC break against
 * v0.4.x: anyone subclassing this provider directly will need to drop the
 * gateway argument from their constructor. Ordinary users via `agent()` /
 * config-driven registration are unaffected.
 */
class WorkersAiProvider extends Provider implements EmbeddingProvider, TextProvider
{
    use \Laravel\Ai\Providers\Concerns\GeneratesEmbeddings;
    use \Laravel\Ai\Providers\Concerns\GeneratesText;
    use \Laravel\Ai\Providers\Concerns\HasEmbeddingGateway;
    use \Laravel\Ai\Providers\Concerns\HasTextGateway;
    use \Laravel\Ai\Providers\Concerns\StreamsText;

    protected ?WorkersAiGateway $workersAiGateway = null;

    public function __construct(protected array $config, protected Dispatcher $events)
    {
        //
    }

    /**
     * Get the credentials for the underlying AI provider.
     *
     * Accepts `key` (the laravel/ai convention used by every first-party
     * provider) and falls back to `api_key` (the shape this package's own
     * docs showed through v0.2.0). The base Provider reads `key` unguarded —
     * without this override, an `api_key`-only config crashes with an
     * undefined-array-key error instead of an actionable message.
     */
    public function providerCredentials(): array
    {
        $key = $this->config['key'] ?? $this->config['api_key'] ?? null;

        if (! is_string($key) || trim($key) === '') {
            throw new AiException(
                'Workers AI requires an API token. Set `key` in your workers-ai provider '
                ."config — e.g. `'key' => env('CLOUDFLARE_AI_API_TOKEN')` — using a Cloudflare "
                .'API token with the `Workers AI: Read` permission.'
            );
        }

        return ['key' => $key];
    }

    /**
     * Get the provider connection configuration other than the credentials.
     *
     * Also strips the legacy `api_key` alias so it doesn't leak into
     * URL/option resolution alongside the canonical `key`.
     */
    public function additionalConfiguration(): array
    {
        return array_diff_key(parent::additionalConfiguration(), array_flip(['api_key']));
    }

    /**
     * Shared gateway instance — text and embeddings both route through the
     * same WorkersAiGateway since Workers AI exposes both capabilities under
     * one OpenAI-compat surface.
     */
    protected function workersAiGateway(): WorkersAiGateway
    {
        return $this->workersAiGateway ??= new WorkersAiGateway($this->events);
    }

    public function textGateway(): TextGateway
    {
        return $this->textGateway ??= $this->workersAiGateway();
    }

    public function embeddingGateway(): EmbeddingGateway
    {
        return $this->embeddingGateway ??= $this->workersAiGateway();
    }

    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? '@cf/meta/llama-3.3-70b-instruct-fp8-fast';
    }

    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? '@cf/meta/llama-3.1-8b-instruct';
    }

    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? '@cf/moonshotai/kimi-k2.6';
    }

    public function defaultEmbeddingsModel(): string
    {
        return $this->config['models']['embeddings']['default'] ?? '@cf/baai/bge-large-en-v1.5';
    }

    public function defaultEmbeddingsDimensions(): int
    {
        return (int) ($this->config['models']['embeddings']['dimensions'] ?? 1024);
    }

    /**
     * Default `max_completion_tokens` to send when the agent (or the call's
     * `TextGenerationOptions`) doesn't set one.
     *
     * Cloudflare's `/v1/chat/completions` defaults to **256 tokens** when the
     * field is omitted — far too small for any non-trivial structured output,
     * which truncates mid-JSON and arrives with a misreported
     * `finish_reason: "stop"`. The package ships 4096 as a sane default;
     * users can set `default_max_tokens` in their provider config block
     * (or `null` to fall back to Cloudflare's default).
     */
    public function defaultMaxTokens(): ?int
    {
        if (! array_key_exists('default_max_tokens', $this->config)) {
            return 4096;
        }

        $value = $this->config['default_max_tokens'];

        return is_null($value) ? null : (int) $value;
    }
}

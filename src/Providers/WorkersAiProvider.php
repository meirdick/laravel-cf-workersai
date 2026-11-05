<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Gateway\AudioGateway;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Gateway\RerankingGateway;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Gateway\TranscriptionGateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\RerankingProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Files\File;
use Laravel\Ai\Providers\Provider;
use Meirdick\WorkersAi\Attributes\ReasoningEffort;
use Meirdick\WorkersAi\Cloudflare\ClairicModel;
use Meirdick\WorkersAi\Cloudflare\ClefModel;
use Meirdick\WorkersAi\Events\Extracted;
use Meirdick\WorkersAi\Events\Extracting;
use Meirdick\WorkersAi\Events\ExtractionFailed;
use Meirdick\WorkersAi\Extraction\Field;
use Meirdick\WorkersAi\Gateway\FakeExtractionGateway;
use Meirdick\WorkersAi\Gateway\WorkersAiAudioGateway;
use Meirdick\WorkersAi\Gateway\WorkersAiExtractionGateway;
use Meirdick\WorkersAi\Gateway\WorkersAiGateway;
use Meirdick\WorkersAi\Gateway\WorkersAiImageGateway;
use Meirdick\WorkersAi\Gateway\WorkersAiRerankingGateway;
use Meirdick\WorkersAi\Gateway\WorkersAiTranscriptionGateway;
use Meirdick\WorkersAi\Prompts\ExtractionPrompt;
use Meirdick\WorkersAi\Responses\ExtractionResponse;
use Throwable;

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
class WorkersAiProvider extends Provider implements AudioProvider, EmbeddingProvider, ImageProvider, RerankingProvider, TextProvider, TranscriptionProvider
{
    use \Laravel\Ai\Providers\Concerns\GeneratesAudio;
    use \Laravel\Ai\Providers\Concerns\GeneratesEmbeddings;
    use \Laravel\Ai\Providers\Concerns\GeneratesImages;
    use \Laravel\Ai\Providers\Concerns\GeneratesText;
    use \Laravel\Ai\Providers\Concerns\GeneratesTranscriptions;
    use \Laravel\Ai\Providers\Concerns\HasAudioGateway;
    use \Laravel\Ai\Providers\Concerns\HasEmbeddingGateway;
    use \Laravel\Ai\Providers\Concerns\HasImageGateway;
    use \Laravel\Ai\Providers\Concerns\HasRerankingGateway;
    use \Laravel\Ai\Providers\Concerns\HasTextGateway;
    use \Laravel\Ai\Providers\Concerns\HasTranscriptionGateway;
    use \Laravel\Ai\Providers\Concerns\Reranks;
    use \Laravel\Ai\Providers\Concerns\StreamsText;

    protected ?WorkersAiGateway $workersAiGateway = null;

    protected ?WorkersAiExtractionGateway $extractionGateway = null;

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
                .'API token with the Account > Workers AI > Read permission.'
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

    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ??= $this->workersAiGateway();
    }

    public function embeddingGateway(): EmbeddingGateway
    {
        return $this->embeddingGateway ??= $this->workersAiGateway();
    }

    public function rerankingGateway(): RerankingGateway
    {
        return $this->rerankingGateway ??= new WorkersAiRerankingGateway($this->events);
    }

    public function imageGateway(): ImageGateway
    {
        return $this->imageGateway ??= new WorkersAiImageGateway($this->events);
    }

    public function audioGateway(): AudioGateway
    {
        return $this->audioGateway ??= new WorkersAiAudioGateway($this->events);
    }

    public function transcriptionGateway(): TranscriptionGateway
    {
        return $this->transcriptionGateway ??= new WorkersAiTranscriptionGateway($this->events);
    }

    public function extractionGateway(): WorkersAiExtractionGateway
    {
        if (app()->bound(FakeExtractionGateway::class)) {
            return app(FakeExtractionGateway::class);
        }

        return $this->extractionGateway ??= new WorkersAiExtractionGateway($this->events);
    }

    /**
     * Extract typed values from `$state` with Clairic.
     *
     * Clairic is not a classification model. It returns a value per field,
     * or null when the input does not determine it. Prefer
     * `Extraction::of($state)->fields(...)->extract()`; this is the method
     * that call reaches.
     *
     * @param  array<string, Field>  $fields
     * @param  array<string, mixed>  $providerOptions
     * @param  array<int|string, File|UploadedFile>  $attachments
     */
    public function extract(
        string $state,
        array $fields,
        ?string $model = null,
        int $timeout = 30,
        array $providerOptions = [],
        array $attachments = [],
        ?string $referenceDate = null,
    ): ExtractionResponse {
        $model ??= $this->defaultExtractionModel();

        $invocationId = (string) Str::uuid7();

        $prompt = new ExtractionPrompt($state, $fields, $this, $model, $timeout, $providerOptions, $attachments, $referenceDate);

        $this->events->dispatch(new Extracting($invocationId, $this, $model, $prompt));

        try {
            $response = $this->extractionGateway()->extract(
                $this,
                $model,
                $state,
                $fields,
                $timeout,
                $providerOptions,
                $attachments,
                $referenceDate,
            );
        } catch (Throwable $exception) {
            $this->events->dispatch(new ExtractionFailed($invocationId, $this, $model, $prompt, $exception));

            throw $exception;
        }

        $this->events->dispatch(new Extracted($invocationId, $this, $model, $prompt, $response));

        return $response;
    }

    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? '@cf/meta/llama-3.3-70b-instruct-fp8-fast';
    }

    /**
     * The model `#[UseCheapestModel]` resolves to.
     *
     * `@cf/meta/llama-3.1-8b-instruct` was the default through 0.6.1 and is
     * now deprecated — Cloudflare answers `410 Model has been deprecated`
     * (verified 2026-09-09), so every `#[UseCheapestModel]` call failed.
     * Override with `models.text.cheapest`.
     */
    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? '@cf/meta/llama-3.2-3b-instruct';
    }

    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? '@cf/moonshotai/kimi-k2.6';
    }

    public function defaultEmbeddingsModel(): string
    {
        return $this->config['models']['embeddings']['default'] ?? '@cf/baai/bge-large-en-v1.5';
    }

    /**
     * The Clef model `Classification::of()->classify()` uses when the call
     * names no model. Override with `models.classification.default`.
     * `models.classification.cheapest` is `@cf/cloudflare/clef-flash`.
     */
    public function defaultClassificationModel(): string
    {
        return $this->config['models']['classification']['default'] ?? ClefModel::DEFAULT;
    }

    public function cheapestClassificationModel(): string
    {
        return $this->config['models']['classification']['cheapest'] ?? ClefModel::CHEAPEST;
    }

    /**
     * The Clairic model `Extraction::of()->extract()` uses when the call
     * names no model. Override with `models.extraction.default`.
     */
    public function defaultExtractionModel(): string
    {
        return $this->config['models']['extraction']['default'] ?? ClairicModel::ID;
    }

    public function defaultRerankingModel(): string
    {
        return $this->config['models']['reranking']['default'] ?? '@cf/baai/bge-reranker-base';
    }

    public function defaultImageModel(): string
    {
        return $this->config['models']['image']['default'] ?? '@cf/black-forest-labs/flux-2-klein-4b';
    }

    public function defaultAudioModel(): string
    {
        return $this->config['models']['audio']['default'] ?? '@cf/deepgram/aura-2-en';
    }

    public function defaultTranscriptionModel(): string
    {
        return $this->config['models']['transcription']['default'] ?? '@cf/openai/whisper-large-v3-turbo';
    }

    /**
     * Normalize laravel/ai's size and quality for the image gateway.
     *
     * `square()`, `portrait()` and `landscape()` send `1:1`, `2:3` and `3:2`. A
     * `WIDTHxHEIGHT` string also works. Quality maps to inference steps, which
     * the gateway caps and names for each model.
     *
     * @return array{width?: int, height?: int, steps?: int}
     */
    public function defaultImageOptions(?string $size = null, ?string $quality = null): array
    {
        $dimensions = match (true) {
            $size === null => [],
            $size === '1:1' => ['width' => 1024, 'height' => 1024],
            $size === '2:3' => ['width' => 832, 'height' => 1248],
            $size === '3:2' => ['width' => 1248, 'height' => 832],
            (bool) preg_match('/^(\d+)x(\d+)$/', $size, $match) => ['width' => (int) $match[1], 'height' => (int) $match[2]],
            default => throw new \InvalidArgumentException("Workers AI image size must be 1:1, 2:3, 3:2 or WIDTHxHEIGHT; [{$size}] given."),
        };

        $steps = match ($quality) {
            null => [],
            'low' => ['steps' => 4],
            'medium' => ['steps' => 8],
            'high' => ['steps' => 20],
            default => throw new \InvalidArgumentException("Workers AI image quality must be low, medium or high; [{$quality}] given."),
        };

        return [...$dimensions, ...$steps];
    }

    /**
     * Whether a document attachment on a chat call becomes Markdown text.
     *
     * Off by default, because it adds a request to `/ai/tomarkdown` that the
     * caller did not ask for. With it off, a document attachment throws.
     */
    public function convertsDocuments(): bool
    {
        return (bool) ($this->config['convert_documents'] ?? false);
    }

    public function defaultEmbeddingsDimensions(): int
    {
        return (int) ($this->config['models']['embeddings']['dimensions'] ?? 1024);
    }

    /**
     * Default `max_completion_tokens` to send when the agent (or the call's
     * `TextGenerationOptions`) doesn't set one.
     *
     * Cloudflare defaults to **256 completion tokens** when the field is
     * omitted — far too small for any non-trivial structured output, which
     * truncates mid-JSON. Verified live 2026-09-09: with no cap,
     * `@cf/meta/llama-3.3-70b-instruct-fp8-fast` and `@cf/openai/gpt-oss-120b`
     * both stopped at exactly 256 completion tokens with
     * `finish_reason: "length"`. (The cap is not universal —
     * `@cf/zai-org/glm-5.3-flash` ran to 8,190 tokens over 369 seconds — which
     * is a second reason to always send a budget.) The package ships 4096;
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

    /**
     * How many times the gateway may re-ask the model for schema-valid
     * structured output before giving up and returning what it has.
     *
     * Workers AI's JSON mode is best-effort — it does not guarantee the
     * response satisfies the requested schema (a model can omit a required
     * field or emit an invalid enum). The gateway validates each structured
     * response and, on failure, feeds the error back and re-asks up to this
     * many times. Defaults to 2; set `structured_output_retries => 0` in the
     * provider config to disable.
     */
    public function structuredOutputRetries(): int
    {
        return max(0, (int) ($this->config['structured_output_retries'] ?? 2));
    }

    /**
     * The `reasoning_effort` to send when an agent does not declare one with
     * `#[ReasoningEffort]` and does not set it through `providerOptions()`.
     *
     * Null (the default) sends nothing and lets the model decide. Set
     * `reasoning_effort => 'low'` in the provider config to cap the chain of
     * thought across every agent on this provider — usually the right default
     * for latency-sensitive work. See the ReasoningEffort attribute for the
     * measured effect and the list of models that honour it.
     */
    public function defaultReasoningEffort(): ?string
    {
        $value = $this->config['reasoning_effort'] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        ReasoningEffort::validate($value);

        return $value;
    }

    /**
     * Whether a step that returned neither text nor tool calls should throw.
     *
     * Defaults to **true**. A Workers AI reasoning model that spends its whole
     * completion budget thinking returns HTTP 200 with `content: null`, which
     * laravel/ai surfaces as an empty string and an empty `toArray()`. Nothing
     * downstream can tell that apart from a real answer, so the package treats
     * it as a failure. Set `throw_on_empty_response => false` to receive the
     * empty response instead.
     */
    public function throwOnEmptyResponse(): bool
    {
        return (bool) ($this->config['throw_on_empty_response'] ?? true);
    }

    /**
     * Whether a step that stopped at the completion-token budget should throw.
     *
     * Defaults to **false**, because a truncated answer is still partial data
     * a caller may legitimately want, and `FinishReason::Length` is reported
     * accurately enough to branch on yourself. Set
     * `throw_on_truncation => true` when a partial answer is worse than no
     * answer — extraction and drafting pipelines usually want this on.
     */
    public function throwOnTruncation(): bool
    {
        return (bool) ($this->config['throw_on_truncation'] ?? false);
    }
}

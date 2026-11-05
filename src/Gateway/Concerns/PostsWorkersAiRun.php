<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Gateway\Concerns;

use finfo;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Providers\Provider;
use Meirdick\WorkersAi\Cloudflare\ErrorEnvelope;
use Meirdick\WorkersAi\Cloudflare\RunEndpoint;
use Meirdick\WorkersAi\Events\WorkersAiUsageReported;
use Meirdick\WorkersAi\Exceptions\GatewayTimeoutException;

/**
 * Sends a request to Workers AI `/ai/run/{model}`.
 *
 * Clef, Clairic, images, audio, transcription and reranking all use this path.
 *
 * Shares the chat client's auth, retry and header rules. The URL is not the
 * chat base: `/compat` and `/ai/v1` do not serve these models.
 */
trait PostsWorkersAiRun
{
    use CreatesWorkersAiClient;

    /** @var array<string, string> */
    private const RUN_IMAGE_FORMATS = [
        'image/jpeg' => 'JPEG',
        'image/png' => 'PNG',
        'image/webp' => 'WebP',
    ];

    /**
     * Post JSON to the run endpoint and return the model's own reply.
     *
     * @param  array<string, mixed>  $body
     * @param  string|null  $required  a key the reply must hold
     * @return array{0: array<string, mixed>, 1: Response}
     */
    protected function postRun(Provider $provider, string $model, int $timeout, array $body, ?string $required = 'answers'): array
    {
        $response = $this->sendRun($provider, $model, $timeout, fn (PendingRequest $client, string $url) => $client->post($url, $body));

        return [$this->runPayload($response, $required), $response];
    }

    /**
     * Send a run request, with the shared error mapping and neuron report.
     *
     * @param  \Closure(PendingRequest, string): Response  $send
     */
    protected function sendRun(Provider $provider, string $model, int $timeout, \Closure $send): Response
    {
        [$client, $url] = $this->runRequest($provider, $model, $timeout);

        try {
            $response = $this->mapFailoverErrors($provider->name(), fn () => $send($client, $url));
        } catch (RequestException $exception) {
            if ($exception->response?->status() === 408) {
                throw GatewayTimeoutException::forProvider($provider->name(), $exception->getCode(), $exception);
            }

            throw $exception;
        }

        $this->reportRunNeurons($provider, $model, $response);

        return $response;
    }

    /**
     * Report the neurons of a run call.
     *
     * JSON replies can hold `usage.neurons`. Every reply measured on 2026-11-05
     * had a `cf-ai-neurons` header, including binary audio and Clef.
     */
    protected function reportRunNeurons(Provider $provider, string $model, Response $response): void
    {
        $usage = $this->isBinaryResponse($response)
            ? null
            : ($response->json('result.usage') ?? $response->json('usage'));

        $usage = is_array($usage) ? $usage : [];
        $neurons = $usage['neurons'] ?? $response->header('cf-ai-neurons');

        if (! is_numeric($neurons) || (float) $neurons <= 0) {
            return;
        }

        $this->events->dispatch(new WorkersAiUsageReported(
            provider: $provider->name(),
            model: $model,
            neurons: (float) $neurons,
            promptTokens: (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0),
            completionTokens: (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0),
        ));
    }

    /**
     * Whether the body is the output itself, not a JSON reply.
     */
    protected function isBinaryResponse(Response $response): bool
    {
        $type = strtolower((string) $response->header('Content-Type'));

        return str_starts_with($type, 'image/') || str_starts_with($type, 'audio/') || str_starts_with($type, 'application/octet-stream');
    }

    /**
     * Read the model's reply from a run response.
     *
     * The REST API wraps a reply in `{result, success, errors: []}`, measured
     * 2026-11-05 on Clef and Clairic, direct and with a gateway header.
     * Cloudflare's model pages show it without the wrapper, which is what a
     * Workers binding returns, so both are read. A reply without the required
     * key throws. An empty response would look like a model with nothing to say.
     *
     * @return array<string, mixed>
     */
    protected function runPayload(Response $response, ?string $required = 'answers'): array
    {
        $data = $response->json();

        if (! is_array($data) || ($data['success'] ?? null) === false || ErrorEnvelope::isErrorPayload($data)) {
            throw new AiException(sprintf(
                'Workers AI Error: [%s] %s',
                ErrorEnvelope::extractType(is_array($data) ? $data : null),
                ErrorEnvelope::extract(is_array($data) ? $data : null),
            ));
        }

        $payload = is_array($data['result'] ?? null) ? $data['result'] : $data;

        if ($required !== null && ! isset($payload[$required])) {
            $name = ['answers' => 'answers', 'response' => 'rankings'][$required] ?? $required;

            throw new AiException("Workers AI Error: Response contained no {$name}.");
        }

        return $payload;
    }

    /**
     * The resolved run URL for the model, with gateway routing as a header.
     *
     * @return array{0: PendingRequest, 1: string}
     */
    protected function runRequest(Provider $provider, string $model, int $timeout): array
    {
        $resolved = RunEndpoint::resolve($provider->additionalConfiguration(), $model);

        $client = $this->client($provider, $timeout)->baseUrl('');

        if ($resolved['gateway'] !== null) {
            $client = $client->withHeaders(['cf-aig-gateway-id' => $resolved['gateway']]);
        }

        return [$client, $resolved['url']];
    }

    /**
     * Map inline images to Clef and Clairic's `images` array.
     *
     * Both accept a data URL or `{content_type, base64}`. The object form
     * keeps the MIME type explicit. Remote URLs are rejected by Cloudflare,
     * and by this method before a request is sent.
     *
     * @param  array<int|string, File|UploadedFile>  $attachments
     * @return list<array{content_type: string, base64: string}>
     */
    protected function runImages(array $attachments, string $model): array
    {
        if (count($attachments) > 4) {
            throw new InvalidArgumentException(
                "{$model} accepts at most 4 images; ".count($attachments).' given.'
            );
        }

        $images = [];

        foreach ($attachments as $attachment) {
            $images[] = $this->runImage($attachment, $model);
        }

        return $images;
    }

    /**
     * @return array{content_type: string, base64: string}
     */
    protected function runImage(File|UploadedFile $attachment, string $model): array
    {
        if (trait_exists(\Laravel\Ai\Gateway\Concerns\ResolvesInlineImages::class)) {
            [$image, $content, $mime] = $this->resolveRunImage($attachment, $model);

            return [
                'content_type' => $mime,
                'base64' => base64_encode($content),
            ];
        }

        return $this->runImageWithoutResolver($attachment, $model);
    }

    /**
     * @return array{Image&StorableFile, string, string}
     */
    protected function resolveRunImage(File|UploadedFile $attachment, string $model): array
    {
        $resolver = new class
        {
            use \Laravel\Ai\Gateway\Concerns\ResolvesInlineImages {
                resolveInlineImage as public image;
            }
        };

        return $resolver->image($attachment, $model, self::RUN_IMAGE_FORMATS);
    }

    /**
     * laravel/ai before 1.2.3 has no ResolvesInlineImages. The check is the
     * same: an image with inline bytes in a format Clef accepts.
     *
     * @return array{content_type: string, base64: string}
     */
    protected function runImageWithoutResolver(File|UploadedFile $attachment, string $model): array
    {
        $image = $attachment instanceof UploadedFile ? Image::fromUpload($attachment) : $attachment;

        if (! $image instanceof Image || ! $image instanceof StorableFile) {
            throw new InvalidArgumentException(
                "{$model} only accepts images with inline content; [".get_debug_type($attachment).'] given.'
            );
        }

        $content = $image->content();
        $mime = $image->mimeType() ?? (new finfo(FILEINFO_MIME_TYPE))->buffer($content);

        if (! array_key_exists($mime, self::RUN_IMAGE_FORMATS)) {
            throw new InvalidArgumentException(
                "{$model} only accepts ".Arr::join(self::RUN_IMAGE_FORMATS, ', ', ', or ')." images; [{$mime}] given."
            );
        }

        return [
            'content_type' => $mime,
            'base64' => base64_encode($content),
        ];
    }

}

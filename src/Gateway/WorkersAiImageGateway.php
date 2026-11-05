<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Gateway;

use finfo;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\PendingRequest;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\ImageResponse;
use Meirdick\WorkersAi\Cloudflare\RunEndpoint;
use Meirdick\WorkersAi\Cloudflare\SdkCompat;
use Meirdick\WorkersAi\Gateway\Concerns\MapsFailoverErrors;
use Meirdick\WorkersAi\Gateway\Concerns\PostsWorkersAiRun;

/**
 * Image generation on `/ai/run/{model}`.
 *
 * The catalog has three request shapes, read from each model's schema:
 *   - `flux-2-*` take multipart form data, even for a prompt alone, and accept
 *     up to 4 reference images. They reply with JSON `{image}`. With a gateway,
 *     they use the gateway's own URL. See `RunEndpoint::multipartUrl()`.
 *   - `flux-1-schnell` takes JSON `{prompt, steps}` and rejects any other key,
 *     including `width`. It replies with JSON `{image}`.
 *   - The others take JSON with `width`, `height` and `num_steps`. Some reply
 *     with binary image bytes, and some with JSON `{image}`.
 */
class WorkersAiImageGateway implements ImageGateway
{
    use MapsFailoverErrors;
    use PostsWorkersAiRun;

    private const MAX_REFERENCE_IMAGES = 4;

    public function __construct(protected Dispatcher $events)
    {
        //
    }

    /**
     * @param  array<int|string, mixed>  $attachments
     * @param  array<string, mixed>  $providerOptions
     */
    public function generateImage(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?int $timeout = null,
        array $providerOptions = [],
    ): ImageResponse {
        $model = RunEndpoint::bareModel($model);
        $options = $provider->defaultImageOptions($size, $quality);
        $multipart = $this->takesMultipart($model);

        if ($attachments !== [] && ! $multipart) {
            throw new InvalidArgumentException(
                "Workers AI model '{$model}' does not take reference images through attachments. Use a flux-2 model, "
                .'such as @cf/black-forest-labs/flux-2-klein-4b, or pass the model\'s own image fields in provider options.'
            );
        }

        $fields = array_merge($providerOptions, $this->fields($model, $prompt, $options));

        $response = $this->sendRun($provider, $model, $timeout ?? 120, function (PendingRequest $client, string $url) use ($provider, $model, $multipart, $fields, $attachments) {
            if (! $multipart) {
                return $client->post($url, $fields);
            }

            $url = RunEndpoint::multipartUrl($provider->additionalConfiguration(), $model);

            foreach ($this->referenceImages($attachments) as $name => $image) {
                $client = $client->attach($name, $image['content'], $image['filename'], ['Content-Type' => $image['mime']]);
            }

            return $client->asMultipart()->post($url, array_map(fn (mixed $value): string => (string) $value, $fields));
        });

        if ($this->isBinaryResponse($response)) {
            $body = $response->body();
            $image = base64_encode($body);
            $mime = strtok((string) $response->header('Content-Type'), ';') ?: 'image/png';
            $usage = null;
        } else {
            $data = $this->runPayload($response, 'image');
            $image = (string) $data['image'];
            $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer((string) base64_decode($image, true)) ?: 'image/jpeg';
            $usage = $data['usage'] ?? null;
        }

        if ($image === '') {
            throw new AiException('Workers AI Error: Response contained no image.');
        }

        return SdkCompat::imageResponse(collect([new GeneratedImage($image, $mime)]), $usage, $provider->name(), $model);
    }

    /**
     * @param  array{width?: int, height?: int, steps?: int}  $options
     * @return array<string, mixed>
     */
    protected function fields(string $model, string $prompt, array $options): array
    {
        $size = array_intersect_key($options, ['width' => true, 'height' => true]);
        $steps = $options['steps'] ?? null;

        if ($this->isFluxOne($model)) {
            if ($size !== []) {
                throw new InvalidArgumentException(
                    "Workers AI model '{$model}' has a fixed size. Use a model that takes a size, "
                    .'such as @cf/black-forest-labs/flux-2-klein-4b.'
                );
            }

            return array_filter(['prompt' => $prompt, 'steps' => $steps === null ? null : min($steps, 8)], fn (mixed $value): bool => $value !== null);
        }

        $key = $this->takesMultipart($model) ? 'steps' : 'num_steps';

        return array_filter(['prompt' => $prompt, ...$size, $key => $steps], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<int|string, mixed>  $attachments
     * @return array<string, array{content: string, filename: string, mime: string}>
     */
    protected function referenceImages(array $attachments): array
    {
        if (count($attachments) > self::MAX_REFERENCE_IMAGES) {
            throw new InvalidArgumentException(
                'Workers AI flux-2 models accept at most '.self::MAX_REFERENCE_IMAGES.' reference images; '.count($attachments).' given.'
            );
        }

        $images = [];

        foreach (array_values($attachments) as $index => $attachment) {
            $image = $this->runImage($attachment, 'flux-2');

            $images["input_image_{$index}"] = [
                'content' => (string) base64_decode($image['base64'], true),
                'filename' => "input_image_{$index}",
                'mime' => $image['content_type'],
            ];
        }

        return $images;
    }

    protected function takesMultipart(string $model): bool
    {
        return str_contains($model, '/flux-2-');
    }

    protected function isFluxOne(string $model): bool
    {
        return str_contains($model, '/flux-1-');
    }
}

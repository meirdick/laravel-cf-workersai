<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Gateway\Concerns;

use finfo;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Providers\Provider;
use Meirdick\WorkersAi\Cloudflare\RunEndpoint;

/**
 * Posts a Clef or Clairic request to Workers AI `/ai/run/{model}`.
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

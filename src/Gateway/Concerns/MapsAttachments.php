<?php

namespace Meirdick\WorkersAi\Gateway\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Files\Video;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Providers\Provider;
use Meirdick\WorkersAi\Gateway\WorkersAiMarkdownGateway;
use Meirdick\WorkersAi\Providers\WorkersAiProvider;

trait MapsAttachments
{
    /** @var \WeakMap<UserMessage, string>|null Converted text, by message. Freed with the message. */
    protected ?\WeakMap $convertedDocuments = null;

    /**
     * Replace document attachments with Markdown text, when the provider opts in.
     *
     * The text goes in the message content, so every chat model can read it.
     * Converted text replaces the attachment on a copy of the message. A tool
     * loop re-sends its messages, so the text is kept for each message, and the
     * same document is not converted again on the next step.
     *
     * @param  array<int, mixed>  $messages
     * @return array<int, mixed>
     */
    protected function withConvertedDocuments(Provider $provider, array $messages): array
    {
        if (! $provider instanceof WorkersAiProvider || ! $provider->convertsDocuments()) {
            return $messages;
        }

        foreach ($messages as $key => $message) {
            if (! $message instanceof UserMessage || $message->attachments->isEmpty()) {
                continue;
            }

            $documents = $message->attachments->filter(fn ($file): bool => $this->isDocument($file));

            if ($documents->isEmpty()) {
                continue;
            }

            $this->convertedDocuments ??= new \WeakMap;

            $text = $this->convertedDocuments[$message] ??= $documents
                ->map(function ($file) use ($provider): string {
                    $name = $file instanceof UploadedFile ? $file->getClientOriginalName() : (method_exists($file, 'name') ? $file->name() : null);

                    return 'Attached document'.($name ? " ({$name})" : '').":\n\n".(new WorkersAiMarkdownGateway)->convert($provider, $file);
                })
                ->implode("\n\n");

            $copy = clone $message;
            $copy->content = trim($message->content."\n\n".$text);
            $copy->attachments = $message->attachments->reject(fn ($file): bool => $this->isDocument($file))->values();

            $messages[$key] = $copy;
        }

        return $messages;
    }

    protected function isDocument(mixed $file): bool
    {
        return $file instanceof Document
            || ($file instanceof UploadedFile && ! $this->isImage($file) && ! $this->isAudioOrVideo($file));
    }

    protected function isAudioOrVideo(mixed $file): bool
    {
        return $file instanceof Audio
            || $file instanceof Video
            || ($file instanceof UploadedFile && preg_match('#^(audio|video)/#', (string) $file->getClientMimeType()) === 1);
    }

    /**
     * Map the given Laravel attachments to Chat Completions content parts.
     */
    protected function mapAttachments(Collection $attachments): array
    {
        return $attachments->map(function ($attachment) {
            if (! $attachment instanceof File && ! $attachment instanceof UploadedFile) {
                throw new InvalidArgumentException(
                    'Unsupported attachment type ['.get_class($attachment).']'
                );
            }

            return match (true) {
                $attachment instanceof Base64Image => [
                    'type' => 'image_url',
                    'image_url' => ['url' => 'data:'.$attachment->mime.';base64,'.$attachment->base64],
                ],
                $attachment instanceof RemoteImage => [
                    'type' => 'image_url',
                    'image_url' => ['url' => $attachment->url],
                ],
                $attachment instanceof LocalImage => [
                    'type' => 'image_url',
                    'image_url' => ['url' => 'data:'.($attachment->mimeType() ?? 'image/png').';base64,'.base64_encode(file_get_contents($attachment->path))],
                ],
                $attachment instanceof StoredImage => [
                    'type' => 'image_url',
                    'image_url' => ['url' => 'data:'.($attachment->mimeType() ?? 'image/png').';base64,'.base64_encode(
                        Storage::disk($attachment->disk)->get($attachment->path)
                    )],
                ],
                $attachment instanceof UploadedFile && $this->isImage($attachment) => [
                    'type' => 'image_url',
                    'image_url' => ['url' => 'data:'.$attachment->getClientMimeType().';base64,'.base64_encode($attachment->get())],
                ],
                $this->isAudioOrVideo($attachment) => throw new InvalidArgumentException(
                    'Workers AI chat models do not accept audio or video attachments. Use Transcription::of() for speech, or @cf/cloudflare/clef-omni for decisions.'
                ),
                default => throw new InvalidArgumentException(
                    'Workers AI chat models do not read document attachments. Set `convert_documents` to true on the provider to send them as Markdown, or call Markdown::of().'
                ),
            };
        })->all();
    }

    /**
     * Determine if the given uploaded file is an image.
     *
     * Allowlist mirrors the MIME types Workers AI's vision models accept on
     * `/compat`. Other image MIMEs (svg+xml, avif, heic, heif, ...) and the
     * raster variants Cloudflare doesn't decode yet are intentionally
     * rejected — silently passing them through would surface as an opaque
     * 400 from the upstream. Expand this list when Cloudflare documents
     * support for new MIMEs.
     */
    protected function isImage(UploadedFile $attachment): bool
    {
        return in_array($attachment->getClientMimeType(), [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
        ]);
    }
}

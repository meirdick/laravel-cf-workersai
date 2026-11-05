<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Gateway;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Files\File;
use Laravel\Ai\Providers\Provider;
use Meirdick\WorkersAi\Cloudflare\RunEndpoint;
use Meirdick\WorkersAi\Gateway\Concerns\CreatesWorkersAiClient;
use Meirdick\WorkersAi\Gateway\Concerns\MapsFailoverErrors;
use Symfony\Component\Mime\MimeTypes;

/**
 * Converts a file to Markdown with Cloudflare's `/ai/tomarkdown`.
 *
 * The API reads the file type from the file name, so a file with no name gets
 * one from its MIME type. A conversion that fails comes back as HTTP 200 with
 * `format: error` on that file.
 */
class WorkersAiMarkdownGateway
{
    use CreatesWorkersAiClient;
    use MapsFailoverErrors;

    public function convert(Provider $provider, File|UploadedFile $file, int $timeout = 60): string
    {
        [$content, $filename, $mime] = $this->fileParts($file);

        $url = RunEndpoint::accountBase($provider->additionalConfiguration()).'/tomarkdown';

        $response = $this->mapFailoverErrors($provider->name(), fn () => $this->client($provider, $timeout)
            ->baseUrl('')
            ->attach('files', $content, $filename, array_filter(['Content-Type' => $mime]))
            ->post($url));

        $item = $response->json('result.0');

        if (! is_array($item)) {
            throw new AiException('Workers AI Error: Markdown conversion returned no result.');
        }

        if (($item['format'] ?? null) === 'error' || ! is_string($item['data'] ?? null)) {
            throw new AiException('Workers AI Error: Could not convert ['.$filename.'] to Markdown. '.($item['error'] ?? 'Unknown error'));
        }

        return $item['data'];
    }

    /**
     * @return array{0: string, 1: string, 2: ?string}
     */
    protected function fileParts(File|UploadedFile $file): array
    {
        if ($file instanceof UploadedFile) {
            return [$file->getContent(), $file->getClientOriginalName(), $file->getClientMimeType()];
        }

        if (! method_exists($file, 'content')) {
            throw new InvalidArgumentException('Cannot convert ['.get_debug_type($file).'] to Markdown, because it has no content to read.');
        }

        $mime = method_exists($file, 'mimeType') ? $file->mimeType() : null;

        return [$file->content(), $this->filename($file, $mime), $mime];
    }

    protected function filename(File $file, ?string $mime): string
    {
        $name = method_exists($file, 'name') ? $file->name() : null;

        if (is_string($name) && pathinfo($name, PATHINFO_EXTENSION) !== '') {
            return $name;
        }

        $extension = $mime === null ? null : (MimeTypes::getDefault()->getExtensions($mime)[0] ?? null);

        if ($extension === null) {
            throw new InvalidArgumentException('Cannot convert the document to Markdown, because its file type is unknown. Give it a MIME type or a file name with an extension.');
        }

        return ($name ?: 'document').'.'.$extension;
    }
}

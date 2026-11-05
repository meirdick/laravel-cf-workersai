<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Laravel\Ai\AiManager;
use Laravel\Ai\Files\File;
use Meirdick\WorkersAi\Gateway\WorkersAiMarkdownGateway;
use Meirdick\WorkersAi\Providers\WorkersAiProvider;

/**
 * Convert a document to Markdown with Cloudflare's `/ai/tomarkdown`.
 *
 * Use the text as a prompt, a Clef state or a Clairic state:
 *
 * ```php
 * $text = Markdown::of(Document::fromPath('invoice.pdf'))->convert();
 *
 * Extraction::of($text)->field('total', new Number)->extract();
 * ```
 */
final class Markdown
{
    public function __construct(private File|UploadedFile $file) {}

    public static function of(File|UploadedFile $file): self
    {
        return new self($file);
    }

    public function convert(?string $provider = null, int $timeout = 60): string
    {
        $name = $provider ?? (config('ai.providers.'.WorkersAiKey::PRIMARY) !== null ? WorkersAiKey::PRIMARY : WorkersAiKey::ALIAS);
        $instance = app(AiManager::class)->instance($name);

        if (! $instance instanceof WorkersAiProvider) {
            throw new InvalidArgumentException("Markdown conversion needs a workers-ai provider. [{$name}] is a ".class_basename($instance).'.');
        }

        return (new WorkersAiMarkdownGateway)->convert($instance, $this->file, $timeout);
    }
}

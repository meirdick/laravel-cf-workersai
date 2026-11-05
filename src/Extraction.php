<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi;

use Closure;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Laravel\Ai\Files\File;
use Meirdick\WorkersAi\Gateway\FakeExtractionGateway;
use Meirdick\WorkersAi\Prompts\ExtractionPrompt;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Extract typed values from text with Clairic.
 *
 * Clairic is a System One extraction model. It does not generate tokens.
 * It returns one typed value per field, or null when the input does not
 * determine it. laravel/ai has no extraction API. Its classification API
 * covers Jev and Clef, which choose among options you list. Clairic's fields
 * are a different request, so this class is the entry point. It mirrors
 * `Laravel\Ai\Classification`: `of()`, `fake()` and the same assertions.
 *
 * ```php
 * use Meirdick\WorkersAi\Extraction;
 * use Meirdick\WorkersAi\Extraction\Number;
 * use Meirdick\WorkersAi\Extraction\Text;
 *
 * $invoice = Extraction::of($text)
 *     ->fields([
 *         'invoice_number' => new Text,
 *         'total' => new Number(decimals: 2, unit: 'USD'),
 *     ])
 *     ->extract();
 *
 * $invoice['total']->value();
 * ```
 */
final class Extraction
{
    /**
     * @param  array<int|string, File|UploadedFile>  $attachments
     */
    public static function of(string $state, array $attachments = []): PendingExtraction
    {
        if (trim($state) === '' && $attachments === []) {
            throw new InvalidArgumentException('Clairic needs a non-empty state, or at least one image.');
        }

        return new PendingExtraction($state, $attachments);
    }

    /**
     * Answer extractions with the given responses and send no request.
     *
     * @param  Closure(ExtractionPrompt): mixed|array<int, mixed>  $responses
     */
    public static function fake(Closure|array $responses = []): FakeExtractionGateway
    {
        $fake = new FakeExtractionGateway($responses);

        app()->instance(FakeExtractionGateway::class, $fake);

        return $fake;
    }

    public static function isFaked(): bool
    {
        return app()->bound(FakeExtractionGateway::class);
    }

    /**
     * @param  Closure(ExtractionPrompt): bool  $callback
     */
    public static function assertExtracted(Closure $callback): void
    {
        PHPUnit::assertTrue(
            collect(self::recorded())->contains(fn (ExtractionPrompt $prompt) => $callback($prompt)),
            'An expected extraction was not recorded.'
        );
    }

    /**
     * @param  Closure(ExtractionPrompt): bool  $callback
     */
    public static function assertNotExtracted(Closure $callback): void
    {
        PHPUnit::assertTrue(
            collect(self::recorded())->doesntContain(fn (ExtractionPrompt $prompt) => $callback($prompt)),
            'An unexpected extraction was recorded.'
        );
    }

    public static function assertNothingExtracted(): void
    {
        PHPUnit::assertEmpty(self::recorded(), 'Unexpected extractions were recorded.');
    }

    /**
     * @return list<ExtractionPrompt>
     */
    private static function recorded(): array
    {
        return self::isFaked() ? app(FakeExtractionGateway::class)->recorded : [];
    }
}

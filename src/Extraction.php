<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Laravel\Ai\Files\File;

/**
 * Extract typed values from text with Clairic.
 *
 * Clairic is a System One extraction model. It does not generate tokens.
 * It returns one typed value per field, or null when the input does not
 * determine it. laravel/ai has no extraction API — classification covers
 * Jev and Clef, which choose among options you list. Clairic's fields are
 * a different request, so this is the entry point.
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
}

<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Providers;

use Laravel\Ai\Contracts\Providers\ClassificationProvider;

/**
 * Picks the provider class for the installed laravel/ai.
 *
 * `ClassificationProvider` and the `Classifies` trait exist from laravel/ai
 * 1.0. On 0.9 to 0.11 the provider stays a text and embeddings provider.
 */
final class ClefClassification
{
    public static function available(): bool
    {
        return interface_exists(ClassificationProvider::class)
            && trait_exists(\Laravel\Ai\Providers\Concerns\Classifies::class)
            && trait_exists(\Laravel\Ai\Providers\Concerns\HasClassificationGateway::class);
    }

    public static function providerClass(): string
    {
        return self::available() ? WorkersAiClassifyingProvider::class : WorkersAiProvider::class;
    }
}

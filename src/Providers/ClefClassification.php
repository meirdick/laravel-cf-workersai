<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Providers;

if (! interface_exists(\Laravel\Ai\Contracts\Providers\ClassificationProvider::class)) {
    return;
}


use Laravel\Ai\Contracts\Providers\ClassificationProvider;

/**
 * Makes the Workers AI provider a laravel/ai classification provider.
 *
 * `ClassificationProvider` and the `Classifies` trait exist from laravel/ai
 * 1.0. On 0.9 to 0.11 neither exists, and a `use` or `implements` of them
 * in a real file is a fatal error when the class loads. The subclass is
 * therefore built here, as a string, and only when both exist.
 *
 * The generated class is what the service provider registers. It adds
 * `classify()` and the interface. The gateway, the default Clef model and
 * the HTTP call live on `WorkersAiProvider`, which loads on every line.
 */
final class ClefClassification
{
    public const CLASS_NAME = 'Meirdick\\WorkersAi\\Providers\\WorkersAiClassifyingProvider';

    public static function available(): bool
    {
        return interface_exists(ClassificationProvider::class)
            && trait_exists(\Laravel\Ai\Providers\Concerns\Classifies::class)
            && trait_exists(\Laravel\Ai\Providers\Concerns\HasClassificationGateway::class);
    }

    /**
     * The provider class to register: the classifying subclass on 1.x, the
     * plain provider on 0.9 to 0.11.
     */
    public static function providerClass(): string
    {
        if (! self::available()) {
            return WorkersAiProvider::class;
        }

        self::load();

        return self::CLASS_NAME;
    }

    public static function load(): void
    {
        if (class_exists(self::CLASS_NAME, false)) {
            return;
        }

        eval(<<<'PHP'
namespace Meirdick\WorkersAi\Providers;

use Laravel\Ai\Contracts\Gateway\ClassificationGateway;
use Meirdick\WorkersAi\Gateway\WorkersAiClassificationGateway;

class WorkersAiClassifyingProvider extends WorkersAiProvider implements \Laravel\Ai\Contracts\Providers\ClassificationProvider
{
    use \Laravel\Ai\Providers\Concerns\Classifies;
    use \Laravel\Ai\Providers\Concerns\HasClassificationGateway;

    public function supportsClassificationAttachments(): bool
    {
        return true;
    }

    public function classificationGateway(): ClassificationGateway
    {
        return $this->classificationGateway ??= new WorkersAiClassificationGateway($this->events);
    }
}
PHP);
    }
}

<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Providers;

if (! interface_exists(\Laravel\Ai\Contracts\Providers\ClassificationProvider::class)) {
    return;
}

use Laravel\Ai\Contracts\Gateway\ClassificationGateway;
use Laravel\Ai\Contracts\Providers\ClassificationProvider;
use Meirdick\WorkersAi\Gateway\WorkersAiClassificationGateway;

/**
 * The Workers AI provider, plus laravel/ai's classification API.
 *
 * Laravel AI 1.0 added the interface and traits this class uses. The guard
 * above keeps this file safe to load on 0.9 to 0.11. `ClefClassification`
 * picks this class on 1.x, and `WorkersAiProvider` on earlier versions.
 */
class WorkersAiClassifyingProvider extends WorkersAiProvider implements ClassificationProvider
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

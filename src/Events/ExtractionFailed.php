<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Events;

use Laravel\Ai\Providers\Provider;
use Meirdick\WorkersAi\Prompts\ExtractionPrompt;
use Throwable;

class ExtractionFailed
{
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public ExtractionPrompt $prompt,
        public Throwable $exception,
    ) {}
}

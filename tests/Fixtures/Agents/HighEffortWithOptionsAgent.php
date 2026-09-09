<?php

declare(strict_types=1);

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Meirdick\WorkersAi\Attributes\ReasoningEffort;
use Stringable;

/**
 * Declares `high` via the attribute but overrides it to `medium` through
 * provider options — provider options are merged last and must win.
 */
#[ReasoningEffort(ReasoningEffort::HIGH)]
class HighEffortWithOptionsAgent implements Agent, HasProviderOptions
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You are thorough.';
    }

    public function providerOptions(Lab|string $provider): array
    {
        return ['reasoning_effort' => ReasoningEffort::MEDIUM];
    }
}

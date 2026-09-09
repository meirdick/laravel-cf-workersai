<?php

declare(strict_types=1);

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Meirdick\WorkersAi\Attributes\ReasoningEffort;
use Stringable;

#[ReasoningEffort(ReasoningEffort::LOW)]
class LowEffortAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You are terse.';
    }
}

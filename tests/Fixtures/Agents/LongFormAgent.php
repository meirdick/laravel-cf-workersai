<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * Agent that produces deliberately long output, used by the live stress
 * suite to exercise long streamed generations and transfer timeouts.
 * (AssistantAgent's "respond extremely concisely" instruction fights
 * long-form prompts and makes those scenarios flaky.)
 */
class LongFormAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a thorough writer. Always answer at length with full detail — never summarize or shorten.';
    }
}

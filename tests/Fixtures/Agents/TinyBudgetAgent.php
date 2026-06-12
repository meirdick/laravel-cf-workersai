<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * Agent with a deliberately too-small token budget, used to verify that
 * truncated completions surface as FinishReason::Length instead of a
 * silent short/empty response with FinishReason::Stop.
 */
#[MaxTokens(24)]
class TinyBudgetAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a verbose storyteller. Always answer at great length, never less than five hundred words.';
    }
}

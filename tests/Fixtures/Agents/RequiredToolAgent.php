<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Tools\FixedNumberGenerator;

/**
 * Tool-using agent that forces a tool call via `tool_choice: required`.
 *
 * Open-weight models on Workers AI are unreliable at *choosing* to call
 * tools under `tool_choice: auto` (llama-4-scout narrates its plan instead
 * of emitting a tool_calls block roughly half the time; llama-3.3-70b never
 * tool-calls at all on the /v1 endpoint — both verified live 2026-06-11).
 * Forcing the choice makes tool execution deterministic.
 *
 * Note: custom drivers reach providerOptions() as a plain string (the Lab
 * enum only covers first-party providers), so match on the driver name.
 */
class RequiredToolAgent implements Agent, HasProviderOptions, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant that generates numbers.';
    }

    public function tools(): iterable
    {
        return [
            new FixedNumberGenerator,
        ];
    }

    public function providerOptions(Lab|string $provider): array
    {
        return in_array($provider, ['workers-ai', 'workersai'], true)
            ? ['tool_choice' => 'required']
            : [];
    }
}

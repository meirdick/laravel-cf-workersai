<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Exercises the Workers AI reasoning-control convention: an agent expresses
 * thinking via provider options (`chat_template_kwargs.thinking`), which the
 * gateway merges into the request body verbatim — mirroring how laravel/ai's
 * first-party gateways pass Anthropic `thinking` / Gemini `thinkingConfig`.
 */
class ThinkingAgent implements Agent, HasProviderOptions
{
    use Promptable;

    public function __construct(public bool $thinking = true) {}

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function providerOptions(Lab|string $provider): array
    {
        return ['chat_template_kwargs' => ['thinking' => $this->thinking]];
    }
}

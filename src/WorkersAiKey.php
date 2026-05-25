<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi;

/**
 * Driver-key constants for the Workers AI provider.
 *
 * Every other provider in the Laravel AI ecosystem (openai, anthropic,
 * xai, gemini, groq, mistral, deepseek) is a single lowercase token, so
 * users naturally reach for "workersai" — especially inside defensive
 * try/catch blocks that would otherwise swallow a "driver not supported"
 * error and silently degrade a feature. The service provider registers
 * both keys against the same factory.
 */
final class WorkersAiKey
{
    public const PRIMARY = 'workers-ai';

    public const ALIAS = 'workersai';
}

<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Events;

/**
 * Dispatched once per Workers AI response that reported a `neurons` figure.
 *
 * Neurons are the unit Cloudflare actually meters and bills Workers AI in.
 * The `/compat` endpoint returns one per call under `usage.neurons`, but
 * laravel/ai's `Responses\Data\Usage` is five fixed integer token counters
 * with no extensible field (verified against v0.11.2), so there is nowhere
 * in the SDK's response objects to put it. Anything tracking spend from
 * token counts alone is tracking the wrong number.
 *
 * Listen for this event to record the real one:
 *
 *     Event::listen(WorkersAiUsageReported::class, function ($event) {
 *         Spend::record($event->model, $event->neurons);
 *     });
 *
 * Observed magnitudes on `/compat` (2026-09-09): a 16-token completion on
 * `@cf/meta/llama-3.3-70b-instruct-fp8-fast` cost 4.48 neurons; the same
 * two-sentence prompt on `@cf/openai/gpt-oss-120b` cost 9.6 neurons at
 * `reasoning_effort: low` and 31.4 at `high`.
 */
final class WorkersAiUsageReported
{
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly float $neurons,
        public readonly int $promptTokens = 0,
        public readonly int $completionTokens = 0,
        public readonly int $reasoningTokens = 0,
    ) {}
}

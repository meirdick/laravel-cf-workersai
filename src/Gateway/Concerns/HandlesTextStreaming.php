<?php

namespace Meirdick\WorkersAi\Gateway\Concerns;

use Generator;
use Illuminate\Support\Str;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Meirdick\WorkersAi\Cloudflare\ErrorEnvelope;
use Meirdick\WorkersAi\Cloudflare\StreamToolCallAccumulator;
use Meirdick\WorkersAi\Cloudflare\ToolCallList;
use Meirdick\WorkersAi\Cloudflare\UsageTokens;

trait HandlesTextStreaming
{
    /**
     * Process a Chat Completions streaming response for a single step and
     * yield Laravel stream events.
     *
     * Since laravel/ai 0.9 this handles exactly one step: tool execution, the
     * follow-up request, cross-step usage accumulation, and the final
     * StreamEnd event are all owned by the core TextGenerationLoop. The step
     * returns a StepResponse whose usage the loop sums and whose
     * providerContentBlocks (captured reasoning) the loop replays into the
     * follow-up turn's assistant message.
     *
     * @return Generator<int, \Laravel\Ai\Streaming\Events\StreamEvent, mixed, StepResponse|null>
     */
    protected function processTextStream(
        string $invocationId,
        Provider $provider,
        string $model,
        ?TextGenerationOptions $options,
        $streamBody,
    ): Generator {
        $messageId = $this->generateEventId();
        $reasoningId = '';
        $streamStartEmitted = false;
        $textStartEmitted = false;
        $reasoningStartEmitted = false;
        $currentText = '';
        // Accumulated reasoning so the loop can replay it on the tool-call
        // follow-up turn (matches DeepSeek native gateway behavior). Without
        // replay, multi-turn tool conversations with reasoning models lose
        // chain-of-thought across the tool boundary.
        $currentReasoning = '';
        $pendingToolCalls = [];
        $toolCalls = [];
        $usage = null;
        $finishReason = null;
        $responseModel = $model;

        foreach ($this->parseServerSentEvents($streamBody) as $data) {
            // ErrorEnvelope handles both `{"error": {...}}` (OpenAI shape) and
            // `{"errors": [...]}` (Cloudflare AI Gateway shape) so streaming
            // errors don't collapse to "Unknown error".
            if (ErrorEnvelope::isErrorPayload($data)) {
                yield (new Error(
                    $this->generateEventId(),
                    (string) ErrorEnvelope::extractType($data),
                    ErrorEnvelope::extract($data),
                    false,
                    time(),
                ))->withInvocationId($invocationId);

                return null;
            }

            $choice = $data['choices'][0] ?? null;

            if (! $choice) {
                if (isset($data['usage'])) {
                    $usage = $this->resolveStreamUsage($usage, $data['usage']);
                }

                continue;
            }

            $delta = $choice['delta'] ?? [];

            if (! $streamStartEmitted) {
                $streamStartEmitted = true;
                $responseModel = $data['model'] ?? $model;

                yield (new StreamStart(
                    $this->generateEventId(),
                    $provider->name(),
                    $data['model'] ?? $model,
                    time(),
                ))->withInvocationId($invocationId);
            }

            $reasoningDelta = $delta['reasoning_content']
                ?? $delta['reasoning']
                ?? $delta['thinking']
                ?? null;

            if (is_string($reasoningDelta) && $reasoningDelta !== '') {
                if (! $reasoningStartEmitted) {
                    $reasoningStartEmitted = true;
                    $reasoningId = $this->generateEventId();

                    yield (new ReasoningStart(
                        $this->generateEventId(),
                        $reasoningId,
                        time(),
                    ))->withInvocationId($invocationId);
                }

                $currentReasoning .= $reasoningDelta;

                yield (new ReasoningDelta(
                    $this->generateEventId(),
                    $reasoningId,
                    $reasoningDelta,
                    time(),
                ))->withInvocationId($invocationId);
            }

            if ($reasoningStartEmitted && isset($delta['content']) && $delta['content'] !== '' && $reasoningDelta === null) {
                $reasoningStartEmitted = false;

                yield (new ReasoningEnd(
                    $this->generateEventId(),
                    $reasoningId,
                    time(),
                ))->withInvocationId($invocationId);

                $reasoningId = '';
            }

            if (isset($delta['content']) && $delta['content'] !== '') {
                if (! $textStartEmitted) {
                    $textStartEmitted = true;

                    yield (new TextStart(
                        $this->generateEventId(),
                        $messageId,
                        time(),
                    ))->withInvocationId($invocationId);
                }

                $currentText .= $delta['content'];

                yield (new TextDelta(
                    $this->generateEventId(),
                    $messageId,
                    $delta['content'],
                    time(),
                ))->withInvocationId($invocationId);
            }

            // StreamToolCallAccumulator owns the null-tolerance rules: it
            // distinguishes explicit-null deltas (skip) from falsy strings
            // like "0" or "" (preserve), which a naive truthy check would
            // drop and corrupt the accumulated JSON arguments.
            $deltaToolCalls = ToolCallList::fromStreamDelta(['choices' => [['delta' => $delta]]]);
            if ($deltaToolCalls !== []) {
                $pendingToolCalls = StreamToolCallAccumulator::append($pendingToolCalls, $deltaToolCalls);
            }

            if (isset($choice['finish_reason']) && $choice['finish_reason'] !== null) {
                $finishReason = $choice['finish_reason'];
            }

            if (isset($data['usage'])) {
                $usage = $this->resolveStreamUsage($usage, $data['usage']);
            }
        }

        if ($reasoningStartEmitted) {
            yield (new ReasoningEnd(
                $this->generateEventId(),
                $reasoningId,
                time(),
            ))->withInvocationId($invocationId);
        }

        if ($textStartEmitted) {
            yield (new TextEnd(
                $this->generateEventId(),
                $messageId,
                time(),
            ))->withInvocationId($invocationId);
        }

        if (filled($pendingToolCalls) && $finishReason === 'tool_calls') {
            $toolCalls = $this->mapStreamToolCalls($pendingToolCalls);

            foreach ($toolCalls as $toolCall) {
                yield (new ToolCallEvent(
                    $this->generateEventId(),
                    $toolCall,
                    time(),
                ))->withInvocationId($invocationId);
            }
        }

        $stepUsage = $usage ?? new Usage(0, 0);

        // Workers AI reports `length` accurately on the streamed finish chunk
        // just as it does on the non-streaming path (re-measured 2026-09-09),
        // so the reason is mapped as sent. The v0.6.1 completion-token
        // heuristic is gone — see ParsesTextResponses::extractFinishReason.
        return $this->withReasoning(new StepResponse(
            text: $currentText,
            toolCalls: $toolCalls,
            finishReason: $this->extractFinishReason(['finish_reason' => $finishReason ?? '']),
            usage: $stepUsage,
            meta: new Meta($provider->name(), $responseModel),
            providerContentBlocks: filled($currentReasoning)
                ? ['reasoning_content' => $currentReasoning]
                : [],
        ), $currentReasoning);
    }

    /**
     * Resolve the stream's usage from a `usage` chunk without letting empty
     * chunks clobber real counts.
     *
     * The live Workers AI endpoint reports usage on the finish chunk, then
     * emits a trailing usage-only chunk whose counts are all zero. A naive
     * last-write-wins assignment erases the real numbers, so an all-zero
     * payload only sticks when no usage has been captured yet.
     *
     * @param  array<string, mixed>  $usagePayload
     */
    protected function resolveStreamUsage(?Usage $current, array $usagePayload): Usage
    {
        $incoming = $this->buildStreamUsage($usagePayload);

        if ($current === null) {
            return $incoming;
        }

        $incomingIsEmpty = $incoming->promptTokens === 0
            && $incoming->completionTokens === 0
            && $incoming->reasoningTokens === 0
            && $incoming->cacheReadInputTokens === 0;

        return $incomingIsEmpty ? $current : $incoming;
    }

    /**
     * Build a Usage instance from a streamed `usage` chunk, applying the same
     * null-tolerance + cache-metric rules as the non-streaming path.
     *
     * @param  array<string, mixed>  $usage
     */
    protected function buildStreamUsage(array $usage): Usage
    {
        return new Usage(
            promptTokens: UsageTokens::promptTokens($usage),
            completionTokens: UsageTokens::completionTokens($usage),
            cacheWriteInputTokens: 0,
            cacheReadInputTokens: UsageTokens::cachedTokens($usage) ?? 0,
            reasoningTokens: UsageTokens::reasoningTokens($usage) ?? 0,
        );
    }

    /**
     * Map raw streaming tool call data to ToolCall DTOs.
     *
     * @return array<ToolCall>
     */
    protected function mapStreamToolCalls(array $toolCalls): array
    {
        return array_map(fn (array $toolCall) => new ToolCall(
            $toolCall['id'] ?? '',
            $toolCall['name'] ?? '',
            json_decode($toolCall['arguments'] ?? '{}', true) ?? [],
            $toolCall['id'] ?? null,
        ), array_values($toolCalls));
    }

    /**
     * Generate a lowercase UUID v7 for use as a stream event ID.
     */
    protected function generateEventId(): string
    {
        return strtolower((string) Str::uuid7());
    }
}

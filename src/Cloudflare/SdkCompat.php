<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Cloudflare;

use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\Usage;

/**
 * Builds and reads laravel/ai value objects across the 0.x and 1.x shapes.
 *
 * 1.x: `TextUsage(inputTokens, outputTokens, cacheRead, cacheWrite, reasoning)`,
 * inclusive counts, nullable extras. 0.x: `Usage(promptTokens, completionTokens,
 * cacheWrite, cacheRead, reasoning)`, prompt net of cache.
 */
final class SdkCompat
{
    public static function isV1(): bool
    {
        return class_exists(TextUsage::class);
    }

    /** @param array<string, mixed>|null $usage the wire `usage` subtree */
    public static function textUsage(?array $usage): Usage
    {
        if (self::isV1()) {
            return new TextUsage(
                inputTokens: UsageTokens::rawPromptTokens($usage),
                outputTokens: UsageTokens::completionTokens($usage),
                cacheReadInputTokens: UsageTokens::cachedTokens($usage),
                reasoningTokens: UsageTokens::reasoningTokens($usage),
            );
        }

        return new Usage(
            promptTokens: UsageTokens::promptTokens($usage),
            completionTokens: UsageTokens::completionTokens($usage),
            cacheWriteInputTokens: 0,
            cacheReadInputTokens: UsageTokens::cachedTokens($usage) ?? 0,
            reasoningTokens: UsageTokens::reasoningTokens($usage) ?? 0,
        );
    }

    public static function emptyTextUsage(): Usage
    {
        return self::isV1() ? new TextUsage : new Usage(0, 0);
    }

    /** Usage for an embeddings response: a `Usage` on 1.x, an int on 0.x. */
    public static function embeddingsUsage(int $tokens): Usage|int
    {
        return property_exists(\Laravel\Ai\Responses\EmbeddingsResponse::class, 'usage')
            ? new Usage(inputTokens: $tokens)
            : $tokens;
    }

    /** Uncached prompt tokens, the figure 0.x reported. */
    public static function promptTokens(Usage $usage): int
    {
        return isset($usage->promptTokens)
            ? $usage->promptTokens
            : max(0, $usage->inputTokens - ($usage->cacheReadInputTokens ?? 0));
    }

    public static function completionTokens(Usage $usage): int
    {
        return $usage->completionTokens ?? $usage->outputTokens;
    }

    public static function reasoningTokens(Usage $usage): int
    {
        return (int) ($usage->reasoningTokens ?? 0);
    }

    public static function cacheReadTokens(Usage $usage): int
    {
        return (int) ($usage->cacheReadInputTokens ?? 0);
    }

    /**
     * Name of the StepResponse argument that carries raw provider state.
     */
    public static function replayBlocksKey(): string
    {
        return property_exists(StepResponse::class, 'replayBlocks') ? 'replayBlocks' : 'providerContentBlocks';
    }

    /** Wrap reasoning in the shape the target version replays. */
    public static function replayBlocks(string $reasoning): array
    {
        if ($reasoning === '') {
            return [];
        }

        return self::isV1()
            ? [['type' => 'reasoning', 'reasoning_content' => $reasoning]]
            : ['reasoning_content' => $reasoning];
    }

    /** Read replayed reasoning off an AssistantMessage on either version. */
    public static function replayedReasoning(object $message): ?string
    {
        $blocks = $message->replayBlocks ?? $message->providerContentBlocks ?? [];

        if (isset($blocks['reasoning_content'])) {
            return $blocks['reasoning_content'];
        }

        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'reasoning') {
                return $block['reasoning_content'] ?? null;
            }
        }

        return null;
    }
}

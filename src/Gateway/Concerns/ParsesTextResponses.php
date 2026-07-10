<?php

namespace Meirdick\WorkersAi\Gateway\Concerns;

use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\Concerns\DecodesStructuredOutput;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Meirdick\WorkersAi\Cloudflare\ErrorEnvelope;
use Meirdick\WorkersAi\Cloudflare\ToolCallList;
use Meirdick\WorkersAi\Cloudflare\UsageTokens;
use Meirdick\WorkersAi\Providers\WorkersAiProvider;

trait ParsesTextResponses
{
    use DecodesStructuredOutput;

    /**
     * Validate the Workers AI response data.
     *
     * Cloudflare's AI Gateway returns errors under both the OpenAI-style
     * `{"error": {...}}` shape and the gateway-native `{"errors": [...]}`
     * envelope; ErrorEnvelope handles both. Without that layered lookup,
     * gateway-level errors collapse to "Unknown error" and operators lose the
     * actual reason (rate limit, billing, model unavailable, malformed
     * request).
     *
     * @throws AiException
     */
    protected function validateTextResponse(?array $data): void
    {
        if (ErrorEnvelope::isErrorPayload($data)) {
            throw new AiException(sprintf(
                'Workers AI Error: [%s] %s',
                ErrorEnvelope::extractType($data),
                ErrorEnvelope::extract($data),
            ));
        }

        if (empty($data['choices'][0])) {
            throw new AiException(
                'Workers AI Error: Response did not contain any choices.',
            );
        }
    }

    /**
     * Parse a single Workers AI response into a StepResponse.
     *
     * Tool invocation, message replay, and step accumulation are owned by
     * laravel/ai's TextGenerationLoop in 0.9 — this only maps one response.
     */
    protected function parseTextResponse(
        array $data,
        Provider $provider,
        bool $structured,
        ?TextGenerationOptions $options = null,
    ): StepResponse {
        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $model = $data['model'] ?? '';

        $text = $this->extractTextContent($message);
        // ToolCallList tolerates explicit-null `tool_calls` from /compat reasoning
        // models (Kimi K2.5/K2.6) — they emit literal nulls when finish_reason
        // is "stop" rather than omitting the key.
        $rawToolCalls = ToolCallList::fromResponse($data);
        $usage = $this->extractUsage($data);
        $finishReason = $this->extractFinishReason(
            $choice,
            $usage->completionTokens,
            $this->resolveMaxTokens($provider, $options),
        );

        $mappedToolCalls = array_map(fn (array $toolCall) => new ToolCall(
            $toolCall['id'] ?? '',
            $toolCall['function']['name'] ?? '',
            json_decode($toolCall['function']['arguments'] ?? '{}', true) ?? [],
            $toolCall['id'] ?? null,
        ), $rawToolCalls);

        return new StepResponse(
            text: $text,
            toolCalls: $mappedToolCalls,
            finishReason: $finishReason,
            usage: $usage,
            meta: new Meta($provider->name(), $model),
            structured: $structured ? $this->decodeStructuredOutput($text) : null,
            providerContentBlocks: $this->extractProviderContentBlocks($message),
        );
    }

    /**
     * Capture reasoning into providerContentBlocks so the core loop replays it
     * on the tool-call follow-up (the loop passes these blocks into the next
     * step's AssistantMessage, and MapsMessages::mapAssistantMessage emits them
     * as `reasoning_content`). Reasoning models (Kimi K2.5/K2.6, Gemma 4, QwQ
     * on Workers AI; DeepSeek upstream) lose multi-turn coherence if the
     * thinking that led to the first tool call isn't replayed on the next
     * request.
     *
     * Kimi K2.6 renamed the response field from `reasoning_content` to
     * `reasoning`; Cloudflare's /compat layer has emitted both across model
     * versions, so accept either and normalize to the canonical
     * `reasoning_content` key. This mirrors the streaming path
     * (HandlesTextStreaming reads `reasoning_content ?? reasoning ?? thinking`).
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    protected function extractProviderContentBlocks(array $message): array
    {
        $reasoning = $message['reasoning_content'] ?? $message['reasoning'] ?? null;

        return filled($reasoning) ? ['reasoning_content' => $reasoning] : [];
    }

    /**
     * Validate decoded structured output against the requested schema and return
     * a list of human-readable problems (empty = valid). Targets the failure
     * Workers AI's best-effort JSON mode actually produces in the field — a
     * well-formed JSON object that omits/empties a required field or carries an
     * out-of-enum value (e.g. Kimi K2.6's empty-required-field bug) — without
     * pulling in a full JSON-Schema validator. A non-object payload (prose,
     * unparseable JSON) is left to the caller's fallback rather than re-asked,
     * since `response_format` makes that case rare and re-asking it is lower-value.
     *
     * @param  array<string, mixed>|null  $schema
     * @return list<string>
     */
    protected function structuredOutputErrors(mixed $data, ?array $schema): array
    {
        if (! is_array($data) || blank($data) || blank($schema)) {
            return [];
        }

        $compiled = (new ObjectSchema($schema))->toSchema();
        $required = $compiled['required'] ?? [];
        $properties = $compiled['properties'] ?? [];

        $errors = [];

        foreach ($required as $field) {
            $value = $data[$field] ?? null;

            if (! array_key_exists($field, $data)
                || $value === null
                || (is_string($value) && trim($value) === '')) {
                $errors[] = "required field `{$field}` is missing or empty";
            }
        }

        foreach ($properties as $field => $definition) {
            $value = $data[$field] ?? null;

            if ($value === null || ! is_array($definition)) {
                continue;
            }

            $enum = $definition['enum'] ?? null;

            if (is_array($enum) && filled($enum) && ! in_array($value, $enum, true)) {
                $errors[] = "field `{$field}` must be one of: ".implode(', ', $enum);
            }
        }

        return array_values($errors);
    }

    /**
     * The maximum number of times to re-ask for schema-valid structured output.
     */
    protected function maxStructuredRetries(Provider $provider): int
    {
        return $provider instanceof WorkersAiProvider
            ? $provider->structuredOutputRetries()
            : 2;
    }

    /**
     * Extract text content from a message.
     */
    protected function extractTextContent(array $message): string
    {
        $content = $message['content'] ?? '';

        if (is_string($content)) {
            return $content;
        }

        if (is_array($content) || is_object($content)) {
            return json_encode($content);
        }

        return strval($content);
    }

    /**
     * Extract usage data from the response.
     *
     * UsageTokens centralizes the explicit-null tolerance and the
     * `prompt_tokens_details.cached_tokens` mapping for prefix-cache metrics
     * (paired with session affinity for multi-turn conversations).
     */
    protected function extractUsage(array $data): Usage
    {
        $usage = $data['usage'] ?? [];

        return new Usage(
            promptTokens: UsageTokens::promptTokens($usage),
            completionTokens: UsageTokens::completionTokens($usage),
            cacheWriteInputTokens: 0,
            cacheReadInputTokens: UsageTokens::cachedTokens($usage) ?? 0,
            reasoningTokens: UsageTokens::reasoningTokens($usage) ?? 0,
        );
    }

    /**
     * Extract and map the finish reason from the response.
     *
     * Cloudflare's `/v1/chat/completions` misreports truncated completions as
     * `finish_reason: "stop"` instead of `"length"`. When we know the requested
     * max-token budget and the completion exhausted it, normalize to `Length`
     * so laravel/ai's length-aware retry/continuation primitives can fire.
     * Without this, agents quietly receive truncated JSON and the SDK has no
     * signal that anything was wrong.
     */
    protected function extractFinishReason(
        array $choice,
        ?int $completionTokens = null,
        ?int $requestedMaxTokens = null,
    ): FinishReason {
        $raw = $choice['finish_reason'] ?? '';

        if ($raw === 'stop'
            && ! is_null($requestedMaxTokens)
            && ! is_null($completionTokens)
            && $completionTokens >= $requestedMaxTokens) {
            return FinishReason::Length;
        }

        return match ($raw) {
            'stop' => FinishReason::Stop,
            'tool_calls' => FinishReason::ToolCalls,
            'length' => FinishReason::Length,
            'content_filter' => FinishReason::ContentFilter,
            default => FinishReason::Unknown,
        };
    }
}

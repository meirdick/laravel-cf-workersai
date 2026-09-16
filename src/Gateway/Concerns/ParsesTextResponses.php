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
use Meirdick\WorkersAi\Events\WorkersAiUsageReported;
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
        $finishReason = $this->extractFinishReason($choice);

        $this->reportNeurons($data, $provider, $model, $usage);

        $mappedToolCalls = array_map(fn (array $toolCall) => new ToolCall(
            $toolCall['id'] ?? '',
            $toolCall['function']['name'] ?? '',
            json_decode($toolCall['function']['arguments'] ?? '{}', true) ?? [],
            $toolCall['id'] ?? null,
        ), $rawToolCalls);

        $providerContentBlocks = $this->extractProviderContentBlocks($message);

        return $this->withReasoning(new StepResponse(
            text: $text,
            toolCalls: $mappedToolCalls,
            finishReason: $finishReason,
            usage: $usage,
            meta: new Meta($provider->name(), $model),
            structured: $structured ? $this->decodeStructuredOutput($text) : null,
            providerContentBlocks: $providerContentBlocks,
        ), $providerContentBlocks['reasoning_content'] ?? '');
    }

    /**
     * Carry the reasoning a turn produced onto the step response.
     *
     * laravel/ai 1.x added `StepResponse::$reasoning` (PR #975) so the
     * chain of thought reaches the agent response and the conversation
     * store instead of living only in `providerContentBlocks`. Versions
     * before 1.x have no such property, and passing it as a constructor
     * argument there is a fatal error, so it is assigned only when present.
     */
    protected function withReasoning(StepResponse $step, string $reasoning): StepResponse
    {
        if ($reasoning !== '' && property_exists($step, 'reasoning')) {
            $step->reasoning = $reasoning;
        }

        return $step;
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
     * Dispatch the Cloudflare-metered `neurons` figure for the call.
     *
     * `Laravel\Ai\Responses\Data\Usage` is five fixed integer token counters
     * with no extensible field, and `Meta` has no arbitrary bag either, so a
     * per-call neuron count has nowhere to live on the SDK's response objects.
     * An event is the only place to put it that survives to the consumer.
     * Silent no-op when the response omits the field.
     */
    protected function reportNeurons(array $data, Provider $provider, string $model, Usage $usage): void
    {
        $neurons = UsageTokens::neurons($data['usage'] ?? []);

        if (is_null($neurons) || ! isset($this->events)) {
            return;
        }

        $this->events->dispatch(new WorkersAiUsageReported(
            provider: $provider->name(),
            model: $model,
            neurons: $neurons,
            promptTokens: $usage->promptTokens,
            completionTokens: $usage->completionTokens,
            reasoningTokens: $usage->reasoningTokens,
        ));
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
     * Through v0.6.1 this method also coerced `stop` to `Length` whenever the
     * completion had exhausted the requested budget, on the belief that
     * Cloudflare misreported truncation as `stop`. Re-measured live on
     * 2026-09-09 against AI Gateway `/compat`, that is not what happens:
     * `@cf/meta/llama-3.3-70b-instruct-fp8-fast`, `@cf/openai/gpt-oss-120b`
     * and `@cf/zai-org/glm-5.3-flash` each returned `finish_reason: "length"`
     * on a 16-token cap, and so did every model truncated at Cloudflare's
     * 256-token default. The coercion was therefore redundant, and it could
     * misfire on a model that legitimately finished on its last budgeted
     * token — which now matters, because `throw_on_truncation` turns a
     * `Length` finish into an exception. The raw reason is trusted.
     *
     * The two trailing parameters are retained for backwards compatibility
     * with subclasses that override this hook. They are unused.
     *
     * @param  int|null  $completionTokens  deprecated, ignored
     * @param  int|null  $requestedMaxTokens  deprecated, ignored
     */
    protected function extractFinishReason(
        array $choice,
        ?int $completionTokens = null,
        ?int $requestedMaxTokens = null,
    ): FinishReason {
        $raw = $choice['finish_reason'] ?? '';

        return match ($raw) {
            'stop' => FinishReason::Stop,
            'tool_calls' => FinishReason::ToolCalls,
            'length' => FinishReason::Length,
            'content_filter' => FinishReason::ContentFilter,
            default => FinishReason::Unknown,
        };
    }
}

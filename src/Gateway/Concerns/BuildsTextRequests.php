<?php

namespace Meirdick\WorkersAi\Gateway\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Provider;
use Meirdick\WorkersAi\Providers\WorkersAiProvider;

trait BuildsTextRequests
{
    /**
     * Build the request body for the Chat Completions API from
     * `Laravel\Ai\Messages\Message[]` (initial-turn shape).
     */
    protected function buildTextRequestBody(
        Provider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
    ): array {
        return $this->finalizeChatBody(
            [
                'model' => $model,
                'messages' => $this->mapMessagesToChat($messages, $instructions),
            ],
            provider: $provider,
            tools: $tools,
            schema: $schema,
            options: $options,
        );
    }

    /**
     * Append tools, response_format, sampling options, and providerOptions to
     * a body that already carries `model` and `messages`.
     *
     * Called from three places — `buildTextRequestBody` (initial turn) and the
     * tool-call follow-up bodies in `ParsesTextResponses::continueWithToolResults`
     * and `HandlesTextStreaming::handleStreamingToolCalls`. Previously each site
     * rebuilt the same shape inline, which let new options drift between paths;
     * threading them all through this helper is the single source of truth.
     *
     * @param  array<string, mixed>  $body  must already contain `model` + `messages`
     * @return array<string, mixed>
     */
    protected function finalizeChatBody(
        array $body,
        Provider $provider,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
    ): array {
        if (filled($tools)) {
            $mappedTools = $this->mapTools($tools);

            if (filled($mappedTools)) {
                $body['tool_choice'] = 'auto';
                $body['tools'] = $mappedTools;
            }
        }

        if (filled($schema)) {
            $body['response_format'] = $this->buildResponseFormat(
                $schema,
                Strict::isAppliedTo($options?->agent),
            );
        }

        $resolvedMaxTokens = $this->resolveMaxTokens($provider, $options);

        if (! is_null($resolvedMaxTokens)) {
            $body['max_completion_tokens'] = $resolvedMaxTokens;
        }

        $body = array_merge($body, Arr::whereNotNull([
            'temperature' => $options?->temperature,
            'top_p' => $options?->topP,
        ]));

        $providerOptions = $options?->providerOptions($provider->driver());

        if (filled($providerOptions)) {
            $body = array_merge($body, Arr::except($providerOptions, ['session_affinity']));
        }

        return $this->guardThinkingTokenBudget($body);
    }

    /**
     * Minimum completion-token budget to grant a request that enabled reasoning.
     *
     * Reasoning models emit their chain of thought into the same completion
     * budget as the answer. With a small budget the model exhausts it on
     * reasoning and returns `content: null` / `finish_reason: "length"` — a
     * silent empty response. Verified live against Kimi K2.6 on Workers AI: a
     * 32-token request produced zero content, and a structured request capped
     * at 2048 spent the entire budget reasoning. The floor reserves room for
     * both phases so thinking-enabled calls can still produce an answer.
     */
    protected int $thinkingTokenFloor = 2_048;

    /**
     * Raise the completion-token budget to a floor when reasoning is enabled.
     *
     * Workers AI's chat-template reasoning toggle (`chat_template_kwargs.thinking`)
     * is merged from the agent's provider options. When it is on, a budget below
     * the floor is bumped up (never down) so the model is not starved of tokens
     * for the answer after reasoning. Only `=== true` triggers the guard; an
     * explicit `false` or absence leaves the budget untouched.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function guardThinkingTokenBudget(array $body): array
    {
        if (data_get($body, 'chat_template_kwargs.thinking') !== true) {
            return $body;
        }

        $current = $body['max_completion_tokens'] ?? null;

        if (! is_null($current) && $current >= $this->thinkingTokenFloor) {
            return $body;
        }

        Log::warning('Workers AI: raising max_completion_tokens to the reasoning floor.', [
            'requested' => $current,
            'floor' => $this->thinkingTokenFloor,
            'model' => $body['model'] ?? null,
        ]);

        $body['max_completion_tokens'] = $this->thinkingTokenFloor;

        return $body;
    }

    /**
     * Relax a forced `tool_choice` on tool-result follow-up turns.
     *
     * `tool_choice: required` (or a forced specific function) applies per
     * request — re-sending it on the follow-up turn after tool results
     * forces the model to call a tool *again* instead of producing the
     * final answer, looping until max-steps and returning empty text.
     * Verified live against Workers AI (llama-4-scout, 2026-06-11). The
     * follow-up turn therefore downgrades a forced choice to `auto`;
     * `none` is preserved since it cannot loop.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function relaxForcedToolChoice(array $body): array
    {
        $choice = $body['tool_choice'] ?? null;

        if ($choice === 'required' || is_array($choice)) {
            $body['tool_choice'] = 'auto';
        }

        return $body;
    }

    /**
     * Resolve the effective `max_completion_tokens` for a request.
     *
     * Precedence: per-call `TextGenerationOptions::$maxTokens` → provider
     * config (`default_max_tokens`) → Cloudflare's endpoint default (returned
     * as `null` so the field is omitted). The 4096-token fallback exists in
     * `WorkersAiProvider::defaultMaxTokens()` to defuse Cloudflare's 256-token
     * `/v1/chat/completions` default, which silently truncates structured
     * output. Returning `null` here is intentional and lets callers opt
     * out of the package default by setting `default_max_tokens => null`.
     */
    protected function resolveMaxTokens(Provider $provider, ?TextGenerationOptions $options): ?int
    {
        if (! is_null($options?->maxTokens)) {
            return $options->maxTokens;
        }

        return $provider instanceof WorkersAiProvider
            ? $provider->defaultMaxTokens()
            : null;
    }

    /**
     * Build the response format options for structured output.
     *
     * `$strict` is opt-in via the `#[Strict]` attribute on the agent (or tool,
     * for OpenAI). When true, ObjectSchema enforces all-required-properties
     * and bans additionalProperties; the request also signals `strict: true`
     * so Workers AI's /compat layer (OpenAI-compatible) treats the schema as
     * an enforceable constraint rather than a hint.
     */
    protected function buildResponseFormat(array $schema, bool $strict): array
    {
        $objectSchema = new ObjectSchema($schema, strict: $strict);

        $schemaArray = $objectSchema->toSchema();

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => $schemaArray['name'] ?? 'schema_definition',
                'schema' => Arr::except($schemaArray, ['name']),
                'strict' => $strict,
            ],
        ];
    }
}

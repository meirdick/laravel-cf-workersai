<?php

namespace Meirdick\WorkersAi\Gateway\Concerns;

use Illuminate\Support\Arr;
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

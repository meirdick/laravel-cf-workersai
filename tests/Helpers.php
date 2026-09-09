<?php

declare(strict_types=1);

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;

/**
 * Global helpers for the Laravel AI gateway test suite. Inlined from the
 * laravel/ai test harness so the package's tests don't depend on laravel/ai's
 * internal Helpers.php (which evolves on a different release cadence).
 */
function workersAiTextResponse(string $content = 'Hello from Workers AI'): array
{
    return [
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => $content,
            ],
            'finish_reason' => 'stop',
        ]],
        'usage' => [
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
        ],
    ];
}

/**
 * A completion Workers AI cut off at the token budget. Verified live: the
 * endpoint sets `finish_reason: "length"` in this situation rather than
 * "stop" — this fixture mirrors the real wire shape.
 *
 * @return array<string, mixed>
 */
function workersAiTruncatedResponse(?string $content = 'partial', int $completionTokens = 4096): array
{
    return [
        'id' => 'chatcmpl-truncated',
        'object' => 'chat.completion',
        'model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => $content],
            'finish_reason' => 'length',
        ]],
        'usage' => [
            'prompt_tokens' => 10,
            'completion_tokens' => $completionTokens,
        ],
    ];
}

function fakeWorkersAiResponse(string $text = 'Hello'): PromiseInterface
{
    return Http::response(workersAiTextResponse($text));
}

function fakeWorkersAiToolCallResponse(): array
{
    return [
        'id' => 'chatcmpl-tool-123',
        'object' => 'chat.completion',
        'model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_123',
                    'type' => 'function',
                    'function' => [
                        'name' => 'FixedNumberGenerator',
                        'arguments' => '{}',
                    ],
                ]],
            ],
            'finish_reason' => 'tool_calls',
        ]],
        'usage' => [
            'prompt_tokens' => 20,
            'completion_tokens' => 10,
        ],
    ];
}

/**
 * The decoded JSON body of the most recent faked HTTP request.
 *
 * @return array<string, mixed>
 */
function lastRequestBody(): array
{
    return json_decode(Http::recorded()->last()[0]->body(), true);
}

/*
 * Live E2E helpers (tests/Integration). Centralized here so every
 * integration file can use them regardless of which file Pest loads first.
 */
function e2eCredentials(): ?array
{
    $token = getenv('WORKERS_AI_E2E_TOKEN');
    $account = getenv('WORKERS_AI_E2E_ACCOUNT');

    return ($token && $account) ? ['token' => $token, 'account' => $account] : null;
}

function configureLiveProvider(array $overrides = []): void
{
    $creds = e2eCredentials();

    // The skip() closures run after beforeEach — bail quietly so missing
    // credentials surface as a skip, not a setup crash.
    if ($creds === null) {
        return;
    }

    config(['ai.providers.workers-ai' => [
        'driver' => 'workers-ai',
        'key' => $creds['token'],
        'account_id' => $creds['account'],
        ...$overrides,
    ]]);
}

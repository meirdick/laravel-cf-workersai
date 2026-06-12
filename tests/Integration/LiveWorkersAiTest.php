<?php

/*
|--------------------------------------------------------------------------
| Live end-to-end tests against the real Workers AI API
|--------------------------------------------------------------------------
|
| Skipped unless WORKERS_AI_E2E_TOKEN and WORKERS_AI_E2E_ACCOUNT are set, so
| the default suite and CI stay offline. The gateway-routed tests
| additionally require WORKERS_AI_E2E_GATEWAY (an existing AI Gateway slug).
|
| Run with:
|   WORKERS_AI_E2E_TOKEN=... WORKERS_AI_E2E_ACCOUNT=... \
|   WORKERS_AI_E2E_GATEWAY=... vendor/bin/pest tests/Integration
*/

use Laravel\Ai\Embeddings;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Tests\Fixtures\Agents\AssistantAgent;

const E2E_TEXT_MODEL = '@cf/meta/llama-3.1-8b-instruct';
const E2E_EMBED_MODEL = '@cf/baai/bge-base-en-v1.5';

describe('direct Workers AI API', function () {
    beforeEach(function () {
        configureLiveProvider();
    });

    test('text generation round-trips', function () {
        $response = (new AssistantAgent)->prompt(
            'Reply with exactly the word: pong',
            provider: 'workers-ai',
            model: E2E_TEXT_MODEL,
        );

        expect($response->text)->not->toBe('')
            ->and(strtolower($response->text))->toContain('pong')
            ->and($response->usage->promptTokens)->toBeGreaterThan(0)
            ->and($response->usage->completionTokens)->toBeGreaterThan(0);
    });

    test('streaming yields deltas and a usage-bearing StreamEnd', function () {
        $events = iterator_to_array((new AssistantAgent)->stream(
            'Count from 1 to 5, digits only.',
            provider: 'workers-ai',
            model: E2E_TEXT_MODEL,
        ));

        $deltas = array_filter($events, fn ($e) => $e instanceof TextDelta);
        $ends = array_values(array_filter($events, fn ($e) => $e instanceof StreamEnd));

        expect($deltas)->not->toBeEmpty()
            ->and($ends)->toHaveCount(1)
            ->and($ends[0]->usage->promptTokens)->toBeGreaterThan(0);
    });

    test('embeddings return vectors', function () {
        $response = Embeddings::for(['hello world'])->generate(
            provider: 'workers-ai',
            model: E2E_EMBED_MODEL,
        );

        expect($response->embeddings)->toHaveCount(1)
            ->and($response->embeddings[0])->toHaveCount(768);
    });

    test('legacy api_key config shape still authenticates', function () {
        $creds = e2eCredentials();

        config(['ai.providers.workers-ai' => [
            'driver' => 'workers-ai',
            'api_key' => $creds['token'],
            'account_id' => $creds['account'],
        ]]);

        $response = (new AssistantAgent)->prompt(
            'Reply with exactly the word: pong',
            provider: 'workers-ai',
            model: E2E_TEXT_MODEL,
        );

        expect($response->text)->not->toBe('');
    });
})->skip(fn () => e2eCredentials() === null, 'Set WORKERS_AI_E2E_TOKEN and WORKERS_AI_E2E_ACCOUNT to run live tests.');

describe('via Cloudflare AI Gateway', function () {
    beforeEach(function () {
        configureLiveProvider(['gateway' => getenv('WORKERS_AI_E2E_GATEWAY')]);
    });

    test('text generation routes through the gateway', function () {
        $response = (new AssistantAgent)->prompt(
            'Reply with exactly the word: pong',
            provider: 'workers-ai',
            model: E2E_TEXT_MODEL,
        );

        expect($response->text)->not->toBe('')
            ->and(strtolower($response->text))->toContain('pong');
    });

    test('streaming routes through the gateway', function () {
        $events = iterator_to_array((new AssistantAgent)->stream(
            'Count from 1 to 5, digits only.',
            provider: 'workers-ai',
            model: E2E_TEXT_MODEL,
        ));

        expect(array_filter($events, fn ($e) => $e instanceof TextDelta))->not->toBeEmpty();
    });

    test('embeddings route through the gateway', function () {
        $response = Embeddings::for(['hello world'])->generate(
            provider: 'workers-ai',
            model: E2E_EMBED_MODEL,
        );

        expect($response->embeddings)->toHaveCount(1)
            ->and($response->embeddings[0])->toHaveCount(768);
    });
})->skip(fn () => e2eCredentials() === null || ! getenv('WORKERS_AI_E2E_GATEWAY'), 'Set WORKERS_AI_E2E_GATEWAY (plus token/account) to run gateway-routed live tests.');

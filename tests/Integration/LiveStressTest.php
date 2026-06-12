<?php

/*
|--------------------------------------------------------------------------
| Live stress tests targeting real-world failure modes
|--------------------------------------------------------------------------
|
| Targets the failure classes observed in production with earlier package
| versions: responses stopping short, empty responses, and timeout hangs.
| Repetition matters here — single-shot success hides flakiness.
|
| Requires WORKERS_AI_E2E_TOKEN + WORKERS_AI_E2E_ACCOUNT, plus
| WORKERS_AI_E2E_STRESS=1 (so the cheaper smoke suite can run without the
| full sweep). Add WORKERS_AI_E2E_GATEWAY to also sweep through AI Gateway.
*/

use Illuminate\Http\Client\ConnectionException;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\LongFormAgent;
use Tests\Fixtures\Agents\NonStrictAgent;
use Tests\Fixtures\Agents\RequiredToolAgent;
use Tests\Fixtures\Agents\TinyBudgetAgent;

const STRESS_FAST_MODEL = '@cf/meta/llama-3.1-8b-instruct';
const STRESS_BIG_MODEL = '@cf/meta/llama-3.3-70b-instruct-fp8-fast';
const STRESS_REASONING_MODEL = '@cf/moonshotai/kimi-k2.6';
// llama-3.3-70b does not emit tool calls on the /v1 endpoint (verified live
// 2026-06-11) — tool scenarios need a function-calling-capable model.
const STRESS_TOOL_MODEL = '@cf/meta/llama-4-scout-17b-16e-instruct';

function stressEnabled(): bool
{
    return e2eCredentials() !== null && getenv('WORKERS_AI_E2E_STRESS');
}

describe('reliability sweep (direct)', function () {
    beforeEach(function () {
        configureLiveProvider();
    });

    test('text generation never returns silently empty', function (string $model, int $rep) {
        $response = (new AssistantAgent)->prompt(
            'Name one historical fact about Jerusalem. One sentence.',
            provider: 'workers-ai',
            model: $model,
        );

        $finishReason = $response->steps->last()->finishReason;

        expect($response->text)->not->toBe('')
            ->and($finishReason)->toBe(FinishReason::Stop)
            ->and($response->usage->completionTokens)->toBeGreaterThan(0);
    })->with([STRESS_FAST_MODEL, STRESS_BIG_MODEL])->with(range(1, 5));

    test('truncation surfaces as Length, not a silent stop', function (int $rep) {
        $response = (new TinyBudgetAgent)->prompt(
            'Tell me the full history of the walls of Jerusalem.',
            provider: 'workers-ai',
            model: STRESS_FAST_MODEL,
        );

        // The contract that fixes "the response stopped short with no
        // signal": a budget-exhausted completion must be flagged Length so
        // callers can retry/continue. It must never be empty AND Stop.
        expect($response->steps->last()->finishReason)->toBe(FinishReason::Length);
    })->with(range(1, 3));

    test('structured output returns parseable, schema-shaped JSON', function (int $rep) {
        $response = (new NonStrictAgent)->prompt(
            'What is the capital of France? Respond with just the city name as the answer.',
            provider: 'workers-ai',
            model: STRESS_BIG_MODEL,
        );

        expect($response['answer'])->toBeString()->not->toBe('')
            ->and(strtolower($response['answer']))->toContain('paris');
    })->with(range(1, 5));

    test('reasoning model with tight budget is flagged, never silently empty', function (int $rep) {
        $response = (new TinyBudgetAgent)->prompt(
            'What is 17 * 23? Explain your reasoning in detail.',
            provider: 'workers-ai',
            model: STRESS_REASONING_MODEL,
        );

        $finishReason = $response->steps->last()->finishReason;

        // Reasoning models burn the budget on thinking and return empty
        // content — acceptable only when flagged Length. Empty + Stop is the
        // silent failure users hit in production.
        if ($response->text === '') {
            expect($finishReason)->toBe(FinishReason::Length);
        } else {
            expect($finishReason)->toBeIn([FinishReason::Stop, FinishReason::Length]);
        }
    })->with(range(1, 3));

    test('tool-call loop completes with the tool result in the answer', function (int $rep) {
        $response = (new RequiredToolAgent)->prompt(
            'Generate a secure random number for me using your tool, then tell me the exact number.',
            provider: 'workers-ai',
            model: STRESS_TOOL_MODEL,
        );

        expect($response->toolResults)->not->toBeEmpty()
            ->and($response->text)->toContain('72019');
    })->with(range(1, 3));

    test('long streamed generations complete with usage', function (int $rep) {
        $events = iterator_to_array((new LongFormAgent)->stream(
            'Write a detailed 300-word essay about the rebuilding of the walls of Jerusalem.',
            provider: 'workers-ai',
            model: STRESS_BIG_MODEL,
        ));

        $deltas = array_filter($events, fn ($e) => $e instanceof TextDelta);
        $ends = array_values(array_filter($events, fn ($e) => $e instanceof StreamEnd));

        expect(count($deltas))->toBeGreaterThan(10)
            ->and($ends)->toHaveCount(1)
            ->and($ends[0]->usage->completionTokens)->toBeGreaterThan(50);
    })->with(range(1, 3));

    test('a transfer timeout fails fast instead of triple-retrying', function () {
        $start = microtime(true);

        try {
            (new LongFormAgent)->prompt(
                'Write an extremely long, 2000-word essay about the history of Jerusalem.',
                provider: 'workers-ai',
                model: STRESS_BIG_MODEL,
                timeout: 3,
            );

            $this->markTestSkipped('Model finished within the 3s timeout; cannot exercise the timeout path.');
        } catch (ConnectionException) {
            $elapsed = microtime(true) - $start;

            // Pre-fix behavior: 3 attempts x 3s + backoff ≈ 10s+. Post-fix:
            // a single attempt, so the caller's timeout means what it says.
            expect($elapsed)->toBeLessThan(7.0);
        }
    });

    test('the default smartest model actually exists in the catalog', function () {
        $provider = app(Laravel\Ai\AiManager::class)->textProvider('workers-ai');

        $response = (new AssistantAgent)->prompt(
            'Say OK.',
            provider: 'workers-ai',
            model: $provider->smartestTextModel(),
        );

        expect($response->text)->not->toBe('');
    });
})->skip(fn () => ! stressEnabled(), 'Set WORKERS_AI_E2E_STRESS=1 (plus token/account) to run the stress sweep.');

describe('reliability sweep (via AI Gateway)', function () {
    beforeEach(function () {
        configureLiveProvider(['gateway' => getenv('WORKERS_AI_E2E_GATEWAY')]);
    });

    test('text generation through the gateway never returns silently empty', function (int $rep) {
        $response = (new AssistantAgent)->prompt(
            'Name one historical fact about Jerusalem. One sentence.',
            provider: 'workers-ai',
            model: STRESS_FAST_MODEL,
        );

        expect($response->text)->not->toBe('')
            ->and($response->steps->last()->finishReason)->toBe(FinishReason::Stop);
    })->with(range(1, 3));

    test('long streamed generation through the gateway completes with usage', function () {
        $events = iterator_to_array((new LongFormAgent)->stream(
            'Write a detailed 300-word essay about the rebuilding of the walls of Jerusalem.',
            provider: 'workers-ai',
            model: STRESS_BIG_MODEL,
        ));

        $deltas = array_filter($events, fn ($e) => $e instanceof TextDelta);
        $ends = array_values(array_filter($events, fn ($e) => $e instanceof StreamEnd));

        expect(count($deltas))->toBeGreaterThan(10)
            ->and($ends)->toHaveCount(1)
            ->and($ends[0]->usage->completionTokens)->toBeGreaterThan(50);
    });

    test('structured output through the gateway returns schema-shaped JSON', function (int $rep) {
        $response = (new NonStrictAgent)->prompt(
            'What is the capital of France? Respond with just the city name as the answer.',
            provider: 'workers-ai',
            model: STRESS_BIG_MODEL,
        );

        expect($response['answer'])->toBeString()->not->toBe('');
    })->with(range(1, 3));
})->skip(fn () => ! stressEnabled() || ! getenv('WORKERS_AI_E2E_GATEWAY'), 'Set WORKERS_AI_E2E_STRESS=1 and WORKERS_AI_E2E_GATEWAY to run the gateway stress sweep.');

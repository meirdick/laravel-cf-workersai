<?php

use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Gateway\StepResponse;

use function Laravel\Ai\agent;

/*
|--------------------------------------------------------------------------
| Raw HTTP response passthrough
|--------------------------------------------------------------------------
|
| laravel/ai's typed response objects have nowhere to put provider-specific
| data: Usage is five fixed int counters, Meta has provider/model/citations.
| `StepResponse::$raw` is the escape hatch, and both first-party OpenAI
| gateways populate it. A consuming application reads Cloudflare's
| `usage.neurons` and its per-call transfer time off it — if the gateway
| leaves it null, that cost tracking silently reports zero rather than
| failing, which is the worst way to lose a number.
|
*/
beforeEach(function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'key' => 'test-key',
        'account_id' => 'test-account',
    ]]);
});

// StepResponse only gained HasRawResponse after laravel/ai 0.9, and the
// package still supports ^0.9. There the raw response is unavailable by
// design rather than broken, so these expectations do not apply.
uses()->beforeEach(function () {
    if (! method_exists(StepResponse::class, 'withRawResponse')) {
        $this->markTestSkipped('laravel/ai '.\Composer\InstalledVersions::getPrettyVersion('laravel/ai').' has no StepResponse::withRawResponse().');
    }
})->in(__FILE__);

test('the raw HTTP response is carried onto the response', function () {
    $payload = workersAiTextResponse();
    $payload['usage']['neurons'] = 4.4769;

    Http::fake(['api.cloudflare.com/*' => Http::response($payload)]);

    $response = agent()->prompt('Hello', provider: 'workersai');

    expect($response->raw)->toBeInstanceOf(HttpResponse::class)
        ->and($response->raw->json('usage.neurons'))->toBe(4.4769);
});

test('the raw response reaches the last step too', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    $response = agent()->prompt('Hello', provider: 'workersai');

    expect($response->steps->last()->raw)->toBeInstanceOf(HttpResponse::class);
});

test('each step of a tool loop carries its own raw response', function () {
    $toolCall = fakeWorkersAiToolCallResponse();
    $toolCall['usage']['neurons'] = 12.5;

    $final = workersAiTextResponse('The number is 72019');
    $final['usage']['neurons'] = 3.25;

    Http::fake(['api.cloudflare.com/*' => Http::sequence([
        Http::response($toolCall),
        Http::response($final),
    ])]);

    $response = (new Tests\Fixtures\Agents\ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'workersai',
    );

    $neurons = $response->steps->map(fn ($step) => $step->raw?->json('usage.neurons'))->all();

    expect($neurons)->toBe([12.5, 3.25]);
});

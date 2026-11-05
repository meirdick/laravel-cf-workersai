<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Contracts\Providers\ClassificationProvider;
use Laravel\Ai\Events\Classified;
use Laravel\Ai\Events\Classifying;
use Laravel\Ai\Exceptions\AiException;
use Meirdick\WorkersAi\Exceptions\GatewayTimeoutException;

/*
 * Jev parity. Everything laravel/ai offers for a classification provider must
 * work on workers-ai. These run the real SDK entry points, not the gateway.
 */

beforeEach(function () {
    if (! interface_exists(ClassificationProvider::class)) {
        $this->markTestSkipped('Classification arrived in laravel/ai 1.0.');
    }

    config(['ai.providers.workers-ai' => [
        'driver' => 'workers-ai',
        'key' => 'test-key',
        'account_id' => 'acct123',
        'name' => 'workers-ai',
        'retry' => false,
    ]]);
});

function clefBody(): array
{
    return [
        'model' => 'clef',
        'answers' => ['decision' => ['type' => 'noul', 'noul' => 0.9]],
        'usage' => ['input_tokens' => 12, 'output_tokens' => 0],
    ];
}

test('answers inside the REST result envelope are read', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response([
        'result' => clefBody(),
        'success' => true,
        'errors' => [],
        'messages' => [],
    ])]);

    $response = Classification::of('A ticket.')
        ->question('decision', new Boolean('Urgent?'))
        ->classify(provider: 'workers-ai');

    expect($response['decision']->probability)->toBe(0.9)
        ->and($response->usage->inputTokens)->toBe(12);
});

test('a 200 error envelope on clef throws', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response([
        'result' => null,
        'success' => false,
        'errors' => [['code' => 5007, 'message' => 'No such model']],
    ])]);

    expect(fn () => Classification::of('A ticket.')
        ->question('decision', new Boolean('Urgent?'))
        ->classify(provider: 'workers-ai'))
        ->toThrow(AiException::class, 'No such model');
});

test('a clef reply with no answers throws instead of returning an empty response', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['model' => 'clef'])]);

    expect(fn () => Classification::of('A ticket.')
        ->question('decision', new Boolean('Urgent?'))
        ->classify(provider: 'workers-ai'))
        ->toThrow(AiException::class, 'no answers');
});

test('a 408 on clef is a gateway timeout', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['errors' => [['message' => 'timeout']]], 408)]);

    expect(fn () => Classification::of('A ticket.')
        ->question('decision', new Boolean('Urgent?'))
        ->classify(provider: 'workers-ai'))
        ->toThrow(GatewayTimeoutException::class);
});

test('Str::decide reaches clef when workers-ai is the default classifier', function () {
    config(['ai.default_for_classification' => 'workers-ai']);

    Http::fake(['api.cloudflare.com/*' => Http::response(clefBody())]);

    expect(Str::decide('Checkout is down.', 'Is this urgent?'))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/ai/run/@cf/cloudflare/clef'));
});

test('Collection::decide reaches clef', function () {
    if (! Collection::hasMacro('decide')) {
        $this->markTestSkipped('Collection::decide() arrived after laravel/ai 1.0.');
    }

    Http::fake(['api.cloudflare.com/*' => Http::response([
        'model' => 'clef',
        'answers' => ['decision' => [
            'type' => 'choice',
            'choice' => 'technical',
            'probabilities' => ['billing' => 0.1, 'technical' => 0.9],
        ]],
    ])]);

    $team = (new Collection(['billing' => 'Invoices', 'technical' => 'Outages']))
        ->decide('Which team?', 'Checkout is down.', provider: 'workers-ai');

    expect($team)->toBe('technical');
});

test('Classification::fake replaces clef and records the call', function () {
    Classification::fake([['decision' => new \Laravel\Ai\Responses\Data\BooleanAnswer(0.7)]]);

    Http::fake();

    $response = Classification::of('A ticket.')
        ->question('decision', new Boolean('Urgent?'))
        ->classify(provider: 'workers-ai');

    expect($response['decision']->probability)->toBe(0.7);

    Classification::assertClassified(fn ($prompt) => $prompt->contains('ticket') && $prompt->asks('decision'));
    Http::assertNothingSent();
});

test('classification events fire on workers-ai', function () {
    Event::fake([Classifying::class, Classified::class]);

    Http::fake(['api.cloudflare.com/*' => Http::response(clefBody())]);

    Classification::of('A ticket.')
        ->question('decision', new Boolean('Urgent?'))
        ->classify(provider: 'workers-ai');

    Event::assertDispatched(Classifying::class);
    Event::assertDispatched(Classified::class);
});

test('clef fails over to a second provider', function () {
    config(['ai.providers.workers-ai-backup' => [
        'driver' => 'workers-ai',
        'key' => 'backup-key',
        'account_id' => 'acct456',
        'name' => 'workers-ai-backup',
        'retry' => false,
    ]]);

    Http::fake([
        'api.cloudflare.com/client/v4/accounts/acct123/*' => Http::response([], 503),
        'api.cloudflare.com/client/v4/accounts/acct456/*' => Http::response(clefBody()),
    ]);

    $response = Classification::of('A ticket.')
        ->question('decision', new Boolean('Urgent?'))
        ->classify(provider: ['workers-ai', 'workers-ai-backup']);

    expect($response['decision']->probability)->toBe(0.9);
});

test('per-call headers and provider options reach clef', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(clefBody())]);

    Classification::of('A ticket.')
        ->question('decision', new Boolean('Urgent?'))
        ->withHeaders(['X-Trace' => 'abc'])
        ->withProviderOptions(['audio' => ['data:audio/mpeg;base64,AA==']])
        ->classify(provider: 'workers-ai', model: '@cf/cloudflare/clef-omni');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Trace', 'abc')
        && $request->data()['audio'] === ['data:audio/mpeg;base64,AA==']
        && $request->data()['model'] === 'clef-omni');
});

test('structured state reaches clef', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(clefBody())]);

    Classification::of(['subject' => 'Refund', 'body' => 'Please refund me.'])
        ->question('decision', new Boolean('Urgent?'))
        ->classify(provider: 'workers-ai');

    Http::assertSent(fn (Request $request): bool => $request->data()['state']['subject'] === 'Refund');
});

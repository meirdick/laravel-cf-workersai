<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Meirdick\WorkersAi\Events\Extracted;
use Meirdick\WorkersAi\Events\Extracting;
use Meirdick\WorkersAi\Events\ExtractionFailed;
use Meirdick\WorkersAi\Exceptions\GatewayTimeoutException;
use Meirdick\WorkersAi\Extraction;
use Meirdick\WorkersAi\Extraction\ListField;
use Meirdick\WorkersAi\Extraction\Number;
use Meirdick\WorkersAi\Extraction\Text;
use Meirdick\WorkersAi\Prompts\ExtractionPrompt;

/*
 * Extraction::of() has no laravel/ai parent, so it must offer by itself what
 * Classification::of() gets from the SDK: a fake, events and failover.
 */

beforeEach(function () {
    config(['ai.providers.workers-ai' => [
        'driver' => 'workers-ai',
        'key' => 'test-key',
        'account_id' => 'acct123',
        'name' => 'workers-ai',
        'retry' => false,
    ]]);
});

function clairicBody(): array
{
    return [
        'model' => 'clairic',
        'answers' => [
            'total' => ['type' => 'number', 'p_null' => 0.01, 'guess' => 1080, 'confidence' => 0.99, 'value' => 1080],
            'contacts' => ['type' => 'list', 'value' => ['Dana', 'Priya'], 'spans' => [[0, 4], [6, 11]], 'confidence' => 0.9],
            'days' => ['type' => 'estimate', 'value' => 30, 'median' => 30, 'p10' => 21, 'p90' => 38, 'p_below' => 0.01, 'p_above' => 0.02],
        ],
        'truncated' => false,
        'state_text' => 'Dana, Priya',
        'usage' => ['input_tokens' => 20, 'output_tokens' => 0],
    ];
}

test('answers inside the REST result envelope are read', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response([
        'result' => clairicBody(),
        'success' => true,
        'errors' => [],
        'messages' => [],
    ])]);

    $response = Extraction::of('An invoice.')->field('total', new Number)->extract();

    expect($response['total']->value())->toBe(1080);
});

test('an unwrapped reply with an empty errors array is not an error', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response([...clairicBody(), 'errors' => []])]);

    expect(Extraction::of('An invoice.')->field('total', new Number)->extract()['total']->value())->toBe(1080);
});

test('success false throws and names the message', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response([
        'success' => false,
        'errors' => [['code' => 7000, 'message' => 'Bad fields']],
    ])]);

    expect(fn () => Extraction::of('An invoice.')->field('total', new Number)->extract())
        ->toThrow(AiException::class, 'Bad fields');
});

test('a reply with no answers throws', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['model' => 'clairic'])]);

    expect(fn () => Extraction::of('An invoice.')->field('total', new Number)->extract())
        ->toThrow(AiException::class, 'no answers');
});

test('a 408 is a gateway timeout', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response([], 408)]);

    expect(fn () => Extraction::of('An invoice.')->field('total', new Number)->extract())
        ->toThrow(GatewayTimeoutException::class);
});

test('the fake answers with the values given and records the call', function () {
    Extraction::fake([['total' => 1080]]);

    Http::fake();

    $response = Extraction::of('An invoice for Dana.')
        ->fields(['total' => new Number, 'po' => new Text])
        ->extract();

    expect($response['total']->value())->toBe(1080)
        ->and($response['total']->isPresent())->toBeTrue()
        ->and($response['po']->value())->toBeNull()
        ->and($response['po']->pNull())->toBe(1.0)
        ->and(Extraction::isFaked())->toBeTrue();

    Extraction::assertExtracted(fn (ExtractionPrompt $prompt) => $prompt->contains('Dana') && $prompt->asks('total'));
    Extraction::assertNotExtracted(fn (ExtractionPrompt $prompt) => $prompt->asks('missing'));
    Http::assertNothingSent();
});

test('the fake accepts a closure', function () {
    Extraction::fake(fn (ExtractionPrompt $prompt) => ['total' => count($prompt)]);

    expect(Extraction::of('An invoice.')->field('total', new Number)->extract()['total']->value())->toBe(1);
});

test('assertNothingExtracted passes when no call ran and fails when one did', function () {
    Extraction::fake();

    Extraction::assertNothingExtracted();

    Extraction::of('An invoice.')->field('total', new Number)->extract();

    expect(fn () => Extraction::assertNothingExtracted())
        ->toThrow(\PHPUnit\Framework\ExpectationFailedException::class);
});

test('extraction is not faked by default', function () {
    expect(Extraction::isFaked())->toBeFalse();
});

test('extracting and extracted events fire', function () {
    Event::fake([Extracting::class, Extracted::class]);

    Http::fake(['api.cloudflare.com/*' => Http::response(clairicBody())]);

    Extraction::of('An invoice.')->field('total', new Number)->extract();

    Event::assertDispatched(Extracting::class, fn (Extracting $event) => $event->model === '@cf/cloudflare/clairic');
    Event::assertDispatched(Extracted::class, fn (Extracted $event) => $event->response['total']->value() === 1080);
});

test('a failed call dispatches extraction failed', function () {
    Event::fake([ExtractionFailed::class]);

    Http::fake(['api.cloudflare.com/*' => Http::response([], 400)]);

    expect(fn () => Extraction::of('An invoice.')->field('total', new Number)->extract())
        ->toThrow(\Illuminate\Http\Client\RequestException::class);

    Event::assertDispatched(ExtractionFailed::class);
});

test('extraction fails over to a second provider', function () {
    config(['ai.providers.workers-ai-backup' => [
        'driver' => 'workers-ai',
        'key' => 'backup-key',
        'account_id' => 'acct456',
        'name' => 'workers-ai-backup',
        'retry' => false,
    ]]);

    Http::fake([
        'api.cloudflare.com/client/v4/accounts/acct123/*' => Http::response([], 503),
        'api.cloudflare.com/client/v4/accounts/acct456/*' => Http::response(clairicBody()),
    ]);

    $response = Extraction::of('An invoice.')
        ->field('total', new Number)
        ->extract(provider: ['workers-ai', 'workers-ai-backup']);

    expect($response['total']->value())->toBe(1080);
});

test('when and unless chain, and closures resolve provider options', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(clairicBody())]);

    Extraction::of('An invoice.')
        ->field('total', new Number)
        ->when(true, fn ($pending) => $pending->field('contacts', new ListField))
        ->unless(true, fn ($pending) => $pending->field('never', new Text))
        ->withProviderOptions(fn ($provider) => ['options' => ['return_state_text' => true]])
        ->extract();

    Http::assertSent(fn (Request $request): bool => array_keys($request->data()['fields']) === ['total', 'contacts']
        && $request->data()['options'] === ['return_state_text' => true]);
});

test('the timeout from timeout() applies, and extract() overrides it', function () {
    Extraction::fake();

    Extraction::of('First.')->field('total', new Number)->timeout(45)->extract();
    Extraction::of('Second.')->field('total', new Number)->timeout(45)->extract(timeout: 30);
    Extraction::of('Third.')->field('total', new Number)->extract();

    Extraction::assertExtracted(fn (ExtractionPrompt $prompt) => $prompt->contains('First') && $prompt->timeout === 45);
    Extraction::assertExtracted(fn (ExtractionPrompt $prompt) => $prompt->contains('Second') && $prompt->timeout === 30);
    Extraction::assertExtracted(fn (ExtractionPrompt $prompt) => $prompt->contains('Third') && $prompt->timeout === 30);
});

test('per-call headers reach clairic on laravel/ai 1.x', function () {
    if (! method_exists(\Laravel\Ai\Providers\Provider::class, 'withHeaders')) {
        $this->markTestSkipped('Provider::withHeaders() arrived in laravel/ai 1.x.');
    }

    Http::fake(['api.cloudflare.com/*' => Http::response(clairicBody())]);

    Extraction::of('An invoice.')->field('total', new Number)->withHeaders(['X-Trace' => 'abc'])->extract();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Trace', 'abc'));
});

test('answers expose guess, list spans and interval tails, and the response the state text', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(clairicBody())]);

    $response = Extraction::of('Dana, Priya')
        ->fields(['total' => new Number, 'contacts' => new ListField, 'days' => new Number])
        ->extract();

    expect($response['total']->guess())->toBe(1080)
        ->and($response['contacts']->spans())->toBe([[0, 4], [6, 11]])
        ->and($response['days']->pBelow())->toBe(0.01)
        ->and($response['days']->pAbove())->toBe(0.02)
        ->and($response['days']->steps())->toBeNull()
        ->and($response->stateText)->toBe('Dana, Priya');
});

test('run config is validated the way chat config is', function () {
    config(['ai.providers.workers-ai.url' => 'https://example.com/ai/v1']);

    Http::fake();

    expect(fn () => Extraction::of('An invoice.')->field('total', new Number)->extract())
        ->toThrow(InvalidArgumentException::class, 'pick one');

    Http::assertNothingSent();
});

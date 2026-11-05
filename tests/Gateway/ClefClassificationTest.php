<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiManager;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;
use Laravel\Ai\Contracts\Providers\ClassificationProvider;
use Laravel\Ai\Files\Image;

use function Laravel\Ai\agent;

beforeEach(function () {
    if (! interface_exists(ClassificationProvider::class)) {
        $this->markTestSkipped('Classification arrived in laravel/ai 1.0.');
    }

    config(['ai.providers.workers-ai' => [
        ...config('ai.providers.workers-ai'),
        'key' => 'test-key',
        'account_id' => 'acct123',
        'name' => 'workers-ai',
    ]]);
});

function clefResponse(): array
{
    return [
        'model' => 'clef',
        'answers' => [
            'urgent' => ['type' => 'noul', 'noul' => 0.91],
            'team' => [
                'type' => 'choice',
                'choice' => 'technical',
                'probabilities' => ['billing' => 0.1, 'technical' => 0.9],
                'confidence' => 0.8,
            ],
            'severity' => [
                'type' => 'score',
                'score' => 2.4,
                'legend' => ['0' => 'None', '1' => 'Some', '2' => 'Most', '3' => 'All'],
                'probabilities' => ['0' => 0.05, '1' => 0.1, '2' => 0.25, '3' => 0.6],
                'confidence' => 0.55,
            ],
        ],
        'usage' => ['input_tokens' => 40, 'output_tokens' => 0],
    ];
}

function classifyTicket(): mixed
{
    return Classification::of('Checkout has been failing for every customer for the last hour.')
        ->questions([
            'urgent' => new Boolean('Is this support request urgent?'),
            'team' => new Choice('Which team should handle this request?', [
                'billing' => 'Payments, invoices, and refunds',
                'technical' => 'Outages, errors, and configuration',
            ]),
            'severity' => new Score('How severe is the customer impact?', ['None', 'Some', 'Most', 'All']),
        ])
        ->classify(provider: 'workers-ai');
}

test('workers-ai is a classification provider and defaults to clef', function () {
    $provider = app(AiManager::class)->instance('workers-ai');

    expect($provider)->toBeInstanceOf(ClassificationProvider::class)
        ->and($provider->defaultClassificationModel())->toBe('@cf/cloudflare/clef')
        ->and($provider->cheapestClassificationModel())->toBe('@cf/cloudflare/clef-flash');
});

test('classification posts the system one body to the clef run endpoint', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(clefResponse())]);

    $response = classifyTicket();

    expect($response['urgent']->probability)->toBe(0.91)
        ->and($response['urgent']->isTrue())->toBeTrue()
        ->and($response['team']->choice)->toBe('technical')
        ->and($response['severity']->score)->toBe(2.4)
        ->and($response->usage->inputTokens)->toBe(40)
        ->and($response->meta->model)->toBe('clef');

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->url() === 'https://api.cloudflare.com/client/v4/accounts/acct123/ai/run/@cf/cloudflare/clef'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && ! $request->hasHeader('cf-aig-gateway-id')
            && $body['model'] === 'clef'
            && $body['state'] === 'Checkout has been failing for every customer for the last hour.'
            && $body['questions']['urgent'] === ['type' => 'noul', 'instructions' => 'Is this support request urgent?']
            && $body['questions']['team']['type'] === 'choice'
            && $body['questions']['team']['criteria']['technical'] === 'Outages, errors, and configuration'
            && $body['questions']['severity']['criteria'] === ['None', 'Some', 'Most', 'All']
            && ! array_key_exists('images', $body);
    });
});

test('a configured gateway is a header, not a compat path', function () {
    config(['ai.providers.workers-ai.gateway' => 'production-llm']);

    Http::fake(['api.cloudflare.com/*' => Http::response(clefResponse())]);

    Classification::of('A ticket.')
        ->question('urgent', new Boolean('Urgent?'))
        ->classify(provider: 'workers-ai', model: '@cf/cloudflare/clef-flash');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.cloudflare.com/client/v4/accounts/acct123/ai/run/@cf/cloudflare/clef-flash'
        && $request->hasHeader('cf-aig-gateway-id', 'production-llm')
        && $request->data()['model'] === 'clef-flash');
});

test('a compat url cannot host a clef run', function () {
    config(['ai.providers.workers-ai' => [
        'driver' => 'workers-ai',
        'key' => 'test-key',
        'url' => 'https://gateway.ai.cloudflare.com/v1/acct123/my-gateway/compat',
        'name' => 'workers-ai',
    ]]);

    Http::fake();

    expect(fn () => Classification::of('A ticket.')
        ->question('urgent', new Boolean('Urgent?'))
        ->classify(provider: 'workers-ai'))
        ->toThrow(InvalidArgumentException::class, '/ai/run');

    Http::assertNothingSent();
});

test('an explicit ai/v1 url is rewritten to the run path', function () {
    config(['ai.providers.workers-ai' => [
        'driver' => 'workers-ai',
        'key' => 'test-key',
        'url' => 'https://api.cloudflare.com/client/v4/accounts/acct123/ai/v1',
        'name' => 'workers-ai',
    ]]);

    Http::fake(['api.cloudflare.com/*' => Http::response(clefResponse())]);

    Classification::of('A ticket.')->question('urgent', new Boolean('Urgent?'))->classify(provider: 'workers-ai');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.cloudflare.com/client/v4/accounts/acct123/ai/run/@cf/cloudflare/clef');
});

test('a chat model is rejected before a request', function () {
    Http::fake();

    expect(fn () => Classification::of('A ticket.')
        ->question('urgent', new Boolean('Urgent?'))
        ->classify(provider: 'workers-ai', model: '@cf/meta/llama-3.2-3b-instruct'))
        ->toThrow(InvalidArgumentException::class, 'not a Clef model');

    Http::assertNothingSent();
});

test('a workers-ai prefix on the model is stripped, not sent', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(clefResponse())]);

    Classification::of('A ticket.')
        ->question('urgent', new Boolean('Urgent?'))
        ->classify(provider: 'workers-ai', model: 'workers-ai/@cf/cloudflare/clef-omni');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.cloudflare.com/client/v4/accounts/acct123/ai/run/@cf/cloudflare/clef-omni'
        && $request->data()['model'] === 'clef-omni');
});

test('images go in the images field, not inside the state', function () {
    if ((new ReflectionMethod(Classification::class, 'of'))->getNumberOfParameters() < 2) {
        $this->markTestSkipped('Classification attachments arrived after laravel/ai 1.0.');
    }

    if (! (new ReflectionMethod(ClassificationProvider::class, 'classify'))->getNumberOfParameters() >= 6) {
        $this->markTestSkipped('This laravel/ai version does not pass classification attachments.');
    }

    Http::fake(['api.cloudflare.com/*' => Http::response(clefResponse())]);

    $png = base64_encode('png-bytes');

    Classification::of('A product photo.', [
        Image::fromBase64($png, 'image/png'),
    ])->question('damaged', new Boolean('Is the product damaged?'))
        ->classify(provider: 'workers-ai');

    Http::assertSent(function (Request $request) use ($png): bool {
        $body = $request->data();

        return $body['state'] === 'A product photo.'
            && $body['images'] === [['content_type' => 'image/png', 'base64' => $png]];
    });
});

test('a fifth image is rejected before a request', function () {
    if ((new ReflectionMethod(Classification::class, 'of'))->getNumberOfParameters() < 2) {
        $this->markTestSkipped('Classification attachments arrived after laravel/ai 1.0.');
    }

    if (! method_exists(Image::class, 'fromBase64')) {
        $this->markTestSkipped('Needs image attachments.');
    }

    Http::fake();

    $images = array_fill(0, 5, Image::fromBase64(base64_encode('x'), 'image/png'));

    expect(fn () => Classification::of('Photos.', $images)
        ->question('damaged', new Boolean('Damaged?'))
        ->classify(provider: 'workers-ai'))
        ->toThrow(InvalidArgumentException::class, 'at most 4');

    Http::assertNothingSent();
});

test('classification does not send the request to the chat completions endpoint', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(clefResponse())]);

    classifyTicket();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'chat/completions'));
});

test('a text prompt still uses chat completions, not the run endpoint', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    agent()->prompt('Hello', provider: 'workers-ai');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/ai/v1/chat/completions'));
});

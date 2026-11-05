<?php

use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Providers\ClassificationProvider;
use Laravel\Ai\Files\Image;
use Meirdick\WorkersAi\Extraction;
use Meirdick\WorkersAi\Extraction\DateField;
use Meirdick\WorkersAi\Extraction\Estimate;
use Meirdick\WorkersAi\Extraction\Forecast;
use Meirdick\WorkersAi\Extraction\ListField;
use Meirdick\WorkersAi\Extraction\Number;
use Meirdick\WorkersAi\Extraction\Text;

use function Laravel\Ai\agent;

/*
|--------------------------------------------------------------------------
| Live Clef, Clairic and third-party routing
|--------------------------------------------------------------------------
|
| These assert parsed answers, not a 200. A wrapped or unexpected reply fails
| here. Skipped without credentials. The third-party tests also need
| WORKERS_AI_E2E_THIRD_PARTY_MODEL (such as openai/gpt-4.1-mini) and spend
| AI Gateway credits.
*/

const INVOICE = 'Invoice INV-2291 from Northwind Supply, issued 2026-09-02, net 30. 4 pallets at $250.00 each, shipping $80.00. Contact: Dana Ruiz, Priya Shah.';

/** A 64x64 solid red PNG, built without ext-gd. */
function solidRedPng(): string
{
    $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $rows = str_repeat("\0".str_repeat("\xFF\x00\x00", 64), 64);

    return "\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NNCCCCC', 64, 64, 8, 2, 0, 0, 0))
        .$chunk('IDAT', gzcompress($rows))
        .$chunk('IEND', '');
}

function assertClefAnswers(): void
{
    if (! interface_exists(ClassificationProvider::class)) {
        test()->markTestSkipped('Classification arrived in laravel/ai 1.0.');
    }

    $response = Classification::of('Checkout has been failing for every customer for the last hour.')
        ->questions([
            'urgent' => new Boolean('Is this support request urgent?'),
            'team' => new Choice('Which team should handle this request?', [
                'billing' => 'Payments, invoices, and refunds',
                'technical' => 'Outages, errors, and configuration',
            ]),
            'severity' => new Score('How severe is the customer impact?', ['No impact', 'Minor', 'Major', 'Critical']),
        ])
        ->classify(provider: 'workers-ai', model: '@cf/cloudflare/clef-flash');

    expect($response['urgent']->probability)->toBeGreaterThan(0.5)
        ->and($response['team']->choice)->toBe('technical')
        ->and($response['severity']->score)->toBeGreaterThan(1.0)
        ->and($response->usage->inputTokens)->toBeGreaterThan(0);
}

function assertClairicAnswers(): void
{
    $invoice = Extraction::of(INVOICE)
        ->fields([
            'invoice_number' => new Text,
            'due_date' => DateField::date('Issue date plus the net payment days'),
            'total' => new Number(instructions: 'Amount due including shipping', decimals: 2, unit: 'USD'),
            'contacts' => ListField::of('text'),
            'po_number' => new Text(instructions: 'Purchase order number'),
            'days_to_pay' => new Estimate(min: 0, max: 120, instructions: 'Days after issue until this invoice is paid', step: 1),
        ])
        ->extract();

    expect($invoice['invoice_number']->value())->toBe('INV-2291')
        ->and($invoice['invoice_number']->span())->toBe([8, 16])
        ->and($invoice['due_date']->value())->toBe('2026-10-02')
        ->and((float) $invoice['total']->value())->toBe(1080.0)
        ->and($invoice['contacts']->value())->toContain('Dana Ruiz')
        ->and($invoice['po_number']->value())->toBeNull()
        ->and($invoice['days_to_pay']->median())->not->toBeNull()
        ->and($invoice->usage->toArray())->not->toBeEmpty();
}

describe('direct', function () {
    beforeEach(fn () => configureLiveProvider());

    test('clef answers', fn () => assertClefAnswers())
        ->skip(fn () => e2eCredentials() === null, 'Set WORKERS_AI_E2E_TOKEN and WORKERS_AI_E2E_ACCOUNT.');

    test('clairic answers', fn () => assertClairicAnswers())
        ->skip(fn () => e2eCredentials() === null, 'Set WORKERS_AI_E2E_TOKEN and WORKERS_AI_E2E_ACCOUNT.');

    test('a third-party model answers with one Cloudflare token', function () {
        $response = agent('Reply with one word.')->prompt('Say pong.', provider: 'workers-ai', model: env('WORKERS_AI_E2E_THIRD_PARTY_MODEL'));

        expect($response->text)->not->toBeEmpty();
    })->skip(fn () => e2eCredentials() === null || ! env('WORKERS_AI_E2E_THIRD_PARTY_MODEL'), 'Set WORKERS_AI_E2E_THIRD_PARTY_MODEL.');
});

describe('through a gateway', function () {
    beforeEach(function () {
        configureLiveProvider(['gateway' => env('WORKERS_AI_E2E_GATEWAY'), 'gateway_token' => env('WORKERS_AI_E2E_GATEWAY_TOKEN')]);
    });

    test('clef answers', fn () => assertClefAnswers())
        ->skip(fn () => e2eCredentials() === null || ! env('WORKERS_AI_E2E_GATEWAY'), 'Set WORKERS_AI_E2E_GATEWAY.');

    test('clairic answers', fn () => assertClairicAnswers())
        ->skip(fn () => e2eCredentials() === null || ! env('WORKERS_AI_E2E_GATEWAY'), 'Set WORKERS_AI_E2E_GATEWAY.');

    test('a third-party model on compat fails with a Cloudflare key', function () {
        expect(fn () => agent('Reply with one word.')->prompt('Say pong.', provider: 'workers-ai', model: env('WORKERS_AI_E2E_THIRD_PARTY_MODEL')))
            ->toThrow(\Illuminate\Http\Client\RequestException::class, '401');
    })->skip(fn () => e2eCredentials() === null || ! env('WORKERS_AI_E2E_GATEWAY') || ! env('WORKERS_AI_E2E_THIRD_PARTY_MODEL'), 'Set WORKERS_AI_E2E_GATEWAY and WORKERS_AI_E2E_THIRD_PARTY_MODEL.');
});

describe('gateway id header on the direct API', function () {
    beforeEach(function () {
        configureLiveProvider(['headers' => ['cf-aig-gateway-id' => env('WORKERS_AI_E2E_GATEWAY')]]);
    });

    test('a Workers AI text model answers with one token', function () {
        $response = agent('Reply with one word.')->prompt('Say pong.', provider: 'workers-ai', model: '@cf/meta/llama-3.2-3b-instruct');

        expect($response->text)->not->toBeEmpty();
    })->skip(fn () => e2eCredentials() === null || ! env('WORKERS_AI_E2E_GATEWAY'), 'Set WORKERS_AI_E2E_GATEWAY.');
});

describe('other live model behavior', function () {
    beforeEach(fn () => configureLiveProvider());

    test('every clef model answers, and Str::decide reaches clef', function () {
        if (! interface_exists(ClassificationProvider::class)) {
            $this->markTestSkipped('Classification arrived in laravel/ai 1.0.');
        }

        foreach (['@cf/cloudflare/clef', '@cf/cloudflare/clef-flash', '@cf/cloudflare/clef-omni'] as $model) {
            $response = Classification::of('Checkout is down for every customer.')
                ->question('urgent', new Boolean('Is this urgent?'))
                ->classify(provider: 'workers-ai', model: $model);

            expect($response['urgent']->isTrue())->toBeTrue();
        }

        config(['ai.default_for_classification' => 'workers-ai']);

        expect(Str::decide('Checkout is down for every customer.', 'Is this urgent?'))->toBeTrue();
    })->skip(fn () => e2eCredentials() === null, 'Set WORKERS_AI_E2E_TOKEN and WORKERS_AI_E2E_ACCOUNT.');

    test('images reach clef and clairic', function () {
        if (! method_exists(Image::class, 'fromBase64')) {
            $this->markTestSkipped('Needs image attachments.');
        }

        $png = base64_encode(solidRedPng());

        $invoice = Extraction::of('', [Image::fromBase64($png, 'image/png')])
            ->field('color', new Text(instructions: 'The main color of the image'))
            ->extract();

        expect($invoice)->toHaveCount(1);

        if (class_exists(Classification::class) && (new ReflectionMethod(Classification::class, 'of'))->getNumberOfParameters() >= 2) {
            $response = Classification::of('An image.', [Image::fromBase64($png, 'image/png')])
                ->question('red', new Boolean('Is the image red?'))
                ->classify(provider: 'workers-ai');

            expect($response['red']->probability)->toBeGreaterThan(0.5);
        }
    })->skip(fn () => e2eCredentials() === null, 'Set WORKERS_AI_E2E_TOKEN and WORKERS_AI_E2E_ACCOUNT.');

    test('state text, forecast, duration and an error status', function () {
        $response = Extraction::of('Monthly sales: Jan 10, Feb 12, Mar 14, Apr 16. The trial lasted two weeks.')
            ->fields([
                'next' => new Forecast(min: 0, max: 100, horizon: 2, interval: 'month', instructions: 'Monthly sales'),
                'trial' => DateField::duration('Length of the trial', 'days'),
            ])
            ->withProviderOptions(['options' => ['return_state_text' => true]])
            ->extract();

        expect($response->stateText)->toContain('Monthly sales')
            ->and($response['next']->median())->toHaveCount(2)
            ->and($response['next']->steps())->toHaveCount(2)
            ->and((int) $response['trial']->value())->toBe(14);

        expect(fn () => Extraction::of('x')->field('a', new Text)->extract(model: '@cf/meta/llama-3.2-3b-instruct'))
            ->toThrow(InvalidArgumentException::class);
    })->skip(fn () => e2eCredentials() === null, 'Set WORKERS_AI_E2E_TOKEN and WORKERS_AI_E2E_ACCOUNT.');
});

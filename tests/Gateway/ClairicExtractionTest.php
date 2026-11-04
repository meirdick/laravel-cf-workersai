<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Files\Image;
use Meirdick\WorkersAi\Extraction;
use Meirdick\WorkersAi\Extraction\DateField;
use Meirdick\WorkersAi\Extraction\Estimate;
use Meirdick\WorkersAi\Extraction\ListField;
use Meirdick\WorkersAi\Extraction\Number;
use Meirdick\WorkersAi\Extraction\Text;
use Meirdick\WorkersAi\Responses\ExtractionResponse;

beforeEach(function () {
    config(['ai.providers.workers-ai' => [
        ...config('ai.providers.workers-ai'),
        'key' => 'test-key',
        'account_id' => 'acct123',
        'name' => 'workers-ai',
    ]]);
});

function clairicResponse(): array
{
    return [
        'model' => 'clairic',
        'answers' => [
            'invoice_number' => [
                'type' => 'text',
                'p_null' => 3.8e-10,
                'span' => [8, 16],
                'guess' => 'INV-2291',
                'confidence' => 1,
                'value' => 'INV-2291',
            ],
            'total' => [
                'type' => 'number',
                'p_null' => 0.02,
                'guess' => 1080,
                'confidence' => 0.98,
                'value' => 1080,
            ],
            'po_number' => [
                'type' => 'text',
                'p_null' => 1,
                'confidence' => 0.99,
                'value' => null,
            ],
            'days_to_pay' => [
                'type' => 'estimate',
                'value' => 30,
                'mean' => 29.535,
                'median' => 30,
                'p10' => 21,
                'p90' => 38,
            ],
        ],
        'truncated' => false,
        'usage' => [
            'state_tokens' => 61,
            'input_tokens' => 655,
            'output_tokens' => 0,
        ],
    ];
}

function invoiceFields(): array
{
    return [
        'invoice_number' => new Text,
        'total' => new Number(instructions: 'Amount due including shipping', decimals: 2, unit: 'USD'),
        'po_number' => new Text(instructions: 'Purchase order number'),
        'days_to_pay' => new Estimate(min: 0, max: 120, instructions: 'Days until paid', step: 1),
    ];
}

test('extraction posts fields to the clairic run endpoint and reads typed answers', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(clairicResponse())]);

    $response = Extraction::of('Invoice INV-2291 from Northwind.')
        ->fields(invoiceFields())
        ->referenceDate('2026-11-03')
        ->extract();

    expect($response)->toBeInstanceOf(ExtractionResponse::class)
        ->and($response['invoice_number']->value())->toBe('INV-2291')
        ->and($response['invoice_number']->span())->toBe([8, 16])
        ->and($response['invoice_number']->isPresent())->toBeTrue()
        ->and($response['total']->value())->toBe(1080)
        ->and($response['po_number']->isNull())->toBeTrue()
        ->and($response['po_number']->isPresent())->toBeFalse()
        ->and($response['days_to_pay']->median())->toBe(30)
        ->and($response['days_to_pay']->p10())->toBe(21)
        ->and($response->truncated)->toBeFalse()
        ->and($response->meta->model)->toBe('clairic');

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->url() === 'https://api.cloudflare.com/client/v4/accounts/acct123/ai/run/@cf/cloudflare/clairic'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $body['model'] === 'clairic'
            && $body['state'] === 'Invoice INV-2291 from Northwind.'
            && $body['reference_date'] === '2026-11-03'
            && $body['fields']['invoice_number'] === ['type' => 'text']
            && $body['fields']['total'] === [
                'type' => 'number',
                'instructions' => 'Amount due including shipping',
                'unit' => 'USD',
                'decimals' => 2,
            ]
            && $body['fields']['days_to_pay']['type'] === 'estimate'
            && $body['fields']['days_to_pay']['min'] === 0
            && $body['fields']['days_to_pay']['max'] === 120
            && ! array_key_exists('images', $body);
    });
});

test('a gateway slug is sent as a header and the default model is clairic', function () {
    config(['ai.providers.workers-ai.gateway' => 'production-llm']);

    Http::fake(['api.cloudflare.com/*' => Http::response(clairicResponse())]);

    Extraction::of('Invoice INV-2291.')->field('invoice_number', new Text)->extract();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('cf-aig-gateway-id', 'production-llm')
        && str_ends_with($request->url(), '/ai/run/@cf/cloudflare/clairic'));
});

test('provider options cannot replace the fields the call built', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(clairicResponse())]);

    Extraction::of('Invoice INV-2291.')
        ->field('invoice_number', new Text)
        ->withProviderOptions([
            'model' => 'not-clairic',
            'fields' => ['stolen' => ['type' => 'text']],
            'options' => ['return_state_text' => true],
        ])
        ->extract();

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $body['model'] === 'clairic'
            && array_keys($body['fields']) === ['invoice_number']
            && $body['options'] === ['return_state_text' => true];
    });
});

test('a list field and a date field keep their type keys', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(clairicResponse())]);

    Extraction::of('Contact Dana Ruiz.')
        ->fields([
            'contacts' => ListField::of('text'),
            'due_date' => DateField::date('Issue date plus the net payment days'),
        ])
        ->extract();

    Http::assertSent(function (Request $request): bool {
        $fields = $request->data()['fields'];

        return $fields['contacts'] === ['type' => 'list', 'items' => 'text']
            && $fields['due_date'] === [
                'type' => 'date',
                'instructions' => 'Issue date plus the net payment days',
            ];
    });
});

test('an empty state with no image is rejected before a request', function () {
    Http::fake();

    expect(fn () => Extraction::of('   ')->field('total', new Number)->extract())
        ->toThrow(InvalidArgumentException::class, 'non-empty state');

    Http::assertNothingSent();
});

test('extracting with no fields is rejected before a request', function () {
    Http::fake();

    expect(fn () => Extraction::of('Invoice INV-2291.')->extract())
        ->toThrow(InvalidArgumentException::class, 'at least one field');

    Http::assertNothingSent();
});

test('a chat model is rejected before a clairic request', function () {
    Http::fake();

    expect(fn () => Extraction::of('Invoice INV-2291.')
        ->field('total', new Number)
        ->extract(model: '@cf/meta/llama-3.2-3b-instruct'))
        ->toThrow(InvalidArgumentException::class, 'not Clairic');

    Http::assertNothingSent();
});

test('an error envelope on a 200 throws and names the message', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response([
        'errors' => [['code' => 5006, 'message' => 'Model not available']],
    ], 200)]);

    expect(fn () => Extraction::of('Invoice INV-2291.')->field('total', new Number)->extract())
        ->toThrow(AiException::class, 'Model not available');
});

test('images are objects on the images field and a blank state is allowed with one', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(clairicResponse())]);

    $png = base64_encode('png-bytes');

    Extraction::of('', [Image::fromBase64($png, 'image/png')])
        ->field('total', new Number)
        ->extract();

    Http::assertSent(fn (Request $request): bool => $request->data()['state'] === ''
        && $request->data()['images'] === [['content_type' => 'image/png', 'base64' => $png]]);
});

test('a compat url cannot host a clairic run', function () {
    config(['ai.providers.workers-ai' => [
        'driver' => 'workers-ai',
        'key' => 'test-key',
        'url' => 'https://gateway.ai.cloudflare.com/v1/acct123/my-gateway/compat',
        'name' => 'workers-ai',
    ]]);

    Http::fake();

    expect(fn () => Extraction::of('Invoice INV-2291.')->field('total', new Number)->extract())
        ->toThrow(InvalidArgumentException::class, '/compat');

    Http::assertNothingSent();
});

test('the response serializes without the raw HTTP response', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(clairicResponse())]);

    $response = Extraction::of('Invoice INV-2291.')->field('invoice_number', new Text)->extract();

    $restored = unserialize(serialize($response));

    expect($restored['invoice_number']->value())->toBe('INV-2291')
        ->and($restored->raw)->toBeNull();
});

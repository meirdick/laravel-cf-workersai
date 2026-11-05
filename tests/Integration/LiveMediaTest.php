<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Audio;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Image;
use Laravel\Ai\Reranking;
use Laravel\Ai\Transcription;
use Meirdick\WorkersAi\Events\WorkersAiUsageReported;
use Meirdick\WorkersAi\Markdown;
use Meirdick\WorkersAi\Tools\CloudflareWebSearch;

use function Laravel\Ai\agent;

/*
|--------------------------------------------------------------------------
| Live reranking, images, speech, transcription, documents and web search
|--------------------------------------------------------------------------
|
| These assert real output: ranked documents, decoded image and audio bytes,
| words in a transcript, text from a PDF. Skipped without credentials. Set
| WORKERS_AI_E2E_ARTIFACTS to a directory to keep the generated files. Web
| search also needs WORKERS_AI_E2E_WEB_SEARCH=1, and spends AI Gateway credits.
*/

function keepArtifact(string $name, string $bytes): void
{
    if ($directory = getenv('WORKERS_AI_E2E_ARTIFACTS')) {
        file_put_contents(rtrim($directory, '/').'/'.$name, $bytes);
    }
}

/** A one-page PDF with the text "Invoice INV-77 total 42 USD", built by hand. */
function minimalPdf(): string
{
    $stream = 'BT /F1 18 Tf 72 700 Td (Invoice INV-77 total 42 USD) Tj ET';
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [];

    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
    }

    $xref = strlen($pdf);
    $pdf .= 'xref
0 '.(count($objects) + 1)."\n0000000000 65535 f \n";

    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
}

/** A 64x64 solid blue PNG, built without ext-gd. */
function solidBluePng(): string
{
    $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return "\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NNCCCCC', 64, 64, 8, 2, 0, 0, 0))
        .$chunk('IDAT', gzcompress(str_repeat("\0".str_repeat("\x00\x00\xFF", 64), 64)))
        .$chunk('IEND', '');
}

function assertRealImage(string $bytes, string $name): void
{
    $info = getimagesizefromstring($bytes);

    expect($info)->toBeArray("{$name} did not decode as an image");

    keepArtifact($name.image_type_to_extension($info[2]), $bytes);
}

function liveRerank(): void
{
    $response = Reranking::of(['A hammer is a tool.', 'An apple is a fruit.', 'A car has wheels.'])
        ->limit(2)
        ->rerank('Which one is food?', provider: 'workers-ai');

    expect($response->first()->document)->toBe('An apple is a fruit.')
        ->and($response->first()->index)->toBe(1)
        ->and($response->first()->score)->toBeGreaterThan(0.0)
        ->and($response)->toHaveCount(2);
}

function liveSpeechBytes(): string
{
    $response = Audio::of('Hello there. The invoice total is forty two dollars.')->generate(provider: 'workers-ai');

    return (string) $response;
}

$noCredentials = fn () => e2eCredentials() === null;
$noGateway = fn () => e2eCredentials() === null || ! env('WORKERS_AI_E2E_GATEWAY');

describe('direct', function () use ($noCredentials) {
    beforeEach(fn () => configureLiveProvider());

    test('reranking orders documents', fn () => liveRerank())->skip($noCredentials, 'Needs credentials.');

    test('reranking reports neurons, and Collection::rerank works', function () {
        $seen = [];
        Event::listen(WorkersAiUsageReported::class, function ($event) use (&$seen) {
            $seen[] = $event;
        });

        $ranked = (new Collection(['A hammer.', 'An apple.']))->rerank(fn (string $item) => $item, 'Which is a fruit?', provider: 'workers-ai');

        expect($ranked->first())->toBe('An apple.')
            ->and($seen)->toHaveCount(1)
            ->and($seen[0]->neurons)->toBeGreaterThan(0.0);
    })->skip($noCredentials, 'Needs credentials.');

    test('clef and clairic report neurons', function () {
        if (! interface_exists(\Laravel\Ai\Contracts\Providers\ClassificationProvider::class)) {
            $this->markTestSkipped('Classification arrived in laravel/ai 1.0.');
        }

        $seen = [];
        Event::listen(WorkersAiUsageReported::class, function ($event) use (&$seen) {
            $seen[$event->model] = $event->neurons;
        });

        \Laravel\Ai\Classification::of('Checkout is down.')
            ->question('urgent', new \Laravel\Ai\Classification\Boolean('Urgent?'))
            ->classify(provider: 'workers-ai', model: '@cf/cloudflare/clef-flash');

        \Meirdick\WorkersAi\Extraction::of('Invoice INV-1.')->field('id', new \Meirdick\WorkersAi\Extraction\Text)->extract();

        expect($seen['@cf/cloudflare/clef-flash'] ?? 0)->toBeGreaterThan(0.0)
            ->and($seen['@cf/cloudflare/clairic'] ?? 0)->toBeGreaterThan(0.0);
    })->skip($noCredentials, 'Needs credentials.');

    test('flux-2 draws at the asked size', function () {
        $image = Image::of('A red square on a white background.')->size('512x384')->quality('low')->generate(provider: 'workers-ai');

        $bytes = (string) $image;
        assertRealImage($bytes, 'flux2');

        expect(getimagesizefromstring($bytes)[0])->toBe(512)
            ->and(getimagesizefromstring($bytes)[1])->toBe(384)
            ->and($image->images->first()->mime)->toStartWith('image/');
    })->skip($noCredentials, 'Needs credentials.');

    test('flux-2 takes a reference image', function () {
        $image = Image::of('Make this image a circle of the same color.')
            ->attachments([ImageFile::fromBase64(base64_encode(solidBluePng()), 'image/png')])
            ->size('512x512')
            ->quality('low')
            ->generate(provider: 'workers-ai');

        assertRealImage((string) $image, 'flux2-reference');
    })->skip($noCredentials, 'Needs credentials.');

    test('flux-1-schnell, stable diffusion and lucid return images', function () {
        $drawn = [];

        foreach ([
            'schnell' => '@cf/black-forest-labs/flux-1-schnell',
            'sdxl-lightning' => '@cf/bytedance/stable-diffusion-xl-lightning',
            'lucid' => '@cf/leonardo/lucid-origin',
            'phoenix' => '@cf/leonardo/phoenix-1.0',
        ] as $name => $model) {
            $pending = Image::of('A green apple on a wooden table.')->quality('low');

            if ($name !== 'schnell') {
                $pending->size('512x512');
            }

            // Cloudflare's content filter sometimes rejects this harmless prompt.
            // That is the filter, not the request shape, so note it and go on.
            try {
                assertRealImage((string) $pending->generate(provider: 'workers-ai', model: $model), $name);
                $drawn[] = $name;
            } catch (\Illuminate\Http\Client\RequestException $exception) {
                if (! str_contains($exception->response->body(), 'NSFW')) {
                    throw $exception;
                }

                fwrite(STDERR, "NSFW-REJECTED {$model}\n");
            }
        }

        expect($drawn)->not->toBeEmpty();
    })->skip($noCredentials, 'Needs credentials.');

    test('speech returns audio that transcribes back to the words', function () {
        $seen = [];
        Event::listen(WorkersAiUsageReported::class, function ($event) use (&$seen) {
            $seen[] = $event;
        });

        $bytes = liveSpeechBytes();
        keepArtifact('aura.mp3', $bytes);

        expect(strlen($bytes))->toBeGreaterThan(1000)
            ->and($seen)->toHaveCount(1);

        $turbo = Transcription::fromBase64(base64_encode($bytes), 'audio/mpeg')->language('en')->generate(provider: 'workers-ai');

        // Models mishear words in synthetic speech, so assert a word they all
        // got right. Whisper turbo once wrote "in-place" for "invoice".
        expect(strtolower($turbo->text))->toContain('total')
            ->and($turbo->segments)->not->toBeEmpty();

        $nova = Transcription::fromBase64(base64_encode($bytes), 'audio/mpeg')->diarize()->generate(provider: 'workers-ai', model: '@cf/deepgram/nova-3');

        expect(strtolower($nova->text))->toContain('total')
            ->and($nova->segments->first()->speaker)->toBe('speaker_0');

        $tiny = Transcription::fromBase64(base64_encode($bytes), 'audio/mpeg')->generate(provider: 'workers-ai', model: '@cf/openai/whisper-tiny-en');

        expect(strtolower($tiny->text))->toContain('total');
    })->skip($noCredentials, 'Needs credentials.');

    test('a named voice and melotts return audio', function () {
        expect(strlen((string) Audio::of('Hello.')->voice('asteria')->generate(provider: 'workers-ai')))->toBeGreaterThan(500)
            ->and(strlen((string) Audio::of('Hello.')->male()->generate(provider: 'workers-ai')))->toBeGreaterThan(500)
            ->and(strlen((string) Audio::of('Hola.')->female()->generate(provider: 'workers-ai', model: '@cf/deepgram/aura-2-es')))->toBeGreaterThan(500)
            ->and(strlen((string) Audio::of('Hello.')->generate(provider: 'workers-ai', model: '@cf/myshell-ai/melotts')))->toBeGreaterThan(500);

        if (method_exists(\Laravel\Ai\PendingResponses\PendingAudioGeneration::class, 'withProviderOptions')) {
            expect(strlen((string) Audio::of('Bonjour.')->withProviderOptions(['lang' => 'fr'])->generate(provider: 'workers-ai', model: '@cf/myshell-ai/melotts')))->toBeGreaterThan(500);
        }
    })->skip($noCredentials, 'Needs credentials.');

    test('Markdown::of reads a real pdf', function () {
        $text = Markdown::of(Document::fromString(minimalPdf(), 'application/pdf'))->convert();

        keepArtifact('pdf.md', $text);

        expect($text)->toContain('INV-77');
    })->skip($noCredentials, 'Needs credentials.');

    test('convert_documents lets a chat model read a pdf', function () {
        configureLiveProvider(['convert_documents' => true]);

        $response = agent('Answer with the invoice number only.')->prompt(
            'What is the invoice number in the attached document?',
            attachments: [Document::fromString(minimalPdf(), 'application/pdf')],
            provider: 'workers-ai',
            model: '@cf/openai/gpt-oss-120b',
        );

        expect($response->text)->toContain('77');
    })->skip($noCredentials, 'Needs credentials.');

    test('a named gateway web search needs a stored key, and the message says so', function () {
        expect(fn () => (new CloudflareWebSearch(limit: 1, gateway: env('WORKERS_AI_E2E_GATEWAY')))->search('Cloudflare Workers AI'))
            ->toThrow(\Laravel\Ai\Exceptions\AiException::class, 'provider key');
    })->skip(fn () => e2eCredentials() === null || ! env('WORKERS_AI_E2E_GATEWAY'), 'Needs a gateway.');

    test('web search returns results, and a model uses the tool', function () {
        $items = (new CloudflareWebSearch(limit: 2))->search('Cloudflare Workers AI');

        expect($items)->not->toBeEmpty()
            ->and($items[0]['url'])->toStartWith('http');

        $response = agent('Use the search tool, then answer in one short sentence.', tools: [new CloudflareWebSearch(limit: 2)])
            ->prompt('Search the web for what Cloudflare Workers AI is.', provider: 'workers-ai', model: '@cf/openai/gpt-oss-120b');

        expect($response->text)->not->toBeEmpty()
            ->and($response->toolCalls ?? collect())->not->toBeEmpty();
    })->skip(fn () => e2eCredentials() === null || ! env('WORKERS_AI_E2E_WEB_SEARCH'), 'Set WORKERS_AI_E2E_WEB_SEARCH=1. Spends AI Gateway credits.');
});

describe('through a gateway', function () use ($noGateway) {
    beforeEach(fn () => configureLiveProvider(['gateway' => env('WORKERS_AI_E2E_GATEWAY'), 'gateway_token' => env('WORKERS_AI_E2E_GATEWAY_TOKEN')]));

    test('reranking orders documents', fn () => liveRerank())->skip($noGateway, 'Set WORKERS_AI_E2E_GATEWAY.');

    test('images, speech and transcription work', function () {
        assertRealImage((string) Image::of('A red square.')->size('512x512')->quality('low')->generate(provider: 'workers-ai'), 'gateway-flux2');

        $bytes = liveSpeechBytes();

        expect(strtolower(Transcription::fromBase64(base64_encode($bytes), 'audio/mpeg')->generate(provider: 'workers-ai')->text))->toContain('total');
    })->skip($noGateway, 'Set WORKERS_AI_E2E_GATEWAY.');

    test('markdown conversion works with a gateway configured', function () {
        expect(Markdown::of(Document::fromString(minimalPdf(), 'application/pdf'))->convert())->toContain('INV-77');
    })->skip($noGateway, 'Set WORKERS_AI_E2E_GATEWAY.');
});

describe('gateway id header on the direct API', function () use ($noGateway) {
    beforeEach(fn () => configureLiveProvider(['headers' => ['cf-aig-gateway-id' => env('WORKERS_AI_E2E_GATEWAY')]]));

    test('reranking and flux-2 work', function () {
        liveRerank();

        assertRealImage((string) Image::of('A red square.')->size('512x512')->quality('low')->generate(provider: 'workers-ai'), 'header-flux2');
    })->skip($noGateway, 'Set WORKERS_AI_E2E_GATEWAY.');
});

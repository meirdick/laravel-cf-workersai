<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Audio;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Image;
use Laravel\Ai\Reranking;
use Laravel\Ai\Transcription;
use Meirdick\WorkersAi\Cloudflare\SdkCompat;
use Meirdick\WorkersAi\Events\WorkersAiUsageReported;
use Meirdick\WorkersAi\Exceptions\GatewayTimeoutException;

const RUN_URL = 'https://api.cloudflare.com/client/v4/accounts/acct123/ai/run/';

beforeEach(function () {
    config(['ai.providers.workers-ai' => [
        'driver' => 'workers-ai',
        'key' => 'test-key',
        'account_id' => 'acct123',
        'name' => 'workers-ai',
        'retry' => false,
    ]]);
});

function runEnvelope(array $result): array
{
    return ['result' => $result, 'success' => true, 'errors' => [], 'messages' => []];
}

/*
 * Reranking
 */

function rerankReply(): array
{
    return runEnvelope([
        'response' => [['id' => 1, 'score' => 0.9], ['id' => 0, 'score' => 0.1]],
        'usage' => ['prompt_tokens' => 32, 'neurons' => 0.25],
    ]);
}

test('reranking posts contexts and returns documents by score', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(rerankReply())]);

    $response = Reranking::of(['A hammer.', 'An apple.'])->limit(2)->rerank('fruit?', provider: 'workers-ai');

    expect($response->first()->document)->toBe('An apple.')
        ->and($response->first()->index)->toBe(1)
        ->and($response->first()->score)->toBe(0.9)
        ->and($response->documents()->all())->toBe(['An apple.', 'A hammer.']);

    Http::assertSent(fn (Request $request): bool => $request->url() === RUN_URL.'@cf/baai/bge-reranker-base'
        && $request->data()['query'] === 'fruit?'
        && $request->data()['contexts'] === [['text' => 'A hammer.'], ['text' => 'An apple.']]
        && $request->data()['top_k'] === 2);
});

test('reranking reports input tokens on laravel/ai 1.x', function () {
    if (! SdkCompat::isV1()) {
        $this->markTestSkipped('0.x reranking responses have no usage.');
    }

    Http::fake(['api.cloudflare.com/*' => Http::response(rerankReply())]);

    expect(Reranking::of(['a', 'b'])->rerank('q', provider: 'workers-ai')->usage->inputTokens)->toBe(32);
});

test('Collection::rerank reaches workers-ai', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(rerankReply())]);

    $ranked = (new Collection([['name' => 'A hammer.'], ['name' => 'An apple.']]))
        ->rerank('name', 'fruit?', provider: 'workers-ai');

    expect($ranked->first()['name'])->toBe('An apple.');
});

test('Reranking::fake replaces the gateway', function () {
    Reranking::fake();
    Http::fake();

    Reranking::of(['a', 'b'])->rerank('q', provider: 'workers-ai');

    Reranking::assertReranked(fn ($prompt) => $prompt->query === 'q');
    Http::assertNothingSent();
});

test('run capabilities report neurons from the reply', function () {
    Event::fake([WorkersAiUsageReported::class]);
    Http::fake(['api.cloudflare.com/*' => Http::response(rerankReply())]);

    Reranking::of(['a', 'b'])->rerank('q', provider: 'workers-ai');

    Event::assertDispatched(WorkersAiUsageReported::class, fn (WorkersAiUsageReported $event) => $event->neurons === 0.25
        && $event->model === '@cf/baai/bge-reranker-base'
        && $event->promptTokens === 32);
});

test('a reply with no rankings throws', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(runEnvelope(['usage' => []]))]);

    expect(fn () => Reranking::of(['a', 'b'])->rerank('q', provider: 'workers-ai'))
        ->toThrow(AiException::class, 'no rankings');
});

test('a 408 on a run capability is a gateway timeout', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response([], 408)]);

    expect(fn () => Reranking::of(['a', 'b'])->rerank('q', provider: 'workers-ai'))
        ->toThrow(GatewayTimeoutException::class);
});

test('a gateway slug routes a run capability with a header', function () {
    config(['ai.providers.workers-ai.gateway' => 'my-gateway']);
    Http::fake(['api.cloudflare.com/*' => Http::response(rerankReply())]);

    Reranking::of(['a', 'b'])->rerank('q', provider: 'workers-ai');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), RUN_URL)
        && $request->hasHeader('cf-aig-gateway-id', 'my-gateway'));
});

test('a compat url throws on a run capability', function () {
    config(['ai.providers.workers-ai' => [
        'driver' => 'workers-ai',
        'key' => 'test-key',
        'url' => 'https://gateway.ai.cloudflare.com/v1/a/g/compat',
        'name' => 'workers-ai',
    ]]);
    Http::fake();

    expect(fn () => Reranking::of(['a', 'b'])->rerank('q', provider: 'workers-ai'))
        ->toThrow(InvalidArgumentException::class, '/ai/run');
});

/*
 * Images
 */

test('flux-2 is the default and sends multipart', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(runEnvelope(['image' => base64_encode('png-bytes')]))]);

    $response = Image::of('a red square')->landscape()->quality('low')->generate(provider: 'workers-ai');

    expect((string) $response)->toBe('png-bytes')
        ->and($response->images)->toHaveCount(1);

    Http::assertSent(function (Request $request): bool {
        $parts = collect($request->data())->pluck('contents', 'name');

        return $request->url() === RUN_URL.'@cf/black-forest-labs/flux-2-klein-4b'
            && $request->isMultipart()
            && $parts['prompt'] === 'a red square'
            && $parts['width'] === '1248'
            && $parts['height'] === '832'
            && $parts['steps'] === '4';
    });
});

test('flux-2 sends reference images as input_image fields', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(runEnvelope(['image' => base64_encode('x')]))]);

    Image::of('style it')
        ->attachments([ImageFile::fromBase64(base64_encode('ref'), 'image/png')])
        ->generate(provider: 'workers-ai');

    Http::assertSent(function (Request $request): bool {
        $part = collect($request->data())->firstWhere('name', 'input_image_0');

        return $part !== null && $part['contents'] === 'ref';
    });
});

test('a fifth reference image throws before a request', function () {
    Http::fake();

    $image = ImageFile::fromBase64(base64_encode('ref'), 'image/png');

    expect(fn () => Image::of('x')->attachments(array_fill(0, 5, $image))->generate(provider: 'workers-ai'))
        ->toThrow(InvalidArgumentException::class, 'at most 4');

    Http::assertNothingSent();
});

test('flux-2 uses the gateway url when a gateway is set, because the header route rejects multipart', function () {
    config(['ai.providers.workers-ai.gateway' => 'my-gateway']);
    Http::fake(['*' => Http::response(runEnvelope(['image' => base64_encode('x')]))]);

    Image::of('x')->generate(provider: 'workers-ai');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://gateway.ai.cloudflare.com/v1/acct123/my-gateway/workers-ai/@cf/black-forest-labs/flux-2-klein-4b'
        && $request->isMultipart());
});

test('flux-2 also uses the gateway url with the gateway header in config', function () {
    config(['ai.providers.workers-ai.headers' => ['CF-AIG-Gateway-Id' => 'my-gateway']]);
    Http::fake(['*' => Http::response(runEnvelope(['image' => base64_encode('x')]))]);

    Image::of('x')->generate(provider: 'workers-ai');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://gateway.ai.cloudflare.com/v1/acct123/my-gateway/workers-ai/'));
});

test('a gateway token reaches flux-2 on the gateway url', function () {
    config(['ai.providers.workers-ai.gateway' => 'my-gateway', 'ai.providers.workers-ai.gateway_token' => 'gw-token']);
    Http::fake(['*' => Http::response(runEnvelope(['image' => base64_encode('x')]))]);

    Image::of('x')->generate(provider: 'workers-ai');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('cf-aig-authorization', 'Bearer gw-token'));
});

test('json image models stay on the run url with the gateway header', function () {
    config(['ai.providers.workers-ai.gateway' => 'my-gateway']);
    Http::fake(['*' => Http::response(runEnvelope(['image' => base64_encode('x')]))]);

    Image::of('x')->generate(provider: 'workers-ai', model: '@cf/black-forest-labs/flux-1-schnell');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), RUN_URL) && $request->hasHeader('cf-aig-gateway-id', 'my-gateway'));
});

test('flux-1-schnell sends json steps and caps them at 8', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(runEnvelope(['image' => base64_encode('x')]))]);

    Image::of('x')->quality('high')->generate(provider: 'workers-ai', model: '@cf/black-forest-labs/flux-1-schnell');

    Http::assertSent(fn (Request $request): bool => $request->data() === ['prompt' => 'x', 'steps' => 8]);
});

test('flux-1-schnell rejects a size, because Cloudflare rejects width', function () {
    Http::fake();

    expect(fn () => Image::of('x')->square()->generate(provider: 'workers-ai', model: '@cf/black-forest-labs/flux-1-schnell'))
        ->toThrow(InvalidArgumentException::class, 'fixed size');

    Http::assertNothingSent();
});

test('stable diffusion sends num_steps and reads binary bytes', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png', 'cf-ai-neurons' => '3.5'])]);
    Event::fake([WorkersAiUsageReported::class]);

    $response = Image::of('x')->square()->quality('medium')->generate(provider: 'workers-ai', model: '@cf/stabilityai/stable-diffusion-xl-base-1.0');

    expect((string) $response)->toBe('png-bytes')
        ->and($response->images->first()->mime)->toBe('image/png');

    Http::assertSent(fn (Request $request): bool => $request->data() === ['prompt' => 'x', 'width' => 1024, 'height' => 1024, 'num_steps' => 8]);
    Event::assertDispatched(WorkersAiUsageReported::class, fn (WorkersAiUsageReported $event) => $event->neurons === 3.5);
});

test('a model that does not take attachments throws', function () {
    Http::fake();

    expect(fn () => Image::of('x')
        ->attachments([ImageFile::fromBase64(base64_encode('r'), 'image/png')])
        ->generate(provider: 'workers-ai', model: '@cf/stabilityai/stable-diffusion-xl-base-1.0'))
        ->toThrow(InvalidArgumentException::class, 'does not take reference images');
});

test('a bad size throws and names the allowed values', function () {
    Http::fake();

    expect(fn () => Image::of('x')->size('huge')->generate(provider: 'workers-ai'))
        ->toThrow(InvalidArgumentException::class, 'WIDTHxHEIGHT');
});

test('Image::fake replaces the gateway', function () {
    Image::fake();
    Http::fake();

    Image::of('a cat')->generate(provider: 'workers-ai');

    Image::assertGenerated(fn ($prompt) => $prompt->contains('cat'));
    Http::assertNothingSent();
});

/*
 * Speech
 */

test('aura sends text and a speaker, and returns the bytes as base64', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response('mp3-bytes', 200, ['Content-Type' => 'audio/mpeg', 'cf-ai-neurons' => '1.25'])]);
    Event::fake([WorkersAiUsageReported::class]);

    $response = Audio::of('Hello.')->male()->generate(provider: 'workers-ai');

    expect((string) $response)->toBe('mp3-bytes')
        ->and($response->mime)->toBe('audio/mpeg');

    Http::assertSent(fn (Request $request): bool => $request->url() === RUN_URL.'@cf/deepgram/aura-2-en'
        && $request->data() === ['text' => 'Hello.', 'speaker' => 'orion']);
    Event::assertDispatched(WorkersAiUsageReported::class, fn (WorkersAiUsageReported $event) => $event->neurons === 1.25);
});

test('a named voice reaches aura, and the default female voice is luna', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response('x', 200, ['Content-Type' => 'audio/mpeg'])]);

    Audio::of('Hi.')->voice('asteria')->generate(provider: 'workers-ai');
    Audio::of('Hi.')->generate(provider: 'workers-ai');

    Http::assertSent(fn (Request $request): bool => $request->data()['speaker'] === 'asteria');
    Http::assertSent(fn (Request $request): bool => $request->data()['speaker'] === 'luna');
});

test('melotts sends a prompt and reads json audio', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(runEnvelope(['audio' => base64_encode('mp3')]))]);

    $response = Audio::of('Hi.')->generate(provider: 'workers-ai', model: '@cf/myshell-ai/melotts');

    expect((string) $response)->toBe('mp3');

    Http::assertSent(fn (Request $request): bool => $request->data() === ['prompt' => 'Hi.']);
});

test('Audio::fake replaces the gateway', function () {
    Audio::fake();
    Http::fake();

    Audio::of('Hello')->generate(provider: 'workers-ai');

    Audio::assertGenerated(fn ($prompt) => $prompt->contains('Hello'));
    Http::assertNothingSent();
});

/*
 * Transcription
 */

test('whisper turbo is the default and sends base64 json', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(runEnvelope([
        'text' => ' Hello there!',
        'segments' => [['start' => 0, 'end' => 0.5, 'text' => ' Hello there!']],
        'usage' => ['neurons' => 1.7],
    ]))]);

    $response = Transcription::fromBase64(base64_encode('mp3'), 'audio/mpeg')->language('en')->generate(provider: 'workers-ai');

    expect($response->text)->toBe('Hello there!')
        ->and($response->segments)->toHaveCount(1)
        ->and($response->segments->first()->text)->toBe('Hello there!')
        ->and($response->segments->first()->endSeconds)->toBe(0.5);

    Http::assertSent(fn (Request $request): bool => $request->url() === RUN_URL.'@cf/openai/whisper-large-v3-turbo'
        && $request->data() === ['audio' => base64_encode('mp3'), 'language' => 'en']);
});

test('nova sends the raw audio, options in the query, and groups speakers', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(runEnvelope(['results' => ['channels' => [['alternatives' => [[
        'transcript' => 'Hi. Hello.',
        'words' => [
            ['word' => 'hi', 'punctuated_word' => 'Hi.', 'start' => 0, 'end' => 1, 'speaker' => 0],
            ['word' => 'hello', 'punctuated_word' => 'Hello.', 'start' => 1, 'end' => 2, 'speaker' => 1],
        ],
    ]]]]]]))]);

    $response = Transcription::fromBase64(base64_encode('mp3'), 'audio/mpeg')
        ->diarize()
        ->generate(provider: 'workers-ai', model: '@cf/deepgram/nova-3');

    expect($response->text)->toBe('Hi. Hello.')
        ->and($response->segments)->toHaveCount(2)
        ->and($response->segments[0]->speaker)->toBe('speaker_0')
        ->and($response->segments[1]->speaker)->toBe('speaker_1')
        ->and($response->segments[1]->text)->toBe('Hello.');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '@cf/deepgram/nova-3?')
        && str_contains($request->url(), 'diarize=true')
        && str_contains($request->url(), 'smart_format=true')
        && $request->body() === 'mp3'
        && $request->hasHeader('Content-Type', 'audio/mpeg'));
});

test('whisper tiny sends the raw audio and maps words to segments', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(runEnvelope([
        'text' => ' Oh there.',
        'words' => [['word' => '', 'start' => 0, 'end' => 0], ['word' => 'Oh', 'start' => 0.1, 'end' => 0.2]],
    ]))]);

    $response = Transcription::fromBase64(base64_encode('mp3'), 'audio/mpeg')->generate(provider: 'workers-ai', model: '@cf/openai/whisper-tiny-en');

    expect($response->text)->toBe('Oh there.')->and($response->segments)->toHaveCount(1);

    Http::assertSent(fn (Request $request): bool => $request->body() === 'mp3');
});

test('diarize on a model with no speaker labels throws', function () {
    Http::fake();

    expect(fn () => Transcription::fromBase64(base64_encode('mp3'), 'audio/mpeg')->diarize()->generate(provider: 'workers-ai'))
        ->toThrow(InvalidArgumentException::class, 'nova-3');

    Http::assertNothingSent();
});

test('whisper tiny sends a json byte array through a gateway, because Cloudflare rejects a raw body there', function () {
    config(['ai.providers.workers-ai.gateway' => 'my-gateway']);
    Http::fake(['*' => Http::response(runEnvelope(['text' => 'Hi', 'words' => []]))]);

    Transcription::fromBase64(base64_encode('mp3'), 'audio/mpeg')->generate(provider: 'workers-ai', model: '@cf/openai/whisper-tiny-en');

    Http::assertSent(fn (Request $request): bool => $request->url() === RUN_URL.'@cf/openai/whisper-tiny-en'
        && $request->hasHeader('cf-aig-gateway-id', 'my-gateway')
        && $request->data() === ['audio' => [109, 112, 51]]);
});

test('whisper tiny with the gateway header in config also sends json', function () {
    config(['ai.providers.workers-ai.headers' => ['cf-aig-gateway-id' => 'my-gateway']]);
    Http::fake(['*' => Http::response(runEnvelope(['text' => 'Hi', 'words' => []]))]);

    Transcription::fromBase64(base64_encode('mp3'), 'audio/mpeg')->generate(provider: 'workers-ai', model: '@cf/openai/whisper');

    Http::assertSent(fn (Request $request): bool => $request->data() === ['audio' => [109, 112, 51]]);
});

test('nova throws with a gateway and names the fix, and does not skip the gateway', function () {
    Http::fake();

    foreach ([['gateway' => 'my-gateway'], ['headers' => ['cf-aig-gateway-id' => 'my-gateway']]] as $config) {
        config(['ai.providers.workers-ai' => ['driver' => 'workers-ai', 'key' => 'k', 'account_id' => 'acct123', 'name' => 'workers-ai', ...$config]]);
        app(\Laravel\Ai\AiManager::class)->forgetInstance('workers-ai');

        expect(fn () => Transcription::fromBase64(base64_encode('mp3'), 'audio/mpeg')->generate(provider: 'workers-ai', model: '@cf/deepgram/nova-3'))
            ->toThrow(InvalidArgumentException::class, 'cannot run through AI Gateway');
    }

    Http::assertNothingSent();
});

test('whisper turbo stays on the run url with the gateway header, because its body is json', function () {
    config(['ai.providers.workers-ai.gateway' => 'my-gateway']);
    Http::fake(['*' => Http::response(runEnvelope(['text' => 'Hi', 'segments' => []]))]);

    Transcription::fromBase64(base64_encode('mp3'), 'audio/mpeg')->generate(provider: 'workers-ai');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), RUN_URL) && $request->hasHeader('cf-aig-gateway-id', 'my-gateway'));
});

test('Transcription::fake replaces the gateway', function () {
    Transcription::fake(['Hello']);
    Http::fake();

    $response = Transcription::fromBase64(base64_encode('mp3'), 'audio/mpeg')->generate(provider: 'workers-ai');

    expect($response->text)->toBe('Hello');
    Http::assertNothingSent();
});

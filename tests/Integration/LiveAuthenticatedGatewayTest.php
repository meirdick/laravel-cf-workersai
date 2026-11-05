<?php

use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Image;
use Laravel\Ai\Reranking;
use Laravel\Ai\Transcription;
use Meirdick\WorkersAi\Extraction;
use Meirdick\WorkersAi\Extraction\Text;

use function Laravel\Ai\agent;

/*
|--------------------------------------------------------------------------
| Live Authenticated Gateway rules
|--------------------------------------------------------------------------
|
| Set WORKERS_AI_E2E_AUTH_GATEWAY to the slug of a gateway with Authenticated
| Gateway on, and WORKERS_AI_E2E_GATEWAY_TOKEN to its token.
*/

const AUTH_TEXT_MODEL = '@cf/meta/llama-3.2-3b-instruct';

$noAuthGateway = fn () => e2eCredentials() === null || ! getenv('WORKERS_AI_E2E_AUTH_GATEWAY') || ! getenv('WORKERS_AI_E2E_GATEWAY_TOKEN');

function authGatewayConfig(?string $token = null): array
{
    return ['gateway' => getenv('WORKERS_AI_E2E_AUTH_GATEWAY'), 'gateway_token' => $token];
}

function authHeaderConfig(?string $token = null): array
{
    return ['headers' => ['cf-aig-gateway-id' => getenv('WORKERS_AI_E2E_AUTH_GATEWAY')], 'gateway_token' => $token];
}

/** Base64 MP3 from the live speech model, which needs no gateway token. */
function authSpeech(): string
{
    return base64_encode((string) \Laravel\Ai\Audio::of('The total is forty two dollars.')->timeout(90)->generate(provider: 'workers-ai'));
}

test('text and embeddings need the gateway token', function () {
    configureLiveProvider(authGatewayConfig());

    expect(fn () => agent('Be brief.')->prompt('Say pong.', provider: 'workers-ai', model: AUTH_TEXT_MODEL))
        ->toThrow(RequestException::class, '401');

    expect(fn () => Embeddings::for(['hi'])->generate(provider: 'workers-ai', model: '@cf/baai/bge-base-en-v1.5'))
        ->toThrow(RequestException::class, '401');
})->skip($noAuthGateway, 'Set WORKERS_AI_E2E_AUTH_GATEWAY and WORKERS_AI_E2E_GATEWAY_TOKEN.');

test('a wrong gateway token is rejected', function () {
    configureLiveProvider(authGatewayConfig('not-a-real-token'));

    expect(fn () => agent('Be brief.')->prompt('Say pong.', provider: 'workers-ai', model: AUTH_TEXT_MODEL))
        ->toThrow(RequestException::class, '401');
})->skip($noAuthGateway, 'Set WORKERS_AI_E2E_AUTH_GATEWAY and WORKERS_AI_E2E_GATEWAY_TOKEN.');

test('the gateway token admits every call', function () {
    configureLiveProvider(authGatewayConfig(getenv('WORKERS_AI_E2E_GATEWAY_TOKEN')));

    expect(agent('Be brief.')->prompt('Say pong.', provider: 'workers-ai', model: AUTH_TEXT_MODEL)->text)->not->toBeEmpty()
        ->and(Embeddings::for(['hi'])->generate(provider: 'workers-ai', model: '@cf/baai/bge-base-en-v1.5')->embeddings)->toHaveCount(1)
        ->and(Extraction::of('Invoice INV-1.')->field('id', new Text)->extract()['id']->value())->toBe('INV-1');

    $audio = authSpeech();

    expect(strtolower(Transcription::fromBase64($audio, 'audio/mpeg')->timeout(90)->generate(provider: 'workers-ai', model: '@cf/openai/whisper-tiny-en')->text))->toContain('42')
        ->and(getimagesizefromstring((string) Image::of('A red square.')->size('512x512')->quality('low')->generate(provider: 'workers-ai')))->toBeArray();
})->skip($noAuthGateway, 'Set WORKERS_AI_E2E_AUTH_GATEWAY and WORKERS_AI_E2E_GATEWAY_TOKEN.');

test('json calls to the cloudflare api need only the key, with gateway or header', function () {
    foreach ([authGatewayConfig(), authHeaderConfig()] as $config) {
        configureLiveProvider($config);
        app(\Laravel\Ai\AiManager::class)->forgetInstance('workers-ai');

        expect(Extraction::of('Invoice INV-1.')->field('id', new Text)->extract()['id']->value())->toBe('INV-1')
            ->and(Reranking::of(['An apple.', 'A hammer.'])->rerank('fruit?', provider: 'workers-ai')->first()->index)->toBe(0)
            ->and(strtolower(Transcription::fromBase64(authSpeech(), 'audio/mpeg')->timeout(90)->generate(provider: 'workers-ai')->text))->toContain('42')
            ->and(strtolower(Transcription::fromBase64(authSpeech(), 'audio/mpeg')->timeout(90)->generate(provider: 'workers-ai', model: '@cf/openai/whisper-tiny-en')->text))->toContain('42');
    }
})->skip($noAuthGateway, 'Set WORKERS_AI_E2E_AUTH_GATEWAY and WORKERS_AI_E2E_GATEWAY_TOKEN.');

test('text and embeddings on the header route need only the key', function () {
    configureLiveProvider(authHeaderConfig());

    expect(agent('Be brief.')->prompt('Say pong.', provider: 'workers-ai', model: AUTH_TEXT_MODEL)->text)->not->toBeEmpty()
        ->and(Embeddings::for(['hi'])->generate(provider: 'workers-ai', model: '@cf/baai/bge-base-en-v1.5')->embeddings)->toHaveCount(1);
})->skip($noAuthGateway, 'Set WORKERS_AI_E2E_AUTH_GATEWAY and WORKERS_AI_E2E_GATEWAY_TOKEN.');

test('flux-2 needs the gateway token on both routes', function () {
    foreach ([authGatewayConfig(), authHeaderConfig()] as $config) {
        configureLiveProvider($config);
        app(\Laravel\Ai\AiManager::class)->forgetInstance('workers-ai');

        expect(fn () => Image::of('A red square.')->size('512x512')->quality('low')->generate(provider: 'workers-ai'))
            ->toThrow(RequestException::class, '401');
    }

    configureLiveProvider(authHeaderConfig(getenv('WORKERS_AI_E2E_GATEWAY_TOKEN')));
    app(\Laravel\Ai\AiManager::class)->forgetInstance('workers-ai');

    expect(getimagesizefromstring((string) Image::of('A red square.')->size('512x512')->quality('low')->generate(provider: 'workers-ai')))->toBeArray();
})->skip($noAuthGateway, 'Set WORKERS_AI_E2E_AUTH_GATEWAY and WORKERS_AI_E2E_GATEWAY_TOKEN.');

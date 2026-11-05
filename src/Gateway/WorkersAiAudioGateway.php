<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Gateway;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\PendingRequest;
use Laravel\Ai\Contracts\Gateway\AudioGateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Responses\AudioResponse;
use Meirdick\WorkersAi\Cloudflare\RunEndpoint;
use Meirdick\WorkersAi\Cloudflare\SdkCompat;
use Meirdick\WorkersAi\Gateway\Concerns\MapsFailoverErrors;
use Meirdick\WorkersAi\Gateway\Concerns\PostsWorkersAiRun;

/**
 * Text to speech on `/ai/run/{model}`.
 *
 * Aura models take `{text, speaker}` and reply with binary audio. MeloTTS takes
 * `{prompt, lang}`, has no voices, and replies with JSON `{audio}`.
 *
 * The models have no `instructions` input, so laravel/ai's `instructions()` has
 * no effect here.
 */
class WorkersAiAudioGateway implements AudioGateway
{
    use MapsFailoverErrors;
    use PostsWorkersAiRun;

    public function __construct(protected Dispatcher $events)
    {
        //
    }

    /**
     * @param  array<string, mixed>  $providerOptions
     */
    public function generateAudio(
        AudioProvider $provider,
        string $model,
        string $text,
        string $voice,
        ?string $instructions = null,
        int $timeout = 30,
        array $providerOptions = [],
    ): AudioResponse {
        $model = RunEndpoint::bareModel($model);

        $body = array_merge($providerOptions, str_contains($model, '/melotts')
            ? ['prompt' => $text]
            : ['text' => $text, 'speaker' => $this->speaker($model, $voice)]);

        $response = $this->sendRun($provider, $model, $timeout, fn (PendingRequest $client, string $url) => $client->post($url, $body));

        if ($this->isBinaryResponse($response)) {
            $audio = base64_encode($response->body());
            $mime = strtok((string) $response->header('Content-Type'), ';') ?: 'audio/mpeg';
        } else {
            $audio = (string) $this->runPayload($response, 'audio')['audio'];
            $mime = 'audio/mpeg';
        }

        if ($audio === '') {
            throw new AiException('Workers AI Error: Response contained no audio.');
        }

        return SdkCompat::audioResponse($audio, $provider->name(), $model, $mime);
    }

    /**
     * Map laravel/ai's default voices to a speaker the model has. Other values
     * pass through, so `->voice('asteria')` reaches the model.
     */
    protected function speaker(string $model, string $voice): string
    {
        $spanish = str_ends_with($model, '-es');

        return match ($voice) {
            'default-female' => $spanish ? 'carina' : 'luna',
            'default-male' => $spanish ? 'nestor' : 'orion',
            default => $voice,
        };
    }
}

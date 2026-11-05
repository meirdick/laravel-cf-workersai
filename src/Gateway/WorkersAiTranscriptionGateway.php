<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Gateway;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\TranscriptionGateway;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Responses\TranscriptionResponse;
use Meirdick\WorkersAi\Cloudflare\RunEndpoint;
use Meirdick\WorkersAi\Cloudflare\SdkCompat;
use Meirdick\WorkersAi\Gateway\Concerns\MapsFailoverErrors;
use Meirdick\WorkersAi\Gateway\Concerns\PostsWorkersAiRun;

/**
 * Speech to text on `/ai/run/{model}`.
 *
 * The catalog has three request shapes:
 *   - `whisper-large-v3-turbo` takes JSON with base64 `audio` and `language`.
 *   - `nova-3` takes the raw audio as the body, and options in the query string.
 *     Only this model labels speakers.
 *   - `whisper` and `whisper-tiny-en` take the raw audio as the body.
 */
class WorkersAiTranscriptionGateway implements TranscriptionGateway
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
    public function generateTranscription(
        TranscriptionProvider $provider,
        string $model,
        TranscribableAudio $audio,
        ?string $language = null,
        bool $diarize = false,
        int $timeout = 30,
        array $providerOptions = [],
    ): TranscriptionResponse {
        $model = RunEndpoint::bareModel($model);
        $nova = str_contains($model, '/nova-');

        if ($diarize && ! $nova) {
            throw new InvalidArgumentException(
                "Workers AI model '{$model}' does not label speakers. Use @cf/deepgram/nova-3 for diarize()."
            );
        }

        $content = $audio->content();
        $mime = $audio->mimeType() ?: 'application/octet-stream';

        [$data] = $this->postTranscription($provider, $model, $timeout, $content, $mime, $language, $diarize, $providerOptions);

        [$text, $segments] = $nova ? $this->readNova($data) : $this->readWhisper($data);

        return SdkCompat::transcriptionResponse(trim($text), $segments, $data['usage'] ?? null, $provider->name(), $model);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: array<string, mixed>, 1: \Illuminate\Http\Client\Response}
     */
    protected function postTranscription(Provider $provider, string $model, int $timeout, string $content, string $mime, ?string $language, bool $diarize, array $options): array
    {
        if (str_contains($model, 'whisper-large')) {
            $body = array_filter(array_merge($options, [
                'audio' => base64_encode($content),
                'language' => $language,
            ]), fn (mixed $value): bool => $value !== null);

            return $this->postRun($provider, $model, $timeout, $body, null);
        }

        $query = str_contains($model, '/nova-')
            ? array_filter(array_merge(['smart_format' => true], $options, ['language' => $language, 'diarize' => $diarize ?: null]), fn (mixed $value): bool => $value !== null)
            : [];

        $response = $this->sendRun($provider, $model, $timeout, fn (PendingRequest $client, string $url) => $client
            ->withBody($content, $mime)
            ->post($url.($query === [] ? '' : '?'.$this->queryString($query))));

        return [$this->runPayload($response, null), $response];
    }

    /**
     * Deepgram reads `true`, and PHP's query builder sends a boolean as `1`.
     *
     * @param  array<string, mixed>  $query
     */
    protected function queryString(array $query): string
    {
        return http_build_query(array_map(fn (mixed $value): mixed => is_bool($value) ? ($value ? 'true' : 'false') : $value, $query));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: Collection<int, TranscriptionSegment>}
     */
    protected function readWhisper(array $data): array
    {
        $parts = $data['segments'] ?? null;

        if (is_array($parts)) {
            $segments = collect($parts)->map(fn (array $part): TranscriptionSegment => new TranscriptionSegment(
                trim((string) ($part['text'] ?? '')),
                '',
                (float) ($part['start'] ?? 0),
                (float) ($part['end'] ?? 0),
            ));
        } else {
            $segments = collect($data['words'] ?? [])
                ->filter(fn (array $word): bool => trim((string) ($word['word'] ?? '')) !== '')
                ->values()
                ->map(fn (array $word): TranscriptionSegment => new TranscriptionSegment(
                    trim((string) $word['word']),
                    '',
                    (float) ($word['start'] ?? 0),
                    (float) ($word['end'] ?? 0),
                ));
        }

        return [(string) ($data['text'] ?? ''), $segments];
    }

    /**
     * Group consecutive words by speaker. With no labels, this is one segment.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: Collection<int, TranscriptionSegment>}
     */
    protected function readNova(array $data): array
    {
        $alternative = data_get($data, 'results.channels.0.alternatives.0', []);

        $segments = [];
        $current = null;

        foreach ($alternative['words'] ?? [] as $word) {
            $speaker = isset($word['speaker']) ? 'speaker_'.$word['speaker'] : '';

            if ($current === null || $current['speaker'] !== $speaker) {
                if ($current !== null) {
                    $segments[] = $current;
                }

                $current = ['speaker' => $speaker, 'words' => [], 'start' => (float) ($word['start'] ?? 0), 'end' => 0.0];
            }

            $current['words'][] = (string) ($word['punctuated_word'] ?? $word['word'] ?? '');
            $current['end'] = (float) ($word['end'] ?? 0);
        }

        if ($current !== null) {
            $segments[] = $current;
        }

        return [
            (string) ($alternative['transcript'] ?? ''),
            collect($segments)->map(fn (array $segment): TranscriptionSegment => new TranscriptionSegment(
                implode(' ', $segment['words']),
                $segment['speaker'],
                $segment['start'],
                $segment['end'],
            )),
        ];
    }
}

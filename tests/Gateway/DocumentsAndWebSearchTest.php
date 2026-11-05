<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Document;
use Meirdick\WorkersAi\Markdown;
use Meirdick\WorkersAi\Tools\CloudflareWebSearch;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.workers-ai' => [
        'driver' => 'workers-ai',
        'key' => 'test-key',
        'account_id' => 'acct123',
        'name' => 'workers-ai',
        'retry' => false,
    ]]);
});

function markdownReply(string $text = '# Invoice'): array
{
    return ['success' => true, 'result' => [['name' => 'a.pdf', 'mimeType' => 'application/pdf', 'format' => 'markdown', 'tokens' => 2, 'data' => $text]]];
}

test('Markdown::of posts the file and names it from its mime type', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(markdownReply())]);

    $text = Markdown::of(Document::fromString('%PDF', 'application/pdf'))->convert();

    expect($text)->toBe('# Invoice');

    Http::assertSent(function (Request $request): bool {
        $part = collect($request->data())->firstWhere('name', 'files');

        return $request->url() === 'https://api.cloudflare.com/client/v4/accounts/acct123/ai/tomarkdown'
            && $part['contents'] === '%PDF'
            && $part['filename'] === 'document.pdf';
    });
});

test('Markdown::of reads an uploaded file', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(markdownReply())]);

    expect(Markdown::of(UploadedFile::fake()->createWithContent('notes.html', '<h1>Hi</h1>'))->convert())->toBe('# Invoice');

    Http::assertSent(fn (Request $request): bool => collect($request->data())->firstWhere('name', 'files')['filename'] === 'notes.html');
});

test('a file Cloudflare cannot convert throws and names the reason', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => [
        ['name' => 'a.pdf', 'format' => 'error', 'error' => 'Corrupt file'],
    ]])]);

    expect(fn () => Markdown::of(Document::fromString('x', 'application/pdf'))->convert())
        ->toThrow(AiException::class, 'Corrupt file');
});

test('a document with no known type throws before a request', function () {
    Http::fake();

    expect(fn () => Markdown::of(Document::fromString('x'))->convert())
        ->toThrow(InvalidArgumentException::class, 'file type is unknown');

    Http::assertNothingSent();
});

test('a document attachment throws by default, and the message names the fix', function () {
    Http::fake();

    expect(fn () => agent('x')->prompt('Hi', attachments: [Document::fromString('x', 'application/pdf')], provider: 'workers-ai'))
        ->toThrow(InvalidArgumentException::class, 'convert_documents');

    Http::assertNothingSent();
});

test('an audio attachment names transcription, not documents', function () {
    Http::fake();

    expect(fn () => agent('x')->prompt('Hi', attachments: [Audio::fromBase64(base64_encode('x'), 'audio/mpeg')], provider: 'workers-ai'))
        ->toThrow(InvalidArgumentException::class, 'audio or video');
});

test('an uploaded audio file names transcription, not documents', function () {
    Http::fake();

    expect(fn () => agent('x')->prompt('Hi', attachments: [UploadedFile::fake()->create('a.mp3', 1, 'audio/mpeg')], provider: 'workers-ai'))
        ->toThrow(InvalidArgumentException::class, 'audio or video');
});

test('convert_documents sends the markdown as text in the message', function () {
    config(['ai.providers.workers-ai.convert_documents' => true]);

    Http::fake([
        '*/ai/tomarkdown' => Http::response(markdownReply('# Total 5')),
        '*/ai/v1/chat/completions' => Http::response(workersAiTextResponse()),
    ]);

    agent('Be brief.')->prompt('What is the total?', attachments: [Document::fromString('%PDF', 'application/pdf')], provider: 'workers-ai');

    Http::assertSent(function (Request $request): bool {
        if (! str_ends_with($request->url(), '/chat/completions')) {
            return false;
        }

        $user = collect($request->data()['messages'])->firstWhere('role', 'user');

        return is_string($user['content'])
            && str_contains($user['content'], 'What is the total?')
            && str_contains($user['content'], "Attached document:\n\n# Total 5");
    });
});

test('convert_documents leaves image attachments as image parts', function () {
    config(['ai.providers.workers-ai.convert_documents' => true]);

    Http::fake([
        '*/ai/tomarkdown' => Http::response(markdownReply()),
        '*/ai/v1/chat/completions' => Http::response(workersAiTextResponse()),
    ]);

    agent('x')->prompt('Hi', attachments: [
        Document::fromString('%PDF', 'application/pdf'),
        \Laravel\Ai\Files\Image::fromBase64(base64_encode('png'), 'image/png'),
    ], provider: 'workers-ai');

    Http::assertSent(function (Request $request): bool {
        if (! str_ends_with($request->url(), '/chat/completions')) {
            return false;
        }

        $content = collect($request->data()['messages'])->firstWhere('role', 'user')['content'];

        return $content[0]['type'] === 'text' && $content[1]['type'] === 'image_url' && count($content) === 2;
    });
});

test('web search posts to the default gateway and returns the items', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['items' => [['url' => 'https://a.test', 'title' => 'A', 'description' => 'B']]])]);

    $items = (new CloudflareWebSearch(limit: 3))->search('laravel');

    expect($items[0]['title'])->toBe('A');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.cloudflare.com/client/v4/accounts/acct123/ai/websearch/'
        && $request->data() === ['query' => 'laravel', 'limit' => 3, 'options' => ['gateway' => ['id' => 'default']]]);
});

test('web search sends the chosen search provider, gateway and alias', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['items' => []])]);

    (new CloudflareWebSearch(searchProvider: 'exa', gateway: 'mine', byokAlias: 'team'))->search('q');

    Http::assertSent(fn (Request $request): bool => $request->data()['provider'] === 'exa'
        && $request->data()['byokAlias'] === 'team'
        && $request->data()['options']['gateway']['id'] === 'mine');
});

test('a failed web search throws with Cloudflare\'s message', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['ok' => false, 'error' => ['message' => 'No provider key is configured']], 400)]);

    expect(fn () => (new CloudflareWebSearch)->search('q'))->toThrow(AiException::class, 'No provider key');
});

test('the web search tool runs in the tool loop', function () {
    Http::fake([
        '*/ai/websearch/' => Http::response(['items' => [['url' => 'https://a.test', 'title' => 'A result']]]),
        '*/ai/v1/chat/completions' => Http::sequence()
            ->push(['id' => 'c1', 'object' => 'chat.completion', 'model' => 'm', 'choices' => [['index' => 0, 'finish_reason' => 'tool_calls', 'message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 't1', 'type' => 'function', 'function' => ['name' => 'CloudflareWebSearch', 'arguments' => '{"query":"laravel"}']],
            ]]]], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]])
            ->push(workersAiTextResponse()),
    ]);

    $response = agent('Be brief.', tools: [new CloudflareWebSearch])->prompt('Search.', provider: 'workers-ai');

    expect($response->text)->not->toBeEmpty();

    Http::assertSent(function (Request $request): bool {
        if (! str_ends_with($request->url(), '/chat/completions') || count($request->data()['messages']) < 3) {
            return false;
        }

        return str_contains(json_encode($request->data()['messages']), 'A result');
    });
});

test('a provider tool is dropped by laravel/ai 1.1 and later, and throws before that', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    $agent = agent('x', tools: [new \Laravel\Ai\Providers\Tools\WebSearch]);

    if (! method_exists(\Laravel\Ai\Gateway\TextGenerationLoop::class, 'toolsSupportedBy')) {
        expect(fn () => $agent->prompt('Hi', provider: 'workers-ai'))->toThrow(RuntimeException::class, 'provider tools');

        return;
    }

    $agent->prompt('Hi', provider: 'workers-ai');

    Http::assertSent(fn (Request $request): bool => ! array_key_exists('tools', $request->data()));
});

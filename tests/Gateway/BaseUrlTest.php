<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Laravel\Ai\agent;

test('workersai builds direct url from account_id', function () {
    configureWorkersAiProvider(accountId: 'test-account-123');

    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    agent()->prompt('Hello', provider: 'workersai');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.cloudflare.com/client/v4/accounts/test-account-123/ai/v1/chat/completions');
});

/*
 * The AI Gateway shape resolves to `/compat`, not the provider-specific
 * `workers-ai/v1` path, because `/compat` was the only shape that worked
 * across every gateway tested. On a gateway with Authenticated Gateway on,
 * the provider path answered 401 on /embeddings while chat on the same
 * gateway and both operations on /compat succeeded; on a gateway with it off,
 * the provider path was fine. /compat costs a prefixed model ID, which the
 * package now adds for you.
 */
test('workersai routes the gateway config through /compat', function () {
    configureWorkersAiProvider(accountId: 'test-account-123', gateway: 'my-gateway');

    Http::fake(['gateway.ai.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    agent()->prompt('Hello', provider: 'workersai');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://gateway.ai.cloudflare.com/v1/test-account-123/my-gateway/compat/chat/completions');
});

test('gateway_path opts back into the provider-specific gateway path', function () {
    config(['ai.providers.workersai' => [
        'driver' => 'workersai',
        'key' => 'test-key',
        'account_id' => 'test-account-123',
        'gateway' => 'my-gateway',
        'gateway_path' => 'workers-ai/v1',
    ]]);

    Http::fake(['gateway.ai.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    agent()->prompt('Hello', provider: 'workersai');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://gateway.ai.cloudflare.com/v1/test-account-123/my-gateway/workers-ai/v1/chat/completions');
});

test('a bare model id is prefixed automatically on the gateway /compat path', function () {
    configureWorkersAiProvider(accountId: 'test-account-123', gateway: 'my-gateway');

    Http::fake(['gateway.ai.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    agent()->prompt('Hello', provider: 'workersai', model: '@cf/openai/gpt-oss-120b');

    Http::assertSent(fn (Request $r) => json_decode($r->body(), true)['model'] === 'workers-ai/@cf/openai/gpt-oss-120b');
});

test('an already-prefixed model id is not double-prefixed', function () {
    configureWorkersAiProvider(accountId: 'test-account-123', gateway: 'my-gateway');

    Http::fake(['gateway.ai.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    agent()->prompt('Hello', provider: 'workersai', model: 'workers-ai/@cf/openai/gpt-oss-120b');

    Http::assertSent(fn (Request $r) => json_decode($r->body(), true)['model'] === 'workers-ai/@cf/openai/gpt-oss-120b');
});

test('the direct API path leaves a bare model id alone', function () {
    configureWorkersAiProvider(accountId: 'test-account-123');

    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    agent()->prompt('Hello', provider: 'workersai', model: '@cf/openai/gpt-oss-120b');

    Http::assertSent(fn (Request $r) => json_decode($r->body(), true)['model'] === '@cf/openai/gpt-oss-120b');
});

test('workersai uses explicit url when set', function () {
    configureWorkersAiProvider(url: 'http://localhost:8787/v1');

    Http::fake(['localhost:8787/*' => Http::response(workersAiTextResponse())]);

    agent()->prompt('Hello', provider: 'workersai');

    Http::assertSent(fn (Request $r) => $r->url() === 'http://localhost:8787/v1/chat/completions');
});

test('workersai throws when both url and account_id are set (ambiguous config)', function () {
    configureWorkersAiProvider(
        accountId: 'test-account-123',
        gateway: 'my-gateway',
        url: 'http://custom.example.com/v1',
    );

    agent()->prompt('Hello', provider: 'workersai');
})->throws(\Laravel\Ai\Exceptions\AiException::class, 'both `url` and `account_id`');

test('workersai throws when account_id is missing and no url set', function () {
    configureWorkersAiProvider();

    agent()->prompt('Hello', provider: 'workersai');
})->throws(\Laravel\Ai\Exceptions\AiException::class, 'account_id');

test('workersai throws when account_id looks like a URL', function () {
    configureWorkersAiProvider(accountId: 'https://api.cloudflare.com/client/v4/accounts/abc');

    agent()->prompt('Hello', provider: 'workersai');
})->throws(\Laravel\Ai\Exceptions\AiException::class, '`account_id` looks like a URL');

test('workersai throws when gateway slug contains a path', function () {
    configureWorkersAiProvider(accountId: 'abc', gateway: 'my-gateway/extra');

    agent()->prompt('Hello', provider: 'workersai');
})->throws(\Laravel\Ai\Exceptions\AiException::class, '`gateway` looks malformed');

/*
 * A bare model ID on /compat is not an error. Measured live 2026-09-09:
 * `@cf/meta/llama-3.3-70b-instruct-fp8-fast` posted to /compat without a
 * prefix returns HTTP 200 for both chat and embeddings. Through v0.6.1 the
 * package threw on this, rejecting a working configuration; it now adds the
 * prefix so routing on the multi-provider endpoint stays explicit.
 */
test('a bare model id on an explicit compat url is prefixed, not rejected', function () {
    configureWorkersAiProvider(url: 'https://gateway.ai.cloudflare.com/v1/abc/gw/compat');

    Http::fake(['gateway.ai.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    agent()->prompt('Hello', provider: 'workersai');

    Http::assertSent(fn (Request $r) => str_starts_with(json_decode($r->body(), true)['model'], 'workers-ai/@cf/'));
});

test('workersai throws when v1 url used with prefixed model', function () {
    configureWorkersAiProvider(accountId: 'test-account');

    agent()->prompt('Hello', provider: 'workersai', model: 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast');
})->throws(\Laravel\Ai\Exceptions\AiException::class, 'has the `workers-ai/` prefix, but the configured endpoint expects bare');

test('workersai sends bearer token in authorization header', function () {
    configureWorkersAiProvider(accountId: 'test-123');

    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    agent()->prompt('Hello', provider: 'workersai');

    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer test-key'));
});

/*
 * Authenticated Gateway. A gateway created with `authentication: true`
 * requires a gateway-issued token in `cf-aig-authorization` alongside the
 * provider credential in `Authorization`. Observed on a real gateway with the
 * setting on: the provider path's /embeddings answered 401 with a bare
 * Cloudflare "Authentication error" naming neither the gateway nor the header.
 */
test('workersai sends the cf-aig-authorization header when a gateway_token is set', function () {
    config(['ai.providers.workersai' => [
        'driver' => 'workersai',
        'key' => 'test-key',
        'account_id' => 'test-123',
        'gateway' => 'my-gateway',
        'gateway_token' => 'aig-token-abc',
    ]]);

    Http::fake(['gateway.ai.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    agent()->prompt('Hello', provider: 'workersai');

    Http::assertSent(fn (Request $r) => $r->hasHeader('cf-aig-authorization', 'Bearer aig-token-abc')
        && $r->hasHeader('Authorization', 'Bearer test-key'));
});

test('no cf-aig-authorization header is sent without a gateway_token', function () {
    configureWorkersAiProvider(accountId: 'test-123', gateway: 'my-gateway');

    Http::fake(['gateway.ai.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    agent()->prompt('Hello', provider: 'workersai');

    Http::assertSent(fn (Request $r) => ! $r->hasHeader('cf-aig-authorization'));
});

test('the gateway_token also reaches the embeddings path', function () {
    config(['ai.providers.workersai' => [
        'driver' => 'workersai',
        'key' => 'test-key',
        'account_id' => 'test-123',
        'gateway' => 'my-gateway',
        'gateway_token' => 'aig-token-abc',
    ]]);

    // Inlined rather than reusing EmbeddingTest's helper — Pest's file load
    // order is not guaranteed, and a global function defined in a sibling
    // test file may not exist yet when this one runs.
    Http::fake(['gateway.ai.cloudflare.com/*' => Http::response([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]]],
        'model' => '@cf/baai/bge-large-en-v1.5',
        'usage' => ['total_tokens' => 10],
    ])]);

    \Laravel\Ai\Embeddings::for(['Hello'])->generate(provider: 'workersai');

    Http::assertSent(fn (Request $r) => $r->hasHeader('cf-aig-authorization', 'Bearer aig-token-abc'));
});

test('workersai sends session affinity header when configured', function () {
    configureWorkersAiProvider(accountId: 'test-123', sessionAffinity: 'ses_abc123');

    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    agent()->prompt('Hello', provider: 'workersai');

    Http::assertSent(fn (Request $r) => $r->hasHeader('x-session-affinity', 'ses_abc123'));
});

function configureWorkersAiProvider(
    ?string $accountId = null,
    ?string $gateway = null,
    ?string $url = null,
    ?string $sessionAffinity = null,
): void {
    config(['ai.providers.workersai' => array_filter([
        'driver' => 'workersai',
        'key' => 'test-key',
        'account_id' => $accountId,
        'gateway' => $gateway,
        'url' => $url,
        'session_affinity' => $sessionAffinity,
    ])]);
}


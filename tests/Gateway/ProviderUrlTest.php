<?php

declare(strict_types=1);

use Meirdick\WorkersAi\Cloudflare\BaseUrl;

test('providerUrl builds the gateway path for one provider', function () {
    expect(BaseUrl::providerUrl('acct123', 'my-gateway', 'openai'))
        ->toBe('https://gateway.ai.cloudflare.com/v1/acct123/my-gateway/openai');
});

test('providerUrl trims a slash the caller put on the provider name', function () {
    expect(BaseUrl::providerUrl('acct123', 'my-gateway', '/anthropic/'))
        ->toBe('https://gateway.ai.cloudflare.com/v1/acct123/my-gateway/anthropic');
});

test('providerUrl rejects a gateway slug that is a path', function () {
    expect(fn () => BaseUrl::providerUrl('acct123', 'my-gateway/extra', 'openai'))
        ->toThrow(InvalidArgumentException::class, '`gateway` looks malformed');
});

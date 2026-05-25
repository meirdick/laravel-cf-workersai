<?php

declare(strict_types=1);

use Laravel\Ai\AiManager;
use Meirdick\WorkersAi\Gateway\WorkersAiGateway;
use Meirdick\WorkersAi\Providers\WorkersAiProvider;

it('registers workers-ai driver with AiManager', function () {
    config()->set('ai.providers.workers-ai', [
        'driver' => 'workers-ai',
        'key' => 'test-key',
        'url' => 'https://example.com/compat',
        'name' => 'workers-ai',
    ]);

    $provider = app(AiManager::class)->instance('workers-ai');

    expect($provider)->toBeInstanceOf(WorkersAiProvider::class);
});

it('registers dashless "workersai" alias with AiManager', function () {
    config()->set('ai.providers.workersai', [
        'driver' => 'workersai',
        'key' => 'test-key',
        'url' => 'https://example.com/compat',
        'name' => 'workersai',
    ]);

    $provider = app(AiManager::class)->instance('workersai');

    expect($provider)->toBeInstanceOf(WorkersAiProvider::class);
});

it('lazy-instantiates a single shared gateway on the WorkersAiProvider', function () {
    config()->set('ai.providers.workers-ai', [
        'driver' => 'workers-ai',
        'key' => 'test-key',
        'account_id' => 'test-account',
        'name' => 'workers-ai',
    ]);

    $provider = app(AiManager::class)->instance('workers-ai');

    expect($provider->textGateway())->toBeInstanceOf(WorkersAiGateway::class);
    expect($provider->embeddingGateway())->toBeInstanceOf(WorkersAiGateway::class);
    expect($provider->textGateway())->toBe($provider->embeddingGateway());
});

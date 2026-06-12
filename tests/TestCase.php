<?php

declare(strict_types=1);

namespace Tests;

use Laravel\Ai\AiServiceProvider;
use Meirdick\WorkersAi\WorkersAiServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
            WorkersAiServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('ai.providers.workers-ai', [
            'driver' => 'workers-ai',
            'key' => 'test-api-key',
            'account_id' => 'test-account-id',
        ]);

        $app['config']->set('ai.providers.workersai', [
            'driver' => 'workersai',
            'key' => 'test-api-key',
            'account_id' => 'test-account-id',
        ]);
    }
}

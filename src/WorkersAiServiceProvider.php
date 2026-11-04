<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\AiManager;
use Meirdick\WorkersAi\Providers\ClefClassification;

class WorkersAiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! class_exists(AiManager::class)) {
            return;
        }

        $this->app->afterResolving(AiManager::class, function (AiManager $manager) {
            $class = ClefClassification::providerClass();

            $creator = fn ($app, array $config) => new $class(
                $config,
                $app->make(Dispatcher::class),
            );

            $manager->extend(WorkersAiKey::PRIMARY, $creator);
            $manager->extend(WorkersAiKey::ALIAS, $creator);
        });
    }
}

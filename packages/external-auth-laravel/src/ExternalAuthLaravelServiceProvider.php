<?php

declare(strict_types=1);

namespace Intl\ExternalAuthLaravel;

use Illuminate\Support\ServiceProvider;
use Intl\ExternalAuth\Contracts\ExternalAuthProviderInterface;
use Intl\ExternalAuthLaravel\Services\ExternalAuthService;
use Intl\ExternalAuthLaravel\Services\ExternalSessionStorage;
use Intl\ExternalAuthLaravel\Testing\FakeEpishkhanV2Adapter;

final class ExternalAuthLaravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/intl-external-auth.php',
            'intl-external-auth'
        );

        $this->app->singleton(ExternalSessionStorage::class);

        if (!$this->app->bound(ExternalAuthProviderInterface::class)) {
            $this->app->singleton(ExternalAuthProviderInterface::class, FakeEpishkhanV2Adapter::class);
        }

        $this->app->singleton(ExternalAuthService::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/intl-external-auth.php' => config_path('intl-external-auth.php'),
            ], 'intl-external-auth-config');

            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        }
    }
}

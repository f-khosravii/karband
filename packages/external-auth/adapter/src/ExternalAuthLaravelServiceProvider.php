<?php

declare(strict_types=1);

namespace Karband\ExternalAuthLaravel;

use Illuminate\Support\ServiceProvider;
use Karband\ExternalAuth\Contracts\ExternalAuthProviderInterface;
use Karband\ExternalAuthLaravel\Services\ExternalAuthService;
use Karband\ExternalAuthLaravel\Services\ExternalSessionStorage;

final class ExternalAuthLaravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/external-auth.php',
            'external-auth'
        );

        $this->app->singleton(ExternalSessionStorage::class);
        $this->app->singleton(ExternalAuthService::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/external-auth.php' => config_path('external-auth.php'),
            ], 'external-auth-config');

            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        }
    }
}

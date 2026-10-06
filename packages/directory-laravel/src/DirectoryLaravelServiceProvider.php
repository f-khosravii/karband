<?php

declare(strict_types=1);

namespace Intl\DirectoryLaravel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Intl\Directory\Contracts\DirectoryResolverInterface;
use Intl\DirectoryLaravel\Services\DirectoryResolver;
use Intl\DirectoryLaravel\Services\DirectoryStorage;

final class DirectoryLaravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/intl-directory.php', 'intl-directory');

        $this->app->singleton(DirectoryStorage::class, function ($app): DirectoryStorage {
            $connectionName = $app['config']->get('intl-directory.connection');
            return new DirectoryStorage(DB::connection($connectionName));
        });

        $this->app->singleton(DirectoryResolver::class, function ($app): DirectoryResolver {
            return new DirectoryResolver($app->make(DirectoryStorage::class));
        });

        $this->app->bind(DirectoryResolverInterface::class, DirectoryResolver::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/intl-directory.php' => config_path('intl-directory.php'),
            ], 'intl-directory-config');
        }
    }
}

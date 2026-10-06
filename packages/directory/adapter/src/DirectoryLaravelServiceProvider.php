<?php

declare(strict_types=1);

namespace Karband\DirectoryLaravel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Karband\Directory\Contracts\DirectoryResolverInterface;
use Karband\DirectoryLaravel\Services\DirectoryResolver;
use Karband\DirectoryLaravel\Services\DirectoryStorage;

final class DirectoryLaravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/directory.php', 'directory');

        $this->app->singleton(DirectoryStorage::class, function ($app): DirectoryStorage {
            $connectionName = $app['config']->get('directory.connection');
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
                __DIR__ . '/../config/directory.php' => config_path('directory.php'),
            ], 'directory-config');
        }
    }
}

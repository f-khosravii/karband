<?php

declare(strict_types=1);

namespace Karband\RequestJourneyLaravel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Karband\RequestJourneyLaravel\Contracts\JourneyRegistryInterface;
use Karband\RequestJourneyLaravel\Contracts\JourneyServiceInterface;
use Karband\RequestJourneyLaravel\Services\DatabaseJourneyRegistry;
use Karband\RequestJourneyLaravel\Services\JourneyStorage;
use Karband\RequestJourneyLaravel\Services\RequestJourneyManager;

final class RequestJourneyLaravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/request-journey.php', 'request-journey');

        $this->app->singleton(JourneyStorage::class, static fn ($app): JourneyStorage =>
            new JourneyStorage(DB::connection($app['config']->get('request-journey.connection'))));

        $this->app->singleton(JourneyRegistryInterface::class, DatabaseJourneyRegistry::class);
        $this->app->singleton(DatabaseJourneyRegistry::class);

        $this->app->singleton(JourneyServiceInterface::class, RequestJourneyManager::class);
        $this->app->singleton(RequestJourneyManager::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->publishes([
            __DIR__ . '/../config/request-journey.php' => config_path('request-journey.php'),
        ], 'request-journey-config');
    }
}

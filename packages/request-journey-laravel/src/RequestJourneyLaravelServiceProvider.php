<?php

declare(strict_types=1);

namespace Intl\RequestJourneyLaravel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Intl\RequestJourneyLaravel\Contracts\JourneyFactProviderInterface;
use Intl\RequestJourneyLaravel\Contracts\JourneyRegistryInterface;
use Intl\RequestJourneyLaravel\Contracts\JourneyServiceInterface;
use Intl\RequestJourneyLaravel\Services\DatabaseJourneyRegistry;
use Intl\RequestJourneyLaravel\Services\DefaultJourneyFactProvider;
use Intl\RequestJourneyLaravel\Services\JourneyStorage;
use Intl\RequestJourneyLaravel\Services\RequestJourneyManager;

final class RequestJourneyLaravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/intl-journey.php', 'intl-journey');

        $this->app->singleton(JourneyStorage::class, static fn ($app): JourneyStorage =>
            new JourneyStorage(DB::connection($app['config']->get('intl-journey.connection'))));

        $this->app->singleton(JourneyRegistryInterface::class, DatabaseJourneyRegistry::class);
        $this->app->singleton(DatabaseJourneyRegistry::class);

        $this->app->singleton(JourneyFactProviderInterface::class, DefaultJourneyFactProvider::class);
        $this->app->singleton(DefaultJourneyFactProvider::class);

        $this->app->singleton(JourneyServiceInterface::class, RequestJourneyManager::class);
        $this->app->singleton(RequestJourneyManager::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->publishes([
            __DIR__ . '/../config/intl-journey.php' => config_path('intl-journey.php'),
        ], 'intl-journey-config');
    }
}

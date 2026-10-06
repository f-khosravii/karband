<?php

declare(strict_types=1);

namespace Intl\ServiceCatalogLaravel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Intl\ServiceCatalogLaravel\Contracts\WorkflowDefinitionVerifier;
use Intl\ServiceCatalogLaravel\Services\CatalogStorage;
use Intl\ServiceCatalogLaravel\Services\PublishedWorkflowVerifier;

final class ServiceCatalogLaravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/intl-service-catalog.php', 'intl-service-catalog');
        $this->app->singleton(CatalogStorage::class, fn ($app): CatalogStorage =>
            new CatalogStorage(DB::connection($app['config']->get('intl-service-catalog.connection'))));
        $this->app->bind(WorkflowDefinitionVerifier::class, PublishedWorkflowVerifier::class);
        // No fake principal, broad permission, HTTP route or default ScopeChecker.
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->publishes([
            __DIR__ . '/../config/intl-service-catalog.php' => config_path('intl-service-catalog.php'),
        ], 'intl-service-catalog-config');
    }
}

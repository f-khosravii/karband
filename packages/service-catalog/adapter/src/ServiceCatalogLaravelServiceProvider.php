<?php

declare(strict_types=1);

namespace Karband\ServiceCatalogLaravel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Karband\ServiceCatalogLaravel\Contracts\WorkflowDefinitionVerifier;
use Karband\ServiceCatalogLaravel\Services\CatalogStorage;
use Karband\ServiceCatalogLaravel\Services\NullWorkflowDefinitionVerifier;

final class ServiceCatalogLaravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/service-catalog.php', 'service-catalog');
        $this->app->singleton(CatalogStorage::class, fn ($app): CatalogStorage =>
            new CatalogStorage(DB::connection($app['config']->get('service-catalog.connection'))));
        if (!$this->app->bound(WorkflowDefinitionVerifier::class)) {
            $this->app->bind(WorkflowDefinitionVerifier::class, NullWorkflowDefinitionVerifier::class);
        }
        // No fake principal, broad permission, HTTP route or default ScopeChecker.
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->publishes([
            __DIR__ . '/../config/service-catalog.php' => config_path('service-catalog.php'),
        ], 'service-catalog-config');
    }
}

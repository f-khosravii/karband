<?php

declare(strict_types=1);

namespace Karband\CatalogPanel;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Karband\CatalogPanel\Contracts\CatalogPanelAuthorizationInterface;
use Karband\CatalogPanel\Services\DefaultCatalogPanelAuthorization;

final class CatalogPanelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/catalog-panel.php', 'catalog-panel');

        if (!$this->app->bound(CatalogPanelAuthorizationInterface::class)) {
            $this->app->singleton(
                CatalogPanelAuthorizationInterface::class,
                DefaultCatalogPanelAuthorization::class
            );
        }
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'catalog-panel');

        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        $prefix = (string) config('catalog-panel.route_prefix', 'catalog');
        $namePrefix = (string) config('catalog-panel.route_name_prefix', 'catalog.');
        $middleware = (array) config('catalog-panel.middleware', ['web']);

        Route::prefix($prefix)
            ->name($namePrefix)
            ->middleware($middleware)
            ->group(__DIR__ . '/../routes/web.php');
    }
}

<?php

declare(strict_types=1);

namespace Karband\DirectoryPanel;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Karband\DirectoryPanel\Contracts\DirectoryPanelAuthorizationInterface;
use Karband\DirectoryPanel\Services\DefaultDirectoryPanelAuthorization;

final class DirectoryPanelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/directory-panel.php', 'directory-panel');

        if (!$this->app->bound(DirectoryPanelAuthorizationInterface::class)) {
            $this->app->singleton(
                DirectoryPanelAuthorizationInterface::class,
                DefaultDirectoryPanelAuthorization::class
            );
        }
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'directory-panel');

        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        $prefix = (string) config('directory-panel.route_prefix', 'directory');
        $namePrefix = (string) config('directory-panel.route_name_prefix', 'directory.');
        $middleware = (array) config('directory-panel.middleware', ['web']);

        Route::prefix($prefix)
            ->name($namePrefix)
            ->middleware($middleware)
            ->group(__DIR__ . '/../routes/web.php');
    }
}

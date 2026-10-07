<?php

declare(strict_types=1);

namespace Karband\AuthPanel;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class AuthPanelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/auth-panel.php', 'auth-panel');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'auth-panel');

        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/auth-panel.php' => config_path('auth-panel.php'),
            ], 'auth-panel-config');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/auth-panel'),
            ], 'auth-panel-views');
        }
    }

    private function registerRoutes(): void
    {
        $prefix = (string) config('auth-panel.route_prefix', '');
        $namePrefix = (string) config('auth-panel.route_name_prefix', 'auth.');
        $middleware = (array) config('auth-panel.middleware', ['web']);

        Route::prefix($prefix)
            ->name($namePrefix)
            ->middleware($middleware)
            ->group(__DIR__ . '/../routes/web.php');
    }
}

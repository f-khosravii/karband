<?php

declare(strict_types=1);

namespace Karband\ServiceRequestsLaravel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Karband\ServiceRequestsLaravel\Services\RequestOperationPermit;
use Karband\ServiceRequestsLaravel\Services\RequestQuoteService;
use Karband\ServiceRequestsLaravel\Services\RequestStorage;

final class ServiceRequestsLaravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/service-requests.php', 'service-requests');
        $this->app->singleton(RequestStorage::class, fn ($app): RequestStorage =>
            new RequestStorage(DB::connection($app['config']->get('service-requests.connection'))));
        
        $this->app->scoped(RequestOperationPermit::class, fn() => new RequestOperationPermit());
        $this->app->scoped(RequestQuoteService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->publishes([
            __DIR__ . '/../config/service-requests.php' => config_path('service-requests.php'),
        ], 'service-requests-config');
    }
}

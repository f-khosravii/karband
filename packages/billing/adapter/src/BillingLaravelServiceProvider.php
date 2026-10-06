<?php

declare(strict_types=1);

namespace Karband\BillingLaravel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Karband\BillingLaravel\Services\PaymentStorage;

/** No default central gateway, no default trusted quote, no roles granted, no HTTP routes. */
final class BillingLaravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/billing.php', 'billing');
        $this->app->singleton(PaymentStorage::class, fn ($app): PaymentStorage =>
            new PaymentStorage(DB::connection($app['config']->get('billing.connection'))));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->publishes([
            __DIR__ . '/../config/billing.php' => config_path('billing.php'),
        ], 'billing-config');
    }
}

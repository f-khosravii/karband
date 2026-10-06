<?php

declare(strict_types=1);
namespace Karband\ServiceRecipientsLaravel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Karband\ServiceRecipientsLaravel\Services\RecipientStorage;

final class ServiceRecipientsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/service-recipients.php', 'service-recipients');
        $this->app->singleton(RecipientStorage::class, fn ($app): RecipientStorage =>
            new RecipientStorage(DB::connection($app['config']->get('service-recipients.connection'))));
        // Deliberately no default Rules, PrincipalProvider, Audit, RequestBridge, or permissive policy.
    }
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->publishes([__DIR__.'/../config/service-recipients.php' => config_path('service-recipients.php')], 'service-recipients-config');
    }
}

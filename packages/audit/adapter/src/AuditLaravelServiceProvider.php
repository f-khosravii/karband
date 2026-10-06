<?php

declare(strict_types=1);

namespace Karband\AuditLaravel;

use Illuminate\Support\ServiceProvider;
use Karband\AuditLaravel\Services\SpatieAuditRecorder;
use Karband\Audit\AuditRecorderInterface;

/** No routes, no auto model logging, no default identity resolver and no central database. */
final class AuditLaravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/audit.php', 'audit');
        $this->app->bind(AuditRecorderInterface::class, SpatieAuditRecorder::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->publishes([
            __DIR__ . '/../config/audit.php' => config_path('audit.php'),
        ], 'audit-config');
    }
}

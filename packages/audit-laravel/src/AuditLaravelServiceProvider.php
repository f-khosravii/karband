<?php

declare(strict_types=1);

namespace Intl\AuditLaravel;

use Illuminate\Support\ServiceProvider;
use Intl\AuditLaravel\Services\SpatieAuditRecorder;
use Intl\Contracts\Audit\AuditRecorderInterface;

/** No routes, no auto model logging, no default identity resolver and no central database. */
final class AuditLaravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/intl-audit.php', 'intl-audit');
        $this->app->bind(AuditRecorderInterface::class, SpatieAuditRecorder::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/intl-audit.php' => config_path('intl-audit.php'),
        ], 'intl-audit-config');
    }
}

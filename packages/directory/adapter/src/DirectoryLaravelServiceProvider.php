<?php

declare(strict_types=1);

namespace Karband\DirectoryLaravel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Karband\Audit\AuditEvent;
use Karband\Audit\AuditRecorderInterface;
use Karband\Directory\Contracts\DirectoryAuditPortInterface;
use Karband\Directory\Contracts\DirectoryResolverInterface;
use Karband\Directory\Contracts\DirectoryStoragePortInterface;
use Karband\Directory\Services\DirectoryBootstrapper;
use Karband\Directory\Services\DirectoryResolver;
use Karband\Directory\Services\DirectoryService;
use Karband\DirectoryLaravel\Services\DirectoryStorage;

final class DirectoryLaravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/directory.php', 'directory');

        $this->app->singleton(DirectoryStorage::class, function ($app): DirectoryStorage {
            $connectionName = $app['config']->get('directory.connection');
            return new DirectoryStorage(DB::connection($connectionName));
        });

        $this->app->bind(DirectoryStoragePortInterface::class, DirectoryStorage::class);

        $this->app->singleton(DirectoryService::class, function ($app): DirectoryService {
            $storage = $app->make(DirectoryStoragePortInterface::class);
            $auditPort = null;

            if ($app->bound(AuditRecorderInterface::class)) {
                try {
                    $auditRecorder = $app->make(AuditRecorderInterface::class);
                    $auditPort = new class ($auditRecorder) implements DirectoryAuditPortInterface {
                        public function __construct(private AuditRecorderInterface $recorder)
                        {
                        }

                        public function record(string $code, string $subjectType, string $subjectId, array $metadata = []): void
                        {
                            try {
                                $this->recorder->record(new AuditEvent(
                                    code: $code,
                                    subjectType: $subjectType,
                                    subjectId: $subjectId,
                                    metadata: $metadata
                                ));
                            } catch (\Throwable) {
                                // Best-effort audit recording
                            }
                        }
                    };
                } catch (\Throwable) {
                    $auditPort = null;
                }
            }

            return new DirectoryService($storage, $auditPort);
        });

        $this->app->singleton(DirectoryResolver::class, function ($app): DirectoryResolver {
            return new DirectoryResolver($app->make(DirectoryStoragePortInterface::class));
        });

        $this->app->singleton(DirectoryBootstrapper::class, function ($app): DirectoryBootstrapper {
            return new DirectoryBootstrapper(
                $app->make(DirectoryService::class),
                $app->make(DirectoryStoragePortInterface::class)
            );
        });

        $this->app->bind(DirectoryResolverInterface::class, DirectoryResolver::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/directory.php' => config_path('directory.php'),
            ], 'directory-config');
        }
    }
}

<?php

declare(strict_types=1);

namespace Karband\AuthenticationLaravel;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Karband\Audit\AuditEvent;
use Karband\Audit\AuditRecorderInterface;
use Karband\Authentication\Contracts\AuthenticationAuditPortInterface;
use Karband\Authentication\Contracts\AuthenticationStoragePortInterface;
use Karband\Authentication\Contracts\PasswordHasherPortInterface;
use Karband\Authentication\Services\AuthenticationService;
use Karband\AuthenticationLaravel\Services\AuthenticationStorage;
use Karband\AuthenticationLaravel\Services\LaravelPasswordHasher;

final class AuthenticationLaravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/authentication.php', 'authentication');

        $this->app->singleton(AuthenticationStorage::class, function ($app): AuthenticationStorage {
            $connectionName = $app['config']->get('authentication.connection');
            $tableName = (string) $app['config']->get('authentication.table', 'authentication_accounts');

            return new AuthenticationStorage(DB::connection($connectionName), $tableName);
        });

        $this->app->bind(AuthenticationStoragePortInterface::class, AuthenticationStorage::class);

        $this->app->singleton(LaravelPasswordHasher::class, function ($app): LaravelPasswordHasher {
            return new LaravelPasswordHasher($app->make(Hasher::class));
        });

        $this->app->bind(PasswordHasherPortInterface::class, LaravelPasswordHasher::class);

        $this->app->singleton(AuthenticationService::class, function ($app): AuthenticationService {
            $storage = $app->make(AuthenticationStoragePortInterface::class);
            $hasher = $app->make(PasswordHasherPortInterface::class);
            $auditPort = null;

            if ($app->bound(AuditRecorderInterface::class)) {
                try {
                    $auditRecorder = $app->make(AuditRecorderInterface::class);
                    $auditPort = new class ($auditRecorder) implements AuthenticationAuditPortInterface {
                        public function __construct(private readonly AuditRecorderInterface $recorder)
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

            return new AuthenticationService($storage, $hasher, $auditPort);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/authentication.php' => config_path('authentication.php'),
            ], 'authentication-config');
        }
    }
}

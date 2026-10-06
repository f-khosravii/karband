<?php

declare(strict_types=1);

namespace Karband\DocumentsLaravel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Karband\DocumentsLaravel\Services\DocumentStorage;

final class DocumentsLaravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/documents.php', 'documents');
        $this->app->singleton(DocumentStorage::class, fn ($app): DocumentStorage =>
            new DocumentStorage(DB::connection($app['config']->get('documents.connection'))));
        // PrincipalProvider, AuthorizerInterface, ResourceVerifier, ReplacementPolicy, FileSafetyScanner are host bindings.
        // No default allow, no API routes, no public storage URL.
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->publishes([
            __DIR__ . '/../config/documents.php' => config_path('documents.php'),
        ], 'documents-config');
    }
}

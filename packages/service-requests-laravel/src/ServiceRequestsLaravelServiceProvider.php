<?php

declare(strict_types=1);

namespace Intl\ServiceRequestsLaravel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Intl\DocumentsLaravel\Contracts\ResourceVerifier;
use Intl\DocumentsLaravel\Contracts\DocumentEditGate;
use Intl\DynamicFormsLaravel\Contracts\ResponseEditGate;
use Intl\WorkflowLaravel\Contracts\WorkflowActionGate;
use Intl\ServiceRecipientsLaravel\Contracts\RecipientRequestBridge;
use Intl\ServiceRecipientsLaravel\Bridge\RecipientOwnershipVerifier;
use Intl\ServiceRequestsLaravel\Contracts\RecipientVerifier;
use Intl\ServiceRequestsLaravel\Contracts\RecipientProfileSource;
use Intl\ServiceRequestsLaravel\Services\LocalRecipientProfileSource;
use Intl\ServiceRequestsLaravel\Services\LocalRecipientRequestBridge;
use Intl\ServiceRequestsLaravel\Services\RequestOperationPermit;
use Intl\ServiceRequestsLaravel\Services\RequestGuards;
use Intl\ServiceRequestsLaravel\Services\RequestStorage;
use Intl\ServiceRequestsLaravel\Services\ServiceRequestResourceVerifier;

/** RecipientVerifier is intentionally NOT bound here. No default authorization or public routes. */
final class ServiceRequestsLaravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/intl-service-requests.php', 'intl-service-requests');
        $this->app->singleton(RequestStorage::class, fn ($app): RequestStorage =>
            new RequestStorage(DB::connection($app['config']->get('intl-service-requests.connection'))));
        // One request/job-local permit is shared by lifecycle façade and all direct package gates.
        $this->app->scoped(RequestOperationPermit::class, fn() => new RequestOperationPermit());
        $this->app->scoped(RequestGuards::class);
        $this->app->bind(WorkflowActionGate::class, RequestGuards::class);
        $this->app->bind(ResponseEditGate::class, RequestGuards::class);
        $this->app->bind(DocumentEditGate::class, RequestGuards::class);
        if (!$this->app->bound(RecipientProfileSource::class)) {
            $this->app->bind(RecipientProfileSource::class, LocalRecipientProfileSource::class);
        }
        if (!$this->app->bound(RecipientRequestBridge::class)) {
            $this->app->bind(RecipientRequestBridge::class, LocalRecipientRequestBridge::class);
        }
        if (!$this->app->bound(RecipientVerifier::class)) {
            $this->app->bind(RecipientVerifier::class, RecipientOwnershipVerifier::class);
        }
        // No default RequestAudit, RequestSubmissionVerifier, gateway or permissive authorizer.
        // A host is free to supply a stricter ResourceVerifier; never overwrite it.
        $this->app->scoped(Services\RequestQuoteService::class);
        if (!$this->app->bound(\Intl\BillingLaravel\Contracts\AcceptedQuoteResolverInterface::class)) {
            $this->app->bind(\Intl\BillingLaravel\Contracts\AcceptedQuoteResolverInterface::class, Bridge\ServiceRequestsQuoteResolver::class);
        }
        if (!$this->app->bound(ResourceVerifier::class)) {
            $this->app->bind(ResourceVerifier::class, ServiceRequestResourceVerifier::class);
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->publishes([
            __DIR__ . '/../config/intl-service-requests.php' => config_path('intl-service-requests.php'),
        ], 'intl-service-requests-config');
    }
}

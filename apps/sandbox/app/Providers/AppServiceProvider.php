<?php

namespace App\Providers;

use App\Bridges\LocalRecipientProfileSource;
use App\Bridges\LocalRecipientRequestBridge;
use App\Bridges\RecipientOwnershipVerifier;
use App\Bridges\RequestCatalogBridge;
use App\Bridges\RequestDocumentsBridge;
use App\Bridges\RequestFormsBridge;
use App\Bridges\RequestPaymentBridge;
use App\Bridges\RequestWorkflowBridge;
use App\Bridges\WorkflowAuthorizationBridge;
use Illuminate\Support\ServiceProvider;
use Karband\DocumentsLaravel\Contracts\DocumentEditGate;
use Karband\DocumentsLaravel\Contracts\ResourceVerifier;
use Karband\DynamicFormsLaravel\Contracts\ResponseEditGate;
use Karband\ServiceRecipientsLaravel\Contracts\RecipientRequestBridge;
use Karband\ServiceRequestsLaravel\Contracts\RecipientProfileSource;
use Karband\ServiceRequestsLaravel\Contracts\RecipientVerifier;
use Karband\ServiceRequestsLaravel\Contracts\RequestCatalogPort;
use Karband\ServiceRequestsLaravel\Contracts\RequestDocumentsPort;
use Karband\ServiceRequestsLaravel\Contracts\RequestFormsPort;
use Karband\ServiceRequestsLaravel\Contracts\RequestPaymentPort;
use Karband\ServiceRequestsLaravel\Contracts\RequestWorkflowPort;
use Karband\ServiceRequestsLaravel\Contracts\RequestWorkflowStarter;
use App\Bridges\RequestGuards;
use App\Bridges\ServiceRequestResourceVerifier;
use Karband\WorkflowLaravel\Contracts\WorkflowActionGate;
use Karband\WorkflowLaravel\Contracts\WorkflowAuthorizer;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Host Composition Bridges
        $this->app->bind(\Karband\AuditLaravel\Contracts\AuditPrincipalProvider::class, \App\Bridges\AuditPrincipalProviderBridge::class);
        $this->app->bind(WorkflowAuthorizer::class, WorkflowAuthorizationBridge::class);
        $this->app->bind(\Karband\WorkflowLaravel\Contracts\ActorResolver::class, \App\Bridges\WorkflowActorResolver::class);
        $this->app->bind(RecipientRequestBridge::class, LocalRecipientRequestBridge::class);
        $this->app->bind(RecipientVerifier::class, RecipientOwnershipVerifier::class);

        // Host Gates Wiring
        $this->app->bind(WorkflowActionGate::class, RequestGuards::class);
        $this->app->bind(ResponseEditGate::class, RequestGuards::class);
        $this->app->bind(DocumentEditGate::class, RequestGuards::class);
        $this->app->bind(\Karband\DocumentsLaravel\Contracts\CatalogServiceVerifier::class, \App\Bridges\CatalogServiceVerifierBridge::class);
        $this->app->bind(\Karband\DynamicFormsLaravel\Contracts\FormCatalogVerifier::class, \App\Bridges\FormCatalogVerifierBridge::class);
        $this->app->singleton(\App\Bridges\DefaultJourneyFactProvider::class);
        $this->app->singleton(\Karband\RequestJourneyLaravel\Contracts\JourneyFactProviderInterface::class, fn ($app) => $app->make(\App\Bridges\DefaultJourneyFactProvider::class));
        $this->app->bind(ResourceVerifier::class, ServiceRequestResourceVerifier::class);
        $this->app->bind(RecipientProfileSource::class, LocalRecipientProfileSource::class);
        $this->app->bind(\Karband\BillingLaravel\Contracts\AcceptedQuoteResolverInterface::class, \App\Bridges\ServiceRequestsQuoteResolver::class);
        $this->app->bind(\Karband\BillingLaravel\Contracts\TrustedRefundEventVerifier::class, \App\Bridges\TrustedCancellationRefundVerifier::class);
        $this->app->bind(\Karband\ServiceCatalogLaravel\Contracts\WorkflowDefinitionVerifier::class, \App\Bridges\PublishedWorkflowVerifier::class);
        $this->app->bind(RequestWorkflowPort::class, RequestWorkflowBridge::class);
        $this->app->bind(RequestWorkflowStarter::class, RequestWorkflowBridge::class);
        $this->app->bind(RequestCatalogPort::class, RequestCatalogBridge::class);
        $this->app->bind(RequestFormsPort::class, RequestFormsBridge::class);
        $this->app->bind(RequestDocumentsPort::class, RequestDocumentsBridge::class);
        $this->app->bind(RequestPaymentPort::class, RequestPaymentBridge::class);
        $this->app->bind(\Karband\BillingLaravel\Contracts\PayableRequestGuard::class, \App\Bridges\LocalPayableRequestGuard::class);
        $this->app->bind(\Karband\BillingLaravel\Contracts\TrustedFinancialTermsProvider::class, \App\Bridges\LocalCatalogFinancialTermsProvider::class);
        $this->app->bind(\Karband\BillingLaravel\Contracts\TrustedQuoteProvider::class, \App\Bridges\LocalCatalogFinancialTermsProvider::class);
        $this->app->bind(\Karband\BillingLaravel\Contracts\TrustedRefundPolicyProvider::class, \App\Bridges\LocalCatalogFinancialTermsProvider::class);
        $this->app->bind(\Karband\ServiceRequestsLaravel\Contracts\RepresentativeProfileValidator::class, \App\Bridges\DefaultRepresentativeProfileValidator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

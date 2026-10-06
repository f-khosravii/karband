<?php

declare(strict_types=1);

namespace Intl\ServiceRequestsLaravel\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Crypt;
use Intl\AuthorizationLaravel\Contracts\PrincipalProvider;
use Intl\BillingLaravel\Services\PaymentService;
use Intl\BillingLaravel\ValueObjects\PaymentSnapshot;
use Intl\Contracts\Authorization\AccessRequest;
use Intl\Contracts\Authorization\ActorContext;
use Intl\Contracts\Authorization\AuthorizationDenied;
use Intl\Contracts\Authorization\AuthorizerInterface;
use Intl\DocumentsLaravel\Services\DocumentManager;
use Intl\DocumentsLaravel\Services\DocumentStorage;
use Intl\DynamicFormsLaravel\Services\FormAnswers;
use Intl\DynamicFormsLaravel\Services\FormStorage;
use Intl\DynamicFormsLaravel\Services\FormRegistry;
use Intl\DynamicForms\Engine\FormValidator;
use Intl\ServiceCatalogLaravel\Services\CatalogStorage;
use Intl\ServiceCatalogLaravel\Services\CatalogWorkflowStarter;
use Intl\ServiceRequests\ValueObjects\RecipientReference;
use Intl\ServiceRequests\ValueObjects\RequestDraft;
use Intl\ServiceRequests\ValueObjects\RequestResource;
use Intl\ServiceRequests\ValueObjects\RequestSnapshot;
use Intl\ServiceRequests\Policies\RequestPolicy;
use Intl\ServiceRequestsLaravel\Contracts\RecipientProfileSource;
use Intl\ServiceRequestsLaravel\Contracts\RequestSubmissionVerifier;
use Intl\ServiceRequestsLaravel\Contracts\RecipientVerifier;
use Intl\ServiceRequestsLaravel\Exceptions\RequestConflict;
use Intl\ServiceRequestsLaravel\Exceptions\RequestNotFound;
use Intl\WorkflowLaravel\Services\WorkflowRuntime;
use Intl\WorkflowLaravel\Services\WorkflowStorage;
use Intl\WorkflowLaravel\ValueObjects\ActionOutcome;

/**
 * Thin local orchestrator. Only Request identity/agency/recipient are stored here.
 * Workflow ALONE owns state/history; Forms/Documents/Billing own their data.
 * No central payment calls are ever made inside local request transactions.
 */
final readonly class ServiceRequests
{
    public function __construct(
        private RequestStorage $requests,
        private CatalogStorage $catalog,
        private WorkflowStorage $workflowStorage,
        private FormStorage $formsStorage,
        private DocumentStorage $documentsStorage,
        private CatalogWorkflowStarter $starter,
        private FormAnswers $forms,
        private DocumentManager $documents,
        private WorkflowRuntime $workflow,
        private PrincipalProvider $principals,
        private AuthorizerInterface $authorization,
        private RecipientVerifier $recipients,
        private RecipientProfileSource $profiles,
        private RequestOperationPermit $permits,
        private Container $container,
    ) {}

    /**
     * Atomic creation/pinning using one shared local DB. Requires a published form,
     * document requirements (possibly empty) and Workflow definition for the active service.
     */
    public function create(RequestDraft $draft): RequestSnapshot
    {
        $actor = $this->actor();
        $agency = $this->requireAgency($actor);
        $this->authorization->authorize($actor, new AccessRequest('requests.create', 'catalog.service', $draft->serviceCode));
        $this->sameLocalConnection();
        $db = $this->requests->connection;
        try {
            return $db->transaction(function () use ($db, $draft, $agency, $actor): RequestSnapshot {
                $existing = $db->table('intl_service_requests')->where('agency_id', $agency)
                    ->where('idempotency_key', $draft->idempotencyKey)->lockForUpdate()->first();
                if ($existing !== null) {
                    $this->samePayload($existing, $draft);
                    return $this->readExistingAuthorized($existing, $actor);
                }
                // Lock recipient first: archive/identity changes use the SAME row-lock gate.
                // No host/network lookup or client-supplied identity is trusted here.
                $profile = $this->profiles->lockActive($agency, $draft->recipient);
                $this->assertRecipient($draft->serviceCode, $agency, $draft->recipient);
                $catalog = $db->table('intl_catalog_services')->where('code',$draft->serviceCode)
                    ->lockForUpdate()->first();
                if ($catalog === null || $catalog->status !== 'active') {
                    throw new RequestConflict('Requested service is unavailable.');
                }
                $settings = json_decode((string)$catalog->settings,true,512,JSON_THROW_ON_ERROR);
                if (!is_array($settings) || !is_array($settings['request_management'] ?? null)) {
                    throw new RequestConflict('Service has no explicit Phase 15 request policy.');
                }
                $policy = RequestPolicy::fromArray($settings['request_management']);
                $id = (string) Str::ulid();
                $db->table('intl_service_requests')->insert([
                    'id' => $id,
                    'service_code' => $draft->serviceCode,
                    'agency_id' => $agency,
                    'recipient_type' => $draft->recipient->type,
                    'recipient_id' => $draft->recipient->id,
                    'idempotency_key' => $draft->idempotencyKey,
                    'created_by' => $actor->id,
                    'workflow_instance_id' => null,
                    'form_version' => 0,
                    'document_version' => 0,
                    'policy_json' => json_encode($policy->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'recipient_snapshot_cipher' => Crypt::encryptString(json_encode($profile,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)),
                    'recipient_revision' => $profile['revision'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $form = $this->forms->reserve($draft->serviceCode, RequestResource::TYPE, $id);
                $definition = $this->container->make(FormRegistry::class)
                    ->loadPublished($draft->serviceCode,(int)$form['version']);
                $keys = array_map(static fn($f): string => $f->key,$definition->fields);
                foreach ([...array_values($policy->recipientFieldMap),
                    ...($policy->recipientIdentityField === null ? [] : [$policy->recipientIdentityField])] as $field) {
                    if (!in_array($field,$keys,true)) {
                        throw new RequestConflict('Recipient mapping must point to a published form field.');
                    }
                }
                $docs = $this->documents->reserve($draft->serviceCode, RequestResource::TYPE, $id);
                // CatalogWorkflowStarter locks the service, revalidates active state,
                // and starts the published Workflow version on this request.
                $wf = $this->starter->start($draft->serviceCode, RequestResource::TYPE, $id);
                if ($wf->currentState !== $policy->draftState) {
                    throw new RequestConflict('Pinned service request policy draft state does not match Workflow.');
                }
                $db->table('intl_service_requests')->where('id', $id)->update([
                    'workflow_instance_id' => $wf->id,
                    'form_version' => $form['version'],
                    'document_version' => $docs['version'],
                    'updated_at' => now(),
                ]);
                if ($this->container->bound(\Intl\RequestJourneyLaravel\Contracts\JourneyServiceInterface::class)) {
                    $this->container->make(\Intl\RequestJourneyLaravel\Contracts\JourneyServiceInterface::class)
                        ->pinForRequest($id, $draft->serviceCode);
                }
                return new RequestSnapshot($id, $draft->serviceCode, $agency, $draft->recipient,
                    $wf->id, $wf->workflowId, $wf->workflowVersion, $wf->currentState,
                    $form['version'], $docs['version']);
            }, 1);
        } catch (UniqueConstraintViolationException $exception) {
            // Supports racing identical requests on DBs where row locks do not protect gaps.
            $existing = $db->table('intl_service_requests')->where('agency_id', $agency)
                ->where('idempotency_key', $draft->idempotencyKey)->first();
            if ($existing === null) {
                throw new RequestConflict('Concurrent request creation conflicted.', previous: $exception);
            }
            $this->samePayload($existing, $draft);
            return $this->readExistingAuthorized($existing, $actor);
        }
    }

    public function view(string $id): RequestSnapshot
    {
        return $this->readExistingAuthorized($this->row($id), $this->actor());
    }

    /** No second status engine. Guards and action-level authorization belong to Workflow. */
    public function act(string $id, string $action, ?string $comment = null, ?string $idempotencyKey = null): ActionOutcome
    {
        $snapshot = $this->view($id);
        $db = $this->requests->connection;
        return $db->transaction(function () use ($db, $snapshot, $action, $comment, $idempotencyKey): ActionOutcome {
            // Serialize lifecycle actions through Workflow's authoritative row first.
            $wf = $db->table('intl_workflow_instances')->where('id',$snapshot->workflowInstanceId)
                ->lockForUpdate()->first();
            $row = $db->table('intl_service_requests')->where('id',$snapshot->id)
                ->lockForUpdate()->first();
            if ($wf === null || $row === null || $row->deleted_at !== null || $row->draft_frozen_at !== null) {
                throw new RequestConflict('Request unavailable.');
            }
            $policy = self::pinnedPolicy($row);
            if (in_array($action, [$policy->correctionAction,$policy->resubmitAction,$policy->cancelDraftAction,
                ...array_values($policy->cancelActionsByState)], true)) {
                throw new RequestConflict('Sensitive action must use the dedicated request lifecycle API.');
            }
            if ($db->table('intl_request_cancellations')->where('request_id',$row->id)
                ->where('status','pending')->exists()) {
                throw new RequestConflict('Cancellation review is pending.');
            }
            if (in_array($action, $policy->submitActions, true)) {
                $this->assertReadyToSubmit($snapshot);
            }
            $outcome = $this->permits->within($snapshot->id, 'workflow', fn() =>
                $this->workflow->apply($snapshot->workflowInstanceId,$action,$comment,$idempotencyKey));
            if ($outcome->applied && !$outcome->replayed && $row->first_submitted_at === null
                && $outcome->instance->currentState !== $policy->draftState
                && !in_array($action,$policy->submitActions,true)) {
                throw new RequestConflict('An unsubmitted request may leave draft only via an authorized submit action.');
            }
            if ($outcome->applied && !$outcome->replayed && in_array($action, $policy->submitActions, true)
                && $row->first_submitted_at === null) {
                $db->table('intl_service_requests')->where('id',$snapshot->id)
                    ->whereNull('first_submitted_at')->update(['first_submitted_at'=>now(),'updated_at'=>now()]);
            }
            return $outcome;
        },1);
    }

    /** @return array<int, array<string,mixed>> */
    public function workflowHistory(string $id): array
    {
        $snapshot = $this->view($id);
        return $this->workflow->history($snapshot->workflowInstanceId);
    }

    /** @param array<string,mixed> $answers @return array{version:int,revision:int,answers:array<string,mixed>} */
    public function saveAnswers(string $id, array $answers, int $expectedRevision): array
    {
        $snapshot = $this->agencyOwnedView($id);
        return $this->forms->save($snapshot->serviceCode, RequestResource::TYPE, $id, $answers, $expectedRevision);
    }

    /** @return array{version:int,revision:int,answers:array<string,mixed>} */
    public function readAnswers(string $id): array
    {
        $snapshot = $this->view($id);
        return $this->forms->read($snapshot->serviceCode, RequestResource::TYPE, $id);
    }

    /** @return array<string,mixed> */
    public function uploadDocument(string $id, string $slot, UploadedFile $file, int $expectedRevision): array
    {
        $snapshot = $this->agencyOwnedView($id);
        return $this->documents->upload($snapshot->serviceCode, RequestResource::TYPE, $id, $slot, $file, $expectedRevision);
    }

    /** @return array<string,mixed> */
    public function documents(string $id): array
    {
        $snapshot = $this->view($id);
        return $this->documents->snapshot($snapshot->serviceCode, RequestResource::TYPE, $id);
    }

    /** Create a LOCAL intent ONLY. Never invoke dispatch() in a request DB transaction. */
    public function createPaymentIntent(string $id, string $paymentIdempotencyKey): PaymentSnapshot
    {
        $snapshot = $this->agencyOwnedView($id);
        $row = $this->row($id);
        if ($row->deleted_at !== null || $row->draft_frozen_at !== null ||
            $this->requests->connection->table('intl_request_cancellations')
                ->where('request_id',$id)->where('status','pending')->exists()) {
            throw new RequestConflict('Payment cannot start for a deleted, frozen or cancellation-pending request.');
        }
        // Host TrustedQuoteProvider must independently check frozen workflow status and current local catalog price.
        return $this->container->make(PaymentService::class)
            ->create($snapshot->serviceCode, RequestResource::TYPE, $id, $paymentIdempotencyKey);
    }

    /** Confirmed NO-DEBIT retry. Billing re-quotes TODAY's local price; unknown cannot retry. */
    public function retryFailedPayment(string $requestId, string $paymentId, string $retryKey): PaymentSnapshot
    {
        $request = $this->agencyOwnedView($requestId);
        $billing = $this->container->make(PaymentService::class);
        $payment = $billing->view($paymentId);
        if ($payment->resourceType !== RequestResource::TYPE || $payment->resourceId !== $requestId ||
            $payment->serviceCode !== $request->serviceCode || $payment->agencyId !== $request->agencyId) {
            throw new RequestConflict('Payment does not belong to this request.');
        }
        $row = $this->row($requestId);
        $policy = self::pinnedPolicy($row);
        if ($row->deleted_at !== null || $row->draft_frozen_at !== null ||
            in_array($request->currentState,$policy->terminalStates,true) ||
            $this->requests->connection->table('intl_request_cancellations')
                ->where('request_id',$requestId)->where('status','pending')->exists()) {
            throw new RequestConflict('Cannot retry payment for closed, frozen or cancellation-pending request.');
        }
        return $billing->retryFailed($paymentId,$retryKey);
    }

    public function assertReadyToSubmit(RequestSnapshot $snapshot): void
    {
        $form = $this->container->make(FormRegistry::class)
            ->loadPublished($snapshot->serviceCode,$snapshot->formVersion);
        $saved = $this->forms->read($snapshot->serviceCode,RequestResource::TYPE,$snapshot->id);
        $result = $this->container->make(FormValidator::class)->validate($form,$saved['answers']);
        if (!$result->valid()) { throw new RequestConflict('Form must be valid before submission.'); }
        $documents = $this->documents->snapshot($snapshot->serviceCode,RequestResource::TYPE,$snapshot->id);
        if ($documents['missing'] !== []) { throw new RequestConflict('Required documents must be complete.'); }
        // No permissive fallback for legal representatives or host-specific prerequisites.
        $this->container->make(RequestSubmissionVerifier::class)
            ->assertReady($snapshot->serviceCode,$snapshot->id);
    }

    public static function pinnedPolicy(object $row): RequestPolicy
    {
        if ($row->policy_json === null) { throw new RequestConflict('Legacy request requires policy migration.'); }
        return RequestPolicy::fromArray(json_decode((string)$row->policy_json,true,512,JSON_THROW_ON_ERROR));
    }

    private function agencyOwnedView(string $id): RequestSnapshot
    {
        $actor = $this->actor();
        $agency = $this->requireAgency($actor);
        $snapshot = $this->view($id);
        if ($snapshot->agencyId !== $agency) {
            throw new AuthorizationDenied('foreign_agency');
        }
        return $snapshot;
    }

    private function row(string $id): object
    {
        if (trim($id) === '' || strlen($id) > 191) {
            throw new \InvalidArgumentException('Invalid request id.');
        }
        $row = $this->requests->connection->table('intl_service_requests')->where('id', $id)->first();
        if ($row === null) { throw new RequestNotFound('Request not found.'); }
        return $row;
    }

    private function readExistingAuthorized(object $row, ActorContext $actor): RequestSnapshot
    {
        if ($row->deleted_at !== null) {
            if ($actor->role !== 'platform_admin') { throw new RequestNotFound('Request not found.'); }
            $this->authorization->authorize($actor,
                new AccessRequest('requests.deleted.view', RequestResource::TYPE, (string)$row->id));
        }
        $this->authorization->authorize($actor,
            new AccessRequest('requests.view', RequestResource::TYPE, (string) $row->id));
        if ($actor->role === 'agency' && $actor->organizationId !== (string) $row->agency_id) {
            throw new AuthorizationDenied('foreign_agency');
        }
        if (!in_array($actor->role, ['agency', 'government_admin', 'platform_admin'], true)) {
            throw new AuthorizationDenied('unsupported_request_role');
        }
        // Confirm that the pinned Workflow instance really belongs to THIS request.
        $wf = $this->requests->connection->table('intl_workflow_instances')
            ->where('id', $row->workflow_instance_id)
            ->where('resource_type', RequestResource::TYPE)
            ->where('resource_id', $row->id)->first();
        if ($wf === null) { throw new \RuntimeException('Pinned workflow instance is missing or mismatched.'); }
        $definition = $this->requests->connection->table('intl_workflow_definitions')
            ->where('id', $wf->definition_id)->first();
        if ($definition === null) { throw new \RuntimeException('Pinned workflow definition is missing.'); }
        return new RequestSnapshot((string) $row->id, (string) $row->service_code,
            (string) $row->agency_id,
            new RecipientReference((string) $row->recipient_type, (string) $row->recipient_id),
            (string) $wf->id, (string) $definition->workflow_id, (int) $definition->version,
            (string) $wf->current_state, (int) $row->form_version, (int) $row->document_version,
            $this->requests->connection->table('intl_request_cancellations')->where('request_id',$row->id)
                ->where('status','pending')->exists());
    }

    private function assertRecipient(string $serviceCode, string $agency, RecipientReference $recipient): void
    {
        try {
            if ($this->recipients->belongsToAgency($serviceCode, $agency, $recipient)) { return; }
        } catch (\Throwable) { /* fail closed */ }
        throw new AuthorizationDenied('recipient_not_verified');
    }

    private function samePayload(object $row, RequestDraft $draft): void
    {
        if ($row->service_code !== $draft->serviceCode || $row->recipient_type !== $draft->recipient->type ||
            $row->recipient_id !== $draft->recipient->id) {
            throw new RequestConflict('Idempotency key reused with different request payload.');
        }
    }

    private function actor(): ActorContext { return $this->principals->current()->actor; }

    private function requireAgency(ActorContext $actor): string
    {
        if ($actor->role !== 'agency' || $actor->organizationId === null || $actor->organizationId === '') {
            throw new AuthorizationDenied('agency_identity_required');
        }
        return $actor->organizationId;
    }

    private function sameLocalConnection(): void
    {
        $db = $this->requests->connection;
        if ($this->profiles->connectionName() !== $db->getName()) {
            throw new \LogicException('Recipient profile source must use the same local DB connection.');
        }
        foreach ([$this->catalog->connection, $this->workflowStorage->connection,
            $this->formsStorage->connection, $this->documentsStorage->connection] as $connection) {
            if ($connection !== $db) {
                throw new \LogicException('Request, Catalog, Workflow, Forms and Documents must use one local DB connection.');
            }
        }
    }
}

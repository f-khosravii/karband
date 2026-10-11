<?php

declare(strict_types=1);

namespace Karband\ServiceRequestsLaravel\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Crypt;
use Karband\Authorization\Contracts\ActorProviderInterface;
use Karband\Authorization\AccessRequest;
use Karband\Authorization\ActorContext;
use Karband\Authorization\AuthorizationDenied;
use Karband\Authorization\AuthorizerInterface;
use Karband\ServiceRequests\ValueObjects\RecipientReference;
use Karband\ServiceRequests\ValueObjects\RequestDraft;
use Karband\ServiceRequests\ValueObjects\RequestResource;
use Karband\ServiceRequests\ValueObjects\RequestSnapshot;
use Karband\ServiceRequests\Policies\RequestPolicy;
use Karband\ServiceRequestsLaravel\Contracts\RecipientProfileSource;
use Karband\ServiceRequestsLaravel\Contracts\RecipientVerifier;
use Karband\ServiceRequestsLaravel\Contracts\RequestCatalogPort;
use Karband\ServiceRequestsLaravel\Contracts\RequestDocumentsPort;
use Karband\ServiceRequestsLaravel\Contracts\RequestFormsPort;
use Karband\ServiceRequestsLaravel\Contracts\RequestPaymentPort;
use Karband\ServiceRequestsLaravel\Contracts\RequestSubmissionVerifier;
use Karband\ServiceRequestsLaravel\Contracts\RequestWorkflowPort;
use Karband\ServiceRequestsLaravel\Exceptions\RequestConflict;
use Karband\ServiceRequestsLaravel\Exceptions\RequestNotFound;
use Karband\ServiceRequestsLaravel\ValueObjects\RequestActionResult;

/**
 * Thin local orchestrator. Only Request identity/agency/recipient are stored here.
 * Workflow ALONE owns state/history; Forms/Documents/Billing own their data.
 * No central payment calls are ever made inside local request transactions.
 */
final readonly class ServiceRequests
{
    public function __construct(
        private RequestStorage $requests,
        private RequestCatalogPort $catalog,
        private RequestWorkflowPort $workflow,
        private RequestFormsPort $forms,
        private RequestDocumentsPort $documents,
        private RequestPaymentPort $payments,
        private ActorProviderInterface $actors,
        private AuthorizerInterface $authorization,
        private RecipientVerifier $recipients,
        private RecipientProfileSource $profiles,
        private RequestOperationPermit $permits,
        private RequestSubmissionVerifier $submissionVerifier,
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
                $existing = $db->table('service_requests')->where('agency_id', $agency)
                    ->where('idempotency_key', $draft->idempotencyKey)->lockForUpdate()->first();
                if ($existing !== null) {
                    $this->samePayload($existing, $draft);
                    return $this->readExistingAuthorized($existing, $actor);
                }
                // Lock recipient first: archive/identity changes use the SAME row-lock gate.
                // No host/network lookup or client-supplied identity is trusted here.
                $profile = $this->profiles->lockActive($agency, $draft->recipient);
                $this->assertRecipient($draft->serviceCode, $agency, $draft->recipient);

                $policy = $this->catalog->getPolicy($draft->serviceCode);

                $id = (string) Str::ulid();
                $db->table('service_requests')->insert([
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
                    'recipient_snapshot_cipher' => Crypt::encryptString(json_encode($profile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
                    'recipient_revision' => $profile['revision'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $form = $this->forms->reserve($draft->serviceCode, RequestResource::TYPE, $id);
                $fieldsToCheck = [
                    ...array_values($policy->recipientFieldMap),
                    ...($policy->recipientIdentityField === null ? [] : [$policy->recipientIdentityField]),
                ];
                $this->forms->assertPublishedFieldsContain($draft->serviceCode, (int) $form['version'], $fieldsToCheck);

                $docs = $this->documents->reserve($draft->serviceCode, RequestResource::TYPE, $id);

                $wf = $this->workflow->start($draft->serviceCode, RequestResource::TYPE, $id);
                if ($wf->currentState !== $policy->draftState) {
                    throw new RequestConflict('Pinned service request policy draft state does not match Workflow.');
                }

                $db->table('service_requests')->where('id', $id)->update([
                    'workflow_instance_id' => $wf->id,
                    'form_version' => $form['version'],
                    'document_version' => $docs['version'],
                    'updated_at' => now(),
                ]);

                return new RequestSnapshot(
                    $id,
                    $draft->serviceCode,
                    $agency,
                    $draft->recipient,
                    $wf->id,
                    $wf->workflowId,
                    $wf->workflowVersion,
                    $wf->currentState,
                    $form['version'],
                    $docs['version']
                );
            }, 1);
        } catch (UniqueConstraintViolationException $exception) {
            // Supports racing identical requests on DBs where row locks do not protect gaps.
            $existing = $db->table('service_requests')->where('agency_id', $agency)
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

    /**
     * List non-deleted requests belonging to the authenticated agency.
     *
     * @return array<int, RequestSnapshot>
     */
    public function listForAgency(int $limit = 25, ?string $afterId = null): array
    {
        $actor = $this->actor();
        $agency = $this->requireAgency($actor);
        if ($limit < 1 || $limit > 100 || ($afterId !== null && !preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/D', $afterId))) {
            throw new \InvalidArgumentException('Invalid request pagination.');
        }

        $q = $this->requests->connection->table('service_requests')
            ->where('agency_id', $agency)
            ->whereNull('deleted_at')
            ->orderBy('id', 'desc')
            ->limit($limit);

        if ($afterId !== null) {
            $q->where('id', '<', $afterId);
        }

        return $q->get()->map(fn (object $row): RequestSnapshot => $this->readExistingAuthorized($row, $actor))->all();
    }

    /**
     * List non-deleted submitted requests available for privileged review.
     *
     * @return array<int, RequestSnapshot>
     */
    public function listForReview(?string $serviceCode = null, int $limit = 25, ?string $afterId = null): array
    {
        $actor = $this->actor();
        if ($limit < 1 || $limit > 100 || ($afterId !== null && !preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/D', $afterId))) {
            throw new \InvalidArgumentException('Invalid request pagination.');
        }

        $q = $this->requests->connection->table('service_requests')
            ->whereNotNull('first_submitted_at')
            ->whereNull('deleted_at')
            ->orderBy('id', 'desc')
            ->limit($limit);

        if ($serviceCode !== null) {
            $q->where('service_code', $serviceCode);
        }

        if ($afterId !== null) {
            $q->where('id', '<', $afterId);
        }

        return $q->get()->map(fn (object $row): RequestSnapshot => $this->readExistingAuthorized($row, $actor))->all();
    }

    /** No second status engine. Guards and action-level authorization belong to Workflow. */
    public function act(string $id, string $action, ?string $comment = null, ?string $idempotencyKey = null): RequestActionResult
    {
        $snapshot = $this->view($id);
        $db = $this->requests->connection;
        return $db->transaction(function () use ($db, $snapshot, $action, $comment, $idempotencyKey): RequestActionResult {
            // Serialize lifecycle actions through Workflow's authoritative row first.
            $wf = $this->workflow->lockAndVerify($snapshot->workflowInstanceId, RequestResource::TYPE, $snapshot->id);
            $row = $db->table('service_requests')->where('id', $snapshot->id)
                ->lockForUpdate()->first();
            if ($row === null || $row->deleted_at !== null || $row->draft_frozen_at !== null) {
                throw new RequestConflict('Request unavailable.');
            }
            $policy = self::pinnedPolicy($row);
            if (in_array($action, [$policy->correctionAction, $policy->resubmitAction, $policy->cancelDraftAction,
                ...array_values($policy->cancelActionsByState)], true)) {
                throw new RequestConflict('Sensitive action must use the dedicated request lifecycle API.');
            }
            if ($db->table('request_cancellations')->where('request_id', $row->id)
                ->where('status', 'pending')->exists()) {
                throw new RequestConflict('Cancellation review is pending.');
            }
            if (in_array($action, $policy->submitActions, true)) {
                $this->assertReadyToSubmit($snapshot);
            }
            $outcome = $this->permits->within($snapshot->id, 'workflow', fn () =>
                $this->workflow->apply($snapshot->workflowInstanceId, $action, $comment, $idempotencyKey));
            if ($outcome->applied && !$outcome->replayed && $row->first_submitted_at === null
                && $outcome->currentState !== $policy->draftState
                && !in_array($action, $policy->submitActions, true)) {
                throw new RequestConflict('An unsubmitted request may leave draft only via an authorized submit action.');
            }
            if ($outcome->applied && !$outcome->replayed && in_array($action, $policy->submitActions, true)
                && $row->first_submitted_at === null) {
                $db->table('service_requests')->where('id', $snapshot->id)
                    ->whereNull('first_submitted_at')->update(['first_submitted_at' => now(), 'updated_at' => now()]);
            }
            return $outcome;
        }, 1);
    }

    /** @return array<int, array<string, mixed>> */
    public function workflowHistory(string $id): array
    {
        $snapshot = $this->view($id);
        return $this->workflow->history($snapshot->workflowInstanceId);
    }

    /** @param array<string, mixed> $answers @return array{version: int, revision: int, answers: array<string, mixed>} */
    public function saveAnswers(string $id, array $answers, int $expectedRevision): array
    {
        $snapshot = $this->agencyOwnedView($id);
        return $this->forms->save($snapshot->serviceCode, RequestResource::TYPE, $id, $answers, $expectedRevision);
    }

    /** @return array{version: int, revision: int, answers: array<string, mixed>} */
    public function readAnswers(string $id): array
    {
        $snapshot = $this->view($id);
        return $this->forms->read($snapshot->serviceCode, RequestResource::TYPE, $id);
    }

    /** @return array<string, mixed> */
    public function uploadDocument(string $id, string $slot, UploadedFile $file, int $expectedRevision): array
    {
        $snapshot = $this->agencyOwnedView($id);
        return $this->documents->upload($snapshot->serviceCode, RequestResource::TYPE, $id, $slot, $file, $expectedRevision);
    }

    /** @return array<string, mixed> */
    public function documents(string $id): array
    {
        $snapshot = $this->view($id);
        return $this->documents->snapshot($snapshot->serviceCode, RequestResource::TYPE, $id);
    }

    /** Create a LOCAL intent ONLY. Never invoke dispatch() in a request DB transaction. */
    public function createPaymentIntent(string $id, string $paymentIdempotencyKey): mixed
    {
        $snapshot = $this->agencyOwnedView($id);
        $row = $this->row($id);
        if ($row->deleted_at !== null || $row->draft_frozen_at !== null ||
            $this->requests->connection->table('request_cancellations')
                ->where('request_id', $id)->where('status', 'pending')->exists()) {
            throw new RequestConflict('Payment cannot start for a deleted, frozen or cancellation-pending request.');
        }
        return $this->payments->createIntent($snapshot->serviceCode, RequestResource::TYPE, $id, $paymentIdempotencyKey);
    }

    /** Confirmed NO-DEBIT retry. Billing re-quotes TODAY's local price; unknown cannot retry. */
    public function retryFailedPayment(string $requestId, string $paymentId, string $retryKey): mixed
    {
        $request = $this->agencyOwnedView($requestId);
        $payment = $this->payments->view($paymentId);
        if ($payment->resourceType !== RequestResource::TYPE || $payment->resourceId !== $requestId ||
            $payment->serviceCode !== $request->serviceCode || $payment->agencyId !== $request->agencyId) {
            throw new RequestConflict('Payment does not belong to this request.');
        }
        $row = $this->row($requestId);
        $policy = self::pinnedPolicy($row);
        if ($row->deleted_at !== null || $row->draft_frozen_at !== null ||
            in_array($request->currentState, $policy->terminalStates, true) ||
            $this->requests->connection->table('request_cancellations')
                ->where('request_id', $requestId)->where('status', 'pending')->exists()) {
            throw new RequestConflict('Cannot retry payment for closed, frozen or cancellation-pending request.');
        }
        return $this->payments->retryFailed($paymentId, $retryKey);
    }

    public function assertReadyToSubmit(RequestSnapshot $snapshot): void
    {
        $saved = $this->forms->read($snapshot->serviceCode, RequestResource::TYPE, $snapshot->id);
        $this->forms->validate($snapshot->serviceCode, $snapshot->formVersion, $saved['answers']);
        $documents = $this->documents->snapshot($snapshot->serviceCode, RequestResource::TYPE, $snapshot->id);
        if ($documents['missing'] !== []) {
            throw new RequestConflict('Required documents must be complete.');
        }
        // No permissive fallback for legal representatives or host-specific prerequisites.
        $this->submissionVerifier->assertReady($snapshot->serviceCode, $snapshot->id);
    }

    public static function pinnedPolicy(object $row): RequestPolicy
    {
        if ($row->policy_json === null) {
            throw new RequestConflict('Legacy request requires policy migration.');
        }
        return RequestPolicy::fromArray(json_decode((string) $row->policy_json, true, 512, JSON_THROW_ON_ERROR));
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
        $row = $this->requests->connection->table('service_requests')->where('id', $id)->first();
        if ($row === null) {
            throw new RequestNotFound('Request not found.');
        }
        return $row;
    }

    private function readExistingAuthorized(object $row, ActorContext $actor): RequestSnapshot
    {
        if ($row->deleted_at !== null) {
            try {
                $this->authorization->authorize(
                    $actor,
                    new AccessRequest('requests.deleted.view', RequestResource::TYPE, (string) $row->id)
                );
            } catch (\Throwable) {
                throw new RequestNotFound('Request not found.');
            }
        }
        $this->authorization->authorize(
            $actor,
            new AccessRequest('requests.view', RequestResource::TYPE, (string) $row->id)
        );
        if ($actor->organizationId !== null && (string) $row->agency_id !== $actor->organizationId) {
            if (!$this->hasPrivilegedReviewAccess($actor, (string) $row->id)) {
                throw new AuthorizationDenied('foreign_agency');
            }
        }

        $defDetails = $this->workflow->getDefinitionDetails((string) $row->workflow_instance_id, RequestResource::TYPE, (string) $row->id);

        return new RequestSnapshot(
            (string) $row->id,
            (string) $row->service_code,
            (string) $row->agency_id,
            new RecipientReference((string) $row->recipient_type, (string) $row->recipient_id),
            (string) $row->workflow_instance_id,
            (string) $defDetails['workflowId'],
            (int) $defDetails['workflowVersion'],
            (string) $defDetails['currentState'],
            (int) $row->form_version,
            (int) $row->document_version,
            $this->requests->connection->table('request_cancellations')->where('request_id', $row->id)
                ->where('status', 'pending')->exists()
        );
    }

    private function hasPrivilegedReviewAccess(ActorContext $actor, string $requestId): bool
    {
        foreach (['requests.cancel.decide', 'requests.correction.issue', 'requests.restore', 'requests.deleted.view'] as $ability) {
            try {
                $this->authorization->authorize($actor, new AccessRequest($ability, RequestResource::TYPE, $requestId));
                return true;
            } catch (\Throwable) {
                continue;
            }
        }
        return false;
    }

    private function assertRecipient(string $serviceCode, string $agency, RecipientReference $recipient): void
    {
        try {
            if ($this->recipients->belongsToAgency($serviceCode, $agency, $recipient)) {
                return;
            }
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

    private function actor(): ActorContext
    {
        return $this->actors->currentActor();
    }

    private function requireAgency(ActorContext $actor): string
    {
        if ($actor->organizationId === null || trim($actor->organizationId) === '') {
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
        foreach ([
            $this->catalog->connectionName(),
            $this->workflow->connectionName(),
            $this->forms->connectionName(),
            $this->documents->connectionName(),
        ] as $connName) {
            if ($connName !== null && $connName !== $db->getName()) {
                throw new \LogicException('Request, Catalog, Workflow, Forms and Documents must use one local DB connection.');
            }
        }
    }
}

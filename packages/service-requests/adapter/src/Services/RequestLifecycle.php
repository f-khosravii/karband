<?php

declare(strict_types=1);

namespace Karband\ServiceRequestsLaravel\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Karband\Authorization\Contracts\ActorProviderInterface;
use Karband\Authorization\AccessRequest;
use Karband\Authorization\ActorContext;
use Karband\Authorization\AuthorizationDenied;
use Karband\Authorization\AuthorizerInterface;
use Karband\ServiceRequests\Policies\CorrectionScope;
use Karband\ServiceRequests\Policies\RequestPolicy;
use Karband\ServiceRequests\ValueObjects\RecipientReference;
use Karband\ServiceRequests\ValueObjects\RequestResource;
use Karband\ServiceRequestsLaravel\Contracts\RecipientProfileSource;
use Karband\ServiceRequestsLaravel\Contracts\RepresentativeProfileValidator;
use Karband\ServiceRequestsLaravel\Contracts\RequestAudit;
use Karband\ServiceRequestsLaravel\Contracts\RequestCatalogPort;
use Karband\ServiceRequestsLaravel\Contracts\RequestDocumentsPort;
use Karband\ServiceRequestsLaravel\Contracts\RequestFormsPort;
use Karband\ServiceRequestsLaravel\Contracts\RequestPaymentPort;
use Karband\ServiceRequestsLaravel\Contracts\RequestWorkflowPort;
use Karband\ServiceRequestsLaravel\Exceptions\RequestConflict;
use Karband\ServiceRequestsLaravel\Exceptions\RequestNotFound;
use Karband\ServiceRequestsLaravel\ValueObjects\RequestActionResult;
use Karband\ServiceRequestsLaravel\ValueObjects\RequestWorkflowInstance;

/**
 * Isolated lifecycle coordinator for service requests.
 * Directly owns ONLY service_requests, request_cancellations, request_representatives, and request_corrections.
 */
final readonly class RequestLifecycle
{
    public function __construct(
        private RequestStorage $storage,
        private RequestWorkflowPort $workflow,
        private RequestCatalogPort $catalog,
        private RequestFormsPort $forms,
        private RequestDocumentsPort $documents,
        private RequestPaymentPort $payments,
        private ActorProviderInterface $actors,
        private AuthorizerInterface $authorization,
        private RecipientProfileSource $profiles,
        private RepresentativeProfileValidator $repValidator,
        private RequestOperationPermit $permit,
        private RequestAudit $audit,
        private ServiceRequests $requests,
    ) {}

    /** Agency can cancel an unsent draft immediately, through a published Workflow transition. */
    public function cancelDraft(string $id, ?string $reason = null): RequestActionResult
    {
        $actor = $this->actor();
        return $this->locked($id, function (object $row, RequestWorkflowInstance $wf) use ($actor, $reason): RequestActionResult {
            $this->agency($actor, $row, 'requests.cancel.draft');
            $policy = ServiceRequests::pinnedPolicy($row);
            if ($row->first_submitted_at !== null || $wf->currentState !== $policy->draftState ||
                $row->draft_frozen_at !== null || $row->deleted_at !== null) {
                throw new RequestConflict('Only an editable unsent draft can be immediately cancelled.');
            }
            $this->noPendingCancellation($row);
            $this->payments->assertNoUnresolvedPayment((string) $row->id);
            $this->payments->assertNoSuccessfulPayment((string) $row->id);

            $outcome = $this->permit->within((string) $row->id, 'workflow', fn () =>
                $this->workflow->apply((string) $row->workflow_instance_id, $policy->cancelDraftAction, $reason));
            if (!$outcome->applied || $outcome->currentState !== $policy->cancelledState) {
                throw new RequestConflict('Published Workflow denied draft cancellation or target was not cancelled.');
            }
            $this->audit->record('requests.draft.cancelled', (string) $row->id, ['history_id' => (int) $outcome->historyId]);
            return $outcome;
        });
    }

    /** Agency-only request. Workflow remains in EXACT original state until government decision. */
    public function requestCancellation(string $id, ?string $reason = null): string
    {
        $actor = $this->actor();
        return $this->locked($id, function (object $row, RequestWorkflowInstance $wf) use ($actor, $reason): string {
            $this->agency($actor, $row, 'requests.cancel.request');
            $policy = ServiceRequests::pinnedPolicy($row);
            if ($row->first_submitted_at === null || !$policy->mayRequestCancellation((string) $wf->currentState)
                || $row->draft_frozen_at !== null || $row->deleted_at !== null) {
                throw new RequestConflict('Cancellation can only be requested after submission and before approval.');
            }
            $this->noPendingCancellation($row);

            $action = $policy->cancellationAction((string) $wf->currentState);
            $this->workflow->assertCancellationTransitionValid(
                (int) $wf->definitionRecordId,
                (string) $wf->currentState,
                $action,
                $policy->cancelledState
            );

            $uuid = (string) Str::ulid();
            $this->storage->connection->table('request_cancellations')->insert([
                'id' => $uuid,
                'request_id' => $row->id,
                'original_workflow_state' => $wf->currentState,
                'original_workflow_revision' => $wf->revision,
                'status' => 'pending',
                'requested_by' => $actor->id,
                'request_reason' => $reason,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->record('requests.cancellation.requested', (string) $row->id, ['cancellation_id' => $uuid]);
            return $uuid;
        });
    }

    /** Government Admin decision commits an atomic cancel transition or denies with durable record. */
    public function decideCancellation(string $id, bool $approve, ?string $reason = null): ?RequestActionResult
    {
        $actor = $this->actor();
        return $this->locked($id, function (object $row, RequestWorkflowInstance $wf) use ($actor, $approve, $reason): ?RequestActionResult {
            $this->government($actor, $row, 'requests.cancel.decide');
            $pending = $this->pendingCancellation($row);
            $this->unchangedSinceCancellation($pending, $wf);
            $outcome = null;
            if ($approve) {
                $this->payments->assertNoUnresolvedPayment((string) $row->id);
                $policy = ServiceRequests::pinnedPolicy($row);
                $action = $policy->cancellationAction((string) $wf->currentState);
                $outcome = $this->permit->within((string) $row->id, 'cancellation_decision', fn () =>
                    $this->workflow->apply((string) $row->workflow_instance_id, $action, $reason));
                if (!$outcome->applied || $outcome->currentState !== $policy->cancelledState) {
                    throw new RequestConflict('Pinned Workflow rejected cancellation or its target was not cancelled.');
                }
            }
            $updated = $this->storage->connection->table('request_cancellations')
                ->where('id', $pending->id)->where('status', 'pending')->update([
                    'status' => $approve ? 'approved' : 'denied',
                    'decided_by' => $actor->id,
                    'decision_reason' => $reason,
                    'workflow_history_id' => $outcome?->historyId,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new RequestConflict('Cancellation was decided concurrently.');
            }
            $this->audit->record(
                $approve ? 'requests.cancellation.approved' : 'requests.cancellation.denied',
                (string) $row->id,
                ['cancellation_id' => (string) $pending->id]
            );
            return $outcome;
        });
    }

    /** Agency can withdraw its request until a government decision commits. */
    public function withdrawCancellation(string $id): void
    {
        $actor = $this->actor();
        $this->locked($id, function (object $row, RequestWorkflowInstance $wf) use ($actor): void {
            $this->agency($actor, $row, 'requests.cancel.withdraw');
            $pending = $this->pendingCancellation($row);
            $this->unchangedSinceCancellation($pending, $wf);
            $updated = $this->storage->connection->table('request_cancellations')
                ->where('id', $pending->id)->where('status', 'pending')->update([
                    'status' => 'withdrawn',
                    'decided_by' => $actor->id,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new RequestConflict('Cancellation was decided concurrently.');
            }
            $this->audit->record('requests.cancellation.withdrawn', (string) $row->id, ['cancellation_id' => (string) $pending->id]);
        });
    }

    /** Every round is separate; no cap. Government must select allowlisted fields/slots for limited services. */
    public function requireCorrection(string $id, ?string $reason = null, array $fields = [], array $slots = []): RequestActionResult
    {
        $actor = $this->actor();
        return $this->locked($id, function (object $row, RequestWorkflowInstance $wf) use ($actor, $reason, $fields, $slots): RequestActionResult {
            $this->government($actor, $row, 'requests.correction.issue');
            $this->noPendingCancellation($row);
            $policy = ServiceRequests::pinnedPolicy($row);
            if ($row->first_submitted_at === null || $row->draft_frozen_at !== null || $row->deleted_at !== null) {
                throw new RequestConflict('Corrections can only be issued on active submitted requests.');
            }
            $activeRound = $this->storage->connection->table('request_corrections')
                ->where('request_id', $row->id)->where('active', true)->first();
            if ($activeRound !== null) {
                throw new RequestConflict('A correction round is already active for this request.');
            }
            $scope = new CorrectionScope($policy->correctionMode, $fields, $slots);
            $this->verifyScope((string) $row->service_code, (int) $row->form_version, (int) $row->document_version, $scope);
            $outcome = $this->permit->within((string) $row->id, 'workflow', fn () =>
                $this->workflow->apply((string) $row->workflow_instance_id, $policy->correctionAction, $reason));
            if (!$outcome->applied || !in_array($outcome->currentState, $policy->correctionStates, true)) {
                throw new RequestConflict('Pinned Workflow rejected the correction action.');
            }
            $round = (int) $this->storage->connection->table('request_corrections')->where('request_id', $row->id)->count() + 1;
            $this->storage->connection->table('request_corrections')->insert([
                'id' => (string) Str::ulid(),
                'request_id' => $row->id,
                'round' => $round,
                'active' => true,
                'mode' => $scope->mode,
                'allowed_fields' => json_encode($scope->fields, JSON_THROW_ON_ERROR),
                'allowed_slots' => json_encode($scope->documentSlots, JSON_THROW_ON_ERROR),
                'reason' => $reason,
                'requested_by' => $actor->id,
                'workflow_history_id' => (int) $outcome->historyId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->record('requests.correction.required', (string) $row->id, ['round' => $round]);
            return $outcome;
        });
    }

    /** Agency saves answers; checked against round allowlist when not in 'full' mode. */
    public function saveCorrectedAnswers(string $id, array $answers, int $expectedRevision): array
    {
        $actor = $this->actor();
        $snapshot = $this->requests->view($id);
        if ($actor->organizationId === null || $actor->organizationId !== $snapshot->agencyId) {
            throw new AuthorizationDenied('agency_scope_denied');
        }
        $row = $this->storage->connection->table('service_requests')->where('id', $id)->first();
        if ($row === null) {
            throw new RequestNotFound('Request not found.');
        }
        $policy = ServiceRequests::pinnedPolicy($row);
        if (!in_array($snapshot->currentState, $policy->correctionStates, true)) {
            throw new RequestConflict('Answers can be corrected only while awaiting correction.');
        }
        $correction = $this->storage->connection->table('request_corrections')
            ->where('request_id', $id)->where('active', true)->first();
        if ($correction === null) {
            throw new RequestConflict('No active correction round.');
        }
        if ($correction->mode !== 'full') {
            $allowed = json_decode((string) $correction->allowed_fields, true, 512, JSON_THROW_ON_ERROR);
            $current = $this->forms->read($snapshot->serviceCode, RequestResource::TYPE, $id)['answers'];
            foreach ($answers as $k => $v) {
                if (!in_array($k, $allowed, true) && (!array_key_exists($k, $current) || $current[$k] !== $v)) {
                    throw new RequestConflict("Field '{$k}' is not editable in this correction round.");
                }
            }
        }
        return $this->forms->save($snapshot->serviceCode, RequestResource::TYPE, $id, $answers, $expectedRevision);
    }

    /** Agency uploads document; checked against round allowlist when not in 'full' mode. */
    public function uploadCorrectedDocument(string $id, string $slot, UploadedFile $file, int $expectedRevision): array
    {
        $actor = $this->actor();
        $snapshot = $this->requests->view($id);
        if ($actor->organizationId === null || $actor->organizationId !== $snapshot->agencyId) {
            throw new AuthorizationDenied('agency_scope_denied');
        }
        $row = $this->storage->connection->table('service_requests')->where('id', $id)->first();
        if ($row === null) {
            throw new RequestNotFound('Request not found.');
        }
        $policy = ServiceRequests::pinnedPolicy($row);
        if (!in_array($snapshot->currentState, $policy->correctionStates, true)) {
            throw new RequestConflict('Documents can be corrected only while awaiting correction.');
        }
        $correction = $this->storage->connection->table('request_corrections')
            ->where('request_id', $id)->where('active', true)->first();
        if ($correction === null) {
            throw new RequestConflict('No active correction round.');
        }
        if ($correction->mode !== 'full') {
            $allowed = json_decode((string) $correction->allowed_slots, true, 512, JSON_THROW_ON_ERROR);
            if (!in_array($slot, $allowed, true)) {
                throw new RequestConflict("Document slot '{$slot}' is not editable in this correction round.");
            }
        }
        return $this->documents->upload($snapshot->serviceCode, RequestResource::TYPE, $id, $slot, $file, $expectedRevision);
    }

    /** Agency resubmits request after correcting; closes active round. */
    public function resubmit(string $id, ?string $comment = null): RequestActionResult
    {
        $actor = $this->actor();
        return $this->locked($id, function (object $row, RequestWorkflowInstance $wf) use ($actor, $comment, $id): RequestActionResult {
            $this->agency($actor, $row, 'requests.correction.resubmit');
            $policy = ServiceRequests::pinnedPolicy($row);
            if (!in_array($wf->currentState, $policy->correctionStates, true)) {
                throw new RequestConflict('Only requests currently in correction state may be resubmitted.');
            }
            $snapshot = $this->requests->view($id);
            $this->requests->assertReadyToSubmit($snapshot);
            $outcome = $this->permit->within($id, 'workflow', fn () =>
                $this->workflow->apply((string) $row->workflow_instance_id, $policy->resubmitAction, $comment));
            if (!$outcome->applied || in_array($outcome->currentState, $policy->correctionStates, true)) {
                throw new RequestConflict('Workflow rejected resubmission.');
            }
            $this->storage->connection->table('request_corrections')->where('request_id', $id)
                ->where('active', true)->update(['active' => false, 'closed_at' => now(), 'updated_at' => now()]);
            $this->audit->record('requests.resubmitted', $id);
            return $outcome;
        });
    }

    /** Agency can soft-delete an unsubmitted, never-frozen draft with no local payments. */
    public function deleteDraft(string $id): void
    {
        $actor = $this->actor();
        $this->locked($id, function (object $row, RequestWorkflowInstance $wf) use ($actor, $id): void {
            $this->agency($actor, $row, 'requests.draft.delete');
            $policy = ServiceRequests::pinnedPolicy($row);
            if ($row->deleted_at !== null || $row->draft_frozen_at !== null ||
                $row->first_submitted_at !== null || $wf->currentState !== $policy->draftState) {
                throw new RequestConflict('Only an editable never-submitted draft can be soft-deleted.');
            }
            $this->noPendingCancellation($row);
            $this->payments->assertNoPaymentsExist($id);
            $this->storage->connection->table('service_requests')->where('id', $id)->whereNull('deleted_at')
                ->update(['deleted_at' => now(), 'deleted_by' => $actor->id, 'updated_at' => now()]);
            $this->audit->record('requests.draft.deleted', $id);
        });
    }

    /** Platform Admin only. Catalog service must be active and old frozen drafts never revive. */
    public function restoreDraft(string $id): void
    {
        $actor = $this->actor();
        $this->locked($id, function (object $row, RequestWorkflowInstance $wf) use ($actor, $id): void {
            $this->platform($actor, $row, 'requests.restore');
            $policy = ServiceRequests::pinnedPolicy($row);
            if ($row->deleted_at === null || $row->draft_frozen_at !== null ||
                $row->first_submitted_at !== null || $wf->currentState !== $policy->draftState) {
                throw new RequestConflict('Draft is not eligible for restoration.');
            }
            $this->catalog->assertServiceActive((string) $row->service_code);
            $this->profiles->lockActive(
                (string) $row->agency_id,
                new RecipientReference((string) $row->recipient_type, (string) $row->recipient_id)
            );
            $this->storage->connection->table('service_requests')->where('id', $id)->whereNotNull('deleted_at')
                ->update(['deleted_at' => null, 'deleted_by' => null, 'updated_at' => now()]);
            $this->audit->record('requests.draft.restored', $id);
        });
    }

    /**
     * Exactly ONE encrypted legal-representative snapshot per request.
     * @param array<string, mixed>|null $requestProfile
     */
    public function selectLegalRepresentative(
        string $id,
        ?string $profileRepresentativeId,
        ?array $requestProfile,
        int $expectedRevision = 0
    ): void {
        $actor = $this->actor();
        $this->locked($id, function (object $row, RequestWorkflowInstance $wf) use ($actor, $id, $profileRepresentativeId, $requestProfile, $expectedRevision): void {
            $this->agency($actor, $row, 'requests.representative.select');
            $policy = ServiceRequests::pinnedPolicy($row);
            if ($row->recipient_type !== 'legal' || $row->first_submitted_at !== null ||
                $wf->currentState !== $policy->draftState || $row->draft_frozen_at !== null ||
                $row->deleted_at !== null) {
                throw new RequestConflict('Representative selection is available only for active legal drafts.');
            }
            $this->noPendingCancellation($row);

            $this->profiles->assertLegalRecipientActive((string) $row->agency_id, (string) $row->recipient_id);

            $mode = config('service-requests.legal_representative_mode');
            if (!in_array($mode, ['profile', 'request'], true)) {
                throw new RequestConflict('Microservice must explicitly configure legal representative mode.');
            }
            if ($mode === 'profile') {
                if (!is_string($profileRepresentativeId) || $profileRepresentativeId === '' || $requestProfile !== null) {
                    throw new \InvalidArgumentException('Select one stored active representative.');
                }
                $data = $this->profiles->getActiveRepresentativeProfile((string) $row->recipient_id, $profileRepresentativeId);
            } else {
                if ($profileRepresentativeId !== null || $requestProfile === null ||
                    $requestProfile === [] || array_is_list($requestProfile)) {
                    throw new \InvalidArgumentException('Enter one request-only representative profile.');
                }
                $this->repValidator->assertValid((string) $row->service_code, $requestProfile);
                $data = $requestProfile;
            }

            $db = $this->storage->connection;
            $current = $db->table('request_representatives')->where('request_id', $id)
                ->lockForUpdate()->first();
            if (($current === null ? 0 : (int) $current->revision) !== $expectedRevision) {
                throw new RequestConflict('Representative was changed concurrently.');
            }
            $values = [
                'source' => $mode,
                'profile_representative_id' => $profileRepresentativeId,
                'snapshot_cipher' => $this->profiles->encryptRepresentativeProfile($data),
                'revision' => $expectedRevision + 1,
                'updated_at' => now(),
            ];
            if ($current === null) {
                $db->table('request_representatives')->insert(['request_id' => $id, 'created_at' => now(), ...$values]);
            } else {
                $db->table('request_representatives')->where('request_id', $id)
                    ->where('revision', $expectedRevision)->update($values);
            }
            $this->audit->record('requests.representative.selected', $id, ['revision' => $expectedRevision + 1]);
        });
    }

    /** @return array<int, object> Authorized cancellation history (reasons can contain confidential text). */
    public function cancellationHistory(string $id): array
    {
        $this->requests->view($id);
        return $this->storage->connection->table('request_cancellations')
            ->where('request_id', $id)->orderBy('created_at')->get()->all();
    }

    /** @return array<int, object> Authorized correction history. */
    public function correctionHistory(string $id): array
    {
        $this->requests->view($id);
        return $this->storage->connection->table('request_corrections')
            ->where('request_id', $id)->orderBy('round', 'asc')->get()->all();
    }

    /** @return object|null Authorized active correction round. */
    public function activeCorrection(string $id): ?object
    {
        $this->requests->view($id);
        return $this->storage->connection->table('request_corrections')
            ->where('request_id', $id)->where('active', true)->first();
    }

    /** Execute under the authoritative Workflow row lock, THEN the local request lock, then audit. */
    private function locked(string $id, callable $operation): mixed
    {
        $db = $this->storage->connection;
        if ($this->audit->connectionName() !== $db->getName() || $this->profiles->connectionName() !== $db->getName()) {
            throw new \LogicException('Request, recipient, and synchronous audit must use the same local connection.');
        }
        return $db->transaction(function () use ($id, $db, $operation): mixed {
            $request = $db->table('service_requests')->where('id', $id)->first();
            if ($request === null) {
                throw new RequestNotFound('Request not found.');
            }
            $wf = $this->workflow->lockAndVerify((string) $request->workflow_instance_id, RequestResource::TYPE, $id);
            $row = $db->table('service_requests')->where('id', $id)->lockForUpdate()->first();
            if ($row === null) {
                throw new RequestConflict('Request/Workflow pin mismatch.');
            }
            return $operation($row, $wf);
        }, 1);
    }

    private function actor(): ActorContext
    {
        return $this->actors->currentActor();
    }

    private function agency(ActorContext $actor, object $row, string $permission): void
    {
        if ($actor->organizationId === null || $actor->organizationId !== (string) $row->agency_id) {
            throw new AuthorizationDenied('agency_ownership_required');
        }
        $this->authorization->authorize($actor, new AccessRequest($permission, RequestResource::TYPE, (string) $row->id));
    }

    private function government(ActorContext $actor, object $row, string $permission): void
    {
        $this->authorization->authorize($actor, new AccessRequest($permission, RequestResource::TYPE, (string) $row->id));
    }

    private function platform(ActorContext $actor, object $row, string $permission): void
    {
        $this->authorization->authorize($actor, new AccessRequest($permission, RequestResource::TYPE, (string) $row->id));
    }

    private function pendingCancellation(object $row): object
    {
        $pending = $this->storage->connection->table('request_cancellations')
            ->where('request_id', $row->id)->where('status', 'pending')->lockForUpdate()->first();
        if ($pending === null) {
            throw new RequestConflict('No pending cancellation request for this service request.');
        }
        return $pending;
    }

    private function noPendingCancellation(object $row): void
    {
        if ($this->storage->connection->table('request_cancellations')
            ->where('request_id', $row->id)->where('status', 'pending')->exists()) {
            throw new RequestConflict('A cancellation request is pending review.');
        }
    }

    private function unchangedSinceCancellation(object $pending, RequestWorkflowInstance $wf): void
    {
        if ($wf->currentState !== $pending->original_workflow_state ||
            $wf->revision !== (int) $pending->original_workflow_revision) {
            throw new RequestConflict('Workflow changed while cancellation was awaiting review.');
        }
    }

    private function verifyScope(string $serviceCode, int $formVersion, int $documentVersion, CorrectionScope $scope): void
    {
        if ($scope->mode === 'full') {
            return;
        }
        $this->forms->assertValidScopeFields($serviceCode, $formVersion, $scope->fields);
        $this->documents->assertValidScopeSlots($serviceCode, $documentVersion, $scope->documentSlots);
    }
}

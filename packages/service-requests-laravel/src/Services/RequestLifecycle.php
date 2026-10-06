<?php

declare(strict_types=1);

namespace Intl\ServiceRequestsLaravel\Services;

use Illuminate\Support\Str;
use Intl\AuthorizationLaravel\Contracts\PrincipalProvider;
use Intl\Contracts\Authorization\AccessRequest;
use Intl\Contracts\Authorization\ActorContext;
use Intl\Contracts\Authorization\AuthorizationDenied;
use Intl\Contracts\Authorization\AuthorizerInterface;
use Intl\DocumentsLaravel\Services\DocumentStorage;
use Intl\DynamicFormsLaravel\Services\FormRegistry;
use Intl\ServiceRequests\Policies\CorrectionScope;
use Intl\ServiceRequests\Policies\RequestPolicy;
use Intl\ServiceRequests\ValueObjects\RequestResource;
use Intl\ServiceRequestsLaravel\Contracts\RequestAudit;
use Intl\ServiceRequestsLaravel\Contracts\RecipientProfileSource;
use Intl\ServiceRequestsLaravel\Contracts\RepresentativeProfileValidator;
use Intl\ServiceRecipientsLaravel\Services\RecipientStorage;
use Intl\ServiceRequestsLaravel\Exceptions\RequestConflict;
use Intl\ServiceRequestsLaravel\Exceptions\RequestNotFound;
use Intl\WorkflowLaravel\Services\DefinitionCatalog;
use Intl\WorkflowLaravel\Services\WorkflowRuntime;
use Intl\WorkflowLaravel\ValueObjects\ActionOutcome;

/** Local-only orchestration. Cancellation REVIEW is separate; authoritative request state stays in Workflow. */
final readonly class RequestLifecycle
{
    public function __construct(
        private RequestStorage $storage,
        private ServiceRequests $requests,
        private WorkflowRuntime $workflow,
        private DefinitionCatalog $definitions,
        private RequestOperationPermit $permit,
        private PrincipalProvider $principals,
        private AuthorizerInterface $authorization,
        private FormRegistry $forms,
        private DocumentStorage $documents,
        private RecipientProfileSource $profiles,
        private RequestAudit $audit,
        private RecipientStorage $recipientStorage,
    ) {}

    /** Agency can cancel an unsent draft immediately, through a published Workflow transition. */
    public function cancelDraft(string $id, ?string $reason = null): ActionOutcome
    {
        $actor = $this->actor();
        return $this->locked($id, function (object $row, object $wf) use ($actor,$reason): ActionOutcome {
            $this->agency($actor,$row,'requests.cancel.draft');
            $policy = ServiceRequests::pinnedPolicy($row);
            if ($row->first_submitted_at !== null || $wf->current_state !== $policy->draftState ||
                $row->draft_frozen_at !== null || $row->deleted_at !== null) {
                throw new RequestConflict('Only an editable unsent draft can be immediately cancelled.');
            }
            $this->noPendingCancellation($row);
            $this->assertNoUnresolvedPayment((string)$row->id);
            // Never silently cancel an already-paid unsent draft until a trusted refund event is defined.
            if ($this->storage->connection->table('intl_payment_intents')
                ->where('resource_type',RequestResource::TYPE)->where('resource_id',$row->id)
                ->where('status','succeeded')->exists()) {
                throw new RequestConflict('Paid draft requires a separate trusted cancellation/refund policy.');
            }
            $outcome = $this->permit->within((string)$row->id,'workflow',fn() =>
                $this->workflow->apply((string)$row->workflow_instance_id,$policy->cancelDraftAction,$reason));
            if (!$outcome->applied || $outcome->instance->currentState !== $policy->cancelledState) {
                throw new RequestConflict('Published Workflow denied draft cancellation or target was not cancelled.');
            }
            $this->audit->record('requests.draft.cancelled',(string)$row->id,['history_id'=>(int)$outcome->historyId]);
            return $outcome;
        });
    }

    /** Agency-only request. Workflow remains in EXACT original state until government decision. */
    public function requestCancellation(string $id, ?string $reason = null): string
    {
        $actor = $this->actor();
        return $this->locked($id, function (object $row, object $wf) use ($actor,$reason): string {
            $this->agency($actor,$row,'requests.cancel.request');
            $policy = ServiceRequests::pinnedPolicy($row);
            if ($row->first_submitted_at === null || !$policy->mayRequestCancellation((string)$wf->current_state)
                || $row->draft_frozen_at !== null || $row->deleted_at !== null) {
                throw new RequestConflict('Cancellation can only be requested after submission and before approval.');
            }
            $this->noPendingCancellation($row);
            // Verify that the CURRENT pinned definition really supports the government decision.
            $action = $policy->cancellationAction((string)$wf->current_state);
            $definition = $this->definitions->byRecordId((int)$wf->definition_id);
            $transition = $definition->findTransition((string)$wf->current_state,$action);
            if ($transition === null || !in_array('government_admin',$transition->allowedRoles,true)
                || $transition->to !== $policy->cancelledState) {
                throw new RequestConflict('Pinned Workflow has no valid cancellation decision transition.');
            }
            $uuid = (string)Str::ulid();
            $this->storage->connection->table('intl_request_cancellations')->insert([
                'id'=>$uuid,'request_id'=>$row->id,'original_workflow_state'=>$wf->current_state,
                'original_workflow_revision'=>$wf->revision,'status'=>'pending',
                'requested_by'=>$actor->id,'request_reason'=>$reason,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->audit->record('requests.cancellation.requested',(string)$row->id);
            return $uuid;
        });
    }

    /** Only government may approve or deny an EXISTING agency cancellation request. */
    public function decideCancellation(string $id, bool $approve, ?string $reason = null): ?ActionOutcome
    {
        $actor = $this->actor();
        return $this->locked($id, function (object $row, object $wf) use ($actor,$approve,$reason): ?ActionOutcome {
            $this->government($actor,$row,'requests.cancel.decide');
            $pending = $this->pendingCancellation($row);
            $this->unchangedSinceCancellation($pending,$wf);
            $outcome = null;
            if ($approve) {
                $this->assertNoUnresolvedPayment((string)$row->id);
                $policy = ServiceRequests::pinnedPolicy($row);
                $action = $policy->cancellationAction((string)$wf->current_state);
                $outcome = $this->permit->within((string)$row->id,'cancellation_decision',fn() =>
                    $this->workflow->apply((string)$row->workflow_instance_id,$action,$reason));
                if (!$outcome->applied || $outcome->instance->currentState !== $policy->cancelledState) {
                    throw new RequestConflict('Pinned Workflow rejected cancellation or its target was not cancelled.');
                }
            }
            $updated = $this->storage->connection->table('intl_request_cancellations')
                ->where('id',$pending->id)->where('status','pending')->update([
                    'status'=>$approve?'approved':'denied','decided_by'=>$actor->id,
                    'decision_reason'=>$reason,'workflow_history_id'=>$outcome?->historyId,'updated_at'=>now(),
                ]);
            if ($updated !== 1) { throw new RequestConflict('Cancellation was decided concurrently.'); }
            $this->audit->record($approve?'requests.cancellation.approved':'requests.cancellation.denied',
                (string)$row->id,['cancellation_id'=>(string)$pending->id]);
            return $outcome;
        });
    }

    /** Agency can withdraw its request until a government decision commits. */
    public function withdrawCancellation(string $id): void
    {
        $actor = $this->actor();
        $this->locked($id, function (object $row, object $wf) use ($actor): void {
            $this->agency($actor,$row,'requests.cancel.withdraw');
            $pending = $this->pendingCancellation($row);
            $this->unchangedSinceCancellation($pending,$wf);
            $updated = $this->storage->connection->table('intl_request_cancellations')
                ->where('id',$pending->id)->where('status','pending')->update([
                    'status'=>'withdrawn','decided_by'=>$actor->id,'updated_at'=>now(),
                ]);
            if ($updated !== 1) { throw new RequestConflict('Cancellation was decided concurrently.'); }
            $this->audit->record('requests.cancellation.withdrawn',(string)$row->id,
                ['cancellation_id'=>(string)$pending->id]);
        });
    }

    /** Every round is separate; no cap. Government must select allowlisted fields/slots for limited services. */
    public function requireCorrection(string $id, string $reason, array $fields = [], array $slots = []): ActionOutcome
    {
        $actor = $this->actor();
        if (trim($reason) === '') { throw new \InvalidArgumentException('Correction reason is mandatory.'); }
        return $this->locked($id, function (object $row, object $wf) use ($actor,$reason,$fields,$slots): ActionOutcome {
            $this->government($actor,$row,'requests.correction.issue');
            $this->noPendingCancellation($row);
            $policy = ServiceRequests::pinnedPolicy($row);
            if ($row->first_submitted_at === null || $row->draft_frozen_at !== null || $row->deleted_at !== null) {
                throw new RequestConflict('Cannot issue correction for a draft or frozen request.');
            }
            $scope = new CorrectionScope($policy->correctionMode,$fields,$slots);
            $this->verifyScope((string)$row->service_code,(int)$row->form_version,(int)$row->document_version,$scope);
            $db = $this->storage->connection;
            if ($db->table('intl_request_corrections')->where('request_id',$row->id)->where('active',true)->exists()) {
                throw new RequestConflict('Complete the current correction round first.');
            }
            $outcome = $this->permit->within((string)$row->id,'workflow',fn() =>
                $this->workflow->apply((string)$row->workflow_instance_id,$policy->correctionAction,$reason));
            if (!$outcome->applied || !in_array($outcome->instance->currentState,$policy->correctionStates,true)) {
                throw new RequestConflict('Workflow did not enter an authorized correction state.');
            }
            $last = $db->table('intl_request_corrections')->where('request_id',$row->id)->max('round');
            $db->table('intl_request_corrections')->insert([
                'id'=>(string)Str::ulid(),'request_id'=>$row->id,'round'=>((int)$last)+1,
                'mode'=>$scope->mode,'active'=>true,'allowed_fields'=>json_encode($scope->fields,JSON_THROW_ON_ERROR),
                'allowed_slots'=>json_encode($scope->documentSlots,JSON_THROW_ON_ERROR),
                'reason'=>$reason,'requested_by'=>$actor->id,'workflow_history_id'=>$outcome->historyId,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->audit->record('requests.correction.requested',(string)$row->id,['round'=>((int)$last)+1]);
            return $outcome;
        });
    }

    /** Revalidate complete pinned form/docs and host representative checks before resubmission. */
    public function resubmit(string $id): ActionOutcome
    {
        $actor = $this->actor();
        return $this->locked($id, function (object $row, object $wf) use ($actor): ActionOutcome {
            $this->agency($actor,$row,'requests.correction.resubmit');
            $this->noPendingCancellation($row);
            $policy = ServiceRequests::pinnedPolicy($row);
            if (!in_array((string)$wf->current_state,$policy->correctionStates,true)) {
                throw new RequestConflict('Request is not in an authorized correction state.');
            }
            $active = $this->storage->connection->table('intl_request_corrections')
                ->where('request_id',$row->id)->where('active',true)->lockForUpdate()->first();
            if ($active === null) { throw new RequestConflict('Missing correction round.'); }
            $this->requests->assertReadyToSubmit($this->requests->view((string)$row->id));
            $outcome = $this->permit->within((string)$row->id,'workflow',fn() =>
                $this->workflow->apply((string)$row->workflow_instance_id,$policy->resubmitAction));
            if (!$outcome->applied) { throw new RequestConflict('Workflow denied resubmission.'); }
            $this->storage->connection->table('intl_request_corrections')
                ->where('id',$active->id)->where('active',true)->update(['active'=>false,'closed_at'=>now(),'updated_at'=>now()]);
            $this->audit->record('requests.correction.resubmitted',(string)$row->id,['round'=>(int)$active->round]);
            return $outcome;
        });
    }

    /** Soft-delete a still-unsent draft. No Workflow status is forged; this is NOT cancellation. */
    public function deleteDraft(string $id): void
    {
        $actor = $this->actor();
        $this->locked($id, function (object $row, object $wf) use ($actor, $id): void {
            $this->agency($actor,$row,'requests.draft.delete');
            $policy = ServiceRequests::pinnedPolicy($row);
            if ($row->deleted_at !== null || $row->draft_frozen_at !== null ||
                $row->first_submitted_at !== null || $wf->current_state !== $policy->draftState) {
                throw new RequestConflict('Only an editable never-submitted draft can be soft-deleted.');
            }
            $this->noPendingCancellation($row);
            if ($this->storage->connection->table('intl_payment_intents')->where('resource_type',RequestResource::TYPE)
                ->where('resource_id',$id)->exists()) {
                throw new RequestConflict('Resolve or remove the local payment intent before deleting a draft.');
            }
            $this->storage->connection->table('intl_service_requests')->where('id',$id)->whereNull('deleted_at')
                ->update(['deleted_at'=>now(),'deleted_by'=>$actor->id,'updated_at'=>now()]);
            $this->audit->record('requests.draft.deleted',$id);
        });
    }

    /** Platform Admin only. Catalog service must be active and old frozen drafts never revive. */
    public function restoreDraft(string $id): void
    {
        $actor = $this->actor();
        $this->locked($id, function (object $row, object $wf) use ($actor,$id): void {
            $this->platform($actor,$row,'requests.restore');
            $policy = ServiceRequests::pinnedPolicy($row);
            if ($row->deleted_at === null || $row->draft_frozen_at !== null ||
                $row->first_submitted_at !== null || $wf->current_state !== $policy->draftState) {
                throw new RequestConflict('Draft is not eligible for restoration.');
            }
            $service = $this->storage->connection->table('intl_catalog_services')
                ->where('code',$row->service_code)->first();
            if ($service === null || $service->status !== 'active') {
                throw new RequestConflict('Cannot restore draft while service is inactive.');
            }
            $this->profiles->lockActive((string)$row->agency_id,
                new \Intl\ServiceRequests\ValueObjects\RecipientReference((string)$row->recipient_type,(string)$row->recipient_id));
            $this->storage->connection->table('intl_service_requests')->where('id',$id)->whereNotNull('deleted_at')
                ->update(['deleted_at'=>null,'deleted_by'=>null,'updated_at'=>now()]);
            $this->audit->record('requests.draft.restored',$id);
        });
    }

    /**
     * Exactly ONE encrypted legal-representative snapshot per request.
     * Does NOT assert legal authority; RequestSubmissionVerifier must validate that separately.
     * Selection is restricted to drafts, to preserve immutability of submitted representative data.
     * @param array<string,mixed>|null $requestProfile
     */
    public function selectLegalRepresentative(string $id, ?string $profileRepresentativeId,
        ?array $requestProfile, int $expectedRevision = 0): void
    {
        $actor = $this->actor();
        $this->locked($id, function (object $row, object $wf) use ($actor,$id,$profileRepresentativeId,$requestProfile,$expectedRevision): void {
            $this->agency($actor,$row,'requests.representative.select');
            $policy = ServiceRequests::pinnedPolicy($row);
            if ($row->recipient_type !== 'legal' || $row->first_submitted_at !== null ||
                $wf->current_state !== $policy->draftState || $row->draft_frozen_at !== null ||
                $row->deleted_at !== null) {
                throw new RequestConflict('Representative selection is available only for active legal drafts.');
            }
            $this->noPendingCancellation($row);
            $recipient = $this->recipientStorage->connection->table('intl_recipients')
                ->where('id',$row->recipient_id)->where('agency_id',$row->agency_id)->lockForUpdate()->first();
            if ($recipient === null || $recipient->status !== 'active' || $recipient->kind !== 'legal') {
                throw new RequestConflict('Legal recipient is unavailable.');
            }
            $mode = config('intl-service-requests.legal_representative_mode');
            if (!in_array($mode,['profile','request'],true)) {
                throw new RequestConflict('Microservice must explicitly configure legal representative mode.');
            }
            if ($mode === 'profile') {
                if (!is_string($profileRepresentativeId) || $profileRepresentativeId === '' || $requestProfile !== null) {
                    throw new \InvalidArgumentException('Select one stored active representative.');
                }
                $rep = $this->recipientStorage->connection->table('intl_recipient_representatives')
                    ->where('id',$profileRepresentativeId)->where('recipient_id',$row->recipient_id)
                    ->where('active',true)->lockForUpdate()->first();
                if ($rep === null) { throw new RequestConflict('Stored representative is unavailable.'); }
                $data = $this->recipientStorage->decrypt((string)$rep->profile_cipher);
            } else {
                if ($profileRepresentativeId !== null || $requestProfile === null ||
                    $requestProfile === [] || array_is_list($requestProfile)) {
                    throw new \InvalidArgumentException('Enter one request-only representative profile.');
                }
                app(RepresentativeProfileValidator::class)->assertValid((string)$row->service_code,$requestProfile);
                $data = $requestProfile;
            }
            $db = $this->storage->connection;
            $current = $db->table('intl_request_representatives')->where('request_id',$id)
                ->lockForUpdate()->first();
            if (($current === null ? 0 : (int)$current->revision) !== $expectedRevision) {
                throw new RequestConflict('Representative was changed concurrently.');
            }
            $values = ['source'=>$mode,'profile_representative_id'=>$profileRepresentativeId,
                'snapshot_cipher'=>$this->recipientStorage->encrypt($data),
                'revision'=>$expectedRevision+1,'updated_at'=>now()];
            if ($current === null) {
                $db->table('intl_request_representatives')->insert(['request_id'=>$id,'created_at'=>now(),...$values]);
            } else {
                $db->table('intl_request_representatives')->where('request_id',$id)
                    ->where('revision',$expectedRevision)->update($values);
            }
            $this->audit->record('requests.representative.selected',$id,['revision'=>$expectedRevision+1]);
        });
    }

    /** @return array<int,object> Authorized cancellation history (reasons can contain confidential text). */
    public function cancellationHistory(string $id): array
    {
        $this->requests->view($id);
        return $this->storage->connection->table('intl_request_cancellations')
            ->where('request_id',$id)->orderBy('created_at')->get()->all();
    }

    /** Execute under the authoritative Workflow row lock, THEN the local request lock, then audit. */
    private function locked(string $id, callable $operation): mixed
    {
        $db = $this->storage->connection;
        if ($this->audit->connectionName() !== $db->getName() || $this->profiles->connectionName() !== $db->getName()) {
            throw new \LogicException('Request, recipient, and synchronous audit must use the same local connection.');
        }
        return $db->transaction(function () use ($id,$db,$operation): mixed {
            $request = $db->table('intl_service_requests')->where('id',$id)->first();
            if ($request === null) { throw new RequestNotFound('Request not found.'); }
            $wf = $db->table('intl_workflow_instances')->where('id',$request->workflow_instance_id)->lockForUpdate()->first();
            $row = $db->table('intl_service_requests')->where('id',$id)->lockForUpdate()->first();
            if ($wf === null || $row === null || $wf->resource_type !== RequestResource::TYPE ||
                $wf->resource_id !== $row->id || $wf->id !== $row->workflow_instance_id) {
                throw new RequestConflict('Request/Workflow pin mismatch.');
            }
            return $operation($row,$wf);
        }, 1);
    }

    private function actor(): ActorContext { return $this->principals->current()->actor; }

    private function agency(ActorContext $actor, object $row, string $permission): void
    {
        if ($actor->role !== 'agency' || $actor->organizationId !== (string)$row->agency_id) {
            throw new AuthorizationDenied('agency_ownership_required');
        }
        $this->authorization->authorize($actor,new AccessRequest($permission,RequestResource::TYPE,(string)$row->id));
    }
    private function government(ActorContext $actor, object $row, string $permission): void
    {
        if ($actor->role !== 'government_admin') { throw new AuthorizationDenied('government_admin_required'); }
        $this->authorization->authorize($actor,new AccessRequest($permission,RequestResource::TYPE,(string)$row->id));
    }
    private function platform(ActorContext $actor, object $row, string $permission): void
    {
        if ($actor->role !== 'platform_admin') { throw new AuthorizationDenied('platform_admin_required'); }
        $this->authorization->authorize($actor,new AccessRequest($permission,RequestResource::TYPE,(string)$row->id));
    }

    private function noPendingCancellation(object $row): void
    {
        if ($this->storage->connection->table('intl_request_cancellations')
            ->where('request_id',$row->id)->where('status','pending')->exists()) {
            throw new RequestConflict('Cancellation is already pending.');
        }
    }

    private function pendingCancellation(object $row): object
    {
        return $this->storage->connection->table('intl_request_cancellations')
            ->where('request_id',$row->id)->where('status','pending')->lockForUpdate()->first()
            ?? throw new RequestConflict('No pending agency cancellation request.');
    }

    private function unchangedSinceCancellation(object $pending, object $wf): void
    {
        if ($wf->current_state !== $pending->original_workflow_state ||
            (int)$wf->revision !== (int)$pending->original_workflow_revision) {
            throw new RequestConflict('Workflow changed while cancellation was awaiting review.');
        }
    }

    private function assertNoUnresolvedPayment(string $requestId): void
    {
        if ($this->storage->connection->table('intl_payment_intents')
            ->where('resource_type',RequestResource::TYPE)->where('resource_id',$requestId)
            ->whereIn('status',['processing','unknown','pending'])->exists()) {
            throw new RequestConflict('An unresolved payment must be reconciled before final cancellation.');
        }
    }

    private function verifyScope(string $serviceCode, int $formVersion, int $documentVersion, CorrectionScope $scope): void
    {
        if ($scope->mode === 'full') { return; }
        $form = $this->forms->loadPublished($serviceCode,$formVersion);
        $fields = array_map(static fn($f): string => $f->key, $form->fields);
        $groups = [];
        $groupFields = [];
        foreach ($form->repeatableGroups ?? [] as $group) {
            $groups[] = $group->key;
            $groupFields[$group->key] = array_map(static fn($f): string => $f->key, $group->fields);
        }
        $documents = $this->documents->published($serviceCode,$documentVersion);
        $slots = array_map(static fn($d): string => $d->code, $documents->requirements);
        foreach ($scope->fields as $field) {
            try {
                $path = \Intl\ServiceRequests\Policies\CorrectionPath::parse($field);
            } catch (\InvalidArgumentException) {
                throw new RequestConflict('Unknown field in correction allowlist.');
            }
            if ($path->kind === \Intl\ServiceRequests\Policies\CorrectionPath::KIND_FLAT_FIELD) {
                if (!in_array($path->field, $fields, true)) {
                    throw new RequestConflict('Unknown field in correction allowlist.');
                }
            } else {
                if (!in_array($path->group, $groups, true)) {
                    throw new RequestConflict('Unknown field in correction allowlist.');
                }
                if ($path->kind === \Intl\ServiceRequests\Policies\CorrectionPath::KIND_CHILD_FIELD) {
                    $validChildren = $groupFields[$path->group] ?? [];
                    if (!in_array($path->field, $validChildren, true)) {
                        throw new RequestConflict('Unknown field in correction allowlist.');
                    }
                }
            }
        }
        foreach ($scope->documentSlots as $slot) {
            if (!in_array($slot,$slots,true)) { throw new RequestConflict('Unknown document slot in correction allowlist.'); }
        }
    }
}

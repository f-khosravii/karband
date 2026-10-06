<?php

declare(strict_types=1);

namespace Intl\ServiceRequestsLaravel\Services;

use Intl\DynamicFormsLaravel\Contracts\ResponseEditGate;
use Intl\DocumentsLaravel\Contracts\DocumentEditGate;
use Intl\WorkflowLaravel\Contracts\WorkflowActionGate;
use Intl\WorkflowLaravel\ValueObjects\WorkflowInstance;
use Intl\ServiceRequests\Policies\RequestPolicy;
use Intl\ServiceRequests\Policies\CorrectionScope;
use Intl\ServiceRequests\ValueObjects\RequestResource;
use Intl\ServiceRequestsLaravel\Exceptions\RequestConflict;

/** Gate direct package entry points, not just ServiceRequests facade/UI controllers. */
final readonly class RequestGuards implements WorkflowActionGate, ResponseEditGate, DocumentEditGate
{
    public function __construct(private RequestStorage $storage, private RequestOperationPermit $permit) {}

    public function assertWorkflowAllowed(WorkflowInstance $instance, string $action): void
    {
        if ($instance->resourceType !== RequestResource::TYPE) { return; }
        $row = $this->storage->connection->table('intl_service_requests')
            ->where('id', $instance->resourceId)->lockForUpdate()->first();
        if ($row === null || $row->workflow_instance_id !== $instance->id || $row->deleted_at !== null ||
            $row->draft_frozen_at !== null) {
            throw new RequestConflict('Request is not available for workflow actions.');
        }
        $policy = RequestPolicy::fromArray(json_decode((string)$row->policy_json,true,512,JSON_THROW_ON_ERROR));
        $pending = $this->storage->connection->table('intl_request_cancellations')
            ->where('request_id',$row->id)->where('status','pending')->exists();
        $purpose = $pending ? 'cancellation_decision' : 'workflow';
        if (!$this->permit->permits((string)$row->id,$purpose)) {
            throw new RequestConflict('Workflow actions must use the authorized Service Requests lifecycle.');
        }
        if ($pending && $action !== $policy->cancellationAction($instance->currentState)) {
            throw new RequestConflict('Cancellation is pending; conflicting workflow action denied.');
        }
    }

    /** @param array<string,mixed> $proposed */
    public function assertResponseAllowed(string $serviceCode, string $resourceType, string $resourceId,
        array $proposed = [], string $slot = ''): void
    {
        if ($resourceType !== RequestResource::TYPE) { return; }
        $db = $this->storage->connection;
        $row = $db->table('intl_service_requests')->where('id',$resourceId)
            ->where('service_code',$serviceCode)->lockForUpdate()->first();
        if ($row === null || $row->deleted_at !== null || $row->draft_frozen_at !== null ||
            $db->table('intl_request_cancellations')->where('request_id',$resourceId)->where('status','pending')->exists()) {
            throw new RequestConflict('Request is not editable.');
        }
        $recipient = $db->table('intl_recipients')->where('id',$row->recipient_id)
            ->where('agency_id',$row->agency_id)->first();
        if ($recipient === null || $recipient->status !== 'active') {
            throw new RequestConflict('Recipient is unavailable.');
        }
        $wf = $db->table('intl_workflow_instances')->where('id',$row->workflow_instance_id)->first();
        if ($wf === null) { throw new RequestConflict('Request workflow is missing.'); }
        $policy = RequestPolicy::fromArray(json_decode((string)$row->policy_json,true,512,JSON_THROW_ON_ERROR));
        if ($row->first_submitted_at === null && $wf->current_state === $policy->draftState) { return; }
        if (!in_array((string)$wf->current_state,$policy->correctionStates,true)) {
            throw new RequestConflict('Request is outside an authorized correction round.');
        }
        $round = $db->table('intl_request_corrections')->where('request_id',$resourceId)
            ->where('active',true)->orderByDesc('round')->first();
        if ($round === null) { throw new RequestConflict('No active correction scope.'); }
        $scope = new CorrectionScope((string)$round->mode,
            json_decode((string)$round->allowed_fields,true,512,JSON_THROW_ON_ERROR),
            json_decode((string)$round->allowed_slots,true,512,JSON_THROW_ON_ERROR));
        if ($slot !== '') { $scope->assertDocumentSlot($slot); }
        else {
            $stored = $db->table('intl_form_answers')->where('service_code',$serviceCode)
                ->where('resource_type',$resourceType)->where('resource_id',$resourceId)
                ->lockForUpdate()->first();
            if ($stored === null) { throw new RequestConflict('Pinned form answers are missing.'); }
            $answers = json_decode((string)$stored->answers,true,512,JSON_THROW_ON_ERROR);
            if (!is_array($answers)) { throw new RequestConflict('Stored form answers are invalid.'); }
            $scope->assertAnswers($answers,$proposed);
        }
    }

    public function assertDocumentSlot(
        string $serviceCode,
        string $resourceType,
        string $resourceId,
        string $slot,
        string $action = 'upload'
    ): void {
        if ($resourceType !== RequestResource::TYPE) { return; }
        $db = $this->storage->connection;
        $row = $db->table('intl_service_requests')->where('id', $resourceId)
            ->where('service_code', $serviceCode)->lockForUpdate()->first();
        if ($row === null || $row->deleted_at !== null || $row->draft_frozen_at !== null ||
            $db->table('intl_request_cancellations')->where('request_id', $resourceId)->where('status', 'pending')->exists()) {
            throw new RequestConflict('Request is not editable.');
        }
        $recipient = $db->table('intl_recipients')->where('id', $row->recipient_id)
            ->where('agency_id', $row->agency_id)->first();
        if ($recipient === null || $recipient->status !== 'active') {
            throw new RequestConflict('Recipient is unavailable.');
        }
        $wf = $db->table('intl_workflow_instances')->where('id', $row->workflow_instance_id)->first();
        if ($wf === null) { throw new RequestConflict('Request workflow is missing.'); }
        $policy = RequestPolicy::fromArray(json_decode((string)$row->policy_json, true, 512, JSON_THROW_ON_ERROR));

        // 1. In draft state, documents can be edited before initial submission
        if ($row->first_submitted_at === null && $wf->current_state === $policy->draftState) {
            return;
        }

        // 2. In correction states, check correction scope
        if (in_array((string)$wf->current_state, $policy->correctionStates, true)) {
            $round = $db->table('intl_request_corrections')->where('request_id', $resourceId)
                ->where('active', true)->orderByDesc('round')->first();
            if ($round === null) { throw new RequestConflict('No active correction scope.'); }
            $scope = new CorrectionScope((string)$round->mode,
                json_decode((string)$round->allowed_fields, true, 512, JSON_THROW_ON_ERROR),
                json_decode((string)$round->allowed_slots, true, 512, JSON_THROW_ON_ERROR));
            $scope->assertDocumentSlot($slot);
            return;
        }

        // 3. Post-submission / other states: evaluate DocumentStatePolicy if bound
        $actor = app()->bound(\Intl\AuthorizationLaravel\Contracts\PrincipalProvider::class)
            ? app(\Intl\AuthorizationLaravel\Contracts\PrincipalProvider::class)->current()->actor
            : null;

        if ($actor !== null && app()->bound(\Intl\DocumentsLaravel\Contracts\DocumentStatePolicy::class)) {
            $statePolicy = app(\Intl\DocumentsLaravel\Contracts\DocumentStatePolicy::class);
            if ($statePolicy->allows($actor, $action, $serviceCode, $resourceType, $resourceId, $slot, (string)$wf->current_state)) {
                return;
            }
        }

        throw new RequestConflict('Request document slot is not editable in current state.');
    }
}

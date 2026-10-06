<?php

declare(strict_types=1);

namespace App\Bridges;

use Karband\DocumentsLaravel\Contracts\DocumentEditGate;
use Karband\DynamicFormsLaravel\Contracts\ResponseEditGate;
use Karband\ServiceRequests\Policies\CorrectionScope;
use Karband\ServiceRequests\Policies\RequestPolicy;
use Karband\ServiceRequests\ValueObjects\RequestResource;
use Karband\ServiceRequestsLaravel\Exceptions\RequestConflict;
use Karband\ServiceRequestsLaravel\Services\RequestOperationPermit;
use Karband\ServiceRequestsLaravel\Services\RequestStorage;
use Karband\WorkflowLaravel\Contracts\WorkflowActionGate;
use Karband\WorkflowLaravel\ValueObjects\WorkflowInstance;

/** Host composition gate: gates direct package entry points for workflow, forms, and documents. */
final readonly class RequestGuards implements WorkflowActionGate, ResponseEditGate, DocumentEditGate
{
    public function __construct(
        private RequestStorage $storage,
        private RequestOperationPermit $permit,
    ) {
    }

    public function assertWorkflowAllowed(WorkflowInstance $instance, string $action): void
    {
        if ($instance->resourceType !== RequestResource::TYPE) {
            return;
        }
        $row = $this->storage->connection->table('service_requests')
            ->where('id', $instance->resourceId)->lockForUpdate()->first();
        if ($row === null || $row->workflow_instance_id !== $instance->id || $row->deleted_at !== null ||
            $row->draft_frozen_at !== null) {
            throw new RequestConflict('Request is not available for workflow actions.');
        }
        $policy = RequestPolicy::fromArray(json_decode((string)$row->policy_json, true, 512, JSON_THROW_ON_ERROR));
        $pending = $this->storage->connection->table('request_cancellations')
            ->where('request_id', $row->id)->where('status', 'pending')->exists();
        $purpose = $pending ? 'cancellation_decision' : 'workflow';
        if (!$this->permit->permits((string)$row->id, $purpose)) {
            throw new RequestConflict('Workflow actions must use the authorized Service Requests lifecycle.');
        }
        if ($pending && $action !== $policy->cancellationAction($instance->currentState)) {
            throw new RequestConflict('Cancellation is pending; conflicting workflow action denied.');
        }
    }

    /** @param array<string,mixed> $proposed */
    public function assertResponseAllowed(
        string $serviceCode,
        string $resourceType,
        string $resourceId,
        array $proposed = [],
        string $slot = '',
    ): void {
        if ($resourceType !== RequestResource::TYPE) {
            return;
        }
        $db = $this->storage->connection;
        $row = $db->table('service_requests')->where('id', $resourceId)
            ->where('service_code', $serviceCode)->lockForUpdate()->first();
        if ($row === null || $row->deleted_at !== null || $row->draft_frozen_at !== null ||
            $db->table('request_cancellations')->where('request_id', $resourceId)->where('status', 'pending')->exists()) {
            throw new RequestConflict('Request is not editable.');
        }
        $recipient = $db->table('recipients')->where('id', $row->recipient_id)
            ->where('agency_id', $row->agency_id)->first();
        if ($recipient === null || $recipient->status !== 'active') {
            throw new RequestConflict('Recipient is unavailable.');
        }
        $wf = $db->table('workflow_instances')->where('id', $row->workflow_instance_id)->first();
        if ($wf === null) {
            throw new RequestConflict('Request workflow is missing.');
        }
        $policy = RequestPolicy::fromArray(json_decode((string)$row->policy_json, true, 512, JSON_THROW_ON_ERROR));
        if ($row->first_submitted_at === null && $wf->current_state === $policy->draftState) {
            return;
        }
        if (!in_array((string)$wf->current_state, $policy->correctionStates, true)) {
            throw new RequestConflict('Request is outside an authorized correction round.');
        }
        $round = $db->table('request_corrections')->where('request_id', $resourceId)
            ->where('active', true)->orderByDesc('round')->first();
        if ($round === null) {
            throw new RequestConflict('No active correction scope.');
        }
        $scope = new CorrectionScope(
            (string)$round->mode,
            json_decode((string)$round->allowed_fields, true, 512, JSON_THROW_ON_ERROR),
            json_decode((string)$round->allowed_slots, true, 512, JSON_THROW_ON_ERROR)
        );
        if ($slot !== '') {
            $scope->assertDocumentSlot($slot);
        } else {
            $stored = $db->table('form_answers')->where('service_code', $serviceCode)
                ->where('resource_type', $resourceType)->where('resource_id', $resourceId)
                ->lockForUpdate()->first();
            if ($stored === null) {
                throw new RequestConflict('Pinned form answers are missing.');
            }
            $answers = json_decode((string)$stored->answers, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($answers)) {
                throw new RequestConflict('Stored form answers are invalid.');
            }
            $scope->assertAnswers($answers, $proposed);
        }
    }

    public function assertDocumentSlot(
        string $serviceCode,
        string $resourceType,
        string $resourceId,
        string $slot,
        string $action = 'upload',
    ): void {
        if ($resourceType !== RequestResource::TYPE) {
            return;
        }
        $db = $this->storage->connection;
        $row = $db->table('service_requests')->where('id', $resourceId)
            ->where('service_code', $serviceCode)->lockForUpdate()->first();
        if ($row === null || $row->deleted_at !== null || $row->draft_frozen_at !== null ||
            $db->table('request_cancellations')->where('request_id', $resourceId)->where('status', 'pending')->exists()) {
            throw new RequestConflict('Request is not editable.');
        }
        $recipient = $db->table('recipients')->where('id', $row->recipient_id)
            ->where('agency_id', $row->agency_id)->first();
        if ($recipient === null || $recipient->status !== 'active') {
            throw new RequestConflict('Recipient is unavailable.');
        }
        $wf = $db->table('workflow_instances')->where('id', $row->workflow_instance_id)->first();
        if ($wf === null) {
            throw new RequestConflict('Request workflow is missing.');
        }
        $policy = RequestPolicy::fromArray(json_decode((string)$row->policy_json, true, 512, JSON_THROW_ON_ERROR));

        if ($row->first_submitted_at === null && $wf->current_state === $policy->draftState) {
            return;
        }

        if (in_array((string)$wf->current_state, $policy->correctionStates, true)) {
            $round = $db->table('request_corrections')->where('request_id', $resourceId)
                ->where('active', true)->orderByDesc('round')->first();
            if ($round === null) {
                throw new RequestConflict('No active correction scope.');
            }
            $scope = new CorrectionScope(
                (string)$round->mode,
                json_decode((string)$round->allowed_fields, true, 512, JSON_THROW_ON_ERROR),
                json_decode((string)$round->allowed_slots, true, 512, JSON_THROW_ON_ERROR)
            );
            $scope->assertDocumentSlot($slot);
            return;
        }

        $actor = app()->bound(\Karband\AuthorizationLaravel\Contracts\PrincipalProvider::class)
            ? app(\Karband\AuthorizationLaravel\Contracts\PrincipalProvider::class)->current()->actor
            : null;

        if ($actor !== null && app()->bound(\Karband\DocumentsLaravel\Contracts\DocumentStatePolicy::class)) {
            $statePolicy = app(\Karband\DocumentsLaravel\Contracts\DocumentStatePolicy::class);
            if ($statePolicy->allows($actor, $action, $serviceCode, $resourceType, $resourceId, $slot, (string)$wf->current_state)) {
                return;
            }
        }

        throw new RequestConflict('Request document slot is not editable in current state.');
    }
}

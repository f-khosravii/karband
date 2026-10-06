<?php

declare(strict_types=1);

namespace App\Bridges;

use Karband\BillingLaravel\Contracts\TrustedRefundEventVerifier;
use Karband\ServiceRequests\Policies\RequestPolicy;
use Karband\ServiceRequests\ValueObjects\RequestResource;
use Karband\ServiceRequestsLaravel\Services\RequestStorage;

/** Host composition bridge: verifies trusted cancellation refunds against workflow history. */
final readonly class TrustedCancellationRefundVerifier implements TrustedRefundEventVerifier
{
    public function __construct(private RequestStorage $storage)
    {
    }

    public function verify(
        string $eventCode,
        string $eventId,
        string $serviceCode,
        string $resourceType,
        string $resourceId,
        string $agencyId,
    ): bool {
        if ($eventCode !== 'cancellation_approved' || $resourceType !== RequestResource::TYPE) {
            return false;
        }
        $db = $this->storage->connection;
        $row = $db->table('service_requests')->where('id', $resourceId)
            ->where('service_code', $serviceCode)->where('agency_id', $agencyId)->first();
        if ($row === null || $row->policy_json === null) {
            return false;
        }
        $cancellation = $db->table('request_cancellations')
            ->where('id', $eventId)->where('request_id', $row->id)->where('status', 'approved')->first();
        if ($cancellation === null || $cancellation->workflow_history_id === null) {
            return false;
        }
        $wf = $db->table('workflow_instances')->where('id', $row->workflow_instance_id)->first();
        if ($wf === null) {
            return false;
        }
        $history = $db->table('workflow_history')->where('id', $cancellation->workflow_history_id)
            ->where('instance_id', $wf->id)->where('from_state', $cancellation->original_workflow_state)->first();
        if ($history === null) {
            return false;
        }
        $policy = RequestPolicy::fromArray(json_decode((string)$row->policy_json, true, 512, JSON_THROW_ON_ERROR));
        return $history->action === $policy->cancellationAction((string)$cancellation->original_workflow_state)
            && (string)$history->to_state === $policy->cancelledState;
    }
}

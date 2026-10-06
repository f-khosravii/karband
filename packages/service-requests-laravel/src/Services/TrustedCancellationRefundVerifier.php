<?php

declare(strict_types=1);

namespace Intl\ServiceRequestsLaravel\Services;

use Intl\BillingLaravel\Contracts\TrustedRefundEventVerifier;
use Intl\ServiceRequests\Policies\RequestPolicy;
use Intl\ServiceRequests\ValueObjects\RequestResource;

/** Narrow verifier for FULL refunds triggered by a real, approved cancellation event.
 * Host must explicitly bind/decorate it for other trusted events (e.g., rejection/issuance failure).
 */
final readonly class TrustedCancellationRefundVerifier implements TrustedRefundEventVerifier
{
    public function __construct(private RequestStorage $storage) {}

    public function verify(string $eventCode, string $eventId, string $serviceCode,
        string $resourceType, string $resourceId, string $agencyId): bool
    {
        if ($eventCode !== 'cancellation_approved' || $resourceType !== RequestResource::TYPE) {
            return false;
        }
        $db = $this->storage->connection;
        $row = $db->table('intl_service_requests')->where('id',$resourceId)
            ->where('service_code',$serviceCode)->where('agency_id',$agencyId)->first();
        if ($row === null || $row->policy_json === null) { return false; }
        $cancellation = $db->table('intl_request_cancellations')
            ->where('id',$eventId)->where('request_id',$row->id)->where('status','approved')->first();
        if ($cancellation === null || $cancellation->workflow_history_id === null) { return false; }
        $wf = $db->table('intl_workflow_instances')->where('id',$row->workflow_instance_id)->first();
        if ($wf === null) { return false; }
        $history = $db->table('intl_workflow_history')->where('id',$cancellation->workflow_history_id)
            ->where('instance_id',$wf->id)->where('from_state',$cancellation->original_workflow_state)->first();
        if ($history === null) { return false; }
        $policy = RequestPolicy::fromArray(json_decode((string)$row->policy_json,true,512,JSON_THROW_ON_ERROR));
        return $history->action === $policy->cancellationAction((string)$cancellation->original_workflow_state)
            && (string)$history->to_state === $policy->cancelledState;
    }
}

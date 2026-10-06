<?php

declare(strict_types=1);

namespace App\Bridges;

use Karband\BillingLaravel\Contracts\PayableRequestGuard;
use Karband\ServiceRequests\ValueObjects\RequestResource;
use Karband\ServiceRequestsLaravel\Services\RequestStorage;
use RuntimeException;

/**
 * Host composition bridge: verifies request is payable by agency in the same DB connection.
 */
final readonly class LocalPayableRequestGuard implements PayableRequestGuard
{
    public function __construct(private RequestStorage $storage)
    {
    }

    public function connectionName(): ?string
    {
        return $this->storage->connection->getName();
    }

    public function assertPayable(string $serviceCode, string $resourceType, string $resourceId, string $agencyId): void
    {
        if ($resourceType !== RequestResource::TYPE) {
            return;
        }

        $row = $this->storage->connection->table('service_requests')
            ->where('id', $resourceId)
            ->where('service_code', $serviceCode)
            ->where('agency_id', $agencyId)
            ->first();

        if ($row === null) {
            throw new RuntimeException("Request '{$resourceId}' not found for payable verification.");
        }

        if ($row->first_submitted_at === null) {
            throw new RuntimeException("Request '{$resourceId}' is in draft and not yet approved for payment.");
        }
    }
}

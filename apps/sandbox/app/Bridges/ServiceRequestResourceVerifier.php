<?php

declare(strict_types=1);

namespace App\Bridges;

use Karband\DocumentsLaravel\Contracts\ResourceVerifier;
use Karband\ServiceRequests\ValueObjects\RequestResource;
use Karband\ServiceRequestsLaravel\Services\RequestStorage;

/** Host composition bridge: verifies that documents are uploaded against valid service requests. */
final readonly class ServiceRequestResourceVerifier implements ResourceVerifier
{
    public function __construct(private RequestStorage $storage)
    {
    }

    public function existsForService(string $serviceCode, string $resourceType, string $resourceId): bool
    {
        return $resourceType === RequestResource::TYPE && $this->storage->connection
            ->table('service_requests')->where('id', $resourceId)->where('service_code', $serviceCode)->exists();
    }
}

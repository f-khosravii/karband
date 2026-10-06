<?php

declare(strict_types=1);

namespace Intl\ServiceRequestsLaravel\Services;

use Intl\DocumentsLaravel\Contracts\ResourceVerifier;
use Intl\ServiceRequests\ValueObjects\RequestResource;

/** Documents can reserve/upload ONLY to a real, local, correctly bound service request. */
final readonly class ServiceRequestResourceVerifier implements ResourceVerifier
{
    public function __construct(private RequestStorage $storage) {}

    public function existsForService(string $serviceCode, string $resourceType, string $resourceId): bool
    {
        return $resourceType === RequestResource::TYPE && $this->storage->connection
            ->table('intl_service_requests')->where('id', $resourceId)->where('service_code', $serviceCode)->exists();
    }
}

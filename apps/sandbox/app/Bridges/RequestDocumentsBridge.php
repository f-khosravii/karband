<?php

declare(strict_types=1);

namespace App\Bridges;

use Illuminate\Http\UploadedFile;
use Karband\DocumentsLaravel\Services\DocumentManager;
use Karband\DocumentsLaravel\Services\DocumentStorage;
use Karband\ServiceRequestsLaravel\Contracts\RequestDocumentsPort;
use Karband\ServiceRequestsLaravel\Exceptions\RequestConflict;

final readonly class RequestDocumentsBridge implements RequestDocumentsPort
{
    public function __construct(
        private DocumentStorage $storage,
        private DocumentManager $documents,
    ) {}

    public function reserve(string $serviceCode, string $resourceType, string $resourceId): array
    {
        return $this->documents->reserve($serviceCode, $resourceType, $resourceId);
    }

    public function assertValidScopeSlots(string $serviceCode, int $documentVersion, array $slots): void
    {
        if ($slots === []) {
            return;
        }
        $definition = $this->storage->published($serviceCode, $documentVersion);
        $validSlots = array_map(static fn ($d): string => $d->code, $definition->requirements);
        foreach ($slots as $slot) {
            if (!in_array($slot, $validSlots, true)) {
                throw new RequestConflict('Unknown document slot in correction allowlist.');
            }
        }
    }

    public function upload(string $serviceCode, string $resourceType, string $resourceId, string $slot, UploadedFile $file, int $expectedRevision): array
    {
        return $this->documents->upload($serviceCode, $resourceType, $resourceId, $slot, $file, $expectedRevision);
    }

    public function snapshot(string $serviceCode, string $resourceType, string $resourceId): array
    {
        return $this->documents->snapshot($serviceCode, $resourceType, $resourceId);
    }

    public function connectionName(): ?string
    {
        return $this->storage->connection->getName();
    }
}

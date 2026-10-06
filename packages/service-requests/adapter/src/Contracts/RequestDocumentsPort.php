<?php

declare(strict_types=1);

namespace Karband\ServiceRequestsLaravel\Contracts;

use Illuminate\Http\UploadedFile;

interface RequestDocumentsPort
{
    /** @return array{version: int, revision: int} */
    public function reserve(string $serviceCode, string $resourceType, string $resourceId): array;

    /** @param list<string> $slots */
    public function assertValidScopeSlots(string $serviceCode, int $documentVersion, array $slots): void;

    /** @return array<string, mixed> */
    public function upload(string $serviceCode, string $resourceType, string $resourceId, string $slot, UploadedFile $file, int $expectedRevision): array;

    /** @return array<string, mixed> */
    public function snapshot(string $serviceCode, string $resourceType, string $resourceId): array;

    public function connectionName(): ?string;
}

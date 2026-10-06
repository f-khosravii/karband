<?php

declare(strict_types=1);

namespace Karband\ServiceRequestsLaravel\Contracts;

/** Mandatory trusted, synchronous audit writer for lifecycle changes; no names/free-text/PII. */
interface RequestAudit
{
    /** @param array<string,string|int> $safeMetadata */
    public function record(string $event, string $requestId, array $safeMetadata = []): void;
    public function connectionName(): ?string;
}

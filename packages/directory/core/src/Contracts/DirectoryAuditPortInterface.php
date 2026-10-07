<?php

declare(strict_types=1);

namespace Karband\Directory\Contracts;

/**
 * Consumer-owned audit port for Directory mutations.
 */
interface DirectoryAuditPortInterface
{
    /**
     * Record a directory audit event.
     *
     * @param array<string, mixed> $metadata
     */
    public function record(string $code, string $subjectType, string $subjectId, array $metadata = []): void;
}

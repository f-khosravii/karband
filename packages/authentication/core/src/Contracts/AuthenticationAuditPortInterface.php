<?php

declare(strict_types=1);

namespace Karband\Authentication\Contracts;

/**
 * Consumer-owned audit port for Authentication lifecycle events.
 */
interface AuthenticationAuditPortInterface
{
    /**
     * Record an authentication audit event.
     *
     * @param array<string, mixed> $metadata
     */
    public function record(string $code, string $subjectType, string $subjectId, array $metadata = []): void;
}

<?php

declare(strict_types=1);

namespace Karband\ServiceRequestsLaravel\Contracts;

use Karband\ServiceRequests\ValueObjects\RecipientReference;

/** Host-supplied, row-locking LOCAL source. Must join recipient transactions on one connection. */
interface RecipientProfileSource
{
    public function connectionName(): ?string;

    /**
     * Lock active recipient, verify agency AND kind, return safe server-side snapshot. No caller-supplied PII.
     * @return array{kind:string,identity_type:string,identity_value:string,profile:array<string,mixed>,revision:int}
     */
    public function lockActive(string $agencyId, RecipientReference $reference): array;

    public function assertLegalRecipientActive(string $agencyId, string $recipientId): void;

    /** @return array<string, mixed> */
    public function getActiveRepresentativeProfile(string $recipientId, string $representativeId): array;

    /** @param array<string, mixed> $profile */
    public function encryptRepresentativeProfile(array $profile): string;
}

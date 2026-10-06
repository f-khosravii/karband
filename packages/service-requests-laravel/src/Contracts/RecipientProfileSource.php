<?php

declare(strict_types=1);

namespace Intl\ServiceRequestsLaravel\Contracts;

use Intl\ServiceRequests\ValueObjects\RecipientReference;

/** Host-supplied, row-locking LOCAL source. Must join recipient transactions on one connection. */
interface RecipientProfileSource
{
    public function connectionName(): ?string;
    /** Lock active recipient, verify agency AND kind, return safe server-side snapshot. No caller-supplied PII.
     * @return array{kind:string,identity_type:string,identity_value:string,profile:array<string,mixed>,revision:int}
     */
    public function lockActive(string $agencyId, RecipientReference $reference): array;
}

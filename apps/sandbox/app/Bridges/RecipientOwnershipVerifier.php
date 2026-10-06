<?php

declare(strict_types=1);

namespace App\Bridges;

use Karband\ServiceRecipients\RecipientKind;
use Karband\ServiceRecipientsLaravel\Services\Recipients;
use Karband\ServiceRequests\ValueObjects\RecipientReference;
use Karband\ServiceRequestsLaravel\Contracts\RecipientVerifier;

/** Host composition bridge: verifies recipient ownership for service requests. */
final readonly class RecipientOwnershipVerifier implements RecipientVerifier
{
    public function __construct(private Recipients $recipients)
    {
    }

    public function belongsToAgency(string $serviceCode, string $agencyId, RecipientReference $recipient): bool
    {
        $kind = RecipientKind::tryFrom($recipient->type);
        return $kind !== null && $this->recipients->isActiveOwnedBy($agencyId, $recipient->id, $kind);
    }
}

<?php

declare(strict_types=1);
namespace Intl\ServiceRecipientsLaravel\Bridge;

use Intl\ServiceRecipients\RecipientKind;
use Intl\ServiceRecipientsLaravel\Services\Recipients;
use Intl\ServiceRequests\ValueObjects\RecipientReference;
use Intl\ServiceRequestsLaravel\Contracts\RecipientVerifier;

/** Optional bridge, only load if ServiceRequests is installed. Phase 15 adds atomic creation gate. */
final readonly class RecipientOwnershipVerifier implements RecipientVerifier
{
    public function __construct(private Recipients $recipients) {}
    public function belongsToAgency(string $serviceCode, string $agencyId, RecipientReference $recipient): bool
    {
        $kind = RecipientKind::tryFrom($recipient->type);
        return $kind !== null && $this->recipients->isActiveOwnedBy($agencyId, $recipient->id, $kind);
    }
}

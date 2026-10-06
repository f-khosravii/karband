<?php

declare(strict_types=1);
namespace Intl\ServiceRecipientsLaravel\Contracts;

/** Mandatory host implementation; MUST use same DB transaction as RecipientStorage.
 * Phase 15 must also make request creation lock the recipient record before accepting it.
 */
interface RecipientRequestBridge
{
    public function connectionName(): ?string;
    public function hasOpenSubmittedRequests(string $agencyId, string $recipientId): bool;
    /** @param array<string,mixed> $profile */
    public function syncEditableDrafts(string $agencyId, string $recipientId, array $profile, string $identityType, string $identityValue): void;
    /** Permanently freeze old drafts; restore NEVER unlocks them. */
    public function freezeDrafts(string $agencyId, string $recipientId): void;
}

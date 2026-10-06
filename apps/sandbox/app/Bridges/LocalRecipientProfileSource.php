<?php

declare(strict_types=1);

namespace App\Bridges;

use Karband\Authorization\AuthorizationDenied;
use Karband\ServiceRecipientsLaravel\Services\RecipientStorage;
use Karband\ServiceRequests\ValueObjects\RecipientReference;
use Karband\ServiceRequestsLaravel\Contracts\RecipientProfileSource;
use Karband\ServiceRequestsLaravel\Exceptions\RequestConflict;

/** Host composition bridge: locks active recipient profile for service requests. */
final readonly class LocalRecipientProfileSource implements RecipientProfileSource
{
    public function __construct(private RecipientStorage $storage)
    {
    }

    public function connectionName(): ?string
    {
        return $this->storage->connection->getName();
    }

    public function lockActive(string $agencyId, RecipientReference $reference): array
    {
        $row = $this->storage->connection->table('recipients')
            ->where('id', $reference->id)->where('agency_id', $agencyId)->lockForUpdate()->first();
        if ($row === null || $row->status !== 'active' || $row->kind !== $reference->type) {
            throw new AuthorizationDenied('recipient_not_available');
        }
        $snapshot = $this->storage->snapshot($row);
        return [
            'kind' => $snapshot->kind->value,
            'identity_type' => $snapshot->identity->type,
            'identity_value' => $snapshot->identity->normalized,
            'profile' => $snapshot->profile,
            'revision' => $snapshot->revision,
        ];
    }

    public function assertLegalRecipientActive(string $agencyId, string $recipientId): void
    {
        $recipient = $this->storage->connection->table('recipients')
            ->where('id', $recipientId)->where('agency_id', $agencyId)->lockForUpdate()->first();
        if ($recipient === null || $recipient->status !== 'active' || $recipient->kind !== 'legal') {
            throw new RequestConflict('Legal recipient is unavailable.');
        }
    }

    public function getActiveRepresentativeProfile(string $recipientId, string $representativeId): array
    {
        $rep = $this->storage->connection->table('recipient_representatives')
            ->where('id', $representativeId)->where('recipient_id', $recipientId)
            ->where('active', true)->lockForUpdate()->first();
        if ($rep === null) {
            throw new RequestConflict('Stored representative is unavailable.');
        }
        return $this->storage->decrypt((string) $rep->profile_cipher);
    }

    public function encryptRepresentativeProfile(array $profile): string
    {
        return $this->storage->encrypt($profile);
    }
}

<?php

declare(strict_types=1);

namespace Intl\ServiceRequestsLaravel\Services;

use Intl\ServiceRequests\ValueObjects\RecipientReference;
use Intl\ServiceRequestsLaravel\Contracts\RecipientProfileSource;
use Intl\ServiceRecipientsLaravel\Services\RecipientStorage;
use Intl\Contracts\Authorization\AuthorizationDenied;

/** Uses exactly the recipient row-lock gate used by recipient identity/archive operations. */
final readonly class LocalRecipientProfileSource implements RecipientProfileSource
{
    public function __construct(private RecipientStorage $storage) {}

    public function connectionName(): ?string { return $this->storage->connection->getName(); }

    public function lockActive(string $agencyId, RecipientReference $reference): array
    {
        $row = $this->storage->connection->table('intl_recipients')
            ->where('id',$reference->id)->where('agency_id',$agencyId)->lockForUpdate()->first();
        if ($row === null || $row->status !== 'active' || $row->kind !== $reference->type) {
            throw new AuthorizationDenied('recipient_not_available');
        }
        $snapshot = $this->storage->snapshot($row);
        return ['kind'=>$snapshot->kind->value,'identity_type'=>$snapshot->identity->type,
            'identity_value'=>$snapshot->identity->normalized,'profile'=>$snapshot->profile,
            'revision'=>$snapshot->revision];
    }
}

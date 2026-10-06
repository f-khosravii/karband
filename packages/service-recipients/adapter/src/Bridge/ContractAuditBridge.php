<?php

declare(strict_types=1);
namespace Karband\ServiceRecipientsLaravel\Bridge;

use Karband\Audit\AuditEvent;
use Karband\Audit\AuditRecorderInterface;
use Karband\ServiceRecipientsLaravel\Contracts\RecipientAudit;

/** Requires Audit patch and same-connection synchronous audit writer. No automatic binding. */
final readonly class ContractAuditBridge implements RecipientAudit
{
    public function __construct(private AuditRecorderInterface $events, private ?string $connection) {}
    public function connectionName(): ?string { return $this->connection; }
    public function record(string $eventCode, string $recipientId, array $metadata = []): void
    {
        $this->events->record(new AuditEvent($eventCode,'recipient',$recipientId,$metadata));
    }
}

<?php

declare(strict_types=1);
namespace Intl\ServiceRecipientsLaravel\Contracts;

/** Must write within the calling transaction to SAME LOCAL DB; NO PII or free-text payload. */
interface RecipientAudit
{
    /** @param array<string,string|int> $metadata Safe opaque machine values only. */
    public function record(string $eventCode, string $recipientId, array $metadata = []): void;
    public function connectionName(): ?string;
}

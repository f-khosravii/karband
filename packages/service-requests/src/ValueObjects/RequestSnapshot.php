<?php

declare(strict_types=1);

namespace Intl\ServiceRequests\ValueObjects;

/** No duplicated status in the request table; current state is read from Workflow on demand. */
final readonly class RequestSnapshot
{
    public function __construct(
        public string $id,
        public string $serviceCode,
        public string $agencyId,
        public RecipientReference $recipient,
        public string $workflowInstanceId,
        public string $workflowId,
        public int $workflowVersion,
        public string $currentState,
        public int $formVersion,
        public int $documentVersion,
        public bool $pendingCancellation = false,
    ) {
        if ($workflowVersion < 1 || $formVersion < 1 || $documentVersion < 1) {
            throw new \InvalidArgumentException('Pinned definition versions must be positive.');
        }
    }
}

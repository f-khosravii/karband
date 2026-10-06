<?php

declare(strict_types=1);

namespace Intl\ServiceRequests\ValueObjects;

/** Public create intent contains no agency/actor fields: those always come from trusted authentication. */
final readonly class RequestDraft
{
    public function __construct(
        public string $serviceCode,
        public RecipientReference $recipient,
        public string $idempotencyKey,
    ) {
        if (strlen($serviceCode) > 120 || preg_match('/\A[a-z][a-z0-9_.-]*\z/D', $serviceCode) !== 1) {
            throw new \InvalidArgumentException('Invalid service code.');
        }
        if (strlen($idempotencyKey) > 120 || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/D', $idempotencyKey) !== 1) {
            throw new \InvalidArgumentException('Invalid request idempotency key.');
        }
    }
}

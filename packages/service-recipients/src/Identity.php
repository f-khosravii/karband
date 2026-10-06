<?php

declare(strict_types=1);
namespace Intl\ServiceRecipients;

/** Trusted output from the host's identity normalizer, not raw browser input. */
final readonly class Identity
{
    public function __construct(public string $type, public string $normalized)
    {
        if (!preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D', $type)
            || trim($normalized) === '' || strlen($normalized) > 512
            || preg_match('/[\x00-\x1F\x7F]/', $normalized)) {
            throw new \InvalidArgumentException('Invalid normalized identity.');
        }
    }

    /** No global lookup; a keyed per-deployment digest will be scoped to agency in the DB. */
    public function digest(RecipientKind $kind, string $agencyId, string $key): string
    {
        if (trim($agencyId) === '' || strlen($agencyId) > 191) {
            throw new \InvalidArgumentException('Invalid agency scope.');
        }
        if (strlen($key) < 32) {
            throw new \InvalidArgumentException('Dedicated HMAC key must contain at least 32 bytes.');
        }
        return hash_hmac('sha256', "intl-recipient-v1\0".$agencyId."\0".$kind->value."\0".$this->type."\0".$this->normalized, $key);
    }
}

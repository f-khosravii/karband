<?php

declare(strict_types=1);

namespace Karband\ServiceRequests\ValueObjects;

/** A non-user business recipient reference. Its PII stays solely with the local host. */
final readonly class RecipientReference
{
    public function __construct(public string $type, public string $id)
    {
        if (strlen($type) > 120 || preg_match('/\A[a-z][a-z0-9_.-]*\z/D', $type) !== 1) {
            throw new \InvalidArgumentException('Recipient type must be a lowercase identifier (1-120 characters).');
        }
        if ($id === '' || trim($id) !== $id || strlen($id) > 191 || preg_match('/[\x00-\x1F\x7F]/', $id)) {
            throw new \InvalidArgumentException('Recipient reference id must be nonempty and at most 191 characters.');
        }
    }
}

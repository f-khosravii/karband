<?php

declare(strict_types=1);

namespace Intl\WorkflowLaravel\Support;

use Intl\Workflow\Support\Identifier;

final class Input
{
    public static function resource(string $type, string $id): void
    {
        Identifier::assert($type, 'Resource type');
        if (trim($id) === '' || strlen($id) > 191) {
            throw new \InvalidArgumentException('Resource id must be non-empty and at most 191 bytes.');
        }
    }

    public static function idempotencyKey(?string $key): void
    {
        if ($key !== null && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,119}\z/D', $key) !== 1) {
            throw new \InvalidArgumentException('Idempotency key must be 1-120 portable ASCII characters.');
        }
    }

    private function __construct()
    {
    }
}

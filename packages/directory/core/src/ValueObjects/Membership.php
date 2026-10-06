<?php

declare(strict_types=1);

namespace Karband\Directory\ValueObjects;

use Karband\Directory\Enums\DirectoryStatus;
use Karband\Directory\Exceptions\DirectoryValidationException;

final readonly class Membership
{
    public function __construct(
        public string $id,
        public string $userId,
        public string $organizationId,
        public DirectoryStatus $status,
    ) {
        if (trim($id) === '' || strlen($id) > 191) {
            throw new DirectoryValidationException('Membership ID must be 1-191 characters.');
        }
        if (trim($userId) === '' || strlen($userId) > 191) {
            throw new DirectoryValidationException('Membership userId must be 1-191 characters.');
        }
        if (trim($organizationId) === '' || strlen($organizationId) > 191) {
            throw new DirectoryValidationException('Membership organizationId must be 1-191 characters.');
        }
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }
}

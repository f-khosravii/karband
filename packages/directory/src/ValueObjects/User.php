<?php

declare(strict_types=1);

namespace Intl\Directory\ValueObjects;

use Intl\Directory\Enums\DirectoryStatus;
use Intl\Directory\Exceptions\DirectoryValidationException;

final readonly class User
{
    public function __construct(
        public string $id,
        public string $displayName,
        public DirectoryStatus $status,
        public ?string $email = null,
        public ?string $phone = null,
    ) {
        if (trim($id) === '' || strlen($id) > 191) {
            throw new DirectoryValidationException('User ID must be 1-191 characters.');
        }
        if (trim($displayName) === '' || strlen($displayName) > 255) {
            throw new DirectoryValidationException('User displayName must be 1-255 characters.');
        }
        if ($email !== null && (trim($email) === '' || strlen($email) > 255)) {
            throw new DirectoryValidationException('User email must be 1-255 characters when present.');
        }
        if ($phone !== null && (trim($phone) === '' || strlen($phone) > 64)) {
            throw new DirectoryValidationException('User phone must be 1-64 characters when present.');
        }
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }
}

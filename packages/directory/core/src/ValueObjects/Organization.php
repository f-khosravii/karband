<?php

declare(strict_types=1);

namespace Karband\Directory\ValueObjects;

use DateTimeImmutable;
use Karband\Directory\Enums\DirectoryStatus;
use Karband\Directory\Exceptions\DirectoryValidationException;

final readonly class Organization
{
    public ?DateTimeImmutable $createdAt;
    public ?DateTimeImmutable $updatedAt;

    public function __construct(
        public string $id,
        public string $name,
        public DirectoryStatus $status,
        public ?string $email = null,
        public ?string $phone = null,
        DateTimeImmutable|string|null $createdAt = null,
        DateTimeImmutable|string|null $updatedAt = null,
    ) {
        if (trim($id) === '' || strlen($id) > 191) {
            throw new DirectoryValidationException('Organization ID must be 1-191 characters.');
        }
        if (trim($name) === '' || strlen($name) > 255) {
            throw new DirectoryValidationException('Organization name must be 1-255 characters.');
        }
        if ($email !== null && (trim($email) === '' || strlen($email) > 255)) {
            throw new DirectoryValidationException('Organization email must be 1-255 characters when present.');
        }
        if ($phone !== null && (trim($phone) === '' || strlen($phone) > 64)) {
            throw new DirectoryValidationException('Organization phone must be 1-64 characters when present.');
        }

        $this->createdAt = is_string($createdAt) ? new DateTimeImmutable($createdAt) : $createdAt;
        $this->updatedAt = is_string($updatedAt) ? new DateTimeImmutable($updatedAt) : $updatedAt;
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }
}

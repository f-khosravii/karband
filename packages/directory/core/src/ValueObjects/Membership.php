<?php

declare(strict_types=1);

namespace Karband\Directory\ValueObjects;

use DateTimeImmutable;
use Karband\Directory\Enums\DirectoryStatus;
use Karband\Directory\Exceptions\DirectoryValidationException;

final readonly class Membership
{
    public OrganizationRole $role;
    public ?DateTimeImmutable $createdAt;
    public ?DateTimeImmutable $endedAt;

    public function __construct(
        public string $id,
        public string $userId,
        public string $organizationId,
        OrganizationRole|string $role = 'member',
        public DirectoryStatus $status = DirectoryStatus::Active,
        DateTimeImmutable|string|null $createdAt = null,
        DateTimeImmutable|string|null $endedAt = null,
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

        $this->role = $role instanceof OrganizationRole ? $role : new OrganizationRole($role);
        $this->createdAt = is_string($createdAt) ? new DateTimeImmutable($createdAt) : $createdAt;
        $this->endedAt = is_string($endedAt) ? new DateTimeImmutable($endedAt) : $endedAt;
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }
}

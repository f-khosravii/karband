<?php

declare(strict_types=1);

namespace Intl\Directory\ValueObjects;

use Intl\Directory\Enums\DirectoryStatus;
use Intl\Directory\Enums\OrganizationRole;
use Intl\Directory\Exceptions\DirectoryValidationException;

final readonly class Organization
{
    /**
     * @param list<OrganizationRole> $roles
     */
    public function __construct(
        public string $id,
        public string $name,
        public DirectoryStatus $status,
        public array $roles = [],
        public ?string $email = null,
        public ?string $phone = null,
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
        foreach ($roles as $role) {
            if (!$role instanceof OrganizationRole) {
                throw new DirectoryValidationException('Organization roles must be instances of OrganizationRole.');
            }
        }
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function hasRole(OrganizationRole $role): bool
    {
        return in_array($role, $this->roles, true);
    }
}

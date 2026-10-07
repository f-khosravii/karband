<?php

declare(strict_types=1);

namespace Karband\DirectoryPanel\Dto;

final readonly class UserDetail
{
    /**
     * @param list<MembershipItem> $memberships
     */
    public function __construct(
        public string $id,
        public string $displayName,
        public string $status,
        public ?string $email = null,
        public ?string $phone = null,
        public bool $isActive = true,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
        public int $organizationCount = 0,
        public array $memberships = [],
    ) {
    }
}

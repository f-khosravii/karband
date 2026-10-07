<?php

declare(strict_types=1);

namespace Karband\DirectoryPanel\Dto;

final readonly class MembershipItem
{
    public function __construct(
        public string $id,
        public string $userId,
        public string $userDisplayName,
        public string $organizationId,
        public string $organizationName,
        public string $status,
        public bool $isActive = true,
        public ?string $role = null,
        public ?string $createdAt = null,
        public ?string $endedAt = null,
    ) {
    }
}

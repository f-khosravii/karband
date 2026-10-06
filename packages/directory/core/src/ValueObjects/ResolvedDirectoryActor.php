<?php

declare(strict_types=1);

namespace Karband\Directory\ValueObjects;

use Karband\Directory\Exceptions\DirectoryValidationException;

final readonly class ResolvedDirectoryActor
{
    public function __construct(
        public string $organizationId,
        public ?string $userId = null,
    ) {
        if (trim($organizationId) === '' || strlen($organizationId) > 191) {
            throw new DirectoryValidationException('ResolvedDirectoryActor organizationId must be 1-191 characters.');
        }
        if ($userId !== null && (trim($userId) === '' || strlen($userId) > 191)) {
            throw new DirectoryValidationException('ResolvedDirectoryActor userId must be 1-191 characters when present.');
        }
    }

    public function isUserActor(): bool
    {
        return $this->userId !== null;
    }

    public function isOrganizationOnlyActor(): bool
    {
        return $this->userId === null;
    }
}

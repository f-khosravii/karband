<?php

declare(strict_types=1);

namespace Karband\Directory\Contracts;

use Karband\Directory\Enums\DirectoryStatus;
use Karband\Directory\ValueObjects\Membership;
use Karband\Directory\ValueObjects\Organization;
use Karband\Directory\ValueObjects\User;

/**
 * Consumer-owned persistence port for Directory.
 * Implemented by database/adapter layer without exposing framework or SQL details.
 */
interface DirectoryStoragePortInterface
{
    // Organizations
    public function saveOrganization(Organization $org): void;
    public function findOrganization(string $id): ?Organization;
    /** @return list<Organization> */
    public function listOrganizations(?string $search = null, ?DirectoryStatus $status = null, int $limit = 100, int $offset = 0): array;
    public function countOrganizations(?string $search = null, ?DirectoryStatus $status = null): int;

    // Users
    public function saveUser(User $user): void;
    public function findUser(string $id): ?User;
    /** @return list<User> */
    public function listUsers(?string $search = null, ?DirectoryStatus $status = null, int $limit = 100, int $offset = 0): array;
    public function countUsers(?string $search = null, ?DirectoryStatus $status = null): int;

    // Memberships & History
    public function saveMembership(Membership $membership): void;
    public function findMembershipById(string $id): ?Membership;
    public function findActiveMembership(string $userId, string $organizationId): ?Membership;
    /** @return list<Membership> */
    public function listMembershipsForUser(string $userId, ?DirectoryStatus $status = null): array;
    /** @return list<Membership> */
    public function listMembershipsForOrganization(string $organizationId, ?DirectoryStatus $status = null): array;
    /** @return list<Membership> */
    public function listMembershipsForUserAndOrganization(string $userId, string $organizationId): array;
    /** @return list<Membership> */
    public function listMemberships(?string $userId = null, ?string $organizationId = null, ?DirectoryStatus $status = null, int $limit = 100, int $offset = 0): array;
    public function countMemberships(?string $userId = null, ?string $organizationId = null, ?DirectoryStatus $status = null): int;

    // External References
    public function saveExternalReference(string $source, string $referenceKind, string $externalId, string $targetType, ?string $targetOrgId, ?string $targetUserId): void;
    public function deleteExternalReference(string $source, string $referenceKind, string $externalId): void;
    public function findExternalReference(string $source, string $referenceKind, string $externalId): ?object;
}

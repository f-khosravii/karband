<?php

declare(strict_types=1);

namespace Karband\DirectoryLaravel\Services;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Karband\Directory\Contracts\DirectoryStoragePortInterface;
use Karband\Directory\Enums\DirectoryStatus;
use Karband\Directory\Exceptions\DirectoryConflictException;
use Karband\Directory\ValueObjects\Membership;
use Karband\Directory\ValueObjects\Organization;
use Karband\Directory\ValueObjects\OrganizationRole;
use Karband\Directory\ValueObjects\User;

/**
 * Pure database persistence repository implementing DirectoryStoragePortInterface.
 */
final readonly class DirectoryStorage implements DirectoryStoragePortInterface
{
    public function __construct(public Connection $connection)
    {
    }

    // --- Organizations ---

    public function saveOrganization(Organization $org): void
    {
        $now = date('Y-m-d H:i:s');
        $exists = $this->connection->table('directory_organizations')->where('id', $org->id)->exists();

        if ($exists) {
            $this->connection->table('directory_organizations')->where('id', $org->id)->update([
                'name' => $org->name,
                'status' => $org->status->value,
                'email' => $org->email,
                'phone' => $org->phone,
                'updated_at' => $now,
            ]);
        } else {
            $this->connection->table('directory_organizations')->insert([
                'id' => $org->id,
                'name' => $org->name,
                'status' => $org->status->value,
                'email' => $org->email,
                'phone' => $org->phone,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function createOrganization(
        string $id,
        string $name,
        DirectoryStatus $status = DirectoryStatus::Active,
        ?string $email = null,
        ?string $phone = null
    ): Organization {
        $org = new Organization(
            id: $id,
            name: $name,
            status: $status,
            email: $email,
            phone: $phone
        );
        $this->saveOrganization($org);
        return $org;
    }

    public function findOrganization(string $id): ?Organization
    {
        $row = $this->connection->table('directory_organizations')->where('id', $id)->first();
        if ($row === null) {
            return null;
        }

        return new Organization(
            id: (string) $row->id,
            name: (string) $row->name,
            status: DirectoryStatus::from((string) $row->status),
            email: $row->email !== null ? (string) $row->email : null,
            phone: $row->phone !== null ? (string) $row->phone : null,
            createdAt: isset($row->created_at) && $row->created_at !== null ? new DateTimeImmutable((string) $row->created_at) : null,
            updatedAt: isset($row->updated_at) && $row->updated_at !== null ? new DateTimeImmutable((string) $row->updated_at) : null,
        );
    }

    public function listOrganizations(
        ?string $search = null,
        ?DirectoryStatus $status = null,
        int $limit = 100,
        int $offset = 0
    ): array {
        $query = $this->connection->table('directory_organizations');
        if ($search !== null && trim($search) !== '') {
            $trimmed = trim($search);
            $query->where(function ($q) use ($trimmed): void {
                $q->where('name', 'like', "%{$trimmed}%")
                  ->orWhere('id', 'like', "%{$trimmed}%")
                  ->orWhere('email', 'like', "%{$trimmed}%");
            });
        }
        if ($status !== null) {
            $query->where('status', $status->value);
        }
        $query->orderBy('created_at', 'desc')->offset($offset)->limit($limit);

        $rows = $query->get();
        $orgs = [];
        foreach ($rows as $row) {
            $orgs[] = new Organization(
                id: (string) $row->id,
                name: (string) $row->name,
                status: DirectoryStatus::from((string) $row->status),
                email: $row->email !== null ? (string) $row->email : null,
                phone: $row->phone !== null ? (string) $row->phone : null,
                createdAt: isset($row->created_at) && $row->created_at !== null ? new DateTimeImmutable((string) $row->created_at) : null,
                updatedAt: isset($row->updated_at) && $row->updated_at !== null ? new DateTimeImmutable((string) $row->updated_at) : null,
            );
        }
        return $orgs;
    }

    public function countOrganizations(?string $search = null, ?DirectoryStatus $status = null): int
    {
        $query = $this->connection->table('directory_organizations');
        if ($search !== null && trim($search) !== '') {
            $trimmed = trim($search);
            $query->where(function ($q) use ($trimmed): void {
                $q->where('name', 'like', "%{$trimmed}%")
                  ->orWhere('id', 'like', "%{$trimmed}%")
                  ->orWhere('email', 'like', "%{$trimmed}%");
            });
        }
        if ($status !== null) {
            $query->where('status', $status->value);
        }
        return $query->count();
    }

    // --- Users ---

    public function saveUser(User $user): void
    {
        $now = date('Y-m-d H:i:s');
        $exists = $this->connection->table('directory_users')->where('id', $user->id)->exists();

        if ($exists) {
            $this->connection->table('directory_users')->where('id', $user->id)->update([
                'display_name' => $user->displayName,
                'status' => $user->status->value,
                'email' => $user->email,
                'phone' => $user->phone,
                'updated_at' => $now,
            ]);
        } else {
            $this->connection->table('directory_users')->insert([
                'id' => $user->id,
                'display_name' => $user->displayName,
                'status' => $user->status->value,
                'email' => $user->email,
                'phone' => $user->phone,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function createUser(
        string $id,
        string $displayName,
        DirectoryStatus $status = DirectoryStatus::Active,
        ?string $email = null,
        ?string $phone = null
    ): User {
        $user = new User(
            id: $id,
            displayName: $displayName,
            status: $status,
            email: $email,
            phone: $phone
        );
        $this->saveUser($user);
        return $user;
    }

    public function findUser(string $id): ?User
    {
        $row = $this->connection->table('directory_users')->where('id', $id)->first();
        if ($row === null) {
            return null;
        }

        return new User(
            id: (string) $row->id,
            displayName: (string) $row->display_name,
            status: DirectoryStatus::from((string) $row->status),
            email: $row->email !== null ? (string) $row->email : null,
            phone: $row->phone !== null ? (string) $row->phone : null,
            createdAt: isset($row->created_at) && $row->created_at !== null ? new DateTimeImmutable((string) $row->created_at) : null,
            updatedAt: isset($row->updated_at) && $row->updated_at !== null ? new DateTimeImmutable((string) $row->updated_at) : null,
        );
    }

    public function listUsers(
        ?string $search = null,
        ?DirectoryStatus $status = null,
        int $limit = 100,
        int $offset = 0
    ): array {
        $query = $this->connection->table('directory_users');
        if ($search !== null && trim($search) !== '') {
            $trimmed = trim($search);
            $query->where(function ($q) use ($trimmed): void {
                $q->where('display_name', 'like', "%{$trimmed}%")
                  ->orWhere('id', 'like', "%{$trimmed}%")
                  ->orWhere('email', 'like', "%{$trimmed}%");
            });
        }
        if ($status !== null) {
            $query->where('status', $status->value);
        }
        $query->orderBy('created_at', 'desc')->offset($offset)->limit($limit);

        $rows = $query->get();
        $users = [];
        foreach ($rows as $row) {
            $users[] = new User(
                id: (string) $row->id,
                displayName: (string) $row->display_name,
                status: DirectoryStatus::from((string) $row->status),
                email: $row->email !== null ? (string) $row->email : null,
                phone: $row->phone !== null ? (string) $row->phone : null,
                createdAt: isset($row->created_at) && $row->created_at !== null ? new DateTimeImmutable((string) $row->created_at) : null,
                updatedAt: isset($row->updated_at) && $row->updated_at !== null ? new DateTimeImmutable((string) $row->updated_at) : null,
            );
        }
        return $users;
    }

    public function countUsers(?string $search = null, ?DirectoryStatus $status = null): int
    {
        $query = $this->connection->table('directory_users');
        if ($search !== null && trim($search) !== '') {
            $trimmed = trim($search);
            $query->where(function ($q) use ($trimmed): void {
                $q->where('display_name', 'like', "%{$trimmed}%")
                  ->orWhere('id', 'like', "%{$trimmed}%")
                  ->orWhere('email', 'like', "%{$trimmed}%");
            });
        }
        if ($status !== null) {
            $query->where('status', $status->value);
        }
        return $query->count();
    }

    // --- Memberships (Directory-Owned Distinct Period History) ---

    public function saveMembership(Membership $membership): void
    {
        $now = date('Y-m-d H:i:s');
        $exists = $this->connection->table('directory_memberships')->where('id', $membership->id)->exists();

        $data = [
            'user_id' => $membership->userId,
            'organization_id' => $membership->organizationId,
            'status' => $membership->status->value,
            'role' => $membership->role->value,
            'active_marker' => $membership->isActive() ? 1 : null,
            'ended_at' => $membership->endedAt?->format('Y-m-d H:i:s'),
            'updated_at' => $now,
        ];

        try {
            if ($exists) {
                $this->connection->table('directory_memberships')->where('id', $membership->id)->update($data);
            } else {
                $data['id'] = $membership->id;
                $data['created_at'] = $membership->createdAt?->format('Y-m-d H:i:s') ?? $now;
                $this->connection->table('directory_memberships')->insert($data);
            }
        } catch (QueryException $e) {
            // Translate database unique constraint violation to DirectoryConflictException
            if (str_contains($e->getMessage(), 'dir_mem_user_org_active_unique') || $e->getCode() === '23000' || (int)$e->getCode() === 23000) {
                throw new DirectoryConflictException(
                    "Active membership already exists for user {$membership->userId} in organization {$membership->organizationId}."
                );
            }
            throw $e;
        }
    }

    public function createMembership(
        string $id,
        string $userId,
        string $organizationId,
        DirectoryStatus $status = DirectoryStatus::Active,
        OrganizationRole|string $role = 'member',
        ?DateTimeImmutable $createdAt = null,
        ?DateTimeImmutable $endedAt = null
    ): Membership {
        $mem = new Membership(
            id: $id,
            userId: $userId,
            organizationId: $organizationId,
            role: $role,
            status: $status,
            createdAt: $createdAt,
            endedAt: $endedAt
        );
        $this->saveMembership($mem);
        return $mem;
    }

    public function findMembershipById(string $id): ?Membership
    {
        $row = $this->connection->table('directory_memberships')->where('id', $id)->first();
        if ($row === null) {
            return null;
        }

        return $this->hydrateMembership($row);
    }

    public function findActiveMembership(string $userId, string $organizationId): ?Membership
    {
        $row = $this->connection->table('directory_memberships')
            ->where('user_id', $userId)
            ->where('organization_id', $organizationId)
            ->where('status', DirectoryStatus::Active->value)
            ->orderBy('created_at', 'desc')
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->hydrateMembership($row);
    }

    public function findMembership(string $userId, string $organizationId): ?Membership
    {
        return $this->findActiveMembership($userId, $organizationId)
            ?? $this->findLatestMembership($userId, $organizationId);
    }

    public function findLatestMembership(string $userId, string $organizationId): ?Membership
    {
        $row = $this->connection->table('directory_memberships')
            ->where('user_id', $userId)
            ->where('organization_id', $organizationId)
            ->orderBy('created_at', 'desc')
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->hydrateMembership($row);
    }

    public function listMembershipsForUser(string $userId, ?DirectoryStatus $status = null): array
    {
        $query = $this->connection->table('directory_memberships')
            ->where('user_id', $userId);

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        $rows = $query->orderBy('created_at', 'desc')->get();
        return array_map(fn ($r) => $this->hydrateMembership($r), $rows->all());
    }

    public function listUserMemberships(string $userId, ?DirectoryStatus $status = null): array
    {
        return $this->listMembershipsForUser($userId, $status);
    }

    public function listMembershipsForOrganization(string $organizationId, ?DirectoryStatus $status = null): array
    {
        $query = $this->connection->table('directory_memberships')
            ->where('organization_id', $organizationId);

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        $rows = $query->orderBy('created_at', 'desc')->get();
        return array_map(fn ($r) => $this->hydrateMembership($r), $rows->all());
    }

    public function listOrganizationMemberships(string $organizationId, ?DirectoryStatus $status = null): array
    {
        return $this->listMembershipsForOrganization($organizationId, $status);
    }

    public function listMembershipsForUserAndOrganization(string $userId, string $organizationId): array
    {
        $rows = $this->connection->table('directory_memberships')
            ->where('user_id', $userId)
            ->where('organization_id', $organizationId)
            ->orderBy('created_at', 'desc')
            ->get();

        return array_map(fn ($r) => $this->hydrateMembership($r), $rows->all());
    }

    public function listMemberships(
        ?string $userId = null,
        ?string $organizationId = null,
        ?DirectoryStatus $status = null,
        int $limit = 100,
        int $offset = 0
    ): array {
        $query = $this->connection->table('directory_memberships');
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }
        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }
        if ($status !== null) {
            $query->where('status', $status->value);
        }
        $rows = $query->orderBy('created_at', 'desc')->offset($offset)->limit($limit)->get();

        return array_map(fn ($r) => $this->hydrateMembership($r), $rows->all());
    }

    public function countMemberships(
        ?string $userId = null,
        ?string $organizationId = null,
        ?DirectoryStatus $status = null
    ): int {
        $query = $this->connection->table('directory_memberships');
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }
        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }
        if ($status !== null) {
            $query->where('status', $status->value);
        }
        return $query->count();
    }

    // --- External References ---

    public function saveExternalReference(
        string $source,
        string $referenceKind,
        string $externalId,
        string $targetType,
        ?string $targetOrgId,
        ?string $targetUserId
    ): void {
        $now = date('Y-m-d H:i:s');
        $this->connection->table('directory_external_references')->insert([
            'id' => (string) Str::ulid(),
            'source' => $source,
            'reference_kind' => $referenceKind,
            'external_id' => $externalId,
            'target_type' => $targetType,
            'target_organization_id' => $targetOrgId,
            'target_user_id' => $targetUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function linkOrganizationReference(string $source, string $referenceKind, string $externalId, string $targetOrgId): void
    {
        $this->saveExternalReference($source, $referenceKind, $externalId, 'organization', $targetOrgId, null);
    }

    public function linkUserReference(string $source, string $referenceKind, string $externalId, string $targetUserId): void
    {
        $this->saveExternalReference($source, $referenceKind, $externalId, 'user', null, $targetUserId);
    }

    public function deleteExternalReference(string $source, string $referenceKind, string $externalId): void
    {
        $this->connection->table('directory_external_references')
            ->where('source', $source)
            ->where('reference_kind', $referenceKind)
            ->where('external_id', $externalId)
            ->delete();
    }

    public function findExternalReference(string $source, string $referenceKind, string $externalId): ?object
    {
        return $this->connection->table('directory_external_references')
            ->where('source', $source)
            ->where('reference_kind', $referenceKind)
            ->where('external_id', $externalId)
            ->first();
    }

    private function hydrateMembership(object $row): Membership
    {
        $roleString = isset($row->role) && $row->role !== null ? (string) $row->role : 'member';

        return new Membership(
            id: (string) $row->id,
            userId: (string) $row->user_id,
            organizationId: (string) $row->organization_id,
            role: new OrganizationRole($roleString),
            status: DirectoryStatus::from((string) $row->status),
            createdAt: isset($row->created_at) && $row->created_at !== null ? new DateTimeImmutable((string) $row->created_at) : null,
            endedAt: isset($row->ended_at) && $row->ended_at !== null ? new DateTimeImmutable((string) $row->ended_at) : null
        );
    }
}

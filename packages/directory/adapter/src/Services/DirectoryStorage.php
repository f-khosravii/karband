<?php

declare(strict_types=1);

namespace Karband\DirectoryLaravel\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Karband\Directory\Enums\DirectoryStatus;
use Karband\Directory\Enums\OrganizationRole;
use Karband\Directory\ValueObjects\Membership;
use Karband\Directory\ValueObjects\Organization;
use Karband\Directory\ValueObjects\User;
use Karband\DirectoryLaravel\Exceptions\DirectoryConflictException;
use Karband\DirectoryLaravel\Exceptions\DirectoryNotFoundException;

final readonly class DirectoryStorage
{
    public function __construct(public Connection $connection)
    {
    }

    public function createOrganization(
        string $id,
        string $name,
        DirectoryStatus $status = DirectoryStatus::Active,
        array $roles = [],
        ?string $email = null,
        ?string $phone = null
    ): Organization {
        $now = date('Y-m-d H:i:s');

        $exists = $this->connection->table('directory_organizations')->where('id', $id)->exists();
        if ($exists) {
            throw new DirectoryConflictException("Organization [{$id}] already exists.");
        }

        $this->connection->table('directory_organizations')->insert([
            'id' => $id,
            'name' => $name,
            'status' => $status->value,
            'email' => $email,
            'phone' => $phone,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($roles as $role) {
            $roleEnum = $role instanceof OrganizationRole ? $role : OrganizationRole::from((string) $role);
            $this->assignOrganizationRole($id, $roleEnum);
        }

        return new Organization(
            id: $id,
            name: $name,
            status: $status,
            roles: $roles,
            email: $email,
            phone: $phone
        );
    }

    public function updateOrganization(
        string $id,
        ?string $name = null,
        ?DirectoryStatus $status = null,
        ?string $email = null,
        ?string $phone = null
    ): Organization {
        $row = $this->connection->table('directory_organizations')->where('id', $id)->first();
        if ($row === null) {
            throw new DirectoryNotFoundException("Organization [{$id}] not found.");
        }

        $updates = ['updated_at' => date('Y-m-d H:i:s')];
        if ($name !== null) {
            $updates['name'] = $name;
        }
        if ($status !== null) {
            $updates['status'] = $status->value;
        }
        if ($email !== null) {
            $updates['email'] = $email;
        }
        if ($phone !== null) {
            $updates['phone'] = $phone;
        }

        $this->connection->table('directory_organizations')->where('id', $id)->update($updates);

        return $this->findOrganization($id) ?? throw new DirectoryNotFoundException("Organization [{$id}] not found.");
    }

    public function activateOrganization(string $id): Organization
    {
        return $this->updateOrganization($id, status: DirectoryStatus::Active);
    }

    public function deactivateOrganization(string $id): Organization
    {
        return $this->updateOrganization($id, status: DirectoryStatus::Inactive);
    }

    public function findOrganization(string $id): ?Organization
    {
        $row = $this->connection->table('directory_organizations')->where('id', $id)->first();
        if ($row === null) {
            return null;
        }

        $roles = $this->getOrganizationRoles($id);

        return new Organization(
            id: (string) $row->id,
            name: (string) $row->name,
            status: DirectoryStatus::from((string) $row->status),
            roles: $roles,
            email: $row->email !== null ? (string) $row->email : null,
            phone: $row->phone !== null ? (string) $row->phone : null
        );
    }

    public function assignOrganizationRole(string $organizationId, OrganizationRole $role): void
    {
        $orgExists = $this->connection->table('directory_organizations')->where('id', $organizationId)->exists();
        if (!$orgExists) {
            throw new DirectoryNotFoundException("Organization [{$organizationId}] not found.");
        }

        $exists = $this->connection->table('directory_organization_roles')
            ->where('organization_id', $organizationId)
            ->where('role', $role->value)
            ->exists();

        if ($exists) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $this->connection->table('directory_organization_roles')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $organizationId,
            'role' => $role->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function removeOrganizationRole(string $organizationId, OrganizationRole $role): void
    {
        $this->connection->table('directory_organization_roles')
            ->where('organization_id', $organizationId)
            ->where('role', $role->value)
            ->delete();
    }

    /**
     * @return list<OrganizationRole>
     */
    public function getOrganizationRoles(string $organizationId): array
    {
        $rows = $this->connection->table('directory_organization_roles')
            ->where('organization_id', $organizationId)
            ->get();

        $roles = [];
        foreach ($rows as $row) {
            $roles[] = OrganizationRole::from((string) $row->role);
        }

        return $roles;
    }

    public function createUser(
        string $id,
        string $displayName,
        DirectoryStatus $status = DirectoryStatus::Active,
        ?string $email = null,
        ?string $phone = null
    ): User {
        $now = date('Y-m-d H:i:s');

        $exists = $this->connection->table('directory_users')->where('id', $id)->exists();
        if ($exists) {
            throw new DirectoryConflictException("User [{$id}] already exists.");
        }

        $this->connection->table('directory_users')->insert([
            'id' => $id,
            'display_name' => $displayName,
            'status' => $status->value,
            'email' => $email,
            'phone' => $phone,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new User(
            id: $id,
            displayName: $displayName,
            status: $status,
            email: $email,
            phone: $phone
        );
    }

    public function updateUser(
        string $id,
        ?string $displayName = null,
        ?DirectoryStatus $status = null,
        ?string $email = null,
        ?string $phone = null
    ): User {
        $row = $this->connection->table('directory_users')->where('id', $id)->first();
        if ($row === null) {
            throw new DirectoryNotFoundException("User [{$id}] not found.");
        }

        $updates = ['updated_at' => date('Y-m-d H:i:s')];
        if ($displayName !== null) {
            $updates['display_name'] = $displayName;
        }
        if ($status !== null) {
            $updates['status'] = $status->value;
        }
        if ($email !== null) {
            $updates['email'] = $email;
        }
        if ($phone !== null) {
            $updates['phone'] = $phone;
        }

        $this->connection->table('directory_users')->where('id', $id)->update($updates);

        return $this->findUser($id) ?? throw new DirectoryNotFoundException("User [{$id}] not found.");
    }

    public function activateUser(string $id): User
    {
        return $this->updateUser($id, status: DirectoryStatus::Active);
    }

    public function deactivateUser(string $id): User
    {
        return $this->updateUser($id, status: DirectoryStatus::Inactive);
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
            phone: $row->phone !== null ? (string) $row->phone : null
        );
    }

    public function createMembership(
        string $id,
        string $userId,
        string $organizationId,
        DirectoryStatus $status = DirectoryStatus::Active
    ): Membership {
        $userExists = $this->connection->table('directory_users')->where('id', $userId)->exists();
        if (!$userExists) {
            throw new DirectoryNotFoundException("User [{$userId}] not found.");
        }

        $orgExists = $this->connection->table('directory_organizations')->where('id', $organizationId)->exists();
        if (!$orgExists) {
            throw new DirectoryNotFoundException("Organization [{$organizationId}] not found.");
        }

        $exists = $this->connection->table('directory_memberships')
            ->where('user_id', $userId)
            ->where('organization_id', $organizationId)
            ->exists();

        if ($exists) {
            throw new DirectoryConflictException("Membership for User [{$userId}] and Organization [{$organizationId}] already exists.");
        }

        $now = date('Y-m-d H:i:s');
        $this->connection->table('directory_memberships')->insert([
            'id' => $id,
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'status' => $status->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new Membership(
            id: $id,
            userId: $userId,
            organizationId: $organizationId,
            status: $status
        );
    }

    public function setMembershipStatus(string $userId, string $organizationId, DirectoryStatus $status): Membership
    {
        $row = $this->connection->table('directory_memberships')
            ->where('user_id', $userId)
            ->where('organization_id', $organizationId)
            ->first();

        if ($row === null) {
            throw new DirectoryNotFoundException("Membership for User [{$userId}] and Organization [{$organizationId}] not found.");
        }

        $this->connection->table('directory_memberships')
            ->where('user_id', $userId)
            ->where('organization_id', $organizationId)
            ->update([
                'status' => $status->value,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return new Membership(
            id: (string) $row->id,
            userId: $userId,
            organizationId: $organizationId,
            status: $status
        );
    }

    public function findMembership(string $userId, string $organizationId): ?Membership
    {
        $row = $this->connection->table('directory_memberships')
            ->where('user_id', $userId)
            ->where('organization_id', $organizationId)
            ->first();

        if ($row === null) {
            return null;
        }

        return new Membership(
            id: (string) $row->id,
            userId: (string) $row->user_id,
            organizationId: (string) $row->organization_id,
            status: DirectoryStatus::from((string) $row->status)
        );
    }

    /**
     * @return list<Membership>
     */
    public function listUserMemberships(string $userId, ?DirectoryStatus $status = null): array
    {
        $query = $this->connection->table('directory_memberships')->where('user_id', $userId);
        if ($status !== null) {
            $query->where('status', $status->value);
        }

        $rows = $query->get();
        $list = [];
        foreach ($rows as $row) {
            $list[] = new Membership(
                id: (string) $row->id,
                userId: (string) $row->user_id,
                organizationId: (string) $row->organization_id,
                status: DirectoryStatus::from((string) $row->status)
            );
        }

        return $list;
    }

    public function linkOrganizationReference(
        string $source,
        string $referenceKind,
        string $externalId,
        string $organizationId
    ): void {
        $orgExists = $this->connection->table('directory_organizations')->where('id', $organizationId)->exists();
        if (!$orgExists) {
            throw new DirectoryNotFoundException("Organization [{$organizationId}] not found.");
        }

        $this->linkReferenceInternal($source, $referenceKind, $externalId, 'organization', $organizationId, null);
    }

    public function linkUserReference(
        string $source,
        string $referenceKind,
        string $externalId,
        string $userId
    ): void {
        $userExists = $this->connection->table('directory_users')->where('id', $userId)->exists();
        if (!$userExists) {
            throw new DirectoryNotFoundException("User [{$userId}] not found.");
        }

        $this->linkReferenceInternal($source, $referenceKind, $externalId, 'user', null, $userId);
    }

    private function linkReferenceInternal(
        string $source,
        string $referenceKind,
        string $externalId,
        string $targetType,
        ?string $targetOrganizationId,
        ?string $targetUserId
    ): void {
        $existing = $this->findExternalReference($source, $referenceKind, $externalId);
        if ($existing !== null) {
            throw new DirectoryConflictException("External reference [{$source}:{$referenceKind}:{$externalId}] is already linked.");
        }

        $now = date('Y-m-d H:i:s');
        $this->connection->table('directory_external_references')->insert([
            'id' => (string) Str::ulid(),
            'source' => $source,
            'reference_kind' => $referenceKind,
            'external_id' => $externalId,
            'target_type' => $targetType,
            'target_organization_id' => $targetOrganizationId,
            'target_user_id' => $targetUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function unlinkReference(string $source, string $referenceKind, string $externalId): void
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
}

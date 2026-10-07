<?php

declare(strict_types=1);

namespace Karband\Directory\Services;

use DateTimeImmutable;
use Karband\Directory\Contracts\DirectoryAuditPortInterface;
use Karband\Directory\Contracts\DirectoryStoragePortInterface;
use Karband\Directory\Enums\DirectoryStatus;
use Karband\Directory\Exceptions\DirectoryConflictException;
use Karband\Directory\Exceptions\DirectoryNotFoundException;
use Karband\Directory\ValueObjects\Membership;
use Karband\Directory\ValueObjects\Organization;
use Karband\Directory\ValueObjects\OrganizationRole;
use Karband\Directory\ValueObjects\User;

/**
 * Pure framework-independent Directory domain service.
 * Coordinates entity lifecycles, membership history, invariants, and audit evidence.
 */
final readonly class DirectoryService
{
    public function __construct(
        private DirectoryStoragePortInterface $storage,
        private ?DirectoryAuditPortInterface $audit = null,
    ) {
    }

    // --- Organizations ---

    public function createOrganization(
        string $id,
        string $name,
        DirectoryStatus $status = DirectoryStatus::Active,
        ?string $email = null,
        ?string $phone = null
    ): Organization {
        if ($this->storage->findOrganization($id) !== null) {
            throw new DirectoryConflictException("Organization [{$id}] already exists.");
        }

        $org = new Organization(
            id: $id,
            name: $name,
            status: $status,
            email: $email,
            phone: $phone
        );

        $this->storage->saveOrganization($org);

        $this->recordAudit('directory.organization.created', 'organization', $id, [
            'to_state' => $status->value,
            'result' => 'created',
        ]);

        return $org;
    }

    public function updateOrganization(
        string $id,
        ?string $name = null,
        ?DirectoryStatus $status = null,
        ?string $email = null,
        ?string $phone = null
    ): Organization {
        $existing = $this->storage->findOrganization($id);
        if ($existing === null) {
            throw new DirectoryNotFoundException("Organization [{$id}] not found.");
        }

        $updated = new Organization(
            id: $id,
            name: $name ?? $existing->name,
            status: $status ?? $existing->status,
            email: $email !== null ? $email : $existing->email,
            phone: $phone !== null ? $phone : $existing->phone,
        );

        $this->storage->saveOrganization($updated);

        $fromState = $existing->status->value;
        $toState = $updated->status->value;

        $auditCode = 'directory.organization.updated';
        if ($status !== null && $status->value !== $fromState) {
            $auditCode = $status === DirectoryStatus::Active ? 'directory.organization.restored' : 'directory.organization.archived';
        }

        $this->recordAudit($auditCode, 'organization', $id, [
            'from_state' => $fromState,
            'to_state' => $toState,
            'result' => 'updated',
        ]);

        return $updated;
    }

    public function archiveOrganization(string $id): Organization
    {
        return $this->updateOrganization($id, status: DirectoryStatus::Inactive);
    }

    public function restoreOrganization(string $id): Organization
    {
        return $this->updateOrganization($id, status: DirectoryStatus::Active);
    }

    // --- Users ---

    public function createUser(
        string $id,
        string $displayName,
        DirectoryStatus $status = DirectoryStatus::Active,
        ?string $email = null,
        ?string $phone = null
    ): User {
        if ($this->storage->findUser($id) !== null) {
            throw new DirectoryConflictException("User [{$id}] already exists.");
        }

        $user = new User(
            id: $id,
            displayName: $displayName,
            status: $status,
            email: $email,
            phone: $phone
        );

        $this->storage->saveUser($user);

        $this->recordAudit('directory.user.created', 'user', $id, [
            'to_state' => $status->value,
            'result' => 'created',
        ]);

        return $user;
    }

    public function updateUser(
        string $id,
        ?string $displayName = null,
        ?DirectoryStatus $status = null,
        ?string $email = null,
        ?string $phone = null
    ): User {
        $existing = $this->storage->findUser($id);
        if ($existing === null) {
            throw new DirectoryNotFoundException("User [{$id}] not found.");
        }

        $updated = new User(
            id: $id,
            displayName: $displayName ?? $existing->displayName,
            status: $status ?? $existing->status,
            email: $email !== null ? $email : $existing->email,
            phone: $phone !== null ? $phone : $existing->phone,
        );

        $this->storage->saveUser($updated);

        $fromState = $existing->status->value;
        $toState = $updated->status->value;

        $auditCode = 'directory.user.updated';
        if ($status !== null && $status->value !== $fromState) {
            $auditCode = $status === DirectoryStatus::Active ? 'directory.user.restored' : 'directory.user.archived';
        }

        $this->recordAudit($auditCode, 'user', $id, [
            'from_state' => $fromState,
            'to_state' => $toState,
            'result' => 'updated',
        ]);

        return $updated;
    }

    public function archiveUser(string $id): User
    {
        return $this->updateUser($id, status: DirectoryStatus::Inactive);
    }

    public function restoreUser(string $id): User
    {
        return $this->updateUser($id, status: DirectoryStatus::Active);
    }

    // --- Memberships (Directory-Owned Distinct Period History) ---

    public function createMembership(
        string $id,
        string $userId,
        string $organizationId,
        OrganizationRole|string $role = 'member',
        DirectoryStatus $status = DirectoryStatus::Active,
        ?DateTimeImmutable $now = null
    ): Membership {
        if ($this->storage->findUser($userId) === null) {
            throw new DirectoryNotFoundException("User [{$userId}] not found.");
        }
        if ($this->storage->findOrganization($organizationId) === null) {
            throw new DirectoryNotFoundException("Organization [{$organizationId}] not found.");
        }

        $active = $this->storage->findActiveMembership($userId, $organizationId);
        if ($active !== null && $status === DirectoryStatus::Active) {
            throw new DirectoryConflictException("Active membership for User [{$userId}] in Organization [{$organizationId}] already exists.");
        }

        $roleVo = $role instanceof OrganizationRole ? $role : new OrganizationRole((string) $role);
        $createdAt = $now ?? new DateTimeImmutable();

        $membership = new Membership(
            id: $id,
            userId: $userId,
            organizationId: $organizationId,
            role: $roleVo,
            status: $status,
            createdAt: $createdAt,
            endedAt: null
        );

        $this->storage->saveMembership($membership);

        $this->recordAudit('directory.membership.created', 'membership', $id, [
            'to_state' => $status->value,
            'role' => $roleVo->value,
            'result' => 'created',
        ]);

        return $membership;
    }

    public function addMembership(
        string $id,
        string $userId,
        string $organizationId,
        OrganizationRole|string $role = 'member',
        DirectoryStatus $status = DirectoryStatus::Active,
        ?DateTimeImmutable $now = null
    ): Membership {
        return $this->createMembership($id, $userId, $organizationId, $role, $status, $now);
    }

    public function endMembership(
        string $userId,
        string $organizationId,
        ?DateTimeImmutable $now = null
    ): Membership {
        $active = $this->storage->findActiveMembership($userId, $organizationId);
        if ($active === null) {
            throw new DirectoryNotFoundException("Active membership for User [{$userId}] in Organization [{$organizationId}] not found.");
        }

        $endedAt = $now ?? new DateTimeImmutable();

        $ended = new Membership(
            id: $active->id,
            userId: $active->userId,
            organizationId: $active->organizationId,
            role: $active->role,
            status: DirectoryStatus::Inactive,
            createdAt: $active->createdAt,
            endedAt: $endedAt
        );

        $this->storage->saveMembership($ended);

        $this->recordAudit('directory.membership.ended', 'membership', $ended->id, [
            'from_state' => DirectoryStatus::Active->value,
            'to_state' => DirectoryStatus::Inactive->value,
            'result' => 'ended',
        ]);

        return $ended;
    }

    public function restoreMembership(
        string $userId,
        string $organizationId,
        ?DateTimeImmutable $now = null
    ): Membership {
        $active = $this->storage->findActiveMembership($userId, $organizationId);
        if ($active !== null) {
            return $active;
        }

        $latest = $this->storage->findMembership($userId, $organizationId);
        if ($latest !== null) {
            // Create a brand new active period for rejoin
            $newId = bin2hex(random_bytes(13));
            return $this->createMembership(
                id: $newId,
                userId: $userId,
                organizationId: $organizationId,
                role: $latest->role,
                status: DirectoryStatus::Active,
                now: $now
            );
        }

        $newId = bin2hex(random_bytes(13));
        return $this->createMembership(
            id: $newId,
            userId: $userId,
            organizationId: $organizationId,
            role: OrganizationRole::default(),
            status: DirectoryStatus::Active,
            now: $now
        );
    }

    public function rejoinMembership(
        string $newMembershipId,
        string $userId,
        string $organizationId,
        OrganizationRole|string $role = 'member',
        ?DateTimeImmutable $now = null
    ): Membership {
        // Rejoining creates a brand new distinct active membership record, preserving previous ended period(s)
        return $this->createMembership($newMembershipId, $userId, $organizationId, $role, DirectoryStatus::Active, $now);
    }

    public function changeMembershipRole(
        string $userId,
        string $organizationId,
        OrganizationRole|string $newRole
    ): Membership {
        $active = $this->storage->findActiveMembership($userId, $organizationId);
        if ($active === null) {
            throw new DirectoryNotFoundException("Active membership for User [{$userId}] in Organization [{$organizationId}] not found.");
        }

        $roleVo = $newRole instanceof OrganizationRole ? $newRole : new OrganizationRole((string) $newRole);

        $updated = new Membership(
            id: $active->id,
            userId: $active->userId,
            organizationId: $active->organizationId,
            role: $roleVo,
            status: $active->status,
            createdAt: $active->createdAt,
            endedAt: $active->endedAt
        );

        $this->storage->saveMembership($updated);

        $this->recordAudit('directory.membership.role_changed', 'membership', $updated->id, [
            'old_role' => $active->role->value,
            'new_role' => $roleVo->value,
            'result' => 'role_changed',
        ]);

        return $updated;
    }

    // --- External References ---

    public function linkOrganizationReference(
        string $source,
        string $referenceKind,
        string $externalId,
        string $organizationId
    ): void {
        if ($this->storage->findOrganization($organizationId) === null) {
            throw new DirectoryNotFoundException("Organization [{$organizationId}] not found.");
        }

        if ($this->storage->findExternalReference($source, $referenceKind, $externalId) !== null) {
            throw new DirectoryConflictException("External reference [{$source}:{$referenceKind}:{$externalId}] is already linked.");
        }

        $this->storage->saveExternalReference($source, $referenceKind, $externalId, 'organization', $organizationId, null);
    }

    public function linkUserReference(
        string $source,
        string $referenceKind,
        string $externalId,
        string $userId
    ): void {
        if ($this->storage->findUser($userId) === null) {
            throw new DirectoryNotFoundException("User [{$userId}] not found.");
        }

        if ($this->storage->findExternalReference($source, $referenceKind, $externalId) !== null) {
            throw new DirectoryConflictException("External reference [{$source}:{$referenceKind}:{$externalId}] is already linked.");
        }

        $this->storage->saveExternalReference($source, $referenceKind, $externalId, 'user', null, $userId);
    }

    public function unlinkReference(string $source, string $referenceKind, string $externalId): void
    {
        $this->storage->deleteExternalReference($source, $referenceKind, $externalId);
    }

    private function recordAudit(string $code, string $subjectType, string $subjectId, array $metadata = []): void
    {
        if ($this->audit === null) {
            return;
        }

        try {
            $this->audit->record($code, $subjectType, $subjectId, $metadata);
        } catch (\Throwable) {
            // Best-effort audit logging: audit failures never abort domain actions or destroy history
        }
    }
}

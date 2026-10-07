<?php

declare(strict_types=1);

namespace App\Bridges;

use Illuminate\Support\Str;
use Karband\Authorization\AccessRequest;
use Karband\Authorization\AuthorizerInterface;
use Karband\Authorization\Contracts\ActorProviderInterface;
use Karband\Directory\Enums\DirectoryStatus;
use Karband\Directory\Services\DirectoryService;
use Karband\Directory\ValueObjects\Membership;
use Karband\Directory\ValueObjects\Organization;
use Karband\Directory\ValueObjects\OrganizationRole;
use Karband\Directory\ValueObjects\User;
use Karband\DirectoryLaravel\Services\DirectoryStorage;
use Karband\DirectoryPanel\Contracts\DirectoryPanelAuthorizationInterface;
use Karband\DirectoryPanel\Contracts\DirectoryPanelPortInterface;
use Karband\DirectoryPanel\Dto\MembershipItem;
use Karband\DirectoryPanel\Dto\OrganizationDetail;
use Karband\DirectoryPanel\Dto\OrganizationItem;
use Karband\DirectoryPanel\Dto\UserDetail;
use Karband\DirectoryPanel\Dto\UserItem;

final readonly class DirectoryPanelBridge implements DirectoryPanelPortInterface, DirectoryPanelAuthorizationInterface
{
    public function __construct(
        private DirectoryStorage $storage,
        private DirectoryService $service,
        private ActorProviderInterface $actors,
        private AuthorizerInterface $authorizer,
    ) {
    }

    // --- Organizations ---

    public function listOrganizations(?string $search = null, ?string $status = null): array
    {
        $statusEnum = $status !== null && $status !== '' ? DirectoryStatus::tryFrom($status) : null;
        $orgs = $this->storage->listOrganizations($search, $statusEnum, limit: 200);

        return array_map(fn (Organization $org): OrganizationItem => $this->toOrgItem($org), $orgs);
    }

    public function getOrganization(string $id): OrganizationDetail
    {
        $org = $this->storage->findOrganization($id);
        if ($org === null) {
            abort(404, "Organization [{$id}] not found.");
        }

        $memberships = $this->listMembershipsForOrganization($id);

        return new OrganizationDetail(
            id: $org->id,
            name: $org->name,
            status: $org->status->value,
            email: $org->email,
            phone: $org->phone,
            isActive: $org->isActive(),
            createdAt: $org->createdAt?->format('Y-m-d H:i:s'),
            updatedAt: $org->updatedAt?->format('Y-m-d H:i:s'),
            memberCount: count($memberships),
            memberships: $memberships,
        );
    }

    public function createOrganization(
        string $id,
        string $name,
        ?string $email = null,
        ?string $phone = null
    ): OrganizationDetail {
        $org = $this->service->createOrganization(
            id: $id,
            name: $name,
            status: DirectoryStatus::Active,
            email: $email,
            phone: $phone
        );

        return $this->getOrganization($org->id);
    }

    public function updateOrganization(
        string $id,
        string $name,
        ?string $email = null,
        ?string $phone = null
    ): OrganizationDetail {
        $org = $this->service->updateOrganization(
            id: $id,
            name: $name,
            email: $email,
            phone: $phone
        );

        return $this->getOrganization($org->id);
    }

    public function archiveOrganization(string $id): OrganizationDetail
    {
        $org = $this->service->archiveOrganization($id);
        return $this->getOrganization($org->id);
    }

    public function restoreOrganization(string $id): OrganizationDetail
    {
        $org = $this->service->restoreOrganization($id);
        return $this->getOrganization($org->id);
    }

    // --- Users ---

    public function listUsers(?string $search = null, ?string $status = null): array
    {
        $statusEnum = $status !== null && $status !== '' ? DirectoryStatus::tryFrom($status) : null;
        $users = $this->storage->listUsers($search, $statusEnum, limit: 200);

        return array_map(fn (User $user): UserItem => $this->toUserItem($user), $users);
    }

    public function getUser(string $id): UserDetail
    {
        $user = $this->storage->findUser($id);
        if ($user === null) {
            abort(404, "User [{$id}] not found.");
        }

        $memberships = $this->listMembershipsForUser($id);

        return new UserDetail(
            id: $user->id,
            displayName: $user->displayName,
            status: $user->status->value,
            email: $user->email,
            phone: $user->phone,
            isActive: $user->isActive(),
            createdAt: $user->createdAt?->format('Y-m-d H:i:s'),
            updatedAt: $user->updatedAt?->format('Y-m-d H:i:s'),
            organizationCount: count($memberships),
            memberships: $memberships,
        );
    }

    public function createUser(
        string $id,
        string $displayName,
        ?string $email = null,
        ?string $phone = null
    ): UserDetail {
        $user = $this->service->createUser(
            id: $id,
            displayName: $displayName,
            status: DirectoryStatus::Active,
            email: $email,
            phone: $phone
        );

        return $this->getUser($user->id);
    }

    public function updateUser(
        string $id,
        string $displayName,
        ?string $email = null,
        ?string $phone = null
    ): UserDetail {
        $user = $this->service->updateUser(
            id: $id,
            displayName: $displayName,
            email: $email,
            phone: $phone
        );

        return $this->getUser($user->id);
    }

    public function archiveUser(string $id): UserDetail
    {
        $user = $this->service->archiveUser($id);
        return $this->getUser($user->id);
    }

    public function restoreUser(string $id): UserDetail
    {
        $user = $this->service->restoreUser($id);
        return $this->getUser($user->id);
    }

    // --- Memberships ---

    public function listMembershipsForUser(string $userId): array
    {
        $memberships = $this->storage->listUserMemberships($userId);
        $items = [];

        foreach ($memberships as $mem) {
            $org = $this->storage->findOrganization($mem->organizationId);
            $user = $this->storage->findUser($mem->userId);

            $items[] = new MembershipItem(
                id: $mem->id,
                userId: $mem->userId,
                userDisplayName: $user?->displayName ?? $mem->userId,
                organizationId: $mem->organizationId,
                organizationName: $org?->name ?? $mem->organizationId,
                status: $mem->status->value,
                isActive: $mem->isActive(),
                role: $mem->role->value,
                createdAt: $mem->createdAt?->format('Y-m-d H:i:s'),
                endedAt: $mem->endedAt?->format('Y-m-d H:i:s'),
            );
        }

        return $items;
    }

    public function listMembershipsForOrganization(string $organizationId): array
    {
        $memberships = $this->storage->listOrganizationMemberships($organizationId);
        $items = [];

        foreach ($memberships as $mem) {
            $org = $this->storage->findOrganization($mem->organizationId);
            $user = $this->storage->findUser($mem->userId);

            $items[] = new MembershipItem(
                id: $mem->id,
                userId: $mem->userId,
                userDisplayName: $user?->displayName ?? $mem->userId,
                organizationId: $mem->organizationId,
                organizationName: $org?->name ?? $mem->organizationId,
                status: $mem->status->value,
                isActive: $mem->isActive(),
                role: $mem->role->value,
                createdAt: $mem->createdAt?->format('Y-m-d H:i:s'),
                endedAt: $mem->endedAt?->format('Y-m-d H:i:s'),
            );
        }

        return $items;
    }

    public function addMembership(
        string $userId,
        string $organizationId,
        ?string $role = null,
        ?string $membershipId = null
    ): MembershipItem {
        $id = $membershipId ?? (string) Str::ulid();
        $roleVO = $role !== null ? new OrganizationRole($role) : OrganizationRole::default();
        $mem = $this->service->createMembership($id, $userId, $organizationId, $roleVO, DirectoryStatus::Active);

        $org = $this->storage->findOrganization($mem->organizationId);
        $user = $this->storage->findUser($mem->userId);

        return new MembershipItem(
            id: $mem->id,
            userId: $mem->userId,
            userDisplayName: $user?->displayName ?? $mem->userId,
            organizationId: $mem->organizationId,
            organizationName: $org?->name ?? $mem->organizationId,
            status: $mem->status->value,
            isActive: $mem->isActive(),
            role: $mem->role->value,
            createdAt: $mem->createdAt?->format('Y-m-d H:i:s'),
            endedAt: $mem->endedAt?->format('Y-m-d H:i:s'),
        );
    }

    public function endMembership(string $userId, string $organizationId): MembershipItem
    {
        $mem = $this->service->endMembership($userId, $organizationId);
        $org = $this->storage->findOrganization($mem->organizationId);
        $user = $this->storage->findUser($mem->userId);

        return new MembershipItem(
            id: $mem->id,
            userId: $mem->userId,
            userDisplayName: $user?->displayName ?? $mem->userId,
            organizationId: $mem->organizationId,
            organizationName: $org?->name ?? $mem->organizationId,
            status: $mem->status->value,
            isActive: $mem->isActive(),
            role: $mem->role->value,
            createdAt: $mem->createdAt?->format('Y-m-d H:i:s'),
            endedAt: $mem->endedAt?->format('Y-m-d H:i:s'),
        );
    }

    public function restoreMembership(string $userId, string $organizationId): MembershipItem
    {
        $mem = $this->service->restoreMembership($userId, $organizationId);
        $org = $this->storage->findOrganization($mem->organizationId);
        $user = $this->storage->findUser($mem->userId);

        return new MembershipItem(
            id: $mem->id,
            userId: $mem->userId,
            userDisplayName: $user?->displayName ?? $mem->userId,
            organizationId: $mem->organizationId,
            organizationName: $org?->name ?? $mem->organizationId,
            status: $mem->status->value,
            isActive: $mem->isActive(),
            role: $mem->role->value,
            createdAt: $mem->createdAt?->format('Y-m-d H:i:s'),
            endedAt: $mem->endedAt?->format('Y-m-d H:i:s'),
        );
    }

    // --- Authorization implementation (fine-grained, role-agnostic) ---

    public function canListOrganizations(): bool
    {
        return $this->checkAbility('directory.organization.list', 'directory.organization', 'global');
    }

    public function canViewOrganization(string $id): bool
    {
        return $this->checkAbility('directory.organization.view', 'directory.organization', $id);
    }

    public function canCreateOrganization(): bool
    {
        return $this->checkAbility('directory.organization.create', 'directory.organization', 'new');
    }

    public function canUpdateOrganization(string $id): bool
    {
        return $this->checkAbility('directory.organization.update', 'directory.organization', $id);
    }

    public function canArchiveOrganization(string $id): bool
    {
        return $this->checkAbility('directory.organization.archive', 'directory.organization', $id);
    }

    public function canRestoreOrganization(string $id): bool
    {
        return $this->checkAbility('directory.organization.restore', 'directory.organization', $id);
    }

    public function canListUsers(): bool
    {
        return $this->checkAbility('directory.user.list', 'directory.user', 'global');
    }

    public function canViewUser(string $id): bool
    {
        return $this->checkAbility('directory.user.view', 'directory.user', $id);
    }

    public function canCreateUser(): bool
    {
        return $this->checkAbility('directory.user.create', 'directory.user', 'new');
    }

    public function canUpdateUser(string $id): bool
    {
        return $this->checkAbility('directory.user.update', 'directory.user', $id);
    }

    public function canArchiveUser(string $id): bool
    {
        return $this->checkAbility('directory.user.archive', 'directory.user', $id);
    }

    public function canRestoreUser(string $id): bool
    {
        return $this->checkAbility('directory.user.restore', 'directory.user', $id);
    }

    public function canListMemberships(): bool
    {
        return $this->checkAbility('directory.membership.list', 'directory.membership', 'global');
    }

    public function canCreateMembership(): bool
    {
        return $this->checkAbility('directory.membership.create', 'directory.membership', 'new');
    }

    public function canUpdateMembership(): bool
    {
        return $this->checkAbility('directory.membership.update', 'directory.membership', 'any');
    }

    public function canEndMembership(): bool
    {
        return $this->checkAbility('directory.membership.end', 'directory.membership', 'any');
    }

    private function checkAbility(string $ability, string $resourceType, string $id): bool
    {
        try {
            $actor = $this->actors->currentActor();
            $this->authorizer->authorize($actor, new AccessRequest($ability, $resourceType, $id));
            return true;
        } catch (\Karband\Authorization\AuthorizationDenied) {
            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    // --- Transformation helpers ---

    private function toOrgItem(Organization $org): OrganizationItem
    {
        return new OrganizationItem(
            id: $org->id,
            name: $org->name,
            status: $org->status->value,
            email: $org->email,
            phone: $org->phone,
            isActive: $org->isActive(),
            createdAt: $org->createdAt?->format('Y-m-d H:i:s'),
        );
    }

    private function toUserItem(User $user): UserItem
    {
        return new UserItem(
            id: $user->id,
            displayName: $user->displayName,
            status: $user->status->value,
            email: $user->email,
            phone: $user->phone,
            isActive: $user->isActive(),
            createdAt: $user->createdAt?->format('Y-m-d H:i:s'),
        );
    }
}

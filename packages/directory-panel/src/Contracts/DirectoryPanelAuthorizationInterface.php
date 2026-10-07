<?php

declare(strict_types=1);

namespace Karband\DirectoryPanel\Contracts;

/**
 * Role-agnostic permission checker for Directory Panel presentation controls and endpoints.
 * Never checks concrete roles (e.g. SuperAdmin, PlatformAdmin, Agency, Tax, Legal);
 * only checks fine-grained canonical directory permissions/capabilities.
 */
interface DirectoryPanelAuthorizationInterface
{
    public function canListOrganizations(): bool;

    public function canViewOrganization(string $id): bool;

    public function canCreateOrganization(): bool;

    public function canUpdateOrganization(string $id): bool;

    public function canArchiveOrganization(string $id): bool;

    public function canRestoreOrganization(string $id): bool;

    public function canListUsers(): bool;

    public function canViewUser(string $id): bool;

    public function canCreateUser(): bool;

    public function canUpdateUser(string $id): bool;

    public function canArchiveUser(string $id): bool;

    public function canRestoreUser(string $id): bool;

    public function canListMemberships(): bool;

    public function canCreateMembership(): bool;

    public function canUpdateMembership(): bool;

    public function canEndMembership(): bool;
}

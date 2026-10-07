<?php

declare(strict_types=1);

namespace Karband\DirectoryPanel\Services;

use Illuminate\Support\Facades\Gate;
use Karband\DirectoryPanel\Contracts\DirectoryPanelAuthorizationInterface;

/**
 * Default implementation of DirectoryPanelAuthorizationInterface using Laravel Gate.
 * Checks fine-grained capabilities without role knowledge.
 * Fails closed: denies access by default if gate is not defined or denied.
 */
final class DefaultDirectoryPanelAuthorization implements DirectoryPanelAuthorizationInterface
{
    public function canListOrganizations(): bool
    {
        return Gate::has('directory.organization.list') && (bool) Gate::allows('directory.organization.list');
    }

    public function canViewOrganization(string $id): bool
    {
        return Gate::has('directory.organization.view') && (bool) Gate::allows('directory.organization.view', $id);
    }

    public function canCreateOrganization(): bool
    {
        return Gate::has('directory.organization.create') && (bool) Gate::allows('directory.organization.create');
    }

    public function canUpdateOrganization(string $id): bool
    {
        return Gate::has('directory.organization.update') && (bool) Gate::allows('directory.organization.update', $id);
    }

    public function canArchiveOrganization(string $id): bool
    {
        return Gate::has('directory.organization.archive') && (bool) Gate::allows('directory.organization.archive', $id);
    }

    public function canRestoreOrganization(string $id): bool
    {
        return Gate::has('directory.organization.restore') && (bool) Gate::allows('directory.organization.restore', $id);
    }

    public function canListUsers(): bool
    {
        return Gate::has('directory.user.list') && (bool) Gate::allows('directory.user.list');
    }

    public function canViewUser(string $id): bool
    {
        return Gate::has('directory.user.view') && (bool) Gate::allows('directory.user.view', $id);
    }

    public function canCreateUser(): bool
    {
        return Gate::has('directory.user.create') && (bool) Gate::allows('directory.user.create');
    }

    public function canUpdateUser(string $id): bool
    {
        return Gate::has('directory.user.update') && (bool) Gate::allows('directory.user.update', $id);
    }

    public function canArchiveUser(string $id): bool
    {
        return Gate::has('directory.user.archive') && (bool) Gate::allows('directory.user.archive', $id);
    }

    public function canRestoreUser(string $id): bool
    {
        return Gate::has('directory.user.restore') && (bool) Gate::allows('directory.user.restore', $id);
    }

    public function canListMemberships(): bool
    {
        return Gate::has('directory.membership.list') && (bool) Gate::allows('directory.membership.list');
    }

    public function canCreateMembership(): bool
    {
        return Gate::has('directory.membership.create') && (bool) Gate::allows('directory.membership.create');
    }

    public function canUpdateMembership(): bool
    {
        return Gate::has('directory.membership.update') && (bool) Gate::allows('directory.membership.update');
    }

    public function canEndMembership(): bool
    {
        return Gate::has('directory.membership.end') && (bool) Gate::allows('directory.membership.end');
    }
}

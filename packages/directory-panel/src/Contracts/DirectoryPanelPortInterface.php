<?php

declare(strict_types=1);

namespace Karband\DirectoryPanel\Contracts;

use Karband\DirectoryPanel\Dto\MembershipItem;
use Karband\DirectoryPanel\Dto\OrganizationDetail;
use Karband\DirectoryPanel\Dto\OrganizationItem;
use Karband\DirectoryPanel\Dto\UserDetail;
use Karband\DirectoryPanel\Dto\UserItem;

/**
 * Consumer-owned port for the Directory Panel.
 * Implemented by host/composition bridges (e.g. Sandbox, Tax, Legal) to interact
 * with underlying Directory capability operations without coupling the presentation
 * package to internal adapter layers or querying foreign tables.
 */
interface DirectoryPanelPortInterface
{
    /**
     * @return list<OrganizationItem>
     */
    public function listOrganizations(?string $search = null, ?string $status = null): array;

    public function getOrganization(string $id): OrganizationDetail;

    public function createOrganization(
        string $id,
        string $name,
        ?string $email = null,
        ?string $phone = null
    ): OrganizationDetail;

    public function updateOrganization(
        string $id,
        string $name,
        ?string $email = null,
        ?string $phone = null
    ): OrganizationDetail;

    public function archiveOrganization(string $id): OrganizationDetail;

    public function restoreOrganization(string $id): OrganizationDetail;

    /**
     * @return list<UserItem>
     */
    public function listUsers(?string $search = null, ?string $status = null): array;

    public function getUser(string $id): UserDetail;

    public function createUser(
        string $id,
        string $displayName,
        ?string $email = null,
        ?string $phone = null
    ): UserDetail;

    public function updateUser(
        string $id,
        string $displayName,
        ?string $email = null,
        ?string $phone = null
    ): UserDetail;

    public function archiveUser(string $id): UserDetail;

    public function restoreUser(string $id): UserDetail;

    /**
     * @return list<MembershipItem>
     */
    public function listMembershipsForUser(string $userId): array;

    /**
     * @return list<MembershipItem>
     */
    public function listMembershipsForOrganization(string $organizationId): array;

    public function addMembership(
        string $userId,
        string $organizationId,
        ?string $role = null,
        ?string $membershipId = null
    ): MembershipItem;

    public function endMembership(string $userId, string $organizationId): MembershipItem;

    public function restoreMembership(string $userId, string $organizationId): MembershipItem;
}

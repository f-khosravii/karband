<?php

declare(strict_types=1);

namespace Karband\Directory\Services;

use Karband\Directory\Contracts\DirectoryStoragePortInterface;
use Karband\Directory\Enums\DirectoryStatus;
use Karband\Directory\ValueObjects\Membership;
use Karband\Directory\ValueObjects\Organization;
use Karband\Directory\ValueObjects\OrganizationRole;
use Karband\Directory\ValueObjects\User;

/**
 * Bootstrap the initial deployment facts: Operator Organization, initial User, and Membership.
 * Directory only records facts about people and organizations.
 *
 * It DOES NOT:
 * - create passwords or manage authentication credentials
 * - assign or infer a privileged "Super Admin" role in Directory
 * - grant Authorization permissions (authorization belongs exclusively to Authorization capability)
 */
final readonly class DirectoryBootstrapper
{
    public function __construct(
        private DirectoryService $service,
        private DirectoryStoragePortInterface $storage,
    ) {
    }

    /**
     * @return array{organization: Organization, user: User, membership: Membership}
     */
    public function bootstrapOperator(
        string $operatorOrgId,
        string $operatorOrgName,
        string $initialUserId,
        string $initialUserDisplayName,
        OrganizationRole|string $membershipRole,
        ?string $operatorEmail = null,
        ?string $operatorPhone = null,
        ?string $userEmail = null,
        ?string $userPhone = null,
        ?string $membershipId = null,
    ): array {
        $org = $this->storage->findOrganization($operatorOrgId)
            ?? $this->service->createOrganization(
                id: $operatorOrgId,
                name: $operatorOrgName,
                status: DirectoryStatus::Active,
                email: $operatorEmail,
                phone: $operatorPhone
            );

        $user = $this->storage->findUser($initialUserId)
            ?? $this->service->createUser(
                id: $initialUserId,
                displayName: $initialUserDisplayName,
                status: DirectoryStatus::Active,
                email: $userEmail,
                phone: $userPhone
            );

        $membership = $this->storage->findActiveMembership($user->id, $org->id)
            ?? $this->service->addMembership(
                id: $membershipId ?? bin2hex(random_bytes(13)),
                userId: $user->id,
                organizationId: $org->id,
                role: $membershipRole
            );

        return [
            'organization' => $org,
            'user' => $user,
            'membership' => $membership,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Karband\Directory\Services;

use Karband\Directory\Contracts\DirectoryResolverInterface;
use Karband\Directory\Contracts\DirectoryStoragePortInterface;
use Karband\Directory\Exceptions\DirectoryActorResolutionException;
use Karband\Directory\ValueObjects\ResolvedDirectoryActor;

final readonly class DirectoryResolver implements DirectoryResolverInterface
{
    public function __construct(private DirectoryStoragePortInterface $storage)
    {
    }

    public function resolveBySourceReference(
        string $source,
        string $referenceKind,
        string $externalId,
        ?string $explicitOrganizationId = null
    ): ResolvedDirectoryActor {
        $ref = $this->storage->findExternalReference($source, $referenceKind, $externalId);
        if ($ref === null) {
            throw new DirectoryActorResolutionException("Unknown source reference [{$source}:{$referenceKind}:{$externalId}].");
        }

        if ($ref->target_type === 'organization') {
            $orgId = (string) $ref->target_organization_id;
            return $this->resolveOrganizationActor($orgId, $explicitOrganizationId);
        }

        if ($ref->target_type === 'user') {
            $userId = (string) $ref->target_user_id;
            return $this->resolveUserActor($userId, $explicitOrganizationId);
        }

        throw new DirectoryActorResolutionException("Unsupported external reference target type [{$ref->target_type}].");
    }

    public function resolveOrganizationActor(
        string $organizationId,
        ?string $explicitOrganizationId = null
    ): ResolvedDirectoryActor {
        if ($explicitOrganizationId !== null && $explicitOrganizationId !== $organizationId) {
            throw new DirectoryActorResolutionException("Explicit organization context [{$explicitOrganizationId}] does not match target organization [{$organizationId}].");
        }

        $org = $this->storage->findOrganization($organizationId);
        if ($org === null || !$org->isActive()) {
            throw new DirectoryActorResolutionException("Target organization [{$organizationId}] is missing or inactive.");
        }

        return new ResolvedDirectoryActor(
            organizationId: $org->id,
            userId: null
        );
    }

    public function resolveUserActor(
        string $userId,
        ?string $explicitOrganizationId = null
    ): ResolvedDirectoryActor {
        $user = $this->storage->findUser($userId);
        if ($user === null || !$user->isActive()) {
            throw new DirectoryActorResolutionException("Target user [{$userId}] is missing or inactive.");
        }

        if ($explicitOrganizationId === null || trim($explicitOrganizationId) === '') {
            throw new DirectoryActorResolutionException("User [{$userId}] requires explicit organization context to resolve actor.");
        }

        $membership = $this->storage->findActiveMembership($userId, $explicitOrganizationId);
        if ($membership === null || !$membership->isActive()) {
            throw new DirectoryActorResolutionException("Membership between user [{$userId}] and organization [{$explicitOrganizationId}] is missing or inactive.");
        }

        $org = $this->storage->findOrganization($explicitOrganizationId);
        if ($org === null || !$org->isActive()) {
            throw new DirectoryActorResolutionException("Organization [{$explicitOrganizationId}] is missing or inactive.");
        }

        return new ResolvedDirectoryActor(
            organizationId: $org->id,
            userId: $user->id
        );
    }
}

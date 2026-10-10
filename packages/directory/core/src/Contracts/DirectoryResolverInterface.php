<?php

declare(strict_types=1);

namespace Karband\Directory\Contracts;

use Karband\Directory\ValueObjects\ResolvedDirectoryActor;

interface DirectoryResolverInterface
{
    /**
     * Resolve an actor given an authenticated source reference and optional explicit organization context.
     * Fails closed by throwing DirectoryActorResolutionException on any missing, inactive, or ambiguous condition.
     */
    public function resolveBySourceReference(
        string $source,
        string $referenceKind,
        string $externalId,
        ?string $explicitOrganizationId = null
    ): ResolvedDirectoryActor;

    /**
     * List all valid, available organization actors for an authenticated source reference.
     * Used by Hosts for pre-selection context discovery (0 vs 1 vs >1 contexts).
     *
     * @return list<ResolvedDirectoryActor>
     */
    public function listAvailableActorsBySourceReference(
        string $source,
        string $referenceKind,
        string $externalId
    ): array;
}

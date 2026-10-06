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
}

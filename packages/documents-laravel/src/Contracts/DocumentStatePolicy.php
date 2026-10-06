<?php

declare(strict_types=1);

namespace Intl\DocumentsLaravel\Contracts;

use Intl\Contracts\Authorization\ActorContext;

/**
 * Host policy determining whether a document action on a specific slot is valid
 * in the resource's current workflow/business state.
 */
interface DocumentStatePolicy
{
    public function allows(
        ActorContext $actor,
        string $action,
        string $serviceCode,
        string $resourceType,
        string $resourceId,
        string $slot,
        string $currentState
    ): bool;
}

<?php

declare(strict_types=1);

namespace Karband\DocumentsLaravel\Contracts;

use Karband\Authorization\ActorContext;

/** The host checks a TRUSTED workflow state (e.g. correction_required), never HTTP state. */
interface ReplacementPolicy
{
    public function allows(ActorContext $actor, string $serviceCode, string $resourceType, string $resourceId, string $slot): bool;
}

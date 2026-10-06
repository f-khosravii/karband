<?php

declare(strict_types=1);

namespace Intl\ServiceCatalogLaravel\Contracts;

use Intl\ServiceCatalog\Definitions\WorkflowBinding;

/** Fail-closed published-definition verification, supplied by the local service. */
interface WorkflowDefinitionVerifier
{
    public function assertPublished(WorkflowBinding $binding): void;
}

<?php

declare(strict_types=1);

namespace Karband\ServiceCatalogLaravel\Contracts;

use Karband\ServiceCatalog\Definitions\WorkflowBinding;

/** Fail-closed published-definition verification, supplied by the local service. */
interface WorkflowDefinitionVerifier
{
    public function assertPublished(WorkflowBinding $binding): void;
}

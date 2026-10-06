<?php

declare(strict_types=1);

namespace Karband\ServiceCatalogLaravel\Services;

use Karband\ServiceCatalog\Definitions\WorkflowBinding;
use Karband\ServiceCatalogLaravel\Contracts\WorkflowDefinitionVerifier;

/** No-op verifier when Workflow package is not bound. */
final readonly class NullWorkflowDefinitionVerifier implements WorkflowDefinitionVerifier
{
    public function assertPublished(WorkflowBinding $binding): void
    {
        // No-op by default
    }
}

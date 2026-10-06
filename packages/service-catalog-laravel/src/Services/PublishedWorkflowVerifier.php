<?php

declare(strict_types=1);

namespace Intl\ServiceCatalogLaravel\Services;

use Intl\ServiceCatalog\Definitions\WorkflowBinding;
use Intl\ServiceCatalogLaravel\Contracts\WorkflowDefinitionVerifier;
use Intl\WorkflowLaravel\Services\DefinitionCatalog;

/** Uses the installed local Workflow Laravel adapter; no cross-service calls. */
final readonly class PublishedWorkflowVerifier implements WorkflowDefinitionVerifier
{
    public function __construct(private DefinitionCatalog $definitions)
    {
    }

    public function assertPublished(WorkflowBinding $binding): void
    {
        $this->definitions->get($binding->id, $binding->version);
    }
}

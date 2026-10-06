<?php

declare(strict_types=1);

namespace App\Bridges;

use Karband\ServiceCatalog\Definitions\WorkflowBinding;
use Karband\ServiceCatalogLaravel\Contracts\WorkflowDefinitionVerifier;
use Karband\WorkflowLaravel\Services\DefinitionCatalog;

/** Host composition bridge: verifies catalog workflow bindings against workflow definition catalog. */
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

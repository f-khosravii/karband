<?php

declare(strict_types=1);

namespace Intl\ServiceCatalog\Definitions;

use Intl\ServiceCatalog\Exceptions\InvalidService;

/** A reference to an already published, immutable local workflow definition. */
final readonly class WorkflowBinding
{
    public function __construct(public string $id, public int $version)
    {
        ServiceDefinition::assertCode($id, 'Workflow id');
        if ($version < 1) {
            throw new InvalidService('Workflow version must be positive.');
        }
    }
}

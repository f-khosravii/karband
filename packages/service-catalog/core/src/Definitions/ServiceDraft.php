<?php

declare(strict_types=1);

namespace Karband\ServiceCatalog\Definitions;

/** Full replacement of the editable service properties. The code is immutable. */
final readonly class ServiceDraft
{
    /** @param array<string, mixed> $settings JSON-safe non-secret settings */
    public function __construct(
        public string $code,
        public string $name,
        public ?string $description = null,
        public array $settings = [],
        public ?WorkflowBinding $workflow = null,
    ) {
        // Reuse the same validation for drafts and persisted definitions.
        new ServiceDefinition($code, $name, $description, $settings, ServiceStatus::Inactive, $workflow, 0);
    }
}

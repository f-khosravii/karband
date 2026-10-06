<?php

declare(strict_types=1);

namespace Karband\Authorization;

use InvalidArgumentException;

/** Explicit ability against a resource; resource ownership is verified by the host. */
final readonly class AccessRequest
{
    public function __construct(
        public string $ability,
        public string $resourceType,
        public string $resourceId,
    ) {
        foreach (['ability' => $ability, 'resourceType' => $resourceType, 'resourceId' => $resourceId] as $label => $value) {
            if (trim($value) === '' || strlen($value) > 255) {
                throw new InvalidArgumentException("Authorization request {$label} must be 1-255 characters.");
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Karband\Authorization;

use InvalidArgumentException;

/** Identity resolved from trusted host authentication. Never hydrate from request input. */
final readonly class ActorContext
{
    public function __construct(
        public string $id,
        public string $role,
        public ?string $organizationId = null,
    ) {
        foreach (['id' => $id, 'role' => $role] as $label => $value) {
            if (trim($value) === '' || strlen($value) > 255) {
                throw new InvalidArgumentException("Authorization actor {$label} must be 1-255 characters.");
            }
        }
        if ($organizationId !== null && (trim($organizationId) === '' || strlen($organizationId) > 255)) {
            throw new InvalidArgumentException('Authorization actor organizationId must be 1-255 characters when present.');
        }
    }
}

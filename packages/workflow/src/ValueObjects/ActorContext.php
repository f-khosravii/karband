<?php

declare(strict_types=1);

namespace Intl\Workflow\ValueObjects;

use Intl\Workflow\Exceptions\InvalidWorkflowDefinition;
use Intl\Workflow\Support\Identifier;

/** Identity facts supplied by the application AFTER authentication. */
final readonly class ActorContext
{
    public function __construct(
        public string $actorId,
        public string $role,
        public ?string $organizationId = null,
    ) {
        self::assertExternalId($this->actorId, 'Actor id');
        Identifier::assert($this->role, 'Actor role');
        if ($this->organizationId !== null) {
            self::assertExternalId($this->organizationId, 'Organization id');
        }
    }

    private static function assertExternalId(string $value, string $field): void
    {
        if (trim($value) === '' || strlen($value) > 255) {
            throw new InvalidWorkflowDefinition($field . ' must be non-empty and at most 255 characters.');
        }
    }
}

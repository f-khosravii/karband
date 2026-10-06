<?php

declare(strict_types=1);

namespace Intl\Workflow\Support;

use Intl\Workflow\Exceptions\InvalidWorkflowDefinition;

/** Internal validation for stable, language-independent identifiers. */
final class Identifier
{
    public static function assert(string $value, string $field): void
    {
        if (strlen($value) > 120 || preg_match('/\A[a-z][a-z0-9_.-]*\z/D', $value) !== 1) {
            throw new InvalidWorkflowDefinition(
                sprintf('%s must be a lowercase identifier (a-z, 0-9, _, ., -), maximum 120 characters.', $field)
            );
        }
    }

    private function __construct()
    {
    }
}

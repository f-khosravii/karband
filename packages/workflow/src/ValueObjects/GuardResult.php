<?php

declare(strict_types=1);

namespace Intl\Workflow\ValueObjects;

use Intl\Workflow\Support\Identifier;

final readonly class GuardResult
{
    private function __construct(
        public bool $passed,
        public ?string $reasonCode,
    ) {
    }

    public static function allow(): self
    {
        return new self(true, null);
    }

    public static function deny(string $reasonCode): self
    {
        Identifier::assert($reasonCode, 'Guard reason code');
        return new self(false, $reasonCode);
    }
}

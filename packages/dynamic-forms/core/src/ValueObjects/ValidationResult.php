<?php

declare(strict_types=1);

namespace Karband\DynamicForms\ValueObjects;

final readonly class ValidationResult
{
    /** @param array<string,mixed> $answers @param array<string,string> $errors */
    public function __construct(public array $answers, public array $errors)
    {
    }

    public function valid(): bool
    {
        return $this->errors === [];
    }
}

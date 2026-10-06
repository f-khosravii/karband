<?php

declare(strict_types=1);

namespace Intl\DynamicForms\Contracts;

/** Return null on success or a safe, human-readable validation error. */
interface CustomRule
{
    /** @param array<string,mixed> $validatedAnswers */
    public function validate(mixed $value, array $validatedAnswers): ?string;
}

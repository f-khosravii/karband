<?php

declare(strict_types=1);

namespace Intl\DynamicForms\Engine;

use Intl\DynamicForms\Contracts\CustomRule;
use Intl\DynamicForms\Contracts\RuleRegistry;
use Intl\DynamicForms\Exceptions\InvalidForm;

final readonly class MapRuleRegistry implements RuleRegistry
{
    /** @param array<string,CustomRule> $rules */
    public function __construct(private array $rules)
    {
        foreach ($rules as $name => $rule) {
            if (!is_string($name) || !preg_match('/\A[a-z][a-z0-9_.-]*\z/D', $name) || !$rule instanceof CustomRule) {
                throw new InvalidForm('Custom rule registry contains an invalid entry.');
            }
        }
    }

    public function resolve(string $name): CustomRule
    {
        return $this->rules[$name] ?? throw new InvalidForm("Custom validation rule '{$name}' is not registered.");
    }
}

<?php

declare(strict_types=1);

namespace Intl\DynamicForms\Engine;

use Intl\DynamicForms\Contracts\CustomRule;
use Intl\DynamicForms\Contracts\RuleRegistry;
use Intl\DynamicForms\Exceptions\InvalidForm;

final class EmptyRuleRegistry implements RuleRegistry
{
    public function resolve(string $name): CustomRule
    {
        throw new InvalidForm("Custom validation rule '{$name}' is not registered.");
    }
}

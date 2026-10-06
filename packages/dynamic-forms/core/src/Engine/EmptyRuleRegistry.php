<?php

declare(strict_types=1);

namespace Karband\DynamicForms\Engine;

use Karband\DynamicForms\Contracts\CustomRule;
use Karband\DynamicForms\Contracts\RuleRegistry;
use Karband\DynamicForms\Exceptions\InvalidForm;

final class EmptyRuleRegistry implements RuleRegistry
{
    public function resolve(string $name): CustomRule
    {
        throw new InvalidForm("Custom validation rule '{$name}' is not registered.");
    }
}

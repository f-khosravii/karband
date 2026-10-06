<?php

declare(strict_types=1);

namespace Intl\DynamicForms\Contracts;

/** Only application-registered rules can execute; never execute expressions from definitions. */
interface RuleRegistry
{
    public function resolve(string $name): CustomRule;
}

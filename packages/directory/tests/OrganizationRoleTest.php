<?php

declare(strict_types=1);

namespace Intl\Directory\Tests;

use Intl\Directory\Enums\OrganizationRole;
use PHPUnit\Framework\TestCase;

final class OrganizationRoleTest extends TestCase
{
    public function test_exact_role_cases(): void
    {
        $cases = array_map(fn(OrganizationRole $r) => $r->value, OrganizationRole::cases());
        sort($cases);

        $this->assertSame(['agency', 'operator', 'provider'], $cases);
    }
}

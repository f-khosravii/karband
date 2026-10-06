<?php

declare(strict_types=1);

namespace Intl\Directory\Tests;

use Intl\Directory\Enums\DirectoryStatus;
use Intl\Directory\Enums\OrganizationRole;
use Intl\Directory\Exceptions\DirectoryValidationException;
use Intl\Directory\ValueObjects\Organization;
use PHPUnit\Framework\TestCase;

final class OrganizationTest extends TestCase
{
    public function test_valid_organization_instantiation(): void
    {
        $org = new Organization(
            id: 'org-123',
            name: 'Ministry of Health',
            status: DirectoryStatus::Active,
            roles: [OrganizationRole::Provider, OrganizationRole::Operator],
            email: 'contact@health.gov',
            phone: '+982112345678'
        );

        $this->assertSame('org-123', $org->id);
        $this->assertSame('Ministry of Health', $org->name);
        $this->assertTrue($org->isActive());
        $this->assertTrue($org->hasRole(OrganizationRole::Provider));
        $this->assertTrue($org->hasRole(OrganizationRole::Operator));
        $this->assertFalse($org->hasRole(OrganizationRole::Agency));
        $this->assertSame('contact@health.gov', $org->email);
        $this->assertSame('+982112345678', $org->phone);
    }

    public function test_inactive_organization(): void
    {
        $org = new Organization(
            id: 'org-inactive',
            name: 'Deprecated Agency',
            status: DirectoryStatus::Inactive,
            roles: [OrganizationRole::Agency]
        );

        $this->assertFalse($org->isActive());
        $this->assertTrue($org->hasRole(OrganizationRole::Agency));
    }

    public function test_empty_id_throws_validation_exception(): void
    {
        $this->expectException(DirectoryValidationException::class);
        new Organization(
            id: '   ',
            name: 'Valid Name',
            status: DirectoryStatus::Active
        );
    }

    public function test_empty_name_throws_validation_exception(): void
    {
        $this->expectException(DirectoryValidationException::class);
        new Organization(
            id: 'org-1',
            name: '',
            status: DirectoryStatus::Active
        );
    }
}

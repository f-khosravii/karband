<?php

declare(strict_types=1);

namespace Karband\Directory\Tests;

use Karband\Directory\Enums\DirectoryStatus;
use Karband\Directory\Exceptions\DirectoryValidationException;
use Karband\Directory\ValueObjects\Organization;
use PHPUnit\Framework\TestCase;

final class OrganizationTest extends TestCase
{
    public function test_valid_organization_instantiation(): void
    {
        $org = new Organization(
            id: 'org-123',
            name: 'Ministry of Health',
            status: DirectoryStatus::Active,
            email: 'contact@health.gov',
            phone: '+982112345678'
        );

        $this->assertSame('org-123', $org->id);
        $this->assertSame('Ministry of Health', $org->name);
        $this->assertTrue($org->isActive());
        $this->assertSame('contact@health.gov', $org->email);
        $this->assertSame('+982112345678', $org->phone);
    }

    public function test_inactive_organization(): void
    {
        $org = new Organization(
            id: 'org-inactive',
            name: 'Deprecated Agency',
            status: DirectoryStatus::Inactive
        );

        $this->assertFalse($org->isActive());
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

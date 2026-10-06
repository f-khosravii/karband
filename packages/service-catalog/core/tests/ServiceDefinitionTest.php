<?php

declare(strict_types=1);

namespace Karband\ServiceCatalog\Tests;

use Karband\ServiceCatalog\Definitions\ServiceDefinition;
use Karband\ServiceCatalog\Definitions\ServiceDraft;
use Karband\ServiceCatalog\Definitions\ServiceStatus;
use Karband\ServiceCatalog\Definitions\WorkflowBinding;
use Karband\ServiceCatalog\Exceptions\InvalidService;
use PHPUnit\Framework\TestCase;

final class ServiceDefinitionTest extends TestCase
{
    private function draft(?WorkflowBinding $binding = null): ServiceDraft
    {
        return new ServiceDraft('birth.certificate', 'Birth Certificate', 'Issue a certificate',
            ['locale' => 'fa', 'visible' => true], $binding);
    }

    public function test_new_service_starts_inactive_without_independent_version(): void
    {
        $service = ServiceDefinition::fromDraft($this->draft());
        self::assertSame(ServiceStatus::Inactive, $service->status);
        self::assertSame(0, $service->revision);
        self::assertNull($service->workflow);
    }

    public function test_activation_requires_workflow(): void
    {
        $this->expectException(InvalidService::class);
        ServiceDefinition::fromDraft($this->draft())->transition(ServiceStatus::Active);
    }

    public function test_activation_pins_workflow_and_increments_revision(): void
    {
        $service = ServiceDefinition::fromDraft($this->draft(new WorkflowBinding('ncr.certificate', 3)));
        $active = $service->transition(ServiceStatus::Active);
        self::assertSame(3, $active->workflow->version);
        self::assertSame(1, $active->revision);
        self::assertSame(ServiceStatus::Inactive, $service->status);
    }

    public function test_name_and_settings_can_change_without_mutating_original(): void
    {
        $original = ServiceDefinition::fromDraft($this->draft());
        $edited = $original->replace(new ServiceDraft('birth.certificate', 'New name', null, ['a' => 1]));
        self::assertSame('New name', $edited->name);
        self::assertSame(1, $edited->revision);
        self::assertSame('Birth Certificate', $original->name);
    }

    public function test_code_cannot_be_renamed(): void
    {
        $this->expectException(InvalidService::class);
        ServiceDefinition::fromDraft($this->draft())->replace(new ServiceDraft('other', 'Other'));
    }

    public function test_archived_service_cannot_be_edited_or_reactivated(): void
    {
        $archived = ServiceDefinition::fromDraft($this->draft())->transition(ServiceStatus::Archived);
        $this->expectException(InvalidService::class);
        $archived->replace($this->draft());
    }

    public function test_archived_service_cannot_be_reactivated(): void
    {
        $this->expectException(InvalidService::class);
        ServiceDefinition::fromDraft($this->draft())->transition(ServiceStatus::Archived)->transition(ServiceStatus::Active);
    }

    public function test_active_must_first_be_deactivated_before_archive(): void
    {
        $active = ServiceDefinition::fromDraft($this->draft(new WorkflowBinding('ncr.sample', 1)))
            ->transition(ServiceStatus::Active);
        $this->expectException(InvalidService::class);
        $active->transition(ServiceStatus::Archived);
    }

    public function test_cannot_unbind_active_workflow(): void
    {
        $active = ServiceDefinition::fromDraft($this->draft(new WorkflowBinding('ncr.sample', 1)))
            ->transition(ServiceStatus::Active);
        $this->expectException(InvalidService::class);
        $active->replace($this->draft());
    }

    public function test_invalid_codes_are_rejected(): void
    {
        $this->expectException(InvalidService::class);
        new ServiceDraft('Other Service', 'Test');
    }

    public function test_settings_must_be_a_json_object(): void
    {
        $this->expectException(InvalidService::class);
        new ServiceDraft('sample', 'Test', settings: [1, 2]);
    }

    public function test_settings_reject_objects(): void
    {
        $this->expectException(InvalidService::class);
        new ServiceDraft('sample', 'Test', settings: ['callback' => new \stdClass()]);
    }

    public function test_settings_reject_excessive_data(): void
    {
        $this->expectException(InvalidService::class);
        new ServiceDraft('sample', 'Test', settings: ['a' => str_repeat('x', 16385)]);
    }

    public function test_unicode_names_use_character_count_not_byte_length(): void
    {
        $service = ServiceDefinition::fromDraft(new ServiceDraft('sample', str_repeat('نام', 60)));
        self::assertSame(str_repeat('نام', 60), $service->name);
    }

    public function test_workflow_version_is_positive(): void
    {
        $this->expectException(InvalidService::class);
        new WorkflowBinding('ncr.sample', 0);
    }
}

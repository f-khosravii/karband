<?php

declare(strict_types=1);

namespace Intl\RequestJourney\Tests;

use Intl\RequestJourney\Definitions\JourneyDefinition;
use Intl\RequestJourney\Definitions\NavigationMode;
use Intl\RequestJourney\Definitions\StepDefinition;
use Intl\RequestJourney\Definitions\StepType;
use Intl\RequestJourney\Exceptions\InvalidJourneyDefinition;
use PHPUnit\Framework\TestCase;

final class JourneyDefinitionTest extends TestCase
{
    public function test_valid_journey_definition_creation_and_serialization(): void
    {
        $step1 = new StepDefinition('applicant', StepType::FORM, 'Applicant Info', 'personal');
        $step2 = new StepDefinition('family', StepType::FORM, 'Family Details', 'family', ['applicant']);
        $step3 = new StepDefinition('docs', StepType::DOCUMENTS, 'Documents', null, ['family']);
        $step4 = new StepDefinition('review', StepType::REVIEW, 'Review & Submit', null, ['docs']);

        $def = new JourneyDefinition('ncr', 1, NavigationMode::FREE, [$step1, $step2, $step3, $step4]);

        self::assertSame('ncr', $def->serviceCode);
        self::assertSame(1, $def->version);
        self::assertSame(NavigationMode::FREE, $def->navigationMode);
        self::assertCount(4, $def->steps);
        self::assertSame($step1, $def->step('applicant'));
        self::assertSame($step2, $def->step('family'));
        self::assertNull($def->step('non_existent'));

        $array = $def->toArray();
        $restored = JourneyDefinition::fromArray($array);

        self::assertSame($def->serviceCode, $restored->serviceCode);
        self::assertSame($def->version, $restored->version);
        self::assertSame($def->navigationMode, $restored->navigationMode);
        self::assertCount(4, $restored->steps);
        self::assertSame('personal', $restored->step('applicant')?->reference);
        self::assertSame(['applicant'], $restored->step('family')?->prerequisites);
    }

    public function test_duplicate_step_key_rejected(): void
    {
        $this->expectException(InvalidJourneyDefinition::class);
        $this->expectExceptionMessage("Duplicate step key: 'step_a'.");

        new JourneyDefinition('srv', 1, NavigationMode::LINEAR, [
            new StepDefinition('step_a', StepType::FORM, 'Step A'),
            new StepDefinition('step_a', StepType::DOCUMENTS, 'Step A Duplicate'),
        ]);
    }

    public function test_unknown_step_type_rejected(): void
    {
        $this->expectException(InvalidJourneyDefinition::class);
        $this->expectExceptionMessage("Unknown step type: 'unknown_type'.");

        new StepDefinition('step1', 'unknown_type', 'Step 1');
    }

    public function test_invalid_navigation_mode_rejected(): void
    {
        $this->expectException(InvalidJourneyDefinition::class);
        $this->expectExceptionMessage("Invalid navigation mode: 'circular'.");

        new JourneyDefinition('srv', 1, 'circular', [
            new StepDefinition('step1', StepType::FORM, 'Step 1'),
        ]);
    }

    public function test_missing_prerequisite_rejected(): void
    {
        $this->expectException(InvalidJourneyDefinition::class);
        $this->expectExceptionMessage("references unknown prerequisite 'missing_step'");

        new JourneyDefinition('srv', 1, NavigationMode::LINEAR, [
            new StepDefinition('step1', StepType::FORM, 'Step 1', null, ['missing_step']),
        ]);
    }

    public function test_self_dependency_rejected(): void
    {
        $this->expectException(InvalidJourneyDefinition::class);
        $this->expectExceptionMessage("cannot depend on itself");

        new JourneyDefinition('srv', 1, NavigationMode::LINEAR, [
            new StepDefinition('step1', StepType::FORM, 'Step 1', null, ['step1']),
        ]);
    }

    public function test_dependency_cycle_rejected(): void
    {
        $this->expectException(InvalidJourneyDefinition::class);
        $this->expectExceptionMessage('Cyclic prerequisite dependency detected');

        new JourneyDefinition('srv', 1, NavigationMode::LINEAR, [
            new StepDefinition('step_a', StepType::FORM, 'Step A', null, ['step_c']),
            new StepDefinition('step_b', StepType::FORM, 'Step B', null, ['step_a']),
            new StepDefinition('step_c', StepType::FORM, 'Step C', null, ['step_b']),
        ]);
    }

    public function test_empty_steps_rejected(): void
    {
        $this->expectException(InvalidJourneyDefinition::class);
        $this->expectExceptionMessage('Journey definition must have at least one step.');

        new JourneyDefinition('srv', 1, NavigationMode::LINEAR, []);
    }

    public function test_invalid_version_rejected(): void
    {
        $this->expectException(InvalidJourneyDefinition::class);
        $this->expectExceptionMessage('Journey version must be >= 1.');

        new JourneyDefinition('srv', 0, NavigationMode::LINEAR, [
            new StepDefinition('step1', StepType::FORM, 'Step 1'),
        ]);
    }
}

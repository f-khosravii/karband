<?php

declare(strict_types=1);

namespace Intl\RequestJourney\Tests;

use Intl\RequestJourney\Definitions\JourneyDefinition;
use Intl\RequestJourney\Definitions\NavigationMode;
use Intl\RequestJourney\Definitions\StepDefinition;
use Intl\RequestJourney\Definitions\StepState;
use Intl\RequestJourney\Definitions\StepType;
use Intl\RequestJourney\Engine\JourneyEngine;
use Intl\RequestJourney\ValueObjects\JourneyFacts;
use PHPUnit\Framework\TestCase;

final class JourneyEngineTest extends TestCase
{
    private JourneyEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new JourneyEngine();
    }

    public function test_linear_progression_with_locked_and_available_steps(): void
    {
        $def = new JourneyDefinition('ins', 1, NavigationMode::LINEAR, [
            new StepDefinition('form_step', StepType::FORM, 'Form Details'),
            new StepDefinition('doc_step', StepType::DOCUMENTS, 'Documents'),
            new StepDefinition('payment_step', StepType::PAYMENT, 'Payment'),
        ]);

        // 1. Initial state: Form is incomplete
        $facts1 = new JourneyFacts(formCompleted: false);
        $prog1 = $this->engine->evaluate($def, $facts1);

        self::assertSame(StepState::CURRENT, $prog1->step('form_step')?->state);
        self::assertSame(StepState::LOCKED, $prog1->step('doc_step')?->state);
        self::assertSame(StepState::LOCKED, $prog1->step('payment_step')?->state);
        self::assertSame('form_step', $prog1->currentStepKey);
        self::assertSame(0.0, $prog1->percentageComplete);

        // 2. Form completed: Documents becomes available / current, Payment locked
        $facts2 = new JourneyFacts(formCompleted: true, documentsCompleted: false);
        $prog2 = $this->engine->evaluate($def, $facts2);

        self::assertSame(StepState::COMPLETED, $prog2->step('form_step')?->state);
        self::assertSame(StepState::CURRENT, $prog2->step('doc_step')?->state);
        self::assertSame(StepState::LOCKED, $prog2->step('payment_step')?->state);
        self::assertSame('doc_step', $prog2->currentStepKey);
        self::assertSame(33.33, $prog2->percentageComplete);

        // 3. Documents completed: Payment becomes available / current
        $facts3 = new JourneyFacts(formCompleted: true, documentsCompleted: true, paymentConfirmed: false);
        $prog3 = $this->engine->evaluate($def, $facts3);

        self::assertSame(StepState::COMPLETED, $prog3->step('form_step')?->state);
        self::assertSame(StepState::COMPLETED, $prog3->step('doc_step')?->state);
        self::assertSame(StepState::CURRENT, $prog3->step('payment_step')?->state);
        self::assertSame('payment_step', $prog3->currentStepKey);
        self::assertSame(66.67, $prog3->percentageComplete);

        // 4. All completed
        $facts4 = new JourneyFacts(formCompleted: true, documentsCompleted: true, paymentConfirmed: true);
        $prog4 = $this->engine->evaluate($def, $facts4);

        self::assertSame(StepState::COMPLETED, $prog4->step('form_step')?->state);
        self::assertSame(StepState::COMPLETED, $prog4->step('doc_step')?->state);
        self::assertSame(StepState::COMPLETED, $prog4->step('payment_step')?->state);
        self::assertSame('payment_step', $prog4->currentStepKey);
        self::assertSame(100.0, $prog4->percentageComplete);
    }

    public function test_free_navigation_mode(): void
    {
        $def = new JourneyDefinition('ncr', 1, NavigationMode::FREE, [
            new StepDefinition('personal', StepType::FORM, 'Personal', 'sec_personal'),
            new StepDefinition('family', StepType::FORM, 'Family', 'sec_family'),
            new StepDefinition('docs', StepType::DOCUMENTS, 'Docs'),
            new StepDefinition('review', StepType::REVIEW, 'Review', null, ['personal', 'family', 'docs']),
        ]);

        $facts = new JourneyFacts(
            completedFormSections: ['sec_personal' => true, 'sec_family' => false],
            documentsCompleted: false,
        );

        $prog = $this->engine->evaluate($def, $facts);

        self::assertSame(StepState::COMPLETED, $prog->step('personal')?->state);
        self::assertSame(StepState::CURRENT, $prog->step('family')?->state);
        self::assertSame(StepState::AVAILABLE, $prog->step('docs')?->state);
        self::assertSame(StepState::LOCKED, $prog->step('review')?->state); // review has incomplete prerequisites
        self::assertSame('family', $prog->currentStepKey);
    }

    public function test_skipped_step_does_not_block_linear_progression(): void
    {
        $def = new JourneyDefinition('service_no_charge', 1, NavigationMode::LINEAR, [
            new StepDefinition('form_step', StepType::FORM, 'Form'),
            new StepDefinition('payment_step', StepType::PAYMENT, 'Payment', skipWhenPricingModes: ['no_charge']),
            new StepDefinition('final_step', StepType::CUSTOM, 'Final Confirmation', customFactKey: 'final_done'),
        ]);

        $facts = new JourneyFacts(
            pricingMode: 'no_charge',
            formCompleted: true,
            customFacts: ['final_done' => false],
        );

        $prog = $this->engine->evaluate($def, $facts);

        self::assertSame(StepState::COMPLETED, $prog->step('form_step')?->state);
        self::assertSame(StepState::SKIPPED, $prog->step('payment_step')?->state);
        self::assertSame(StepState::CURRENT, $prog->step('final_step')?->state);
        self::assertSame('final_step', $prog->currentStepKey);
        self::assertSame(50.0, $prog->percentageComplete); // 1 completed out of 2 non-skipped
    }

    public function test_workflow_state_availability_constraints(): void
    {
        $def = new JourneyDefinition('quote_service', 1, NavigationMode::FREE, [
            new StepDefinition('setup', StepType::FORM, 'Setup'),
            new StepDefinition('quote', StepType::QUOTE, 'Quote Acceptance', availableWhenWorkflowStates: ['quoted', 'underwriting']),
        ]);

        // 1. Workflow in draft state -> quote is locked due to state constraint
        $factsDraft = new JourneyFacts(workflowState: 'draft', formCompleted: true, quoteAccepted: false);
        $progDraft = $this->engine->evaluate($def, $factsDraft);
        self::assertSame(StepState::LOCKED, $progDraft->step('quote')?->state);

        // 2. Workflow in quoted state -> quote becomes available / current
        $factsQuoted = new JourneyFacts(workflowState: 'quoted', formCompleted: true, quoteAccepted: false);
        $progQuoted = $this->engine->evaluate($def, $factsQuoted);
        self::assertSame(StepState::CURRENT, $progQuoted->step('quote')?->state);

        // 3. Quote accepted -> quote step completed
        $factsAccepted = new JourneyFacts(workflowState: 'quoted', formCompleted: true, quoteAccepted: true);
        $progAccepted = $this->engine->evaluate($def, $factsAccepted);
        self::assertSame(StepState::COMPLETED, $progAccepted->step('quote')?->state);
    }

    public function test_signature_and_custom_fact_completion(): void
    {
        $def = new JourneyDefinition('signing_srv', 1, NavigationMode::LINEAR, [
            new StepDefinition('sig_step', StepType::SIGNATURE, 'Sign Document', customFactKey: 'has_signed_file'),
            new StepDefinition('cust_step', StepType::CUSTOM, 'Admin Approval', customFactKey: 'admin_approved'),
        ]);

        $facts = new JourneyFacts(customFacts: [
            'has_signed_file' => true,
            'admin_approved' => false,
        ]);

        $prog = $this->engine->evaluate($def, $facts);

        self::assertSame(StepState::COMPLETED, $prog->step('sig_step')?->state);
        self::assertSame(StepState::CURRENT, $prog->step('cust_step')?->state);
    }

    public function test_last_visited_step_handling(): void
    {
        $def = new JourneyDefinition('ncr', 1, NavigationMode::FREE, [
            new StepDefinition('step1', StepType::FORM, 'Step 1'),
            new StepDefinition('step2', StepType::FORM, 'Step 2'),
            new StepDefinition('step3', StepType::FORM, 'Step 3', prerequisites: ['step1']),
        ]);

        // 1. User previously visited step2 (which is available) -> step2 is designated current
        $facts = new JourneyFacts();
        $prog1 = $this->engine->evaluate($def, $facts, 'step2');
        self::assertSame('step2', $prog1->currentStepKey);
        self::assertSame(StepState::AVAILABLE, $prog1->step('step1')?->state);
        self::assertSame(StepState::CURRENT, $prog1->step('step2')?->state);

        // 2. User provides last visited step3 which is locked -> ignored, defaults to first available (step1)
        $prog2 = $this->engine->evaluate($def, $facts, 'step3');
        self::assertSame('step1', $prog2->currentStepKey);
        self::assertSame(StepState::CURRENT, $prog2->step('step1')?->state);
        self::assertSame(StepState::LOCKED, $prog2->step('step3')?->state);

        // 3. User provides unknown last visited key -> ignored
        $prog3 = $this->engine->evaluate($def, $facts, 'non_existent_key');
        self::assertSame('step1', $prog3->currentStepKey);
    }

    public function test_last_visited_does_not_imply_completion(): void
    {
        $def = new JourneyDefinition('srv', 1, NavigationMode::FREE, [
            new StepDefinition('payment', StepType::PAYMENT, 'Payment'),
        ]);

        $facts = new JourneyFacts(paymentConfirmed: false);
        $prog = $this->engine->evaluate($def, $facts, 'payment');

        self::assertSame('payment', $prog->currentStepKey);
        self::assertSame(StepState::CURRENT, $prog->step('payment')?->state);
        self::assertFalse($prog->step('payment')?->isCompleted());
    }

    public function test_form_section_and_full_form_facts(): void
    {
        $def = new JourneyDefinition('srv', 1, NavigationMode::LINEAR, [
            new StepDefinition('sec_a', StepType::FORM, 'Section A', 'section_a'),
            new StepDefinition('all_form', StepType::FORM, 'Full Form'),
        ]);

        $facts = new JourneyFacts(
            formCompleted: false,
            completedFormSections: ['section_a' => true],
        );

        $prog = $this->engine->evaluate($def, $facts);
        self::assertSame(StepState::COMPLETED, $prog->step('sec_a')?->state);
        self::assertSame(StepState::CURRENT, $prog->step('all_form')?->state);
    }

    public function test_review_step_completion_with_prerequisites(): void
    {
        $def = new JourneyDefinition('srv', 1, NavigationMode::LINEAR, [
            new StepDefinition('step1', StepType::FORM, 'Step 1'),
            new StepDefinition('review', StepType::REVIEW, 'Review Step', prerequisites: ['step1']),
        ]);

        // When step1 is incomplete, review is locked
        $facts1 = new JourneyFacts(formCompleted: false);
        $prog1 = $this->engine->evaluate($def, $facts1);
        self::assertSame(StepState::LOCKED, $prog1->step('review')?->state);

        // When step1 is completed, review becomes completed (or available if reviewConfirmed is explicit)
        $facts2 = new JourneyFacts(formCompleted: true);
        $prog2 = $this->engine->evaluate($def, $facts2);
        self::assertSame(StepState::COMPLETED, $prog2->step('step1')?->state);
        self::assertSame(StepState::COMPLETED, $prog2->step('review')?->state);
    }

    public function test_all_steps_completed_current_step(): void
    {
        $def = new JourneyDefinition('srv', 1, NavigationMode::LINEAR, [
            new StepDefinition('step1', StepType::FORM, 'Step 1'),
            new StepDefinition('step2', StepType::DOCUMENTS, 'Step 2'),
        ]);

        $facts = new JourneyFacts(formCompleted: true, documentsCompleted: true);
        $prog = $this->engine->evaluate($def, $facts);

        self::assertSame('step2', $prog->currentStepKey);
        self::assertSame(100.0, $prog->percentageComplete);
    }

    public function test_completed_step_intentional_revisit_preserves_facts_and_sets_current_step(): void
    {
        $def = new JourneyDefinition('srv', 1, NavigationMode::FREE, [
            new StepDefinition('step_a', StepType::FORM, 'Step A Form', 'section_a'),
            new StepDefinition('step_b', StepType::DOCUMENTS, 'Step B Docs'),
        ]);

        // Scenario: Step A is completed, Step B is available (incomplete)
        $facts = new JourneyFacts(
            completedFormSections: ['section_a' => true],
            documentsCompleted: false,
        );

        // 1. Without visit (or on step_b): currentStepKey is step_b (state CURRENT), step_a is COMPLETED
        $progDefault = $this->engine->evaluate($def, $facts);
        self::assertSame('step_b', $progDefault->currentStepKey);
        self::assertSame(StepState::COMPLETED, $progDefault->step('step_a')?->state);
        self::assertSame(StepState::CURRENT, $progDefault->step('step_b')?->state);

        // 2. User intentionally visits completed step_a (e.g. To review their answers)
        $progVisited = $this->engine->evaluate($def, $facts, lastVisitedStepKey: 'step_a');

        // Verify: currentStepKey is step_a
        self::assertSame('step_a', $progVisited->currentStepKey);
        // Verify: step_a remains COMPLETED (not falsely marked incomplete or overwritten)
        self::assertSame(StepState::COMPLETED, $progVisited->step('step_a')?->state);
        // Verify: step_b remains AVAILABLE
        self::assertSame(StepState::AVAILABLE, $progVisited->step('step_b')?->state);
        // Verify: completion percentage is untouched
        self::assertSame(50.0, $progVisited->percentageComplete);
    }
}

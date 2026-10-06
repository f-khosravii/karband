<?php

declare(strict_types=1);

namespace Intl\Workflow\Tests;

use Intl\Workflow\Contracts\TransitionGuard;
use Intl\Workflow\Definitions\Transition;
use Intl\Workflow\Definitions\WorkflowDefinition;
use Intl\Workflow\Engine\StateMachine;
use Intl\Workflow\Exceptions\InvalidWorkflowDefinition;
use Intl\Workflow\ValueObjects\ActorContext;
use Intl\Workflow\ValueObjects\GuardResult;
use Intl\Workflow\ValueObjects\TransitionContext;
use PHPUnit\Framework\TestCase;

final class StateMachineTest extends TestCase
{
    /** @param list<string> $guards */
    private static function definition(array $guards = []): WorkflowDefinition
    {
        return new WorkflowDefinition(
            'example.service',
            1,
            'draft',
            ['draft', 'submitted', 'correction_required', 'approved'],
            [
                new Transition('submit', 'draft', 'submitted', ['agency'], false, $guards),
                new Transition('require_correction', 'submitted', 'correction_required', ['government_admin'], true),
                new Transition('approve', 'submitted', 'approved', ['government_admin', 'platform_admin']),
            ],
        );
    }

    private static function context(
        string $state = 'draft',
        string $action = 'submit',
        string $role = 'agency',
        ?string $comment = null,
    ): TransitionContext {
        return new TransitionContext($state, $action, new ActorContext('user-1', $role, 'org-1'), $comment);
    }

    public function test_agency_can_submit(): void
    {
        $result = (new StateMachine())->evaluate(self::definition(), self::context());
        self::assertTrue($result->allowed);
        self::assertSame('submitted', $result->nextState);
        self::assertNull($result->reasonCode);
    }

    public function test_government_admin_can_request_correction_with_comment(): void
    {
        $result = (new StateMachine())->evaluate(
            self::definition(), self::context('submitted', 'require_correction', 'government_admin', 'Missing document')
        );
        self::assertTrue($result->allowed);
        self::assertSame('correction_required', $result->nextState);
    }

    public function test_platform_admin_cannot_request_correction_when_not_explicitly_allowed(): void
    {
        $result = (new StateMachine())->evaluate(
            self::definition(), self::context('submitted', 'require_correction', 'platform_admin', 'Review')
        );
        self::assertFalse($result->allowed);
        self::assertSame('actor_not_allowed', $result->reasonCode);
    }

    public function test_platform_admin_can_use_explicitly_permitted_action(): void
    {
        $result = (new StateMachine())->evaluate(
            self::definition(), self::context('submitted', 'approve', 'platform_admin')
        );
        self::assertTrue($result->allowed);
        self::assertSame('approved', $result->nextState);
    }

    public function test_agency_cannot_execute_government_action(): void
    {
        $result = (new StateMachine())->evaluate(
            self::definition(), self::context('submitted', 'approve', 'agency')
        );
        self::assertFalse($result->allowed);
        self::assertSame('actor_not_allowed', $result->reasonCode);
        self::assertNull($result->nextState);
    }

    public function test_comment_is_required_and_whitespace_does_not_count(): void
    {
        $result = (new StateMachine())->evaluate(
            self::definition(), self::context('submitted', 'require_correction', 'government_admin', " \n\t ")
        );
        self::assertFalse($result->allowed);
        self::assertSame('comment_required', $result->reasonCode);
    }

    public function test_unknown_state_is_not_accepted(): void
    {
        $result = (new StateMachine())->evaluate(self::definition(), self::context('unknown'));
        self::assertFalse($result->allowed);
        self::assertSame('unknown_state', $result->reasonCode);
    }

    public function test_undefined_action_is_not_accepted(): void
    {
        $result = (new StateMachine())->evaluate(self::definition(), self::context('draft', 'approve'));
        self::assertFalse($result->allowed);
        self::assertSame('transition_not_found', $result->reasonCode);
    }

    public function test_guard_not_registered_fails_closed(): void
    {
        $result = (new StateMachine())->evaluate(self::definition(['ready']), self::context());
        self::assertFalse($result->allowed);
        self::assertSame('guard_not_registered', $result->reasonCode);
        self::assertSame('ready', $result->failedGuard);
    }

    public function test_guard_registration_can_be_preflight_checked(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        (new StateMachine())->assertGuardsRegistered(self::definition(['ready']));
    }

    public function test_registered_guard_may_allow_a_transition(): void
    {
        $guard = new class implements TransitionGuard {
            public function evaluate(TransitionContext $context): GuardResult
            {
                return GuardResult::allow();
            }
        };
        $machine = new StateMachine(['ready' => $guard]);
        $machine->assertGuardsRegistered(self::definition(['ready']));
        $result = $machine->evaluate(self::definition(['ready']), self::context());
        self::assertTrue($result->allowed);
    }

    public function test_rejected_guard_reports_machine_readable_reason(): void
    {
        $guard = new class implements TransitionGuard {
            public function evaluate(TransitionContext $context): GuardResult
            {
                return GuardResult::deny('documents_missing');
            }
        };
        $result = (new StateMachine(['ready' => $guard]))->evaluate(self::definition(['ready']), self::context());
        self::assertFalse($result->allowed);
        self::assertSame('guard_rejected', $result->reasonCode);
        self::assertSame('ready', $result->failedGuard);
        self::assertSame('documents_missing', $result->guardReasonCode);
    }

    public function test_guards_are_evaluated_in_defined_order_and_stop_on_failure(): void
    {
        $calls = [];
        $first = new class($calls) implements TransitionGuard {
            public function __construct(private array &$calls)
            {
            }
            public function evaluate(TransitionContext $context): GuardResult
            {
                $this->calls[] = 'first';
                return GuardResult::deny('not_ready');
            }
        };
        $second = new class($calls) implements TransitionGuard {
            public function __construct(private array &$calls)
            {
            }
            public function evaluate(TransitionContext $context): GuardResult
            {
                $this->calls[] = 'second';
                return GuardResult::allow();
            }
        };
        $result = (new StateMachine(['first' => $first, 'second' => $second]))
            ->evaluate(self::definition(['first', 'second']), self::context());
        self::assertSame(['first'], $calls);
        self::assertFalse($result->allowed);
    }

    public function test_guards_are_not_called_for_an_unauthorized_actor(): void
    {
        $guard = new class implements TransitionGuard {
            public function evaluate(TransitionContext $context): GuardResult
            {
                throw new \LogicException('Should never be called');
            }
        };
        $result = (new StateMachine(['ready' => $guard]))
            ->evaluate(self::definition(['ready']), self::context(role: 'government_admin'));
        self::assertSame('actor_not_allowed', $result->reasonCode);
    }

    public function test_invalid_guard_registration_is_rejected(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        new StateMachine(['ready' => new \stdClass()]);
    }

    public function test_guard_exception_does_not_turn_into_permission(): void
    {
        $guard = new class implements TransitionGuard {
            public function evaluate(TransitionContext $context): GuardResult
            {
                throw new \RuntimeException('External dependency failed');
            }
        };
        $this->expectException(\RuntimeException::class);
        (new StateMachine(['ready' => $guard]))->evaluate(self::definition(['ready']), self::context());
    }

    public function test_client_customer_is_not_implicitly_a_role(): void
    {
        $result = (new StateMachine())->evaluate(
            self::definition(), self::context(role: 'service_recipient')
        );
        self::assertSame('actor_not_allowed', $result->reasonCode);
    }

    public function test_invalid_fact_key_is_rejected(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        new TransitionContext('draft', 'submit', new ActorContext('agency-1', 'agency'), facts: ['request Complete' => true]);
    }

    public function test_unvalidated_blank_actor_id_is_rejected(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        new ActorContext(' ', 'agency');
    }
}

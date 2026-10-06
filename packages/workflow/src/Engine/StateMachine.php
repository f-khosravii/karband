<?php

declare(strict_types=1);

namespace Intl\Workflow\Engine;

use Intl\Workflow\Contracts\TransitionGuard;
use Intl\Workflow\Definitions\WorkflowDefinition;
use Intl\Workflow\Exceptions\InvalidWorkflowDefinition;
use Intl\Workflow\Support\Identifier;
use Intl\Workflow\ValueObjects\TransitionContext;
use Intl\Workflow\ValueObjects\TransitionResult;

/**
 * Framework-agnostic, non-mutating state machine.
 * All persistence, authorization of object ownership, and auditing live in the host app.
 */
final readonly class StateMachine
{
    /** @param array<string, TransitionGuard> $guards */
    public function __construct(private array $guards = [])
    {
        foreach ($this->guards as $key => $guard) {
            if (!is_string($key)) {
                throw new InvalidWorkflowDefinition('Guard registry keys must be strings.');
            }
            Identifier::assert($key, 'Registered guard key');
            if (!$guard instanceof TransitionGuard) {
                throw new InvalidWorkflowDefinition('Registered guard must implement TransitionGuard: ' . $key);
            }
        }
    }

    /** Optional preflight; call when publishing/activating a definition. */
    public function assertGuardsRegistered(WorkflowDefinition $definition): void
    {
        foreach ($definition->transitions as $transition) {
            foreach ($transition->guardKeys as $key) {
                if (!isset($this->guards[$key])) {
                    throw new InvalidWorkflowDefinition('Unregistered guard: ' . $key);
                }
            }
        }
    }

    /** Does not perform a database write or trigger a payment/side effect. */
    public function evaluate(WorkflowDefinition $definition, TransitionContext $context): TransitionResult
    {
        if (!$definition->containsState($context->currentState)) {
            return TransitionResult::reject('unknown_state');
        }

        $transition = $definition->findTransition($context->currentState, $context->action);
        if ($transition === null) {
            return TransitionResult::reject('transition_not_found');
        }

        if (!in_array($context->actor->role, $transition->allowedRoles, true)) {
            return TransitionResult::reject('actor_not_allowed');
        }

        if ($transition->commentRequired && trim($context->comment ?? '') === '') {
            return TransitionResult::reject('comment_required');
        }

        foreach ($transition->guardKeys as $key) {
            $guard = $this->guards[$key] ?? null;
            if ($guard === null) {
                // Fail closed: misconfiguration must never bypass a business rule.
                return TransitionResult::reject('guard_not_registered', $key);
            }
            $guardResult = $guard->evaluate($context);
            if (!$guardResult->passed) {
                return TransitionResult::reject('guard_rejected', $key, $guardResult->reasonCode);
            }
        }

        return TransitionResult::permit($transition->to);
    }
}

<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Karband\Workflow\Contracts\TransitionGuard;
use Karband\Workflow\Definitions\WorkflowDefinition;
use Karband\Workflow\Engine\StateMachine;
use Karband\Workflow\Exceptions\InvalidWorkflowDefinition;
use Karband\Workflow\ValueObjects\ActorContext;
use Karband\Workflow\ValueObjects\GuardResult;
use Karband\Workflow\ValueObjects\TransitionContext;

$assertions = 0;
function check(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new \RuntimeException('Failed: ' . $message);
    }
}

$config = [
    'id' => 'test.service',
    'version' => 1,
    'initial_state' => 'draft',
    'states' => ['draft', 'submitted', 'correction_required'],
    'transitions' => [
        ['action' => 'submit', 'from' => 'draft', 'to' => 'submitted', 'allowed_roles' => ['agency'], 'guards' => ['complete']],
        ['action' => 'require_correction', 'from' => 'submitted', 'to' => 'correction_required', 'allowed_roles' => ['government_admin'], 'comment_required' => true],
    ],
];

$definition = WorkflowDefinition::fromJson(json_encode($config, JSON_THROW_ON_ERROR));
check($definition->version === 1, 'version preserved');
check($definition->containsState('draft'), 'known state');
check(!$definition->containsState('absent'), 'unknown state');
check($definition->findTransition('draft', 'submit')?->to === 'submitted', 'transition loaded');

$actor = new ActorContext('agency-1', 'agency', 'agency-1');
$context = new TransitionContext('draft', 'submit', $actor);
$missing = (new StateMachine())->evaluate($definition, $context);
check(!$missing->allowed && $missing->reasonCode === 'guard_not_registered', 'missing guard fails closed');

$passingGuard = new class implements TransitionGuard {
    public function evaluate(TransitionContext $context): GuardResult
    {
        return GuardResult::allow();
    }
};
$machine = new StateMachine(['complete' => $passingGuard]);
$machine->assertGuardsRegistered($definition);
$ok = $machine->evaluate($definition, $context);
check($ok->allowed && $ok->nextState === 'submitted', 'allowed transition');
check($ok->reasonCode === null, 'success has no failure code');

$unauthorized = $machine->evaluate($definition, new TransitionContext('draft', 'submit', new ActorContext('gov-1', 'government_admin')));
check(!$unauthorized->allowed && $unauthorized->reasonCode === 'actor_not_allowed', 'role denied');
check($machine->evaluate($definition, new TransitionContext('missing', 'submit', $actor))->reasonCode === 'unknown_state', 'invalid state denied');
check($machine->evaluate($definition, new TransitionContext('draft', 'noop', $actor))->reasonCode === 'transition_not_found', 'unknown action denied');

$gov = new ActorContext('gov-1', 'government_admin', 'ministry-1');
$needsComment = $machine->evaluate($definition, new TransitionContext('submitted', 'require_correction', $gov, '  '));
check($needsComment->reasonCode === 'comment_required', 'blank comment denied');
$withComment = $machine->evaluate($definition, new TransitionContext('submitted', 'require_correction', $gov, 'Attach your ID'));
check($withComment->allowed && $withComment->nextState === 'correction_required', 'correction allowed with comment');

$denying = new class implements TransitionGuard {
    public function evaluate(TransitionContext $context): GuardResult
    {
        return GuardResult::deny('incomplete');
    }
};
$denied = (new StateMachine(['complete' => $denying]))->evaluate($definition, $context);
check($denied->reasonCode === 'guard_rejected', 'business guard rejected');
check($denied->guardReasonCode === 'incomplete' && $denied->failedGuard === 'complete', 'business failure detail');

$broken = $config;
$broken['transitions'][] = $config['transitions'][0];
try {
    WorkflowDefinition::fromArray($broken);
    throw new \RuntimeException('Duplicate transition wrongly accepted');
} catch (InvalidWorkflowDefinition) {
    check(true, 'duplicate transition rejected');
}

$broken = $config;
$broken['version'] = 0;
try {
    WorkflowDefinition::fromArray($broken);
    throw new \RuntimeException('Invalid version wrongly accepted');
} catch (InvalidWorkflowDefinition) {
    check(true, 'invalid version rejected');
}

$ncrExample = WorkflowDefinition::fromJson((string) file_get_contents(dirname(__DIR__) . '/examples/ncr-like-v1.json'));
check($ncrExample->id === 'ncr.request', 'illustrative definition loads');

printf("Standalone smoke test passed: %d checks.\n", $assertions);

<?php

declare(strict_types=1);

// Run from this folder after `composer require "intl/workflow:@dev"` at the root.
require dirname(__DIR__) . '/tests/bootstrap.php';

use Intl\Workflow\Definitions\WorkflowDefinition;
use Intl\Workflow\Engine\StateMachine;
use Intl\Workflow\ValueObjects\ActorContext;
use Intl\Workflow\ValueObjects\TransitionContext;

$definition = WorkflowDefinition::fromArray([
    'id' => 'example.service',
    'version' => 1,
    'initial_state' => 'draft',
    'states' => ['draft', 'submitted', 'correction_required'],
    'transitions' => [
        [
            'action' => 'submit',
            'from' => 'draft',
            'to' => 'submitted',
            'allowed_roles' => ['agency'],
        ],
        [
            'action' => 'require_correction',
            'from' => 'submitted',
            'to' => 'correction_required',
            'allowed_roles' => ['government_admin'],
            'comment_required' => true,
        ],
    ],
]);

$machine = new StateMachine();
$result = $machine->evaluate(
    $definition,
    new TransitionContext('draft', 'submit', new ActorContext('agency-1', 'agency', 'agency-1')),
);

var_export([
    'allowed' => $result->allowed,
    'next_state' => $result->nextState,
    'reason' => $result->reasonCode,
]);
echo PHP_EOL;

<?php

declare(strict_types=1);

namespace Karband\Workflow\Definitions;

use Karband\Workflow\Exceptions\InvalidWorkflowDefinition;
use Karband\Workflow\Support\Identifier;

/**
 * A single action available from one source state.
 * Allowed roles are configuration, not hardcoded domain roles.
 */
final readonly class Transition
{
    /**
     * @param list<string> $allowedRoles
     * @param list<string> $guardKeys Keys of trusted, application-registered guards.
     */
    public function __construct(
        public string $action,
        public string $from,
        public string $to,
        public array $allowedRoles,
        public bool $commentRequired = false,
        public array $guardKeys = [],
    ) {
        Identifier::assert($this->action, 'Transition action');
        Identifier::assert($this->from, 'Transition from');
        Identifier::assert($this->to, 'Transition to');

        if ($this->allowedRoles === [] || !array_is_list($this->allowedRoles)) {
            throw new InvalidWorkflowDefinition('Transition allowedRoles must be a non-empty list.');
        }

        $roles = [];
        foreach ($this->allowedRoles as $role) {
            if (!is_string($role)) {
                throw new InvalidWorkflowDefinition('Every allowed role must be a string.');
            }
            Identifier::assert($role, 'Allowed role');
            if (isset($roles[$role])) {
                throw new InvalidWorkflowDefinition('Duplicate allowed role: ' . $role);
            }
            $roles[$role] = true;
        }

        if (!array_is_list($this->guardKeys)) {
            throw new InvalidWorkflowDefinition('Transition guardKeys must be a list.');
        }

        $guards = [];
        foreach ($this->guardKeys as $key) {
            if (!is_string($key)) {
                throw new InvalidWorkflowDefinition('Every guard key must be a string.');
            }
            Identifier::assert($key, 'Guard key');
            if (isset($guards[$key])) {
                throw new InvalidWorkflowDefinition('Duplicate guard key: ' . $key);
            }
            $guards[$key] = true;
        }
    }
}

<?php

declare(strict_types=1);

namespace Intl\Workflow\ValueObjects;

use Intl\Workflow\Exceptions\InvalidWorkflowDefinition;
use Intl\Workflow\Support\Identifier;

/** Facts come from trusted application code, never raw HTTP request data. */
final readonly class TransitionContext
{
    /** @param array<string, mixed> $facts */
    public function __construct(
        public string $currentState,
        public string $action,
        public ActorContext $actor,
        public ?string $comment = null,
        public array $facts = [],
    ) {
        Identifier::assert($this->currentState, 'Current state');
        Identifier::assert($this->action, 'Requested action');
        foreach (array_keys($this->facts) as $key) {
            if (!is_string($key)) {
                throw new InvalidWorkflowDefinition('Transition facts must use string keys.');
            }
            Identifier::assert($key, 'Fact key');
        }
    }
}

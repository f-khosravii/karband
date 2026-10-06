<?php

declare(strict_types=1);

namespace Intl\Workflow\Definitions;

use Intl\Workflow\Exceptions\InvalidWorkflowDefinition;
use Intl\Workflow\Support\DefinitionLoader;
use Intl\Workflow\Support\Identifier;

/** Immutable, service-specific workflow definition with explicit version. */
final readonly class WorkflowDefinition
{
    /** @var array<string, Transition> */
    private array $transitionIndex;

    /** @var array<string, true> */
    private array $stateIndex;

    /**
     * @param list<string> $states
     * @param list<Transition> $transitions
     */
    public function __construct(
        public string $id,
        public int $version,
        public string $initialState,
        public array $states,
        public array $transitions,
    ) {
        Identifier::assert($this->id, 'Workflow id');
        Identifier::assert($this->initialState, 'Initial state');

        if ($this->version < 1) {
            throw new InvalidWorkflowDefinition('Workflow version must be a positive integer.');
        }
        if ($this->states === [] || !array_is_list($this->states)) {
            throw new InvalidWorkflowDefinition('Workflow states must be a non-empty list.');
        }
        if (!array_is_list($this->transitions)) {
            throw new InvalidWorkflowDefinition('Workflow transitions must be a list.');
        }

        $stateIndex = [];
        foreach ($this->states as $state) {
            if (!is_string($state)) {
                throw new InvalidWorkflowDefinition('Every workflow state must be a string.');
            }
            Identifier::assert($state, 'Workflow state');
            if (isset($stateIndex[$state])) {
                throw new InvalidWorkflowDefinition('Duplicate workflow state: ' . $state);
            }
            $stateIndex[$state] = true;
        }
        if (!isset($stateIndex[$this->initialState])) {
            throw new InvalidWorkflowDefinition('Initial state must be listed in states.');
        }

        $transitionIndex = [];
        foreach ($this->transitions as $transition) {
            if (!$transition instanceof Transition) {
                throw new InvalidWorkflowDefinition('Every transition must be a Transition object.');
            }
            if (!isset($stateIndex[$transition->from], $stateIndex[$transition->to])) {
                throw new InvalidWorkflowDefinition(
                    sprintf('Transition %s references an undefined state.', $transition->action)
                );
            }
            // Exactly one transition per (source state, action), independent of role.
            $key = self::transitionKey($transition->from, $transition->action);
            if (isset($transitionIndex[$key])) {
                throw new InvalidWorkflowDefinition(
                    sprintf('Ambiguous transitions for state %s and action %s.', $transition->from, $transition->action)
                );
            }
            $transitionIndex[$key] = $transition;
        }

        $this->stateIndex = $stateIndex;
        $this->transitionIndex = $transitionIndex;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return DefinitionLoader::fromArray($data);
    }

    public static function fromJson(string $json): self
    {
        return DefinitionLoader::fromJson($json);
    }

    public function containsState(string $state): bool
    {
        return isset($this->stateIndex[$state]);
    }

    public function findTransition(string $from, string $action): ?Transition
    {
        return $this->transitionIndex[self::transitionKey($from, $action)] ?? null;
    }

    private static function transitionKey(string $from, string $action): string
    {
        return $from . "\0" . $action;
    }
}

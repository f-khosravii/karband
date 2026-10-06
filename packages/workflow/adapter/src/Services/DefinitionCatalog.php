<?php

declare(strict_types=1);

namespace Karband\WorkflowLaravel\Services;

use Karband\Workflow\Definitions\WorkflowDefinition;
use Karband\Workflow\Engine\StateMachine;
use Karband\Workflow\Support\Identifier;
use Karband\WorkflowLaravel\Contracts\ActorResolver;
use Karband\WorkflowLaravel\Contracts\WorkflowAuthorizer;
use Karband\WorkflowLaravel\Exceptions\DefinitionAlreadyPublished;
use Karband\WorkflowLaravel\Exceptions\StoredDefinitionMismatch;

/** Published definitions are inserted only. Never alter an existing (id, version). */
final readonly class DefinitionCatalog
{
    public function __construct(
        private WorkflowStorage $storage,
        private StateMachine $machine,
        private ActorResolver $actors,
        private WorkflowAuthorizer $authorization,
    ) {
    }

    /** Only call from authenticated/authorized host code, never a public route alone. */
    public function publish(WorkflowDefinition $definition): int
    {
        $this->machine->assertGuardsRegistered($definition);
        $data = self::toArray($definition);
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $db = $this->storage->connection;

        return $db->transaction(function () use ($definition, $json, $db): int {
            $actor = $this->actors->resolve();
            $this->authorization->authorizePublish($definition, $actor);

            $exists = $db->table('workflow_definitions')
                ->where('workflow_id', $definition->id)
                ->where('version', $definition->version)
                ->exists();
            if ($exists) {
                throw new DefinitionAlreadyPublished('This workflow id/version is already published.');
            }

            // DB unique key also prevents concurrent duplicate publications.
            return (int) $db->table('workflow_definitions')->insertGetId([
                'workflow_id' => $definition->id,
                'version' => $definition->version,
                'definition' => $json,
                'published_at' => now(),
            ]);
        }, $this->attempts());
    }

    /** Explicit version required: an existing instance must never silently switch versions. */
    public function get(string $workflowId, int $version): WorkflowDefinition
    {
        Identifier::assert($workflowId, 'Workflow id');
        if ($version < 1) {
            throw new \InvalidArgumentException('Workflow version must be positive.');
        }
        $row = $this->storage->connection->table('workflow_definitions')
            ->where('workflow_id', $workflowId)->where('version', $version)->first();
        if ($row === null) {
            throw new \RuntimeException('Requested published workflow definition was not found.');
        }
        return $this->parse($row);
    }

    public function byRecordId(int $id): WorkflowDefinition
    {
        $row = $this->storage->connection->table('workflow_definitions')->where('id', $id)->first();
        if ($row === null) {
            throw new StoredDefinitionMismatch('A pinned workflow definition is missing.');
        }
        return $this->parse($row);
    }

    private function parse(object $row): WorkflowDefinition
    {
        $definition = WorkflowDefinition::fromJson((string) $row->definition);
        if ($definition->id !== (string) $row->workflow_id || $definition->version !== (int) $row->version) {
            throw new StoredDefinitionMismatch('Pinned workflow metadata and JSON do not match.');
        }
        // Fail closed if an old workflow refers to a guard no longer registered.
        $this->machine->assertGuardsRegistered($definition);
        return $definition;
    }

    /** @return array<string, mixed> */
    private static function toArray(WorkflowDefinition $definition): array
    {
        $transitions = [];
        foreach ($definition->transitions as $transition) {
            $transitions[] = [
                'action' => $transition->action,
                'from' => $transition->from,
                'to' => $transition->to,
                'allowed_roles' => $transition->allowedRoles,
                'comment_required' => $transition->commentRequired,
                'guards' => $transition->guardKeys,
            ];
        }
        return [
            'id' => $definition->id,
            'version' => $definition->version,
            'initial_state' => $definition->initialState,
            'states' => $definition->states,
            'transitions' => $transitions,
        ];
    }

    private function attempts(): int
    {
        $attempts = config('workflow.deadlock_attempts', 3);
        if (!is_int($attempts) || $attempts < 1 || $attempts > 5) {
            throw new \InvalidArgumentException('workflow.deadlock_attempts must be an integer from 1 to 5.');
        }
        return $attempts;
    }
}

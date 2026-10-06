<?php

declare(strict_types=1);

namespace Karband\WorkflowLaravel\Services;

use Illuminate\Support\Str;
use Karband\Workflow\Engine\StateMachine;
use Karband\Workflow\Support\Identifier;
use Karband\Workflow\ValueObjects\TransitionContext;
use Karband\WorkflowLaravel\Contracts\ActorResolver;
use Karband\WorkflowLaravel\Contracts\TrustedFactProvider;
use Karband\WorkflowLaravel\Contracts\WorkflowAuthorizer;
use Karband\WorkflowLaravel\Contracts\WorkflowActionGate;
use Karband\WorkflowLaravel\Exceptions\IdempotencyConflict;
use Karband\WorkflowLaravel\Exceptions\StaleWorkflowState;
use Karband\WorkflowLaravel\Support\Input;
use Karband\WorkflowLaravel\ValueObjects\ActionOutcome;
use Karband\WorkflowLaravel\ValueObjects\WorkflowInstance;

/**
 * Database adapter ONLY. No NCR status mapping, payments, HTTP endpoints or auth assumptions.
 * Every write is bound to a host-authorized actor and an explicitly pinned definition version.
 */
final readonly class WorkflowRuntime
{
    public function __construct(
        private WorkflowStorage $storage,
        private DefinitionCatalog $definitions,
        private StateMachine $machine,
        private ActorResolver $actors,
        private WorkflowAuthorizer $authorization,
        private TrustedFactProvider $facts,
    ) {
    }

    public function start(
        string $workflowId,
        int $version,
        string $resourceType,
        string $resourceId,
    ): WorkflowInstance {
        Input::resource($resourceType, $resourceId);
        Identifier::assert($workflowId, 'Workflow id');
        $db = $this->storage->connection;

        return $db->transaction(function () use ($workflowId, $version, $resourceType, $resourceId, $db): WorkflowInstance {
            $definition = $this->definitions->get($workflowId, $version);
            $actor = $this->actors->resolve();
            $this->authorization->authorizeStart($definition, $resourceType, $resourceId, $actor);
            $record = $db->table('workflow_definitions')
                ->where('workflow_id', $workflowId)->where('version', $version)->first();
            if ($record === null) {
                throw new \RuntimeException('Published definition disappeared while starting workflow.');
            }

            $id = (string) Str::ulid();
            $time = now();
            $db->table('workflow_instances')->insert([
                'id' => $id,
                'definition_id' => $record->id,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'current_state' => $definition->initialState,
                'revision' => 0,
                'created_at' => $time,
                'updated_at' => $time,
            ]);
            return new WorkflowInstance($id, $resourceType, $resourceId, $definition->id, $definition->version, $definition->initialState, 0);
        }, $this->attempts());
    }

    /**
     * Re-lock and re-evaluate the current state every time. Only successful actions are
     * idempotently replayed. Guard and fact code MUST be read-only and retry-safe.
     */
    public function apply(
        string $instanceId,
        string $action,
        ?string $comment = null,
        ?string $idempotencyKey = null,
    ): ActionOutcome {
        Identifier::assert($action, 'Requested action');
        Input::idempotencyKey($idempotencyKey);
        $db = $this->storage->connection;

        return $db->transaction(function () use ($instanceId, $action, $comment, $idempotencyKey, $db): ActionOutcome {
            $row = $db->table('workflow_instances')->where('id', $instanceId)->lockForUpdate()->first();
            if ($row === null) {
                throw new \RuntimeException('Workflow instance not found.');
            }
            $definition = $this->definitions->byRecordId((int) $row->definition_id);
            $instance = $this->storage->snapshot($row, $definition);
            $actor = $this->actors->resolve();
            $this->authorization->authorizeAction($instance, $action, $actor);

            if ($idempotencyKey !== null) {
                $previous = $db->table('workflow_history')
                    ->where('instance_id', $instanceId)
                    ->where('idempotency_key', $idempotencyKey)->first();
                if ($previous !== null) {
                    if (
                        $previous->action !== $action ||
                        $previous->actor_id !== $actor->actorId ||
                        $previous->actor_role !== $actor->role ||
                        $previous->actor_organization_id !== $actor->organizationId ||
                        $previous->comment !== $comment
                    ) {
                        throw new IdempotencyConflict('An idempotency key was reused for another command.');
                    }
                    // Later valid actions may have advanced the state since this action.
                    return ActionOutcome::success($instance, (int) $previous->id, (string) $previous->from_state, true);
                }
            }

            // ServiceRequest installs a fail-closed gate. Prevent direct runtime calls
            // from bypassing a pending cancellation or a required correction sequence.
            if (app()->bound(WorkflowActionGate::class)) {
                app(WorkflowActionGate::class)->assertWorkflowAllowed($instance, $action);
            }

            $trustedFacts = $this->facts->forAction($instance, $action, $actor);
            $context = new TransitionContext($instance->currentState, $action, $actor, $comment, $trustedFacts);
            $decision = $this->machine->evaluate($definition, $context);
            if (!$decision->allowed) {
                return ActionOutcome::denied($instance, $decision);
            }

            $changed = $db->table('workflow_instances')
                ->where('id', $instanceId)
                ->where('revision', $instance->revision)
                ->where('current_state', $instance->currentState)
                ->update([
                    'current_state' => $decision->nextState,
                    'revision' => $instance->revision + 1,
                    'updated_at' => now(),
                ]);
            if ($changed !== 1) {
                throw new StaleWorkflowState('The workflow state changed concurrently; retry with a fresh state.');
            }

            $historyId = (int) $db->table('workflow_history')->insertGetId([
                'instance_id' => $instanceId,
                'from_state' => $instance->currentState,
                'to_state' => $decision->nextState,
                'action' => $action,
                'actor_id' => $actor->actorId,
                'actor_role' => $actor->role,
                'actor_organization_id' => $actor->organizationId,
                'comment' => $comment,
                'idempotency_key' => $idempotencyKey,
                'created_at' => now(),
            ]);
            $updated = new WorkflowInstance(
                $instance->id, $instance->resourceType, $instance->resourceId,
                $instance->workflowId, $instance->workflowVersion,
                $decision->nextState, $instance->revision + 1,
            );
            return ActionOutcome::success($updated, $historyId, $instance->currentState);
        }, $this->attempts());
    }

    /** Authorized read: never expose instance status to users by querying raw tables. */
    public function view(string $instanceId): WorkflowInstance
    {
        $db = $this->storage->connection;
        $row = $db->table('workflow_instances')->where('id', $instanceId)->first();
        if ($row === null) {
            throw new \RuntimeException('Workflow instance not found.');
        }
        $definition = $this->definitions->byRecordId((int) $row->definition_id);
        $instance = $this->storage->snapshot($row, $definition);
        $this->authorization->authorizeView($instance, $this->actors->resolve());
        return $instance;
    }

    /** @return list<object> Ordered history rows, accessible only after host authorization. */
    public function history(string $instanceId): array
    {
        $this->view($instanceId);
        return $this->storage->connection->table('workflow_history')
            ->where('instance_id', $instanceId)->orderBy('id')->get()->all();
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

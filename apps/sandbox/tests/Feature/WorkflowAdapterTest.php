<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Karband\Workflow\Contracts\TransitionGuard;
use Karband\Workflow\Definitions\WorkflowDefinition;
use Karband\Workflow\Engine\StateMachine;
use Karband\Workflow\ValueObjects\ActorContext;
use Karband\Workflow\ValueObjects\GuardResult;
use Karband\Workflow\ValueObjects\TransitionContext;
use Karband\WorkflowLaravel\Contracts\ActorResolver;
use Karband\WorkflowLaravel\Contracts\TrustedFactProvider;
use Karband\WorkflowLaravel\Contracts\WorkflowAuthorizer;
use Karband\WorkflowLaravel\Exceptions\DefinitionAlreadyPublished;
use Karband\WorkflowLaravel\Exceptions\IdempotencyConflict;
use Karband\WorkflowLaravel\Services\DefinitionCatalog;
use Karband\WorkflowLaravel\Services\WorkflowRuntime;
use Karband\WorkflowLaravel\ValueObjects\WorkflowInstance;
use Tests\TestCase;

/** Integration tests run INSIDE apps/sandbox after Composer install + package discovery. */
final class WorkflowAdapterTest extends TestCase
{
    use RefreshDatabase;

    private FakeActorResolver $actors;
    private FakeWorkflowAuthorizer $authorization;
    private FakeTrustedFacts $facts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actors = new FakeActorResolver(new ActorContext('admin-1', 'platform_admin', 'platform'));
        $this->authorization = new FakeWorkflowAuthorizer();
        $this->authorization->owners = [
            'request-1' => 'agency-a',
            'request-2' => 'agency-a',
            'request-3' => 'agency-a',
            'request-foreign' => 'agency-b',
        ];
        $this->facts = new FakeTrustedFacts();
        $this->app->instance(ActorResolver::class, $this->actors);
        $this->app->instance(WorkflowAuthorizer::class, $this->authorization);
        $this->app->instance(TrustedFactProvider::class, $this->facts);
    }

    private function definition(int $version = 1, bool $guarded = false): WorkflowDefinition
    {
        return WorkflowDefinition::fromArray([
            'id' => 'ncr.sample',
            'version' => $version,
            'initial_state' => 'draft',
            'states' => ['draft', 'submitted', 'correction_required', 'approved'],
            'transitions' => [
                ['action' => 'submit', 'from' => 'draft', 'to' => 'submitted', 'allowed_roles' => ['agency']],
                ['action' => 'require_correction', 'from' => 'submitted', 'to' => 'correction_required',
                    'allowed_roles' => ['government_admin'], 'comment_required' => true],
                ['action' => 'resubmit', 'from' => 'correction_required', 'to' => 'submitted', 'allowed_roles' => ['agency']],
                ['action' => 'approve', 'from' => 'submitted', 'to' => 'approved',
                    'allowed_roles' => ['government_admin'], 'guards' => $guarded ? ['documents_complete'] : []],
            ],
        ]);
    }

    private function agency(string $id = 'agency-a'): void
    {
        $this->actors->actor = new ActorContext('operator-1', 'agency', $id);
    }

    private function government(string $organization = 'gov-a'): void
    {
        $this->actors->actor = new ActorContext('reviewer-1', 'government_admin', $organization);
    }

    private function start(string $resource = 'request-1', int $version = 1): WorkflowInstance
    {
        $this->agency();
        return $this->app->make(WorkflowRuntime::class)->start('ncr.sample', $version, 'ncr.request', $resource);
    }

    private function publish(int $version = 1, bool $guarded = false): int
    {
        $this->actors->actor = new ActorContext('admin-1', 'platform_admin', 'platform');
        return $this->app->make(DefinitionCatalog::class)->publish($this->definition($version, $guarded));
    }

    public function test_published_definition_is_versioned_and_exactly_retrievable(): void
    {
        $id1 = $this->publish(1);
        $id2 = $this->publish(2);
        $this->assertNotSame($id1, $id2);
        $this->assertSame(1, $this->app->make(DefinitionCatalog::class)->get('ncr.sample', 1)->version);
        $this->assertSame(2, $this->app->make(DefinitionCatalog::class)->get('ncr.sample', 2)->version);
    }

    public function test_cannot_overwrite_a_published_version(): void
    {
        $this->publish();
        $this->expectException(DefinitionAlreadyPublished::class);
        $this->publish();
    }

    public function test_only_explicitly_authorized_actor_can_publish(): void
    {
        $this->agency();
        $this->expectException(\RuntimeException::class);
        $this->app->make(DefinitionCatalog::class)->publish($this->definition());
    }

    public function test_new_instance_pins_version_and_starts_in_initial_state(): void
    {
        $this->publish(1);
        $first = $this->start();
        $this->assertSame(1, $first->workflowVersion);
        $this->assertSame('draft', $first->currentState);
        $this->assertSame(0, $first->revision);
        $this->publish(2);
        $this->agency();
        $this->assertSame(1, $this->app->make(WorkflowRuntime::class)->view($first->id)->workflowVersion);
    }

    public function test_cannot_start_a_request_from_another_agency(): void
    {
        $this->publish();
        $this->agency('agency-b');
        $this->expectException(\RuntimeException::class);
        $this->app->make(WorkflowRuntime::class)->start('ncr.sample', 1, 'ncr.request', 'request-1');
    }

    public function test_agency_submits_and_history_is_atomic_and_attributed(): void
    {
        $this->publish();
        $instance = $this->start();
        $result = $this->app->make(WorkflowRuntime::class)->apply($instance->id, 'submit', 'All documents attached');
        $this->assertTrue($result->applied);
        $this->assertFalse($result->replayed);
        $this->assertSame('draft', $result->previousState);
        $this->assertSame('submitted', $result->instance->currentState);
        $this->assertSame(1, $result->instance->revision);
        $history = $this->app->make(WorkflowRuntime::class)->history($instance->id);
        $this->assertCount(1, $history);
        $this->assertSame('agency-a', $history[0]->actor_organization_id);
        $this->assertSame('operator-1', $history[0]->actor_id);
        $this->assertSame('All documents attached', $history[0]->comment);
    }

    public function test_missing_comment_denies_correction_without_mutation(): void
    {
        $this->publish();
        $instance = $this->start();
        $this->app->make(WorkflowRuntime::class)->apply($instance->id, 'submit');
        $this->government();
        $outcome = $this->app->make(WorkflowRuntime::class)->apply($instance->id, 'require_correction');
        $this->assertFalse($outcome->applied);
        $this->assertSame('comment_required', $outcome->denial->reasonCode);
        $this->assertSame('submitted', $outcome->instance->currentState);
        $this->assertCount(1, $this->app->make(WorkflowRuntime::class)->history($instance->id));
    }

    public function test_government_can_request_correction_with_reason_and_agency_resubmits(): void
    {
        $this->publish();
        $instance = $this->start();
        $runtime = $this->app->make(WorkflowRuntime::class);
        $runtime->apply($instance->id, 'submit');
        $this->government();
        $result = $runtime->apply($instance->id, 'require_correction', 'Attach missing identity document');
        $this->assertSame('correction_required', $result->instance->currentState);
        $this->agency();
        $again = $runtime->apply($instance->id, 'resubmit');
        $this->assertSame('submitted', $again->instance->currentState);
        $this->assertCount(3, $runtime->history($instance->id));
    }

    public function test_engine_denies_an_action_when_actor_has_wrong_role(): void
    {
        $this->publish();
        $instance = $this->start();
        $this->app->make(WorkflowRuntime::class)->apply($instance->id, 'submit');
        $this->agency();
        $denied = $this->app->make(WorkflowRuntime::class)->apply($instance->id, 'require_correction', 'Test');
        $this->assertFalse($denied->applied);
        $this->assertSame('actor_not_allowed', $denied->denial->reasonCode);
    }

    public function test_authorizer_denies_government_admin_from_unrelated_organization(): void
    {
        $this->publish();
        $instance = $this->start();
        $this->app->make(WorkflowRuntime::class)->apply($instance->id, 'submit');
        $this->government('gov-wrong');
        $this->expectException(\RuntimeException::class);
        $this->app->make(WorkflowRuntime::class)->apply($instance->id, 'require_correction', 'Check');
    }

    public function test_guard_denies_until_trusted_fact_changes(): void
    {
        $this->app->instance(StateMachine::class, new StateMachine([
            'documents_complete' => new FakeDocumentsGuard(),
        ]));
        $this->publish(1, true);
        $instance = $this->start();
        $runtime = $this->app->make(WorkflowRuntime::class);
        $runtime->apply($instance->id, 'submit');
        $this->government();
        $blocked = $runtime->apply($instance->id, 'approve');
        $this->assertSame('guard_rejected', $blocked->denial->reasonCode);
        $this->assertSame('documents_complete', $blocked->denial->failedGuard);
        $this->facts->facts['documents_complete'] = true;
        $passed = $runtime->apply($instance->id, 'approve');
        $this->assertTrue($passed->applied);
        $this->assertSame('approved', $passed->instance->currentState);
    }

    public function test_unregistered_guard_prevents_publication(): void
    {
        $this->expectException(\Karband\Workflow\Exceptions\InvalidWorkflowDefinition::class);
        $this->publish(1, true);
    }

    public function test_successful_action_is_replayed_once_for_matching_idempotency_key(): void
    {
        $this->publish();
        $instance = $this->start();
        $runtime = $this->app->make(WorkflowRuntime::class);
        $first = $runtime->apply($instance->id, 'submit', 'Done', 'submit-001');
        $again = $runtime->apply($instance->id, 'submit', 'Done', 'submit-001');
        $this->assertTrue($again->applied);
        $this->assertTrue($again->replayed);
        $this->assertSame($first->historyId, $again->historyId);
        $this->assertSame(1, $again->instance->revision);
        $this->assertCount(1, $runtime->history($instance->id));
    }

    public function test_idempotency_key_must_not_be_reused_with_different_payload(): void
    {
        $this->publish();
        $instance = $this->start();
        $runtime = $this->app->make(WorkflowRuntime::class);
        $runtime->apply($instance->id, 'submit', 'Original', 'unique-001');
        $this->expectException(IdempotencyConflict::class);
        $runtime->apply($instance->id, 'submit', 'Different', 'unique-001');
    }

    public function test_repeating_action_without_key_does_not_apply_twice(): void
    {
        $this->publish();
        $instance = $this->start();
        $runtime = $this->app->make(WorkflowRuntime::class);
        $runtime->apply($instance->id, 'submit');
        $again = $runtime->apply($instance->id, 'submit');
        $this->assertFalse($again->applied);
        $this->assertSame('transition_not_found', $again->denial->reasonCode);
        $this->assertCount(1, $runtime->history($instance->id));
    }

    public function test_one_resource_cannot_receive_two_workflow_instances(): void
    {
        $this->publish();
        $this->start();
        $this->expectException(QueryException::class);
        $this->start();
    }

    public function test_foreign_agency_cannot_read_snapshot_or_history(): void
    {
        $this->publish();
        $instance = $this->start();
        $this->agency('agency-b');
        $this->expectException(\RuntimeException::class);
        $this->app->make(WorkflowRuntime::class)->history($instance->id);
    }

    public function test_missing_actor_binding_fails_closed(): void
    {
        $this->app->forgetInstance(ActorResolver::class);
        $this->expectException(\Illuminate\Contracts\Container\BindingResolutionException::class);
        $this->app->make(WorkflowRuntime::class);
    }

    public function test_a_guard_exception_does_not_change_state_or_write_history(): void
    {
        $this->app->instance(StateMachine::class, new StateMachine([
            'documents_complete' => new FakeExplodingGuard(),
        ]));
        $this->publish(1, true);
        $instance = $this->start();
        $runtime = $this->app->make(WorkflowRuntime::class);
        $runtime->apply($instance->id, 'submit');
        $this->government();
        try {
            $runtime->apply($instance->id, 'approve');
            $this->fail('Expected guard failure.');
        } catch (\LogicException $error) {
            $this->assertSame('guard exploded', $error->getMessage());
        }
        $this->assertSame('submitted', $runtime->view($instance->id)->currentState);
        $this->assertCount(1, $runtime->history($instance->id));
    }
}

final class FakeActorResolver implements ActorResolver
{
    public function __construct(public ActorContext $actor)
    {
    }

    public function resolve(): ActorContext
    {
        return $this->actor;
    }
}

final class FakeWorkflowAuthorizer implements WorkflowAuthorizer
{
    /** @var array<string, string> */
    public array $owners = [];

    public function authorizePublish(WorkflowDefinition $definition, ActorContext $actor): void
    {
        if ($actor->role !== 'platform_admin') {
            throw new \RuntimeException('Not authorized to publish.');
        }
    }

    public function authorizeStart(
        WorkflowDefinition $definition,
        string $resourceType,
        string $resourceId,
        ActorContext $actor,
    ): void {
        if ($actor->role !== 'agency' || ($this->owners[$resourceId] ?? null) !== $actor->organizationId) {
            throw new \RuntimeException('Not authorized to start for this resource.');
        }
    }

    public function authorizeAction(WorkflowInstance $instance, string $action, ActorContext $actor): void
    {
        $this->authorizeView($instance, $actor);
    }

    public function authorizeView(WorkflowInstance $instance, ActorContext $actor): void
    {
        if (
            ($actor->role === 'agency' && ($this->owners[$instance->resourceId] ?? null) === $actor->organizationId)
            || ($actor->role === 'government_admin' && $actor->organizationId === 'gov-a')
        ) {
            return;
        }
        throw new \RuntimeException('Not authorized for this instance.');
    }
}

final class FakeTrustedFacts implements TrustedFactProvider
{
    /** @var array<string, mixed> */
    public array $facts = [];

    public function forAction(WorkflowInstance $instance, string $action, ActorContext $actor): array
    {
        return $this->facts;
    }
}

final class FakeDocumentsGuard implements TransitionGuard
{
    public function evaluate(TransitionContext $context): GuardResult
    {
        return ($context->facts['documents_complete'] ?? false) === true
            ? GuardResult::allow()
            : GuardResult::deny('missing_documents');
    }
}

final class FakeExplodingGuard implements TransitionGuard
{
    public function evaluate(TransitionContext $context): GuardResult
    {
        throw new \LogicException('guard exploded');
    }
}

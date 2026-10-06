# INTL Workflow Laravel Adapter v1

A fail-closed Laravel 13 persistence adapter for the existing framework-independent `karband/workflow` package. This adapter owns **only** published definitions, workflow instances and successful transition history. Host applications remain responsible for authentication, tenant/request authorization, business data, status mapping and payment flows.

## Package boundaries

- Does not alter `packages/workflow`, NCR tables, NCR controllers, root `composer.json` or the root PHPUnit config.
- Runs migrations only within a Laravel application that installs and discovers this package.
- Publishes immutable definitions identified by `(workflow_id, version)` and pins each instance to a definition row.
- Every `publish`, `start` and `apply` resolves an authenticated actor and invokes a **host-provided** authorization contract. `view` / `history` also require authorization. Neither an ActorResolver nor WorkflowAuthorizer is supplied by default.
- `apply` locks the instance row and uses a revision compare-and-swap under a database transaction; records the new state and audit entry together, with optional successful-command idempotency keys.
- Guard registration is application-owned. Trusted facts are provided via a host interface (default: empty). A missing registered guard fails closed.
- No generic web routes, UI, notifications, external calls, hooks or payments are included.

## Dependencies

- PHP `^8.4`
- Laravel / Illuminate `^13.0`
- `karband/workflow:dev-main` from the same monorepo
- A transactional database. For strong concurrent-write locking, use MySQL/InnoDB or PostgreSQL. SQLite is appropriate for local functional tests **but does not implement SQL row-level `FOR UPDATE` locking**.

## Installation inside `intl-karband/apps/sandbox`

**First:** apply the ZIP/patch from the monorepo root. The Composer repository must point to packages **relative to the sandbox**, even if the root already includes `packages/*`.

```bash
cd ~/Desktop/intl-karband/apps/sandbox
composer config repositories.intl-local path '../../packages/*'
composer require 'karband/workflow:dev-main' 'karband/workflow-laravel:dev-main' -W
php artisan package:discover
php artisan migrate:status
```

The provider auto-discovers itself; it loads its migration and merges default configuration. Before trying test migrations, make sure `.env.testing` points to a **dedicated** test database. A local SQLite in-memory testing connection is convenient. `php artisan migrate` should only be used on an intended sandbox database, never on NCR production.

```bash
php artisan test --filter=IntlWorkflowAdapterTest
cd ../..
./vendor/bin/phpunit
```

The root PHPUnit suite does **not** automatically execute the Laravel-specific adapter test: that test resides at `apps/sandbox/tests/Feature/IntlWorkflowAdapterTest.php`. Root tests should retain 45 existing tests if no other changes were made.

## Required host bindings (fail closed)

The host application must bind the two interfaces below in its service provider. An authenticated user is **not** automatically the agency; resolve the actual actor and their organization from the host's real guard/provider system. `ServiceRecipient` (agency customer) is not a login account or workflow actor.

```php
use Karband\WorkflowLaravel\Contracts\ActorResolver;
use Karband\WorkflowLaravel\Contracts\WorkflowAuthorizer;
use App\Workflow\HostActorResolver;
use App\Workflow\HostWorkflowAuthorizer;

$this->app->bind(ActorResolver::class, HostActorResolver::class);
$this->app->bind(WorkflowAuthorizer::class, HostWorkflowAuthorizer::class);
```

Both are **application-specific classes you must implement**, not classes shipped in the package. See `src/Contracts/*.php`. The authorizer must verify the *resource owner, relevant government organization, assigned agency and the host permissions* for every command; a role name alone is insufficient. `platform_admin` does **not** implicitly override service-level restrictions.

Optional read-only trusted business facts:

```php
$this->app->bind(
    \Karband\WorkflowLaravel\Contracts\TrustedFactProvider::class,
    \App\Workflow\HostTrustedFacts::class,
);
```

To register guards, publish the config into the **host app** and set trusted mappings:

```bash
php artisan vendor:publish --tag=workflow-config
```

```php
'guards' => [
    'documents_complete' => \App\Workflow\Guards\DocumentsComplete::class,
],
```

Each guard implements `Karband\Workflow\Contracts\TransitionGuard`. Registration is validated when publishing and when reading a stored definition. In particular, payment guards must only inspect **trusted previously persisted payment state** and must not initiate external payment requests. Missing authorization bindings, guard registration or trusted facts must never be replaced by optimistic defaults.

## Publishing and executing (trusted application code only)

```php
use Karband\Workflow\Definitions\WorkflowDefinition;
use Karband\WorkflowLaravel\Services\DefinitionCatalog;
use Karband\WorkflowLaravel\Services\WorkflowRuntime;

$definition = WorkflowDefinition::fromArray([
    'id' => 'insurance.claim',
    'version' => 1,
    'initial_state' => 'draft',
    'states' => ['draft', 'submitted', 'correction_required'],
    'transitions' => [
        ['action' => 'submit', 'from' => 'draft', 'to' => 'submitted', 'allowed_roles' => ['agency']],
        ['action' => 'request_changes', 'from' => 'submitted', 'to' => 'correction_required',
         'allowed_roles' => ['government_admin'], 'comment_required' => true],
    ],
]);

// In an AUTHORIZED management action or explicit, audited deployment command:
app(DefinitionCatalog::class)->publish($definition);

// When an authorized agency creates a resource; version MUST be explicit:
$instance = app(WorkflowRuntime::class)->start('insurance.claim', 1, 'insurance.request', 'host-123');

// Authorized agency action. Optional idempotency key is stable per client command:
$outcome = app(WorkflowRuntime::class)->apply($instance->id, 'submit', null, 'command-123');
if (! $outcome->applied) {
    // No state mutation/history occurred. Inspect $outcome->denial->reasonCode.
}
```

No automatic HTTP routes or ability to publish definitions are exposed. Never expose `DefinitionCatalog::publish` directly to arbitrary clients; your host authorization must explicitly permit it.

## Schema / state integrity

- `workflow_definitions`: unique `(workflow_id, version)`, canonical JSON and publication timestamp.
- `workflow_instances`: generated ULID, pinned foreign key, generic `(resource_type, resource_id)` unique reference, state, revision. One workflow instance **per host resource per microservice database** in v1; version changes require explicit migration design, not changing the foreign key.
- `workflow_history`: immutable-in-service successful actions, action actor/role/org, comment, optional idempotency key, insertion timestamp.

An `ActionOutcome` marked `replayed` is the result of an **earlier successful action**; `instance` represents its **current** snapshot (possibly advanced by later actions), and `previousState` is the original action's source state. Rejected actions are not stored with an idempotency key and can be retried after requirements change. On conflicts with reused keys, an `IdempotencyConflict` is thrown.

Persistence-level publication immutability is protected by package service design and database unique keys; **direct SQL update permission remains a separate operational risk**. Restrict direct DB write privileges and back up definitions. No admin workflow designer is included.

## Important NCR migration restriction

NCR currently has numeric status codes and request/payment side effects. The adapter stores **string workflow states in separate tables**, not NCR's existing `requests.status`. It is **not** a drop-in replacement. Before migrating NCR, produce an exact transition + authorization + numeric-state mapping, then run shadow comparison tests against the existing logic.

If the host must update its own request status in the same logical operation, it must use the **same underlying database connection** and an encompassing transaction, with a consistent lock order: host row first, then adapter instance. Apply the outcome only when `$outcome->applied` is true. Never call remote wallets, send notifications or perform other non-idempotent I/O inside retryable transactions. Consider a separately designed outbox for after-commit processing if needed.

## Tests and limitations

The new sandbox test contains 19 integration tests covering version pinning, authentication/authorization barriers, independent roles, comments, guards, successful actions/history, optional idempotency, uniqueness and exception rollback. Run the suite on the user's local Laravel 13 + SQLite or production-equivalent DB. Real multi-connection concurrency, migration of existing NCR state and end-to-end payment idempotency require separate integration tests in their host systems; this package does not claim to implement them.

# INTL Workflow Core v1

A PHP 8.4+, framework-independent workflow state machine for multiple INTL services. It evaluates a configured action **without** mutating database state, sending payments, or authorizing access to a specific customer's request.

## Included

- Distinct, immutable `WorkflowDefinition` objects identified by `id` and positive integer `version`.
- Strict JSON / PHP-array definition loading (unknown/missing configuration keys rejected).
- Validation of state names, transitions, allowed roles, guard declarations, duplicate `(from, action)` transitions, and missing states.
- Read-only `StateMachine::evaluate()` that checks current state, declared action, explicit role, mandatory comment, then registered guards in declared order.
- Stable, machine-readable reasons on rejection (`unknown_state`, `transition_not_found`, `actor_not_allowed`, `comment_required`, `guard_not_registered`, `guard_rejected`).
- Optional guard-registration preflight and customizable, application-implemented `TransitionGuard` contracts.
- No Laravel, database, JWT, HTTP, external service, or other INTL-package dependency.
- PHPUnit 12 tests, a zero-dependency smoke test, and an illustrative NCR-like definition.

**Not included:** Workflow designer UI, publication database, persistence adapter, generic IAM/ACL, notifications, attachments, correspondences, payment processing, or integration changes in the existing NCR app. Do not replace NCR's current workflows before performing a controlled migration.

## Installation (INTL monorepo)

Unpack the delivery at the `intl-platform` repository root so that `packages/workflow/composer.json` exists. Ensure your root Composer configuration already includes a path repository for `packages/*` (the INTL foundation currently uses this pattern).

```bash
composer require "karband/workflow:@dev"
php packages/workflow/tests/standalone-smoke.php
./vendor/bin/phpunit -c packages/workflow/phpunit.xml
```

If also required inside `apps/sandbox` or another separate Laravel application, configure a Composer path repository relative to that application's directory (for example `../../packages/*`), then require `karband/workflow:@dev` **there too**. The root monorepo install does not automatically make the package available in every nested application.

See `docs/WORKFLOW_INSTALL_FA.md` for Persian instructions and precise security/migration notes.

## Basic usage

```php
use Karband\Workflow\Definitions\WorkflowDefinition;
use Karband\Workflow\Engine\StateMachine;
use Karband\Workflow\ValueObjects\ActorContext;
use Karband\Workflow\ValueObjects\TransitionContext;

$definition = WorkflowDefinition::fromArray([
    'id' => 'insurance.claim',
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
            'action' => 'request_changes',
            'from' => 'submitted',
            'to' => 'correction_required',
            'allowed_roles' => ['government_admin'],
            'comment_required' => true,
        ],
    ],
]);

$result = (new StateMachine())->evaluate(
    $definition,
    new TransitionContext(
        'draft',
        'submit',
        new ActorContext('agency-100', 'agency', 'agency-100'),
    ),
);

if ($result->allowed) {
    // Application must atomically persist the new status and history entry.
    echo $result->nextState; // submitted
}
```

`ActorContext` holds authentication-derived identity/role; `organizationId` is informational to the engine. Application authorization must establish the actor's permitted organization and request *before* calling the engine. The engine does not intrinsically give `platform_admin` override access. `service_recipient` is a business entity, never a built-in login or role.

## Business guard example

```php
use Karband\Workflow\Contracts\TransitionGuard;
use Karband\Workflow\Engine\StateMachine;
use Karband\Workflow\ValueObjects\GuardResult;
use Karband\Workflow\ValueObjects\TransitionContext;

$complete = new class implements TransitionGuard {
    public function evaluate(TransitionContext $context): GuardResult
    {
        // In production, verify with trusted application/repository facts.
        return ($context->facts['request_complete'] ?? false) === true
            ? GuardResult::allow()
            : GuardResult::deny('missing_required_fields');
    }
};

$machine = new StateMachine(['request_complete' => $complete]);
$machine->assertGuardsRegistered($definition);
```

**Important:** The JSON file `examples/ncr-like-v1.json` lists `request_complete` and `trusted_payment_confirmed` as illustrative guards. They are **not** implemented in the framework-independent core. Production NCR must supply vetted implementations, verify its real actor permissions and existing numeric status mappings, and preserve payment idempotency and atomicity. Do not treat raw client-provided `facts` as trustworthy for payment checks.

## Version and concurrency invariants

- This package makes **in-memory** definition objects immutable. The future storage/publishing adapter must enforce immutability of published `(workflow_id, version)` definitions and reject overwrites.
- Each request should persist both `workflow_id` and `workflow_version` on creation. Resolve that exact definition version throughout the request's life; never silently move existing requests to a newer version.
- The host app must re-read and lock / optimistic-check the **current request status** inside a DB transaction when applying the result, and atomically write status, actor, action, explanation, and history. Evaluation alone is not a concurrency boundary.
- External payments must be verified by trusted infrastructure with idempotent handling; evaluation must not initiate them.
- Exceptions thrown by a guard propagate; no approval result is produced. The host app should roll back, log, and handle the failure securely.
- One `(from, action)` pair has exactly one target and allowed-role set. This avoids ambiguous behavior when multiple actors use an action.
- Identifiers use lowercase ASCII `[a-z][a-z0-9_.-]*` (max 120 chars) so definitions remain portable and safe to reference across services.

## Tests

With PHPUnit 12 installed via the monorepo:

```bash
./vendor/bin/phpunit -c packages/workflow/phpunit.xml
```

With PHP only:

```bash
php packages/workflow/tests/standalone-smoke.php
php packages/workflow/examples/demo.php
```

The standalone test covers key behaviors but does not replace the complete PHPUnit suite.

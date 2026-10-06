# intl/service-catalog-laravel

Local, database-backed adapter for `intl/service-catalog`. No central server, cross-microservice database or automatic HTTP endpoints. **No categories** and **no service-definition version history**.

## Responsibilities

- Optimistic revisions + row locking for edits and lifecycle changes.
- Published Workflow id/version validation through the *existing local* Workflow Laravel DefinitionCatalog.
- Explicit authorization using the existing `Intl\Contracts\Authorization\AuthorizerInterface` + host-provided `PrincipalProvider` and `ScopeChecker` (Spatie adapter). No default bypass or role-to-permission seeder.
- Lifecycle: inactive (default), active, archived (permanent). To archive an active service, first deactivate it.
- Agency discoverability: `available()` requires list permission and per-service use permission; returns only public `code`, `name`, `description`. Host implements entitlement via `ScopeChecker`.
- For NEW workflow instances, `CatalogWorkflowStarter` checks `catalog.service.use`, locks active catalog service and starts **a pinned** Workflow instance on the SAME DB connection; the existing Workflow authorization layer checks resource ownership. Do NOT call raw `WorkflowRuntime::start` from an untrusted new-request route or you bypass service activation/entitlement checks.
- Existing workflow instances remain pinned when a service is disabled/archived or when the binding is changed. This adapter does not restrict the existing Workflow engine from processing them.

## Required Spatie permissions (example)

`catalog.service.create`, `catalog.service.view`, `catalog.service.update`, `catalog.service.activate`, `catalog.service.deactivate`, `catalog.service.archive`, `catalog.service.list`, `catalog.service.use`.

Map permissions and scope in each independent microservice. Agency entitlement is deliberately NOT a central `agency_services` table in the generic package. Agency scopes are checked by the host.

## Security and operational notes

- Bind `PrincipalProvider` and `ScopeChecker` in the host, as required by the existing authorization package. Published workflow verification is bound automatically but uses the installed Workflow adapter; its actor/authorizer bindings must still be configured by the host when publishing/starting.
- Store only non-sensitive JSON in `settings` (16 KiB max); secret keys and external API credentials belong in environment/secret storage.
- `ServiceDefinition::revision` is concurrency control, not a historical business definition version. If the actual *form/settings* of an in-progress request need to stay fixed, snapshot them on the request in that host. Workflow versions are already pinned independently by WorkflowRuntime.
- `CatalogWorkflowStarter` requires `intl-service-catalog.connection` and `intl-workflow.connection` to resolve to the **same Laravel Connection object**. Default `null` on both uses the local default connection. Lock semantics depend on the DB backend; SQLite in-memory tests cannot prove multi-process locking under production concurrency.
- Package migrations are auto-loaded; do NOT publish/copy them again. No `migrate:fresh` against non-test databases.
- No public routes, global service seeder, permissive default roles, shared organization filters or access to any other microservice.

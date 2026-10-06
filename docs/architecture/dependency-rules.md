# Monorepo Dependency Rules & Architecture Invariants

## 1. Two-Tier Package Architecture

The monorepo is strictly organized into 12 business capabilities, each split into two packages (total 24 packages):
1. **Core Package (`packages/<capability>`)**: Pure PHP domain logic, interfaces, value objects, domain events, and state engines.
2. **Laravel Adapter Package (`packages/<capability>-laravel`)**: Framework-specific bindings, Eloquent models, database migrations, controllers, CLI commands, and service providers.

```
+-------------------------------------------------------------+
|                      Host Application                       |
|          (Composition Root, Dependency Injection, UI)        |
+-------------------------------------------------------------+
           |                                       |
           v                                       v
+-----------------------+              +-----------------------+
|  Capability A Adapter |              |  Capability B Adapter |
| (Laravel / Eloquent)  |              | (Laravel / Eloquent)  |
+-----------------------+              +-----------------------+
           |                                       |
           v                                       v
+-----------------------+              +-----------------------+
|   Capability A Core   | <........... |   Capability B Core   |
|      (Pure PHP)       |   (Domain    |      (Pure PHP)       |
+-----------------------+   Contracts) +-----------------------+
```

---

## 2. Core Package Rules (Tier 1)

1. **Zero Framework Dependencies**:
   - Must never import `Illuminate\*`, `Laravel\*`, `Symfony\*` (except isolated standalone components if strictly needed, but preferably pure PHP), or any database drivers.
2. **Zero Database / IO Coupling**:
   - Must never execute SQL queries, reference database tables, or assume relational persistence structures.
3. **Zero Adapter Imports**:
   - Must never import or depend on `*-laravel` packages.
4. **Minimal Inter-Core Dependencies**:
   - A core package may only depend on another capability's core package when there is a fundamental domain relationship (e.g., `service-requests` core consuming `workflow` core state machine abstractions or `authorization` core value objects).
5. **Deterministic & Testable**:
   - All domain logic in core packages must be 100% testable without database, mock HTTP servers, or Laravel container bootstrapping.

---

## 3. Laravel Adapter Rules (Tier 2)

1. **Strict Own-Core Alignment**:
   - Each `<capability>-laravel` adapter package must depend on and implement the contracts defined in its corresponding `<capability>` core package.
2. **Zero Adapter-to-Adapter Coupling**:
   - An adapter package must **NEVER** import or depend on another capability's adapter package (e.g., `service-requests-laravel` must not import `service-recipients-laravel`).
   - If an adapter needs services or models from another domain, it must communicate via:
     - The other domain's Core interfaces / Value Objects, OR
     - Host-level composition / event subscribers / orchestrator actions.
3. **Zero Foreign Table Access**:
   - An adapter's Eloquent models and migrations must **ONLY** manage and query tables owned by its capability.
   - Cross-capability joins and raw SQL referencing foreign tables (e.g. `service_requests` querying `service_recipients` or `workflows` tables directly) are strictly forbidden.
4. **Explicit Dependency Declarations**:
   - Every class, trait, or interface referenced in an adapter's codebase must be provided by a package explicitly declared in its `composer.json` requirements.

---

## 4. Host Composition Rules

1. **Composition Root**:
   - Cross-capability orchestration belongs in the host application layer (e.g., `apps/sandbox` or production microservice hosts).
2. **Event & Workflow Integration**:
   - Host service providers register event listeners, notification bridges, and composite actions connecting individual capability adapters.
3. **Fail-Closed Runtime**:
   - Any runtime dependency resolution (authorization, tenant context, external token validation) must fail closed with explicit exceptions rather than silent fallbacks or default permissions.

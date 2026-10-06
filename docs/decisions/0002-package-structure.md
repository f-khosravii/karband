# ADR-0002: Two-Tier 24-Package Structure & Removal of Generic Contracts

Status: Accepted

## Context

The monorepo previously used an ad-hoc package structure with an untyped generic `packages/contracts` repository alongside various domain and adapter packages. This led to:
1. Low cohesion: generic interfaces like `ActorContext`, `AuditEvent`, and `HealthCheckInterface` were bundled together without clear domain ownership.
2. Unclear layering: Some adapter packages depended directly on other adapter packages, causing tight coupling and cyclic dependencies.
3. Undeclared dependencies: Several packages consumed classes from sister packages without declaring them in `composer.json`.

## Decision

1. **Adopt a strict 2-tier 24-package model (12 Capabilities x 2 Packages)**:
   - Every capability consists of:
     - Pure PHP Core (`packages/<capability>`)
     - Laravel Adapter (`packages/<capability>-laravel`)
2. **Capabilities Inventory (12 Capabilities)**:
   - `audit` / `audit-laravel`
   - `authorization` / `authorization-laravel`
   - `billing` / `billing-laravel`
   - `directory` / `directory-laravel`
   - `documents` / `documents-laravel`
   - `dynamic-forms` / `dynamic-forms-laravel`
   - `external-auth` / `external-auth-laravel`
   - `request-journey` / `request-journey-laravel`
   - `service-catalog` / `service-catalog-laravel`
   - `service-recipients` / `service-recipients-laravel`
   - `service-requests` / `service-requests-laravel`
   - `workflow` / `workflow-laravel`
3. **Eliminate Generic `packages/contracts`**:
   - Authorization value objects (`ActorContext`, `AccessRequest`, `AuthorizationDenied`, `AuthorizerInterface`) move to `packages/authorization`.
   - Audit interfaces (`AuditEvent`, `AuditRecorderInterface`) move to `packages/audit`.
   - Dead/unused interfaces (`HealthCheckInterface`) are removed.
4. **Enforce Zero Adapter-to-Adapter Coupling**:
   - Adapter packages must never depend on sister adapter packages. Cross-domain coordination is performed via core interfaces or host application composition.

## Consequences

- Clear domain boundaries and explicit ownership.
- Zero cyclic dependencies across packages.
- Isolated unit testability for domain core packages without Laravel framework bootstrapping.
- Modular adoption of capabilities by host applications.

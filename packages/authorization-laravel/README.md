# intl/authorization-laravel

Reusable, fail-closed Laravel authorization adapter for INTL services. Uses Spatie Laravel Permission for **guard-specific roles/abilities**, and requires a host-supplied `ScopeChecker` for resource ownership and Laravel Policy checks. It is not an authentication package and does not write or seed roles automatically.

## Layers

- `intl/contracts`: `Authorization/ActorContext`, `AccessRequest`, `AuthorizerInterface`, `AuthorizationDenied`. Framework independent; reusable for future adapters.
- `intl/authorization-laravel`: `SpatieAuthorizer`, `PrincipalProvider`, `ScopeChecker`, and a service provider that binds only `AuthorizerInterface`. Neither `PrincipalProvider` nor `ScopeChecker` has a default; missing host bindings fail rather than grant access.
- `Bridge/*`: optional explicit adapter to `intl/workflow-laravel`. Workflow's existing contracts remain unchanged; **no bridge is auto-enabled**.

## Authorization algorithm

1. Host `PrincipalProvider` supplies a trusted authenticated `Principal`: Laravel user model using `HasRoles`, selected actor role and agency/organization identity, and the active Laravel guard.
2. Verify caller's `ActorContext` matches the trusted principal and that Laravel's actual guard is authenticated as that same user.
3. Check that user has the **selected** role on that guard.
4. Check ability belongs to the selected role, *and* the user has that ability. This avoids inadvertently inheriting an ability from a different assigned role during role switching; direct per-user grants cannot override selected role policy.
5. Delegate actual resource ownership, agency access and optional Laravel Policy checks to the host `ScopeChecker`. Exceptions and unknown resources deny access.

The package provides **no privileged default role**: `platform_admin` must receive explicit permissions just like other actors. `government_admin` requires no cross-government filter **within its own dedicated microservice**; the host still checks that this is the intended service, relevant data, and permitted action. A service recipient is **not** a login account.

## Ability naming with optional Workflow bridge

| Operation | Explicit Spatie ability | Resource passed to ScopeChecker |
|---|---|---|
| Publish a definition | `workflow.definition.publish` | `workflow.definition` / definition ID |
| Start an instance | `workflow.instance.start` | Requested host resource type / ID |
| Apply an action | `workflow.action.<action>` | Instance host resource type / ID |
| View snapshot/history | `workflow.instance.view` | Instance host resource type / ID |

The host **must** create the needed permissions and grant them to roles for its actual guard(s); these strings do not confer rights merely by existing. The Workflow engine still independently checks transition allowed roles and guard conditions. Do not use the authorization package as a substitute for those checks.

## Installation and host wiring

See `docs/AUTHORIZATION_INSTALL_FA.md` in the patch. Installation in Sandbox is intentionally not applied to NCR. Do **not** import test fixture role/permission setup into production. If an app already has Spatie, compare migrations/config/version and guard setup before publishing.

## Security and limits

- No hard-coded tenant/team partitioning. Each service has its own government organization. Agency ownership is verified through the host's trusted repository. Future agency staff accounts can share the same `organizationId` and retain unique user IDs.
- The `PrincipalProvider` **must not** read role or agency ID from HTTP input. Use authenticated guard + trusted DB/SSO mapping.
- Never return `true` from `ScopeChecker` for unknown resource types or missing ownership records. A resource may be a Workflow definition, host application request, document or another entity; the host implements appropriate policy per type.
- Any authorization failure throws `AuthorizationDenied` instead of returning ambiguous results. Missing host bindings cause Laravel DI failure (fail closed).
- Neither middleware nor routes are registered automatically; host routes/services must call authorization or configure policies before exposing resources.

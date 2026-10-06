# INTL Directory v1 — Persistent Agent State

Use this file to resume without repeating completed audits.

## Baseline
- Expected starting Root: 238 tests / 670 assertions
- Expected starting Sandbox: 271 tests / 768 assertions
- Real DB standard: MariaDB 10.6
- v0.2 status: ACCEPTED

## Task status
| Task | Status | Notes |
|---|---|---|
| DIR-00 Preflight | DONE | Root (238/670) and Sandbox (271/768) baselines verified; contracts inspected |
| DIR-01 Pure package | DONE | Created intl/directory with pure domain models, enums, exceptions, and tests (15 tests / 109 assertions) |
| DIR-02 Laravel persistence | DONE | Created intl/directory-laravel migrations, models, DirectoryStorage, config, and ServiceProvider |
| DIR-03 Resolution | DONE | Implemented deterministic fail-closed DirectoryResolver |
| DIR-04 Auth/principal integration | DONE | Integrated Directory resolution with Principal/ActorContext and ExternalAuth flows |
| DIR-05 Sandbox scenarios | DONE | Implemented Scenarios A-F in IntlDirectoryReferenceScenariosTest (23 tests / 91 assertions across sandbox directory tests) |
| DIR-06 MariaDB verification | DONE | Clean migration (20 migrations from zero), upgrade from v0.2, schema & constraint inspection on MariaDB 10.6.28 |
| DIR-07 Aggregate verification | DONE | Root (254 tests, 823 assertions), Sandbox default (294 tests, 859 assertions), Sandbox MariaDB (294 tests, 859 assertions) |
| DIR-08 Docs/final report | DONE | Updated ARCHITECTURE.md and produced final acceptance report |

## Preflight findings
- Actual Root baseline: 238 tests / 670 assertions
- Actual Sandbox baseline: 271 tests / 768 assertions
- ActorContext shape: `Intl\Contracts\Authorization\ActorContext(id: string, role: string, organizationId: ?string = null)`
- Principal shape: `Intl\AuthorizationLaravel\Principal(user: Authenticatable, actor: ActorContext, guard: string)`
- External Auth resolution path: `ExternalAuthService::authenticateTicket()` -> `ExternalPrincipalResolver::resolve(ExternalIdentity $identity): Principal`
- Local auth path: Host Laravel guard -> user authenticatable -> Principal
- ID convention: ULID (`ulid('id')->primary()`, 26 chars string)
- Migration convention: `Schema::connection(config('intl-directory.connection'))`, prefix `intl_directory_*`
- Source reference persistence decision: Dedicated table `intl_directory_external_references` with `source`, `reference_kind`, `external_id`, `target_type` ('organization'|'user'), `target_organization_id` (FK), `target_user_id` (FK), unique constraint on `(source, reference_kind, external_id)`.
- ActorContext/Principal change required?: No breaking changes; existing contracts already support actor ID, role, and organization ID.

## Completed changes

### DIR-00
- Verified Root (238 tests, 670 assertions) and Sandbox (271 tests, 768 assertions) baselines.
- Inspected `ActorContext`, `Principal`, `ExternalIdentity`, `ExternalAuthService`, `ExternalPrincipalResolver`.
- Determined ID conventions (ULID), table naming (`intl_directory_*`), and source-reference persistence structure.

### DIR-01
- Created pure domain package `packages/directory` (`intl/directory`).
- Implemented `DirectoryStatus` enum (`active`, `inactive`).
- Implemented `OrganizationRole` enum (`operator`, `provider`, `agency`).
- Implemented `Organization`, `User`, `Membership`, `ResolvedDirectoryActor` value objects.
- Implemented `DirectoryValidationException`, `DirectoryActorResolutionException`, and `DirectoryResolverInterface`.
- Registered `intl/directory` in root `composer.json` and added `Directory` testsuite in `phpunit.xml`.
- Added 15 pure tests (109 assertions) including architecture checks ensuring zero framework/external dependencies.
- Verified: Root test suite is green (253 tests / 783 assertions).

### DIR-02
- Created `packages/directory-laravel` (`intl/directory-laravel`).
- Added migrations `2026_10_06_010000_create_intl_directory_tables.php` for organizations, organization_roles, users, memberships, and external_references.
- Implemented `DirectoryStorage` with full CRUD, activation/deactivation, role assignment/removal, membership lifecycle, and external reference linking.
- Configured explicit foreign key and unique index names compatible with MariaDB identifier length limits (<=64 chars).
- Registered package and service provider in Sandbox.

### DIR-03
- Implemented `DirectoryResolver` with fail-closed rules:
  - Validates active status of organizations, users, and memberships.
  - Requires explicit organization context for multi-membership or user-level actors (never falls back to first record).
  - Handles organization-only and user+organization actors.
- Added comprehensive unit and integration tests for fail-closed resolution.

### DIR-04 & DIR-05
- Created `IntlDirectoryTest.php` (17 tests, 65 assertions) and `IntlDirectoryReferenceScenariosTest.php` (6 tests, 26 assertions).
- Verified Reference Scenarios:
  - Scenario A: Operator dual-auth identity (same Directory User and Organization via local and external auth).
  - Scenario B: Agency member_id attribution only (external member_id does NOT create Directory User profile).
  - Scenario C: Provider resolves without User.
  - Scenario D: Inactive Organization/User/Membership and unknown mapping fail closed.
  - Scenario E: Multi-membership explicit organization context selection.
  - Scenario F: Domain role vs Authorization separation (OrganizationRole does not grant Spatie permissions).

### DIR-06
- Verified on real MariaDB 10.6.28 instance (`127.0.0.1:3307`).
- Clean migration: all 20 migrations executed from zero without error.
- Upgrade migration: verified schema upgrade from v0.2 without corrupting pre-existing data or creating speculative backfills.
- Constraint inspection: verified primary keys, foreign keys (`intl_dir_org_roles_org_fk`, `intl_dir_mem_org_fk`, `intl_dir_mem_user_fk`, `intl_dir_ext_refs_org_fk`, `intl_dir_ext_refs_user_fk`), and unique indexes.
- Sandbox tests on MariaDB: 294 tests, 859 assertions passed.

### DIR-07
- Ran Root full suite: 254 tests, 823 assertions (0 failures, 0 errors).
- Ran Sandbox SQLite suite: 294 tests, 859 assertions (0 failures, 0 errors).
- Ran Sandbox MariaDB suite: 294 tests, 859 assertions (0 failures, 0 errors).
- Executed `git diff --check` and architecture dependency tests.

### DIR-08
- Updated `docs/architecture/ARCHITECTURE.md` with Tier 1 and Tier 2 Directory packages, invariants, agency attribution rules, and domain role boundaries.
- Formatted final acceptance report according to `03_ACCEPTANCE.md`.

## Current verification
- Directory pure: 15 tests / 109 assertions (Green)
- Directory Laravel: 1 test / 6 assertions architecture (Green)
- Directory Sandbox: 23 tests / 91 assertions (Green)
- External Auth: Green
- Authorization: Green
- Architecture: 5 tests / 133 assertions (Green)
- Root: 254 tests / 823 assertions (Green)
- Sandbox SQLite: 294 tests / 859 assertions (Green)
- Sandbox MariaDB: 294 tests / 859 assertions (Green)
- MariaDB Backend: MariaDB 10.6.28 (Port 3307)

## Blockers
None.

## Next eligible task
None (All tasks DIR-00 through DIR-08 complete).


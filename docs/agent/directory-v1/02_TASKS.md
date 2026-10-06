# INTL Directory v1 — Task Registry

Execute in order. Do not start a later task before its dependencies are verified.
Update `04_STATE.md` after each task.

## DIR-00 — Targeted preflight
Goal: confirm the accepted v0.2 baseline and inspect only contracts Directory must integrate with.

Inspect at minimum:
- root/package Composer layout;
- existing `AGENTS.md`;
- `intl/contracts` ActorContext;
- `authorization-laravel` Principal and authorizer flow;
- `external-auth` identity value objects;
- `external-auth-laravel` principal resolution path;
- Sandbox local auth/user model only as needed;
- current migration conventions;
- current ULID/ID conventions;
- audit conventions only if lifecycle events are already expected.

Verify Root and Sandbox baselines. Do not perform a repo-wide redesign audit.

Record in STATE:
- exact baseline counts;
- relevant classes/contracts;
- whether ActorContext/Principal need additive change;
- selected ID convention;
- selected source-reference persistence shape.

## DIR-01 — Pure `intl/directory` package
Depends on DIR-00.

Create `packages/directory` following current namespace/composer conventions.

Required domain types:
- Organization
- OrganizationRole
- User
- Membership
- DirectoryStatus (or small equivalent enums matching repository style)
- ResolvedDirectoryActor

OrganizationRole values exactly:
- operator
- provider
- agency

Required:
- active/inactive semantics;
- multiple organization roles;
- no permission implication;
- ResolvedDirectoryActor requires Organization ID and nullable User ID.

Pure package must not import Laravel, Eloquent, Spatie, external-auth, authorization-laravel, ServiceRecipient, or framework packages.

Add focused pure tests and relevant architecture checks.

## DIR-02 — `intl/directory-laravel` persistence
Depends on DIR-01.

Create `packages/directory-laravel`.

Implement migrations/tables for:
1. organizations
2. organization roles
3. users
4. memberships
5. source/external references

Use INTL naming conventions.

Expected conceptual table names:
- `intl_directory_organizations`
- `intl_directory_organization_roles`
- `intl_directory_users`
- `intl_directory_memberships`
- `intl_directory_external_references` or a repository-consistent equivalent

Required DB invariants:
- unique `(organization_id, role)`
- unique `(user_id, organization_id)`
- deterministic source + reference-kind + external-id uniqueness
- exactly one Directory target at application/domain level
- prefer FK integrity where portable and consistent

Keep profile fields minimal per spec.

Implement a small management surface for create/update/activate/deactivate Organization and User, role assignment/removal, Membership lifecycle, and source-reference link/unlink. Avoid broad generic CRUD.

Add persistence/lifecycle/constraint tests.

## DIR-03 — Source reference mapping and Directory resolution
Depends on DIR-02.

Implement deterministic fail-closed mapping/resolution.

Organization source reference:
source ref -> active Organization -> ResolvedDirectoryActor(org, null)

User source reference:
source ref -> active User + explicit Organization context + active Membership + active Organization -> ResolvedDirectoryActor(org, user)

Multi-membership:
- explicit org context required;
- never choose first membership.

Reject missing/inactive org, user, membership, unknown or ambiguous references, invalid target mapping.

Mapping does not authenticate, authorize, assign roles, or consume agency `member_id` as User.

Add exhaustive targeted tests.

## DIR-04 — Minimal integration with existing auth/principal flow
Depends on DIR-03.

Maintain:
Authentication -> Directory mapping/resolution -> Principal / ActorContext -> Authorization

Do not make pure Directory depend on external-auth.

Use Laravel/host adapter to convert already-authenticated identity data to Directory lookup input.

Support existing operator local-auth path mapping to the same Directory User used by external auth.

Do not store passwords/credentials in Directory.

If Principal/ActorContext need changes:
- make smallest additive backward-compatible change;
- preserve all existing callers/tests.

Required semantic capability:
- acting organization;
- optional local Directory User.

Agency `member_id` remains attribution, not Directory User.

Prove OrganizationRole does not grant local permissions and external role claims do not elevate authorization.

Required tests:
1. operator local -> Directory U + operator Org;
2. same operator external -> same U + same Org;
3. agency external -> agency Org + null User;
4. provider direct -> provider Org + null User;
5. external `role=admin` cannot elevate;
6. existing v0.2 auth tests remain green.

## DIR-05 — Sandbox reference scenarios
Depends on DIR-04.

Scenario A — Operator dual-auth identity:
same Directory User and Organization through local and external auth, no duplicate profile.

Scenario B — Agency:
external organization reference -> agency Organization, User null, member_id attribution only, no Directory User auto-created.

Scenario C — Provider:
provider Organization resolves without User.

Scenario D — Lifecycle:
inactive Organization/User/Membership and unknown mapping deny.

Scenario E — Multi-membership:
one User, two active Memberships; no org context denied; explicit A/B resolves correctly.

Scenario F — Domain role vs Authorization:
OrganizationRole and external role claims grant no local permissions by themselves.

Use real public services where available; avoid permissive fakes when a real service exists.

## DIR-06 — MariaDB migration and upgrade verification
Depends on DIR-05.

Use the same real backend standard as v0.2 final acceptance (MariaDB 10.6 if unchanged).

Clean install:
run all migrations from zero including Directory and inspect actual schema/indexes.

Upgrade:
start from accepted v0.2 schema without Directory, seed representative existing data, apply Directory migrations.

Verify:
- no existing business rows unexpectedly modified;
- no automatic ambiguous Organization/User/Membership backfill;
- existing v0.2 rows remain intact;
- Directory constraints exist.

Preferred default: explicit provisioning, no speculative backfill.

## DIR-07 — Aggregate verification and architecture regression
Depends on DIR-06.

Run targeted:
- Directory pure;
- Directory Laravel;
- External Auth;
- Authorization;
- Architecture;
- Sandbox Directory scenarios.

Run full:
- Root;
- Sandbox default backend;
- MariaDB relevant/full suite according to current release convention.

Run Composer validation and source hygiene:
- `git diff --check`
- no debug dumps
- no secrets
- no absolute developer paths

Verify:
- no circular dependencies;
- pure Directory framework-free;
- no External Auth dependency in pure Directory;
- no Authorization logic moved into Directory;
- ServiceRecipient remains separate.

## DIR-08 — Documentation and final report
Depends on DIR-07.

Update existing architecture documentation, not duplicate docs.

Document:
- Organization/User/Membership/OrganizationRole;
- operator/provider/agency;
- Directory vs authentication;
- Directory vs authorization;
- agency member_id attribution;
- per-microservice local DB;
- future optional Users for provider/agency.

Do not add Reporting design.

Use exact final report format in `03_ACCEPTANCE.md`.
Do not create/report a Phase 9.

# INTL Directory v1 — Acceptance Contract

## 1. Baseline protection
Pre-Directory accepted baseline:
- Root: 238 tests / 670 assertions
- Sandbox: 271 tests / 768 assertions
- MariaDB 10.6 accepted

Counts may increase.
No previously passing behavior may silently regress.

## 2. Domain acceptance
- Organization: active/inactive local organization profile
- Organization roles: multiple roles supported
- Role values: operator, provider, agency
- User: optional local human profile
- Membership: User <-> Organization relationship
- Membership uniqueness: one logical record per user/org pair
- User-less Organization actor: supported
- Multi-membership: explicit org context required
- ServiceRecipient separation: preserved

## 3. Authentication/Directory acceptance

Operator local:
local authenticated identity -> same Directory User U -> active Membership -> operator Organization O -> ResolvedDirectoryActor(O, U)

Operator external:
external authenticated identity -> same Directory User U -> same Membership -> same operator Organization O -> same ResolvedDirectoryActor(O, U)

No duplicate Directory profile.

Agency:
Epishkhan agency organization reference -> agency Organization A -> ResolvedDirectoryActor(A, null)

External `member_id`:
- must not create Directory User;
- must remain attribution/context only.

Provider:
provider Organization P -> ResolvedDirectoryActor(P, null)

No local User required in v1.

## 4. Fail-closed acceptance
Executable tests required for:
- inactive Organization -> denied;
- inactive User -> denied;
- inactive Membership -> denied;
- unknown source reference -> denied;
- ambiguous source reference -> denied;
- multiple active Memberships + no explicit Organization -> denied;
- explicit valid Organization context -> resolves;
- invalid Membership for requested Organization -> denied.

No first-record fallback.

## 5. Authorization separation
Executable proof required:
- OrganizationRole::AGENCY grants no business permission by itself.
- OrganizationRole::PROVIDER grants no admin permission by itself.
- OrganizationRole::OPERATOR grants no platform admin permission by itself.
- external claim `role=admin` creates neither OrganizationRole::OPERATOR nor local auth role/permission.
- existing `authorization-laravel` remains authoritative.

## 6. Persistence acceptance
Real DB evidence required.

Required MariaDB constraints:
- unique organization-role pair;
- unique Membership pair;
- deterministic unique source reference;
- target integrity consistent with implemented mapping design.

No automatic ambiguous backfill.

## 7. Package boundary acceptance
`intl/directory` must be framework-free and not depend on Laravel, Spatie, external-auth, external-auth-laravel, authorization-laravel, service-requests-laravel, dynamic-forms-laravel, or documents-laravel.

`intl/directory-laravel` may integrate through public contracts/adapters.

No circular dependency.

## 8. Explicit non-goals
Final diff must not contain new implementation for:
- reporting;
- analytics;
- activity dashboards;
- invitation/team management;
- workspace/tenant engine;
- local password auth;
- MFA;
- new SSO protocol;
- permission management;
- agency member directory;
- ServiceRecipient merge;
- business-specific provider/agency profile fields.

## 9. Required test groups
Report exact tests/assertions for:
- Directory pure;
- Directory Laravel;
- Directory Sandbox scenarios;
- External Auth regression;
- Authorization regression;
- Architecture;
- Root full;
- Sandbox full;
- MariaDB-backed relevant integration.

Also report failures/errors/skips and DB version.

## 10. Migration acceptance
Clean: all migrations from zero on real MariaDB.

Upgrade: accepted v0.2 schema -> Directory-added schema.

Verify:
- no pre-existing data corruption;
- no ambiguous actor backfill;
- existing requests/auth/payment/document/journey rows remain intact;
- Directory constraints created correctly.

## 11. Final report format
Return exactly these sections:

### DIRECTORY V1 STATUS
`ACCEPTED`
or
`NOT ACCEPTED`

### IMPLEMENTED
Packages/classes/services/migrations actually added.

### DOMAIN MODEL
Final Organization/User/Membership/OrganizationRole semantics.

### AUTHENTICATION BOUNDARY
How local and external auth map into Directory without Directory performing authentication.

### RESOLUTION
Exact fail-closed runtime resolution rules.

### AGENCY ATTRIBUTION
Prove `member_id` remains outside Directory User/profile.

### AUTHORIZATION SEPARATION
Evidence OrganizationRole/external claims do not grant local permissions.

### DATABASE
Backend/version, clean migration, upgrade result, actual constraints.

### REFERENCE SCENARIOS
Operator dual-auth / Agency / Provider / Multi-membership / lifecycle denial.

### TESTS
Exact counts and commands.

### ARCHITECTURE
Package boundaries and dependency result.

### CHANGES TO EXISTING V0.2 CONTRACTS
List only additive integration changes, if any.

### NON-BLOCKING LIMITATIONS
Only intentional v1 scope limitations.

### BLOCKERS
Must be empty for acceptance.

### NEXT
State only that Directory v1 is ready for use by subsequent real application migrations. Do not start reporting or actual NCR/Insurance/Legal migration.

End with exactly one:
`DIRECTORY V1 STATUS: PASS`
or
`DIRECTORY V1 STATUS: NOT READY`

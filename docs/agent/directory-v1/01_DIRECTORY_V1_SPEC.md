# INTL Directory v1 — Frozen Functional & Architecture Specification

## 1. Problem being solved
INTL Platform already has:
- `external-auth` / `external-auth-laravel` for external authentication identity/session boundaries;
- local authentication paths in hosts where applicable;
- `authorization-laravel` for local RBAC/permissions;
- `audit-laravel` for activity logging.

What is missing is one shared local model for the actors/entities known by each microservice.

Directory v1 provides that local identity/profile layer.

It is NOT an authentication package and NOT an authorization package.

## 2. Core model
Directory v1 has four business concepts:
1. `Organization`
2. `OrganizationRole`
3. `User`
4. `Membership`

A fifth technical concept is allowed for mapping authenticated/source identities into Directory:
5. source/external reference mapping

The mapping concept is infrastructure/bridge data, not a fifth business actor type.

## 3. Organization
An `Organization` is a real organizational entity known by the local microservice.

Examples:
- the organization operating the platform;
- an organization whose services/processes are delivered through the platform;
- an agency/intermediary.

Do NOT model these as three different organization entity types.

Recommended shared v1 profile:
- `id`
- `name`
- `status`
- optional `email`
- optional `phone`
- timestamps in Laravel persistence

Status:
- `active`
- `inactive`

Do NOT add speculative common fields such as address, tax ID, logo, preferences, departments, metadata JSON, or business-specific agency/provider fields unless existing repository evidence makes one strictly necessary.

An Organization may exist without any local User.
An inactive Organization cannot resolve to an active runtime actor.

## 4. OrganizationRole
Organization roles define the Organization's place in the INTL ecosystem.

Initial values are exactly:
- `operator`
- `provider`
- `agency`

Meaning:
- `operator`: organization that operates/manages the platform;
- `provider`: organization whose service/process is delivered through the platform;
- `agency`: intermediary/agency acting in request flows.

These are DOMAIN ROLES, not Authorization roles.

An Organization may hold more than one OrganizationRole.

Do NOT implement `organization.type = agency` as the source of truth.
Use a many-to-many/set-style role relationship.

OrganizationRole MUST NOT:
- imply permissions;
- assign Spatie roles;
- grant API access;
- be derived from untrusted External Auth role claims.

## 5. User
A `User` is a locally known human/person profile in the Directory.

Recommended v1 fields:
- `id`
- `display_name`
- `status`
- optional `email`
- optional `phone`
- timestamps in Laravel persistence

Status:
- `active`
- `inactive`

Do NOT store inside Directory User:
- password/password hash;
- authentication credentials;
- SSO ticket/token/session;
- Spatie/local Authorization roles;
- permissions;
- `organization_id` as a direct single-owner field;
- external agency `member_id`.

A User may belong to one or more Organizations through Membership.

The same User may be reached through more than one authentication source.

Example requirement:
Local login -> User U-10
External/Epishkhan login -> same User U-10

Directory profile must remain one User.

## 6. Membership
Membership represents:

User <-> Organization

Recommended v1 fields:
- `id`
- `user_id`
- `organization_id`
- `status`
- timestamps

Status:
- `active`
- `inactive`

Required invariant:
`UNIQUE(user_id, organization_id)`

Membership is NOT an authorization-role table.

Do NOT add:
- permissions;
- Spatie roles;
- job title;
- department;
- invitation state;
- ownership flags;
- team management

in v1.

An inactive Membership prevents that User from acting for that Organization.

If a User belongs to multiple active Organizations, Directory MUST NOT silently choose one. Explicit Organization context is required.

## 7. Agency semantics
Current agency authentication comes from the central Epishkhan/pre-counter flow.

Business identity:
`Agency Organization`

Example:
Organization:
- id = ORG-500
- role = agency

An authenticated session may also contain:
`member_id = M-7821`

That `member_id` is NOT a Directory User.
It is attribution/context information identifying which external member acted on behalf of the Agency.

Required runtime result:
- `organization_id = ORG-500`
- `user_id = null`
- external `member_id` remains outside Directory

Do not create a Directory User automatically for an agency member.

Future agency-local Users may be added later through User + Membership without changing Organization architecture.

## 8. Provider semantics
Current provider-side actor may be the Organization itself.

Example:
Organization:
- id = ORG-200
- role = provider

Current runtime may resolve:
- organization_id = ORG-200
- user_id = null

Future provider Users can be added with User + Membership without changing Organization.

Do not call this role `government`, `government_organization`, `service_organization`, or `service_owner`.

The accepted domain term is:
`provider`

## 9. Operator semantics
The organization that owns/operates/develops the platform is represented as:
OrganizationRole = `operator`

The operator currently has local User(s).

Example:
Organization ORG-001
- role = operator

User U-10
Membership U-10 -> ORG-001

The same operator User may authenticate through:
- local authentication;
- external/Epishkhan authentication.

Both paths must resolve to the same Directory User and same Organization.

Do not model "Platform" as a separate business entity type.

## 10. Resolution output
The pure runtime resolution value should be equivalent to:
`ResolvedDirectoryActor`

with:
- `organizationId` required
- `userId` nullable

Do not put Authorization roles or permissions in this value.

Do not put external agency `member_id` in this value unless repository constraints make a separate attribution context impossible. Preferred design keeps it outside Directory.

## 11. Authentication boundary
Directory receives authenticated references/identity facts.

Directory does NOT:
- verify passwords;
- exchange SSO tickets;
- introspect external sessions;
- revoke sessions;
- trust external roles as local authority.

Existing authentication packages remain authoritative for authentication.

Preferred direction:

Local Auth --------\
                    -> authenticated source identity/reference
External Auth -----/
                    -> Directory mapping
                    -> DirectoryResolver
                    -> ResolvedDirectoryActor
                    -> Principal / ActorContext
                    -> Authorization

Do NOT make pure `intl/directory` depend on `external-auth`.

## 12. Source/external reference mapping
Directory Laravel needs a mapping mechanism that can map source identifiers to Directory targets.

The exact class name may follow repository conventions.

Conceptual fields:
- `source`
- `reference_kind`
- `external_id`
- target Organization OR target User
- timestamps

Typical examples:

Epishkhan agency identifier:
- source = epishkhan
- reference_kind = organization
- external_id = AG-728
- target = Organization ORG-500

Epishkhan person subject:
- source = epishkhan
- reference_kind = subject
- external_id = USER-93
- target = User U-10

Mapping must not authenticate the source.
Mapping must be deterministic and fail closed.

Prefer DB referential integrity to Organization/User targets where practical.
Avoid an unconstrained polymorphic design if repository conventions allow stronger FK integrity.

Required uniqueness should prevent one source/reference-kind/external-id from ambiguously mapping to multiple active targets.

Do not assume external agency member_id should be mapped.

## 13. Runtime resolution rules
Organization actor resolution:
Organization exists + active
-> ResolvedDirectoryActor(organizationId, null)

User actor resolution:
User exists + active
AND Organization exists + active
AND Membership(user, organization) exists + active
-> ResolvedDirectoryActor(organizationId, userId)

Fail closed when:
- Organization missing;
- Organization inactive;
- User missing;
- User inactive;
- Membership missing;
- Membership inactive;
- source reference unknown;
- source reference ambiguous;
- User has multiple memberships and no explicit organization context is supplied.

Never choose "first membership".

## 14. Relationship to Authorization
Directory answers:
"Who is this locally and which Organization are they acting for?"

Authorization answers:
"What may they do?"

OrganizationRole is not permission.

Examples:
`OrganizationRole::AGENCY`
must NOT automatically grant:
- request.create
- request.view
- document.upload

External claim:
`role = admin`
must NOT automatically create:
- OrganizationRole::OPERATOR
- local admin role
- permission

Keep current `authorization-laravel` authority intact.

## 15. Relationship to ActorContext / Principal
Inspect current `Principal` and `ActorContext` before modifying.

Prefer no change if current contracts can represent:
- organization identity;
- optional local User identity.

If change is necessary:
- additive only;
- backward compatible;
- preserve existing v0.2 callers/tests.

Do not redesign Authorization.
Do not make Directory own Principal or ActorContext.

## 16. Relationship to ServiceRecipient
Directory User/Organization is NOT ServiceRecipient.

A request actor may create a request for a different recipient.

Preserve:
Directory actor != service recipient

Do not merge packages or tables.

## 17. Local database independence
Every host/microservice has its own Directory data.

Shared package != shared central Directory DB.

Examples:
NCR DB:
- its local organizations/users/memberships/references

Insurance DB:
- its own independent copies

Legal DB:
- its own independent copies

Do not introduce cross-microservice Directory calls or a central business DB.

## 18. Out of scope for Directory v1
Explicitly do NOT implement:
- reporting;
- analytics;
- activity dashboards;
- invitation flows;
- teams;
- departments;
- user-owned API keys;
- password authentication;
- MFA;
- SSO protocols;
- authorization role management;
- permission management;
- tenancy/workspaces;
- user preferences;
- avatar/media;
- organization branding;
- organization-specific business fields;
- agency member directory;
- permanent deletion;
- merge/deduplication engine.

Audit integration should be limited to safe lifecycle events if current platform conventions require it.

## 19. Required v1 reference scenarios
The implementation must prove:
1. Operator User via local auth resolves to User + operator Organization.
2. Same Operator User via external auth resolves to the same Directory User + same Organization.
3. Agency external organization reference resolves to agency Organization + `userId=null`.
4. Agency external `member_id` does not create a Directory User.
5. Provider Organization can resolve with `userId=null`.
6. Organization may have multiple OrganizationRoles.
7. Inactive Organization fails closed.
8. Inactive User fails closed.
9. Inactive Membership fails closed.
10. User with multiple active Memberships requires explicit Organization context.
11. Unknown source reference fails closed.
12. External role claim cannot grant OrganizationRole or local permission.
13. OrganizationRole assignment does not grant Authorization permissions.
14. Existing v0.2 authentication/authorization flows remain green.

This specification is frozen for Directory v1 unless actual repository constraints trigger a HARD STOP defined in `00_RUNBOOK.md`.

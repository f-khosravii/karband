# INTL Platform Architecture Baseline (v0.2-baseline)

## 1. Purpose & Scope

The INTL Platform provides decoupled, reusable domain libraries and Laravel adapter packages for building independent public-sector electronic government services and workflow applications.

---

## 2. Package Inventory & Layering

The platform comprises 21 Composer packages strictly divided into three tiers:

### Tier 0: Platform Contracts
- `intl/contracts`: Framework-free core interfaces, Value Objects, and Shared Types (`ActorContext`, `AccessRequest`, `AuditEvent`, `HealthCheckInterface`).

### Tier 1: Pure Domain Packages (Framework-Free)
1. `intl/workflow`: Deterministic state-machine engine, graph transition definitions, action authorization interfaces.
2. `intl/directory`: Local organization, user, membership, and organization role model with fail-closed runtime resolution value objects.
3. `intl/service-catalog`: Service definitions, versioning, pricing modes, request policies, workflow bindings.
4. `intl/dynamic-forms`: Schema validation for flat forms, sections, repeatable groups with `_item_id`, and sibling conditional fields.
5. `intl/documents`: Document slot definitions, MIME/size rules, conditional requirements (`required_when`, `required_at`), and upload validators.
6. `intl/billing`: Financial value objects (`Money`), two-stage preparation/confirmation commands, payment/refund status enums.
7. `intl/service-recipients`: Citizen/company identity hashing, encryption contracts, and authority verification policies.
8. `intl/service-requests`: Request models, request policies, structured correction scopes, quote revision lifecycle.
9. `intl/external-auth`: External identity representations, SSO ticket validation abstractions, session status transitions.
10. `intl/request-journey`: Multi-step UX navigation engine (`linear`, `free`), step state derivation (`LOCKED`, `AVAILABLE`, `CURRENT`, `COMPLETED`, `SKIPPED`), prerequisite checking.

### Tier 2: Framework Integration Packages (Laravel Adapters)
1. `intl/authorization-laravel`: Spatie-backed microservice-local RBAC, `Principal`, `ActorContext`, scope checkers.
2. `intl/audit-laravel`: Spatie-backed tamper-evident structured activity logging.
3. `intl/directory-laravel`: Microservice-local database persistence for organizations, users, memberships, and external reference mappings.
4. `intl/workflow-laravel`: Eloquent workflow persistence, optimistic concurrency control, transaction management.
5. `intl/service-catalog-laravel`: Catalog repository, JSON settings parsing, revision activations.
6. `intl/dynamic-forms-laravel`: Answer persistence, versioned form drafts, structured correction validation.
7. `intl/documents-laravel`: Document storage, versioning, replacement policies, file safety scanner integration, withdrawal audit.
8. `intl/billing-laravel`: Payment intents, two-stage payment processing, idempotency locking, central gateway bridge, refund service.
9. `intl/service-recipients-laravel`: Encrypted recipient storage, blind indexing, identity verification bridge.
10. `intl/service-requests-laravel`: Request lifecycle orchestrator, request quote management, structured correction rounds, transition gates.
11. `intl/external-auth-laravel`: Session store, token-to-principal resolution, introspection, session lifecycle tracking.
12. `intl/request-journey-laravel`: Journey definition registry, default fact provider, step visitation persistence.

---

## 3. Key Invariants & System Boundaries

### A. Workflow Authority
- `Workflow` remains the single authoritative business-state engine. Subsystems (Quote, Billing, Documents, Journey, External Auth, Directory) cannot silently overwrite or mutate workflow instances.

### B. Directory v1 Local Identity Boundary
- **Core Business Concepts**: `Organization`, `OrganizationRole` (`operator`, `provider`, `agency`), `User`, and `Membership` (`user <-> organization`).
- **External Reference Mapping**: Maps external/source credentials (e.g. Epishkhan agency or subject identifiers) to local Directory targets.
- **Fail-Closed Resolution**: Runtime actor resolution (`ResolvedDirectoryActor`) requires active organization, active user, active membership, and explicit organization context when a user holds multiple memberships. No first-record fallback.
- **Authentication Boundary**: Directory does not authenticate users, verify passwords, or manage SSO sessions. Local auth and external auth produce authenticated facts that resolve into Directory profiles.
- **Agency Attribution**: External agency `member_id` remains caller attribution metadata and is NOT automatically converted into a Directory User.
- **Authorization Separation**: `OrganizationRole` is a domain classification, not a permission. Neither `OrganizationRole` nor external claims grant local Spatie permissions by themselves.
- **ServiceRecipient Separation**: Directory actors represent the requester/operator/provider organizations and staff; `ServiceRecipient` represents the citizen or legal entity receiving the public service.
- **Local Database Independence**: Each microservice maintains its own local Directory database. No central cross-service business database is required.

### C. Authorization & External Auth
- Authorization is strictly microservice-local. External SSO (e.g. Epishkhan) authenticates identity and issues tickets; the host resolves tickets into a local `Principal` and `ActorContext`. External role claims cannot elevate local permissions.

### D. Two-Stage Billing V2
- Payment execution operates under a two-stage pattern (`prepare` -> `confirm` / `cancel`) with strict idempotency keys. Network uncertainties require status reconciliation against the central gateway.
- Billing holds zero knowledge of Request Quote persistence tables; quotes are passed via trusted domain providers.

### E. Request Quote
- Quotes follow monotonic revisions (`revision = 1, 2, ...`). Once accepted, a quote revision is immutable. Cross-agency proposal/acceptance isolation is enforced. Quote acceptance does not auto-execute payment.

### F. Dynamic Forms v2 & Structured Corrections
- Form schemas support Sections and flat Repeatable Groups. Repeatable items maintain persistent, stable `_item_id`s across reorders.
- Structured corrections support granular path scopes: `field`, `children.*.field`, `children.<id>.field`, `children._add`, `children.*._remove`, `children.<id>._remove`. Unmentioned fields and unapproved item mutations are rejected.

### G. Documents v2
- Slot requirement evaluation (`required_when`, `required_at`) is decoupled from action authorization (`upload`, `replace`, `withdraw`). Withdrawn files preserve history but are excluded from active set completeness.

### H. Request Journey
- Journey is a purely derived, read-only UX projection. It cannot trigger workflow transitions or mutate business data.
- Accessible completed steps remain revisit-able: when visited, `last_visited_step_key` updates the current UX pointer without altering step completion facts.

---

## 4. Reference Scenarios Provenance

| Scenario | Legacy-Derived Facts | Synthetic Platform Additions | Packages Exercised |
| :--- | :--- | :--- | :--- |
| **NCR** | Free navigation, Section grouping, Repeatable `spouses[]` & `children[]`, Stable identity across reorder, Wildcard and exact child corrections, No payment. | Synthetic test agency IDs, mock file uploads. | `workflow`, `directory`, `service-requests`, `dynamic-forms`, `documents`, `request-journey` |
| **Insurance** | Linear flow, Dynamic vehicle/policy forms, Request Quote negotiation, Quote acceptance, Policy document upload, Two-stage billing. | Synthetic insurance provider fees, test payment gateway adapter. | `workflow`, `directory`, `service-catalog`, `service-requests`, `billing`, `documents`, `request-journey` |
| **Legal** | Formal power-of-attorney representation snapshot, Representative identity verification, Strict document slot authorization. | Synthetic lawyer accreditation registry bridge. | `service-recipients`, `workflow`, `directory`, `service-requests`, `documents`, `authorization` |
| **Tax** | Demand/filing request flow, Conditional document submission, Assessment fee quote, Two-stage fee capture with timeout reconciliation. | Synthetic central tax payment gateway timeout simulations. | `workflow`, `directory`, `service-requests`, `billing`, `documents`, `audit` |
| **Platform E2E** | N/A (Fully Synthetic Platform Verification) | Complete end-to-end integration: SSO Auth -> Directory Resolution -> Draft -> Repeatable Forms -> Docs -> Correction Cycle -> Quote -> Post-Submit Contract -> Two-Stage Billing -> Final Cert -> 100% Journey. | All 23 packages |

---

## 5. Migration Strategy & Upgrades

- All database migrations are additive. Existing records remain readable without synthetic backfills.
- Historical Billing v1 records preserve audit history; new columns default safely.
- Journey progress and Quote revisions are created on demand and not backfilled onto historical requests.
- Directory migrations add `intl_directory_*` tables cleanly without modifying existing business data or performing speculative backfills.
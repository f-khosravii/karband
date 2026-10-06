> **Phase 14 update:** This README describes the original Phase 8 baseline. For corrected price-per-attempt, full-refund policy, new bindings and API signatures, FIRST read `docs/PHASE14_BILLING_PRICE_REFUND_FA.md`. Older retry/price-change/refund limitations below are superseded.

# INTL Billing Laravel Adapter

## What this package IS

A local payment intent/audit state machine and an isolated **port** for a central payment service. Each government organization's microservice has its own DB. The *only* intentional shared runtime dependency is the external **central payment API**, accessed through `CentralPaymentGateway`. No separate organization's DB or service is touched. No central payment credentials, API route or operational gateway implementation are shipped: the real API schema and its security guarantees are not supplied yet.

`create()` records only a local `ready` intent, taking the trusted amount and eligibility from the host's `TrustedQuoteProvider`. `dispatch()` first commits an immutable external request key and a `processing` state, and **only then** calls the central provider outside the DB transaction. It can call charge **at most once per attempt**. On timeout, crash or uncertain status use `reconcile()`, which **only** calls lookup with exactly the same key, never a second charge. If the central API cannot guarantee idempotent charge and authoritative lookup by key, DO NOT enable payment dispatch in production.

A central-proven definitive **NO-DEBIT failure** may be retried using `retryFailed()`; that creates a NEW attempt and NEW central idempotency key. An unknown outcome must NEVER be retried as another charge, even when a customer says the debit did not happen. Escalate unresolved unknown attempts to audited manual investigation. Local `succeeded` should be recorded only after a verified provider confirmation. Host Workflow should treat `TrustedPaymentFacts::isConfirmed()` as a trusted backend fact; this adapter **never** changes Workflow state directly.

## Required host bindings: fail closed

- `Intl\AuthorizationLaravel\Contracts\PrincipalProvider` and `Intl\AuthorizationLaravel\Contracts\ScopeChecker`: actual host guard and real agency ownership; provided by host app. `AuthorizerInterface` is bound by `intl/authorization-laravel` (Spatie).
- `TrustedQuoteProvider`: re-check local resource existence, service, agency, current payable Workflow state and **authoritative quoted amount**. A request body price or resource owner is never trusted. Re-check happens on creation, dispatch and retries. The host must coordinate payable Workflow transitions against an in-flight payment to avoid charging when the resource is simultaneously cancelled.
- `CentralPaymentGateway`: implement against the REAL central **payment service** after receiving its authenticated endpoints, currencies/units, status codes, idempotency guarantees, lookup consistency rules and timeout policy. Charge MUST be idempotent by `PaymentCommand::requestKey`; lookup MUST be authoritative for that same key. There is **no default permissive implementation**.
- Set `INTL_PAYMENT_CLIENT_NAMESPACE` to a stable deployment-unique identifier (not a secret). It is stored per intent and cannot change for old records. Production must configure credentials via secret manager, TLS, verified server identity, bounded timeouts and appropriate logs *in this gateway*, never in local request records.

No roles are auto-created or auto-granted. Permissions: `billing.payment.create`, `.view`, `.execute`, `.reconcile`, `.retry` (full canonical names shown in Sandbox tests). By default only host-selected `agency` may create, execute or retry. Reconciliation and reads may be granted to explicitly authorized host roles subject to `ScopeChecker`; platform admin is **not** superuser.

## Local schema and semantics

`intl_payment_intents`: a single intent per (`resource_type`,`resource_id`) in v1; stores only identifying metadata, agency ID, original quote, stable external request namespace, local user idempotency key, attempt, processing/unknown/pending/succeeded/failed status and confirmed central reference. It DOES NOT store wallet balance, wallet ID, debit instructions, provider credentials or copies of central ledger records.

`intl_payment_events`: local append-only state transition journal (no sensitive provider payload). Data is always local to the deployed microservice. Locks and unique DB indexes protect claims and idempotency; final guarantees must be checked for each deployed RDBMS. A `processing` attempt whose PHP worker crashes stays in that state; an authorized reconciliation job after the configured delay (default 60 seconds) must query the provider. Implement operational monitoring/alerts for unresolved attempts and failures to write confirmed results. There is no background worker or timer silently installed by this package.

## Limitations before production

- The real central payment API contract was **not provided**. Therefore the `CentralPaymentGateway` is an interface only; Sandbox uses a fake. No live ePishkhan call can or should run from this patch.
- A local database transaction and remote payment are never a distributed atomic transaction. Recovery requires provider idempotency+lookup, durable local DB, and scheduled reconciliation (with operational monitoring). A 404/not-found cannot be treated as definitive failure unless the provider formally guarantees finality and no debit; safest mapping is `unknown`.
- v1 allows one local payment intent per host resource; multiple fees, partial/refund/cancellation after success, price revisions after payment, formal invoices, audit retention policy, disputes and chargebacks require separate future design. A changed price causes manual review, not a silent new charge.
- Do not implement HTTP routes, add migrations to NCR, or automate Workflow transitions at package install. Host application owns its request controllers, eligible-state policy and idempotent Workflow action after `isConfirmed()`.

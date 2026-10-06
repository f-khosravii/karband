# INTL Service Requests Laravel

This is a per-microservice registry and a **thin orchestrator**, not a second Workflow, Forms, Documents or Billing implementation. One local DB connection is mandatory across request, catalog, workflow, form and document metadata for atomic initial creation. Files are uploaded separately; central payment operations must NEVER occur in an open local DB transaction.

**Host-required bindings**: `RecipientVerifier`, `PrincipalProvider`, `ScopeChecker`, `ActorResolver`, `WorkflowAuthorizer`, `ReplacementPolicy`, `FileSafetyScanner`. `ResourceVerifier` for Documents defaults narrowly to `service_request` and may be replaced by host with a stricter verifier. No default-allow policy is shipped. Host must define Spatie permissions/scopes for `requests.create` on `catalog.service`, `requests.view` on `service_request`, and all permissions already required by dependent modules.

**Creation prerequisites**: published Workflow, published form (>=1 field) and published document requirement set (may be empty), active/entitled local service and trusted recipient relation. Idempotency is per-agency. Failed creation must roll back request, pins and workflow. For edit and submit, host must configure trusted Workflow guards checking pinned form/document completeness and rules; the request registry intentionally does not invent universal business rules.

**Payment**: `createPaymentIntent()` creates only a local intent via the Billing package and its host-supplied `TrustedQuoteProvider`; dispatch/reconcile are separate authorized Billing operations and are never hidden inside RequestService. Host must wire trusted Billing confirmation into Workflow guards. The central payment gateway remains unimplemented until its real API contract is verified. Never interpret network timeout as failure or retry a charge with a new key.

**Recipient**: only `recipient_type`+`recipient_id` opaque references are stored. The verified person's business data remains in each host microservice's own DB; no recipient user account is created.

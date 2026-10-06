# INTL Audit Laravel

Uses [Spatie Laravel Activitylog v5](https://spatie.be/docs/laravel-activitylog/v5/introduction) rather than writing a competing audit engine.

* Every microservice stores its audit records **locally** using its own DB and Spatie's `activity_log` table.
* No central audit service, no cross-government connection, no wallet data, no routes and no public read endpoint.
* Explicit business and security events only; no automatic broad model logging. Existing Workflow history remains the canonical transition history.
* `AuditRecorderInterface` and sanitized `AuditEvent` live in existing `intl/contracts`.
* The adapter uses the host's `PrincipalProvider` from `intl/authorization-laravel`, matches it against the actual authenticated guard user and confirms the selected role is assigned. It never accepts user-supplied role or organization details.
* Extend `config('intl-audit.allowed_events')` in the individual service as new events are integrated.

```php
use Intl\Contracts\Audit\AuditEvent;
use Intl\Contracts\Audit\AuditRecorderInterface;

$recorder = app(AuditRecorderInterface::class);
$recorder->record(new AuditEvent(
    'catalog.service.activated',
    'service',
    'opaque-service-code',
    ['correlation_id' => 'trace-123', 'result' => 'success'],
));
```

The calling application still has to **authorize** actions with INTL Authorization and apply ownership checks; an audit event does not grant a permission. A denied access event is explicitly recorded only when the host has a trusted principal. Anonymous login failures, job/service actors, and any mandated tamper-evident external archive require a separate deliberate implementation; do not forge a logged-in user to capture them.

For local success actions, record *synchronously on the same database connection inside the application's transaction*. Do not turn on Spatie activity buffering or offload the audit write to an asynchronous queue for these actions. Never put an external payment API call inside that transaction; log external outcomes from durable Billing results. Failed/denied business attempts that roll back need an explicit audit write after rollback, using the verified current principal. This package does not ensure atomicity across external systems.

Audit records are database rows, **not cryptographically tamper-proof**. Enforce DB access restrictions, backups, audit viewer policies and retention requirements in each organization. Before running cleanup (`activitylog:clean`), review retention mandates; never silently apply the library's default retention as a legal policy. Do not log customer information, document contents, payment credentials, request body, raw errors, passwords, API keys or tokens. Use opaque subject references only.

Spatie's standard migration supports integer polymorphic actor IDs. Review UUID/ULID models and adjust its published migration **before** migrating. In applications with several auth guards, the host's `PrincipalProvider` must use the actual guard. If your auth accounts are not Eloquent models, add a specifically reviewed adapter rather than weakening principal verification.

See `docs/AUDIT_INSTALL_LATER_FA.md` for future installation steps and known limitations.

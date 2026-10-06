# ADR-0005: Schema Clean Break & Migration Consolidation

Status: Accepted

## Context

The repository has never been deployed to production or shared with external consumers. Previous development accumulated iterative, fragmented migrations and prefixed tables with `intl_` (e.g., `intl_workflows`, `directory_users`, `intl_payments`), along with complex migration compatibility layers.

## Decision

1. **Clean Break - Zero Legacy Compatibility**:
   - Because there are no existing production deployments or legacy databases to support, all migrations will be consolidated into clean initial migrations per capability adapter package.
2. **Remove `intl_` Table Prefixes**:
   - Table names will use standard domain-scoped naming without the legacy `intl_` prefix (e.g., `workflows`, `directory_users`, `payments`, `service_requests`).
3. **Capability Migration Isolation**:
   - Each `<capability>-laravel` package owns and provides the migrations for its tables only.
   - Spatie third-party migrations (permission tables and activity log tables) are consolidated directly into `authorization-laravel` and `audit-laravel`.
4. **Relational Backend Baseline**:
   - All migrations must execute cleanly and identically from scratch on both SQLite (for local unit testing) and MariaDB / MySQL (intended relational production/integration backend).

## Consequences

- Clean, readable database schema without historical migration debt.
- Simplified schema maintenance and reduced testing overhead.
- Total separation of capability persistence.

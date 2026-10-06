# ADR-0003: Directory Identity & Recipient Separation

Status: Accepted

## Context

Public-sector electronic services require clear distinction between organizational actors (operators, providers, agency officers) who process and handle service requests, and service recipients (citizens, legal representatives, companies) on whose behalf requests are filed.

Previously, identity concepts were occasionally conflated between internal users, SSO tokens, external agencies, and citizen recipients.

## Decision

1. **Separation of Directory vs Service Recipient**:
   - **`directory`**: Authoritative domain for organizations, users, memberships, and organizational roles (`operator`, `provider`, `agency`). Handles internal staff and partner organizations.
   - **`service-recipients`**: Authoritative domain for citizens and legal entities receiving public services. Handles encrypted identity storage, blind indexing, and government authority verification.
2. **Fail-Closed Actor Resolution**:
   - Runtime actor resolution (`ResolvedDirectoryActor`) requires an active user, active organization, and active membership.
   - When a user holds multiple memberships, explicit organization context must be provided. First-record fallback is strictly forbidden.
3. **External Authentication Decoupling**:
   - Directory does not verify passwords or manage SSO tokens.
   - External SSO adapters (e.g. Epishkhan) authenticate identity and issue tokens. Host middleware resolves authenticated tokens into local `Principal` and `ResolvedDirectoryActor` context.
4. **Agency Attribution vs Directory Users**:
   - External agency metadata (`member_id`, agency codes) represents request caller attribution and is not automatically converted into local Directory user accounts.

## Consequences

- Clear domain boundaries between internal actors and external citizen recipients.
- Robust security model with fail-closed multi-tenancy/membership resolution.
- Independent microservice-local directory databases without centralized cross-service coupling.

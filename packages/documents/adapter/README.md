# karband/documents-laravel

Private, append-only, authorization-bound documents for a **single independently deployed** INTL microservice. No central API, no shared storage, no HTTP endpoints, no public URL and no role permission seeding. Depends on `karband/documents`, `karband/contracts`, `karband/authorization-laravel` and `karband/service-catalog-laravel`.

- `DocumentRegistry`: `saveDraft` / `draft` / `publishDraft` / `published` / `currentForAgency`. Immutable published definitions, optimistic draft revision and server MIME/size allowlist.
- `DocumentManager`: `reserve()` pins the published definition before files are uploaded. `upload()` only works on an existing set, checks real MIME by `finfo`, maximum size, resource ownership/permission and revision; one document per named slot. The first upload fills an empty slot. Replacement appends a new immutable revision **only** when a host-bound `ReplacementPolicy` confirms trusted correction status. A host-bound `ResourceVerifier` must verify the real resource belongs to the service. No silent allow defaults. `snapshot`, `history`, `open` require explicit resource-level view permission.
- `TrustedDocumentFacts::isComplete()` is privileged internal backend code for the host's trusted Workflow fact provider; it must NEVER be exposed via an unauthenticated route.
- `Storage::disk(config('documents.private_disk'))` must be private. Default Laravel 13 `local` is `storage/app/private`, but verify your installation. No direct public URLs. Physical blobs are not automatically deleted on replacement. Failed transactions attempt newly-written-blob cleanup; production still needs orphan reconciliation.
- No documents seeded, no automatic network connectivity. No bundled virus scanner, OCR, antivirus, encryption, retention/purge, signed download controller or background uploader is included. You must bind a real `FileSafetyScanner`; this package fails closed if it is not bound. Define legal retention and encryption before handling real citizen documents.

Permissions (explicitly seed on the host, not automatically): `documents.definition.edit`, `documents.definition.view`, `documents.definition.publish`, `documents.file.upload`, `documents.file.view`, and `catalog.service.use`. Host `ScopeChecker` must apply agency ownership to every file action. Admin roles do not bypass this check.

See `docs/DOCUMENTS_INSTALL_FA.md` for setup, migration, tests, and trusted correction policy integration.

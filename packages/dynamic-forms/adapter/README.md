# karband/dynamic-forms-laravel

For a **single independent microservice and its own local database**. Depends on `karband/dynamic-forms`, INTL Authorization, and INTL Service Catalog. No central service, tenancy tables or panel routes are included.

`FormRegistry` offers `saveDraft($form, $expectedRevision)`, `draft()`, `publishDraft($serviceCode, $expectedRevision)`, `published($serviceCode,$version)`, `currentForAgency($serviceCode)`. Draft writes are optimistic, versions published once, and rules must be registered before publication.

`FormAnswers` offers `reserve($serviceCode, $resourceType, $resourceId)` at request creation, `save(..., $answers, $expectedRevision)`, and `read(...)`. Resource access always goes through the host's authenticated principal + Spatie permission + trusted `ScopeChecker`. New answers require an active, entitled service; existing forms continue using the version pinned at reserve/first save, even after the service is deactivated.

**Important:** A request created before a new form is published but only answered after publication MUST call `reserve()` when first creating the request; otherwise a late first `save()` uses the newest version. Wrap `reserve()` and the host's workflow start inside the **same outer local database transaction**, and verify all components share the same connection. This package deliberately does not silently modify the Workflow adapter.

Sensitive data: the package stores answer JSON in the local DB. The host must implement applicable encryption-at-rest, retention/deletion policies, output escaping and audit/access reviews before production usage. No HTTP endpoints are registered: the host may build the admin panel on the authenticated services.

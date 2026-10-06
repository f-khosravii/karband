# intl/service-catalog

Framework-independent, category-free service definitions. Each service is owned by one independently deployed microservice; no multi-organization tables, no central runtime, no service-version history.

- Immutable lowercase service code; editable name, description, non-secret JSON settings, and published workflow binding.
- Lifecycle: `inactive -> active -> inactive`; `inactive -> archived` is final.
- Activation requires a workflow binding. Laravel adapter verifies the binding is actually published.
- `revision` is an optimistic concurrency number, **not** service-definition versioning.
- Existing workflow instances keep their already pinned workflow version even when a service's binding changes.

Run core tests: `./vendor/bin/phpunit -c packages/service-catalog/phpunit.xml`.

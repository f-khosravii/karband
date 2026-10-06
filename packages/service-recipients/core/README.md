# karband/service-recipients

Framework-independent types/contracts. No country-specific identity format, no Laravel, no customer login, no shared database. `RecipientRules` **must** be provided by each independent microservice. `Identity::digest()` requires a stable dedicated deployment HMAC key; the database lookup is also agency-scoped. Does not manage form answers, workflows, customer snapshots, or corporate authority verification.

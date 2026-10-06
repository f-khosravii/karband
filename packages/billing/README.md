# INTL Billing Core

Independent of any framework, DB, ePishkhan API or internal wallet semantics.

- `Money`: positive integer in **provider-agreed monetary unit**, ISO-like three-letter currency. No floats. Confirm the unit with the REAL central API contract (do not assume IRR/rials/tomans).
- `PaymentCommand`: the external request describes a **payment** tied to a service/resource/agency. No wallet ID, wallet tables, balance operations or wallet transfers.
- `PaymentOutcome`: `succeeded` **requires** a trusted central reference. `failed` is **only** a conclusive no-debit result. Unknown/timeout/5xx/not-found/ambiguous response is always `unknown`.
- `PaymentStateMachine`: successful and definitively failed results cannot be overwritten by delayed responses. Ready is local and must not be finalized without a dispatch claim.

Core unit tests: `./vendor/bin/phpunit -c packages/billing/phpunit.xml` (after later installation). Standalone smoke: `php packages/billing/tools/standalone-smoke.php`.

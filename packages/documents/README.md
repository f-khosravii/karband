# intl/documents

Framework-independent single-file-per-slot document requirements and upload metadata validation. Service-specific immutable definitions are versioned and can have required and optional slots. No Laravel, disk, controller, auth, workflow, or network dependency.

PHP 8.4. An empty definition is allowed. Slots use distinct codes; use multiple slots for multiple required documents. The Laravel adapter enforces its own administrator-configured MIME allowlist, MIME sniffing and private storage.

```bash
./vendor/bin/phpunit -c packages/documents/phpunit.xml
php -d zend.assertions=1 -d assert.exception=1 packages/documents/tools/standalone-smoke.php
```

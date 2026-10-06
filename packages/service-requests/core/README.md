# INTL Service Requests (Core)

Pure PHP 8.4 request identity and immutable snapshot value objects. This package deliberately does not own request status, workflows, file storage, prices, wallet concepts, authentication, recipient PII or inter-organizational infrastructure.

`RequestDraft` accepts only service code, an opaque business `RecipientReference` and a retry-safe creation key. Agency and acting user MUST come from trusted host authentication. Canonical resource type for dependent modules: `RequestResource::TYPE` (`service_request`).

<?php

declare(strict_types=1);

namespace Karband\Audit;

use InvalidArgumentException;

/** A deliberate, sanitized domain/security event. Never pass user-supplied raw payloads. */
final readonly class AuditEvent
{
    /** Metadata allowlist. Values must be short opaque tokens, not messages or personal data. */
    private const SAFE_KEYS = [
        'correlation_id', 'operation_id', 'service_code', 'workflow_version',
        'from_state', 'to_state', 'result', 'reason_code', 'revision',
    ];

    /** @param array<string, string|int> $metadata */
    public function __construct(
        public string $code,
        public string $subjectType,
        public string $subjectId,
        public array $metadata = [],
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*){1,4}$/D', $code) || strlen($code) > 100) {
            throw new InvalidArgumentException('Audit event code must use dot-separated machine tokens.');
        }
        if (!preg_match('/^[a-z][a-z0-9_.-]*$/D', $subjectType) || strlen($subjectType) > 64) {
            throw new InvalidArgumentException('Audit subject type must be a machine token.');
        }
        if (!preg_match('/^[A-Za-z0-9_.:-]+$/D', $subjectId) || strlen($subjectId) > 160) {
            throw new InvalidArgumentException('Audit subject ID must be an opaque, non-sensitive reference.');
        }
        if (count($metadata) > count(self::SAFE_KEYS)) {
            throw new InvalidArgumentException('Too many audit metadata fields.');
        }
        foreach ($metadata as $key => $value) {
            if (!in_array($key, self::SAFE_KEYS, true)) {
                throw new InvalidArgumentException('Unapproved audit metadata field: ' . (string) $key);
            }
            if ((!is_string($value) && !is_int($value)) ||
                (is_string($value) && (!preg_match('/^[A-Za-z0-9_.:-]+$/D', $value) || strlen($value) > 160))) {
                throw new InvalidArgumentException('Audit metadata values must be short opaque tokens.');
            }
        }
    }
}

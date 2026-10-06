<?php

declare(strict_types=1);

namespace Intl\ExternalAuth\ValueObjects;

/**
 * Pure framework-independent external identity representation.
 * Contains generic trusted identity/session facts only.
 * MUST NOT contain national ID, credentials, tokens, or raw payloads.
 */
final readonly class ExternalIdentity
{
    /**
     * @param array<string, string> $displayClaims Allowlisted safe string key-value pairs (e.g. name, email)
     */
    public function __construct(
        public string $provider,
        public string $subjectId,
        public ?string $organizationId,
        public string $externalSessionId,
        public ExternalSessionStatus $status,
        public \DateTimeImmutable $issuedAt,
        public \DateTimeImmutable $expiresAt,
        public array $displayClaims = [],
    ) {
        if (trim($provider) === '' || strlen($provider) > 64) {
            throw new \InvalidArgumentException('Provider must be 1-64 characters.');
        }
        if (trim($subjectId) === '' || strlen($subjectId) > 191) {
            throw new \InvalidArgumentException('Subject ID must be 1-191 characters.');
        }
        if (trim($externalSessionId) === '' || strlen($externalSessionId) > 191) {
            throw new \InvalidArgumentException('External session ID must be 1-191 characters.');
        }
        if ($organizationId !== null && (trim($organizationId) === '' || strlen($organizationId) > 191)) {
            throw new \InvalidArgumentException('Organization ID must be 1-191 characters when present.');
        }
        if ($expiresAt <= $issuedAt) {
            throw new \InvalidArgumentException('Expiration timestamp must be after issued timestamp.');
        }
        foreach ($displayClaims as $key => $value) {
            if (!is_string($key) || trim($key) === '' || !is_string($value)) {
                throw new \InvalidArgumentException('Display claims must be string key-value pairs.');
            }
        }
    }

    public function isExpired(?\DateTimeImmutable $at = null): bool
    {
        $at ??= new \DateTimeImmutable();
        return $at >= $this->expiresAt || $this->status === ExternalSessionStatus::Expired;
    }

    public function isActive(?\DateTimeImmutable $at = null): bool
    {
        return $this->status === ExternalSessionStatus::Active && !$this->isExpired($at);
    }
}

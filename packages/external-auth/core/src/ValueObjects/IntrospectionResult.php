<?php

declare(strict_types=1);

namespace Karband\ExternalAuth\ValueObjects;

final readonly class IntrospectionResult
{
    /**
     * @param array<string, string> $claims
     */
    public function __construct(
        public bool $active,
        public ExternalSessionStatus $status,
        public string $subjectId,
        public ?string $organizationId,
        public \DateTimeImmutable $expiresAt,
        public array $claims = [],
    ) {}

    /**
     * @param array<string, string> $claims
     */
    public static function active(
        string $subjectId,
        ?string $organizationId,
        \DateTimeImmutable $expiresAt,
        array $claims = []
    ): self {
        return new self(
            active: true,
            status: ExternalSessionStatus::Active,
            subjectId: $subjectId,
            organizationId: $organizationId,
            expiresAt: $expiresAt,
            claims: $claims,
        );
    }

    public static function inactive(ExternalSessionStatus $status = ExternalSessionStatus::Revoked): self
    {
        return new self(
            active: false,
            status: $status,
            subjectId: '',
            organizationId: null,
            expiresAt: new \DateTimeImmutable('@0'),
            claims: [],
        );
    }
}

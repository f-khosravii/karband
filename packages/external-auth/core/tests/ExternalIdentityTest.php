<?php

declare(strict_types=1);

namespace Karband\ExternalAuth\Tests;

use Karband\ExternalAuth\ValueObjects\ExternalIdentity;
use Karband\ExternalAuth\ValueObjects\ExternalSessionStatus;
use PHPUnit\Framework\TestCase;

final class ExternalIdentityTest extends TestCase
{
    public function test_valid_immutable_identity_construction(): void
    {
        $issuedAt = new \DateTimeImmutable('2026-10-04 10:00:00');
        $expiresAt = new \DateTimeImmutable('2026-10-04 11:00:00');

        $identity = new ExternalIdentity(
            provider: 'epishkhan_v2',
            subjectId: 'sub-12345',
            organizationId: 'agency-99',
            externalSessionId: 'sess-abcde',
            status: ExternalSessionStatus::Active,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
            displayClaims: ['name' => 'John Doe', 'email' => 'john@example.test']
        );

        self::assertSame('epishkhan_v2', $identity->provider);
        self::assertSame('sub-12345', $identity->subjectId);
        self::assertSame('agency-99', $identity->organizationId);
        self::assertSame('sess-abcde', $identity->externalSessionId);
        self::assertSame(ExternalSessionStatus::Active, $identity->status);
        self::assertSame($issuedAt, $identity->issuedAt);
        self::assertSame($expiresAt, $identity->expiresAt);
        self::assertSame(['name' => 'John Doe', 'email' => 'john@example.test'], $identity->displayClaims);
    }

    public function test_rejects_empty_provider(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ExternalIdentity(
            provider: '',
            subjectId: 'sub-1',
            organizationId: null,
            externalSessionId: 'sess-1',
            status: ExternalSessionStatus::Active,
            issuedAt: new \DateTimeImmutable('now'),
            expiresAt: new \DateTimeImmutable('+1 hour')
        );
    }

    public function test_rejects_empty_subject_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ExternalIdentity(
            provider: 'epishkhan_v2',
            subjectId: '   ',
            organizationId: null,
            externalSessionId: 'sess-1',
            status: ExternalSessionStatus::Active,
            issuedAt: new \DateTimeImmutable('now'),
            expiresAt: new \DateTimeImmutable('+1 hour')
        );
    }

    public function test_rejects_empty_session_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ExternalIdentity(
            provider: 'epishkhan_v2',
            subjectId: 'sub-1',
            organizationId: null,
            externalSessionId: '',
            status: ExternalSessionStatus::Active,
            issuedAt: new \DateTimeImmutable('now'),
            expiresAt: new \DateTimeImmutable('+1 hour')
        );
    }

    public function test_rejects_expiration_before_issued_at(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ExternalIdentity(
            provider: 'epishkhan_v2',
            subjectId: 'sub-1',
            organizationId: null,
            externalSessionId: 'sess-1',
            status: ExternalSessionStatus::Active,
            issuedAt: new \DateTimeImmutable('2026-10-04 12:00:00'),
            expiresAt: new \DateTimeImmutable('2026-10-04 11:00:00')
        );
    }

    public function test_rejects_invalid_display_claims(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ExternalIdentity(
            provider: 'epishkhan_v2',
            subjectId: 'sub-1',
            organizationId: null,
            externalSessionId: 'sess-1',
            status: ExternalSessionStatus::Active,
            issuedAt: new \DateTimeImmutable('now'),
            expiresAt: new \DateTimeImmutable('+1 hour'),
            displayClaims: ['invalid_key' => 12345] // non-string value
        );
    }

    public function test_expiry_and_active_semantics(): void
    {
        $issued = new \DateTimeImmutable('2026-10-04 10:00:00');
        $expires = new \DateTimeImmutable('2026-10-04 11:00:00');

        $activeIdentity = new ExternalIdentity(
            provider: 'epishkhan_v2',
            subjectId: 'sub-1',
            organizationId: null,
            externalSessionId: 'sess-1',
            status: ExternalSessionStatus::Active,
            issuedAt: $issued,
            expiresAt: $expires
        );

        self::assertTrue($activeIdentity->isActive(new \DateTimeImmutable('2026-10-04 10:30:00')));
        self::assertFalse($activeIdentity->isExpired(new \DateTimeImmutable('2026-10-04 10:30:00')));

        self::assertFalse($activeIdentity->isActive(new \DateTimeImmutable('2026-10-04 11:00:01')));
        self::assertTrue($activeIdentity->isExpired(new \DateTimeImmutable('2026-10-04 11:00:01')));

        $revokedIdentity = new ExternalIdentity(
            provider: 'epishkhan_v2',
            subjectId: 'sub-1',
            organizationId: null,
            externalSessionId: 'sess-1',
            status: ExternalSessionStatus::Revoked,
            issuedAt: $issued,
            expiresAt: $expires
        );

        self::assertFalse($revokedIdentity->isActive(new \DateTimeImmutable('2026-10-04 10:30:00')));
    }

    public function test_identity_has_no_sensitive_credential_properties(): void
    {
        $ref = new \ReflectionClass(ExternalIdentity::class);
        $props = array_map(fn($p) => $p->getName(), $ref->getProperties());

        self::assertNotContains('token', $props);
        self::assertNotContains('accessToken', $props);
        self::assertNotContains('oauthToken', $props);
        self::assertNotContains('nationalId', $props);
        self::assertNotContains('password', $props);
    }
}

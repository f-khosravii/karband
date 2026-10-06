<?php

declare(strict_types=1);

namespace Intl\ExternalAuthLaravel\Tests;

use Illuminate\Foundation\Auth\User as BaseUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Intl\AuthorizationLaravel\Principal;
use Intl\Contracts\Audit\AuditEvent;
use Intl\Contracts\Audit\AuditRecorderInterface;
use Intl\Contracts\Authorization\ActorContext;
use Intl\ExternalAuth\Exceptions\ExternalAuthUnavailable;
use Intl\ExternalAuth\Exceptions\InvalidHandoffTicket;
use Intl\ExternalAuth\Exceptions\SessionExpiredException;
use Intl\ExternalAuth\Exceptions\SessionRevokedException;
use Intl\ExternalAuth\ValueObjects\ExternalIdentity;
use Intl\ExternalAuth\ValueObjects\ExternalSessionStatus;
use Intl\ExternalAuthLaravel\Contracts\ExternalPrincipalResolver;
use Intl\ExternalAuthLaravel\Services\ExternalAuthService;
use Intl\ExternalAuthLaravel\Services\ExternalSessionStorage;
use Intl\ExternalAuthLaravel\Testing\FakeEpishkhanV2Adapter;
use Tests\TestCase;

final class ExternalAuthServiceTest extends TestCase
{
    use RefreshDatabase;

    private FakeEpishkhanV2Adapter $adapter;
    private ExternalAuthTestResolver $resolver;
    private ExternalSessionStorage $storage;
    private ExternalAuthTestAuditRecorder $audit;
    private ExternalAuthService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(\Intl\ExternalAuthLaravel\ExternalAuthLaravelServiceProvider::class);

        $this->adapter = new FakeEpishkhanV2Adapter();
        $this->resolver = new ExternalAuthTestResolver();
        $this->storage = $this->app->make(ExternalSessionStorage::class);
        $this->audit = new ExternalAuthTestAuditRecorder();

        $this->service = new ExternalAuthService(
            provider: $this->adapter,
            resolver: $this->resolver,
            storage: $this->storage,
            audit: $this->audit
        );
    }

    public function test_successful_ticket_exchange_and_principal_resolution(): void
    {
        $issued = new \DateTimeImmutable('now');
        $expires = new \DateTimeImmutable('+1 hour');

        $this->adapter->stageTicket(
            ticket: 'valid-ticket-001',
            subjectId: 'sub-100',
            organizationId: 'agency-alpha',
            sessionId: 'sess-100',
            issuedAt: $issued,
            expiresAt: $expires,
            displayClaims: ['name' => 'Agency User']
        );

        $principal = $this->service->authenticateTicket('valid-ticket-001');

        self::assertInstanceOf(Principal::class, $principal);
        self::assertSame('user-sub-100', $principal->actor->id);
        self::assertSame('agency', $principal->actor->role); // Local role from resolver, NOT SSO
        self::assertSame('agency-alpha', $principal->actor->organizationId); // Org preserved
    }

    public function test_consumed_or_invalid_ticket_rejected(): void
    {
        $this->adapter->stageTicket(
            ticket: 'single-use-ticket',
            subjectId: 'sub-101',
            organizationId: 'agency-alpha',
            sessionId: 'sess-101',
            issuedAt: new \DateTimeImmutable('now'),
            expiresAt: new \DateTimeImmutable('+1 hour')
        );

        $this->service->authenticateTicket('single-use-ticket');

        $this->expectException(InvalidHandoffTicket::class);
        $this->service->authenticateTicket('single-use-ticket'); // second attempt fails
    }

    public function test_expired_session_rejected(): void
    {
        $this->adapter->stageTicket(
            ticket: 'expired-ticket',
            subjectId: 'sub-102',
            organizationId: null,
            sessionId: 'sess-102',
            issuedAt: new \DateTimeImmutable('-2 hours'),
            expiresAt: new \DateTimeImmutable('-1 hour')
        );

        $this->expectException(SessionExpiredException::class);
        $this->service->authenticateTicket('expired-ticket');
    }

    public function test_revoked_session_rejected(): void
    {
        $identity = $this->adapter->stageTicket(
            ticket: 'revoked-ticket',
            subjectId: 'sub-103',
            organizationId: null,
            sessionId: 'sess-103',
            issuedAt: new \DateTimeImmutable('now'),
            expiresAt: new \DateTimeImmutable('+1 hour')
        );

        $this->adapter->revokeSession('sess-103');

        $this->expectException(SessionRevokedException::class);
        $this->service->authenticateTicket('revoked-ticket');
    }

    public function test_provider_unavailable_fails_closed(): void
    {
        $this->adapter->available = false;

        $this->expectException(ExternalAuthUnavailable::class);
        $this->service->authenticateTicket('any-ticket');
    }

    public function test_introspection_invalidates_local_session_trust(): void
    {
        $this->adapter->stageTicket(
            ticket: 't-1',
            subjectId: 'sub-104',
            organizationId: 'agency-a',
            sessionId: 'sess-104',
            issuedAt: new \DateTimeImmutable('now'),
            expiresAt: new \DateTimeImmutable('+1 hour')
        );

        $this->service->authenticateTicket('t-1');
        self::assertTrue($this->service->validateSession(FakeEpishkhanV2Adapter::PROVIDER, 'sess-104'));

        // Revoke centrally
        $this->adapter->revokeSession('sess-104');

        // Introspection must detect revocation and invalidate local session
        self::assertFalse($this->service->validateSession(FakeEpishkhanV2Adapter::PROVIDER, 'sess-104'));
        
        $local = $this->storage->find(FakeEpishkhanV2Adapter::PROVIDER, 'sess-104');
        self::assertNotNull($local->revoked_at);
    }

    public function test_idempotent_session_revocation(): void
    {
        $this->adapter->stageTicket(
            ticket: 't-2',
            subjectId: 'sub-105',
            organizationId: 'agency-a',
            sessionId: 'sess-105',
            issuedAt: new \DateTimeImmutable('now'),
            expiresAt: new \DateTimeImmutable('+1 hour')
        );

        $this->service->authenticateTicket('t-2');

        $this->service->revokeSession(FakeEpishkhanV2Adapter::PROVIDER, 'sess-105');
        self::assertFalse($this->service->validateSession(FakeEpishkhanV2Adapter::PROVIDER, 'sess-105'));

        // Second revoke call must be safe and idempotent
        $this->service->revokeSession(FakeEpishkhanV2Adapter::PROVIDER, 'sess-105');
        self::assertFalse($this->service->validateSession(FakeEpishkhanV2Adapter::PROVIDER, 'sess-105'));
    }

    public function test_cross_session_cross_provider_confusion_rejected(): void
    {
        $this->adapter->stageTicket(
            ticket: 't-3',
            subjectId: 'sub-106',
            organizationId: 'agency-a',
            sessionId: 'sess-106',
            issuedAt: new \DateTimeImmutable('now'),
            expiresAt: new \DateTimeImmutable('+1 hour')
        );

        $this->service->authenticateTicket('t-3');

        // Valid for epishkhan_v2, invalid for wrong_provider
        self::assertTrue($this->service->validateSession(FakeEpishkhanV2Adapter::PROVIDER, 'sess-106'));
        self::assertFalse($this->service->validateSession('wrong_provider', 'sess-106'));
    }

    public function test_sensitive_credentials_not_written_to_audit_or_database(): void
    {
        $ticketSecret = 'SECRET-HANDOFF-TOKEN-99999';

        $this->adapter->stageTicket(
            ticket: $ticketSecret,
            subjectId: 'sub-107',
            organizationId: 'agency-b',
            sessionId: 'sess-107',
            issuedAt: new \DateTimeImmutable('now'),
            expiresAt: new \DateTimeImmutable('+1 hour')
        );

        $this->service->authenticateTicket($ticketSecret);

        // Verify DB row contains no ticket string
        $row = $this->storage->find(FakeEpishkhanV2Adapter::PROVIDER, 'sess-107');
        self::assertNotNull($row);
        $rowString = json_encode($row);
        self::assertStringNotContainsString($ticketSecret, $rowString);

        // Verify Audit records contain no ticket string
        self::assertNotEmpty($this->audit->events);
        foreach ($this->audit->events as $event) {
            $eventString = json_encode($event);
            self::assertStringNotContainsString($ticketSecret, $eventString);
        }
    }
}

/** Test user for Orchestra Testbench */
final class ExternalAuthTestUser extends BaseUser
{
    protected $guarded = [];
    protected $table = 'users';
}

/** Test principal resolver mapping ExternalIdentity -> Principal */
final class ExternalAuthTestResolver implements ExternalPrincipalResolver
{
    public function resolve(ExternalIdentity $identity): Principal
    {
        $user = new ExternalAuthTestUser([
            'id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.test',
        ]);

        // Local role is derived locally ('agency'), NEVER from external SSO claims directly
        $actor = new ActorContext(
            id: 'user-' . $identity->subjectId,
            role: 'agency',
            organizationId: $identity->organizationId
        );

        return new Principal($user, $actor, 'web');
    }
}

/** Test audit recorder */
final class ExternalAuthTestAuditRecorder implements AuditRecorderInterface
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function record(AuditEvent $event): void
    {
        $this->events[] = $event;
    }
}

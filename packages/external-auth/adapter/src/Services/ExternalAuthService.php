<?php

declare(strict_types=1);

namespace Karband\ExternalAuthLaravel\Services;

use Karband\Audit\AuditEvent;
use Karband\Audit\AuditRecorderInterface;
use Karband\ExternalAuth\Contracts\ExternalAuthProviderInterface;
use Karband\ExternalAuth\Exceptions\InvalidHandoffTicket;
use Karband\ExternalAuth\Exceptions\SessionExpiredException;
use Karband\ExternalAuth\Exceptions\SessionRevokedException;
use Karband\ExternalAuth\ValueObjects\ExternalSessionStatus;
use Karband\ExternalAuthLaravel\Contracts\ExternalPrincipalResolver;

final readonly class ExternalAuthService
{
    public function __construct(
        private ExternalAuthProviderInterface $provider,
        private ExternalPrincipalResolver $resolver,
        private ExternalSessionStorage $storage,
        private ?AuditRecorderInterface $audit = null,
    ) {}

    /**
     * Exchange a transient handoff ticket, validate identity, store session locally,
     * and resolve local Principal. Transient ticket is NEVER logged or saved.
     */
    public function authenticateTicket(string $ticket): mixed
    {
        if (trim($ticket) === '') {
            throw new InvalidHandoffTicket('Ticket must not be empty.');
        }

        $identity = $this->provider->exchangeTicket($ticket);

        if ($identity->isExpired()) {
            throw new SessionExpiredException('Exchanged session is expired.');
        }

        if ($identity->status === ExternalSessionStatus::Revoked) {
            throw new SessionRevokedException('Exchanged session is revoked.');
        }

        // Persist session metadata locally (no sensitive credentials/tokens stored)
        $this->storage->record($identity);

        // Resolve local principal using microservice-authoritative logic
        $principal = $this->resolver->resolve($identity);

        // Record audit event safely (no ticket, no credentials, no PII)
        try {
            $this->audit?->record(new AuditEvent(
                code: 'external_auth.ticket_exchanged',
                subjectType: 'external_session',
                subjectId: $identity->externalSessionId,
                metadata: [
                    'service_code' => 'external_auth',
                    'result' => 'success',
                ]
            ));
        } catch (\Throwable) {
            // Ignore audit recording if session principal is not yet bound during ticket exchange
        }

        return $principal;
    }

    /**
     * Validate an active external session against central provider introspection and local record.
     */
    public function validateSession(string $providerName, string $sessionId): bool
    {
        $local = $this->storage->find($providerName, $sessionId);
        if ($local === null || $local->revoked_at !== null) {
            return false;
        }

        // Introspect central status
        $result = $this->provider->introspectSession($sessionId);
        if (!$result->active || $result->status !== ExternalSessionStatus::Active) {
            $this->storage->markRevoked($providerName, $sessionId);
            return false;
        }

        return true;
    }

    /**
     * Revoke external session centrally and mark locally revoked. Idempotent.
     */
    public function revokeSession(string $providerName, string $sessionId, ?string $actorId = null): void
    {
        $this->provider->revokeSession($sessionId);
        $this->storage->markRevoked($providerName, $sessionId);

        try {
            $this->audit?->record(new AuditEvent(
                code: 'external_auth.session_revoked',
                subjectType: 'external_session',
                subjectId: $sessionId,
                metadata: [
                    'service_code' => 'external_auth',
                    'result' => 'revoked',
                ]
            ));
        } catch (\Throwable) {
            // Ignore audit recording if session principal is not bound
        }
    }
}

<?php

declare(strict_types=1);

namespace Intl\ExternalAuthLaravel\Testing;

use Intl\ExternalAuth\Contracts\ExternalAuthProviderInterface;
use Intl\ExternalAuth\Exceptions\ExternalAuthUnavailable;
use Intl\ExternalAuth\Exceptions\InvalidHandoffTicket;
use Intl\ExternalAuth\Exceptions\InvalidProviderResponse;
use Intl\ExternalAuth\ValueObjects\ExternalIdentity;
use Intl\ExternalAuth\ValueObjects\ExternalSessionStatus;
use Intl\ExternalAuth\ValueObjects\IntrospectionResult;

/**
 * Deterministic test adapter for Epishkhan V2 external authentication contract.
 */
final class FakeEpishkhanV2Adapter implements ExternalAuthProviderInterface
{
    public const PROVIDER = 'epishkhan_v2';

    /** @var array<string, ExternalIdentity> */
    public array $validTickets = [];

    /** @var array<string, ExternalIdentity> */
    public array $sessions = [];

    public bool $available = true;
    public bool $malformedResponse = false;

    public function stageTicket(
        string $ticket,
        string $subjectId,
        ?string $organizationId,
        string $sessionId,
        \DateTimeImmutable $issuedAt,
        \DateTimeImmutable $expiresAt,
        array $displayClaims = [],
    ): ExternalIdentity {
        $identity = new ExternalIdentity(
            provider: self::PROVIDER,
            subjectId: $subjectId,
            organizationId: $organizationId,
            externalSessionId: $sessionId,
            status: ExternalSessionStatus::Active,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
            displayClaims: $displayClaims,
        );
        $this->validTickets[$ticket] = $identity;
        return $identity;
    }

    public function exchangeTicket(string $ticket): ExternalIdentity
    {
        if (!$this->available) {
            throw new ExternalAuthUnavailable('Central Epishkhan V2 SSO service is unavailable.');
        }
        if ($this->malformedResponse) {
            throw new InvalidProviderResponse('Epishkhan V2 returned malformed response.');
        }
        if (!isset($this->validTickets[$ticket])) {
            throw new InvalidHandoffTicket('Ticket is invalid or already consumed.');
        }

        $identity = $this->validTickets[$ticket];
        unset($this->validTickets[$ticket]); // one-time handoff exchange
        $this->sessions[$identity->externalSessionId] = $identity;

        return $identity;
    }

    public function introspectSession(string $sessionId): IntrospectionResult
    {
        if (!$this->available) {
            throw new ExternalAuthUnavailable('Central Epishkhan V2 SSO service is unavailable.');
        }

        if (!isset($this->sessions[$sessionId])) {
            return IntrospectionResult::inactive(ExternalSessionStatus::Revoked);
        }

        $session = $this->sessions[$sessionId];
        if ($session->isExpired()) {
            return IntrospectionResult::inactive(ExternalSessionStatus::Expired);
        }

        if ($session->status === ExternalSessionStatus::Revoked) {
            return IntrospectionResult::inactive(ExternalSessionStatus::Revoked);
        }

        return IntrospectionResult::active(
            subjectId: $session->subjectId,
            organizationId: $session->organizationId,
            expiresAt: $session->expiresAt,
            claims: $session->displayClaims,
        );
    }

    public function revokeSession(string $sessionId): void
    {
        if (!$this->available) {
            throw new ExternalAuthUnavailable('Central Epishkhan V2 SSO service is unavailable.');
        }

        if (isset($this->sessions[$sessionId])) {
            $old = $this->sessions[$sessionId];
            $this->sessions[$sessionId] = new ExternalIdentity(
                provider: $old->provider,
                subjectId: $old->subjectId,
                organizationId: $old->organizationId,
                externalSessionId: $old->externalSessionId,
                status: ExternalSessionStatus::Revoked,
                issuedAt: $old->issuedAt,
                expiresAt: $old->expiresAt,
                displayClaims: $old->displayClaims,
            );
        }

        foreach ($this->validTickets as $t => $old) {
            if ($old->externalSessionId === $sessionId) {
                $this->validTickets[$t] = new ExternalIdentity(
                    provider: $old->provider,
                    subjectId: $old->subjectId,
                    organizationId: $old->organizationId,
                    externalSessionId: $old->externalSessionId,
                    status: ExternalSessionStatus::Revoked,
                    issuedAt: $old->issuedAt,
                    expiresAt: $old->expiresAt,
                    displayClaims: $old->displayClaims,
                );
            }
        }
    }
}

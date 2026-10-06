<?php

declare(strict_types=1);

namespace Intl\ExternalAuth\Contracts;

use Intl\ExternalAuth\ValueObjects\ExternalIdentity;
use Intl\ExternalAuth\ValueObjects\IntrospectionResult;

/**
 * Pure framework-independent provider boundary for central SSO contracts.
 */
interface ExternalAuthProviderInterface
{
    /**
     * Exchange a transient handoff ticket for a verified ExternalIdentity.
     *
     * @throws \Intl\ExternalAuth\Exceptions\InvalidHandoffTicket
     * @throws \Intl\ExternalAuth\Exceptions\ExternalAuthUnavailable
     * @throws \Intl\ExternalAuth\Exceptions\InvalidProviderResponse
     */
    public function exchangeTicket(string $ticket): ExternalIdentity;

    /**
     * Introspect an active external session.
     *
     * @throws \Intl\ExternalAuth\Exceptions\ExternalAuthUnavailable
     */
    public function introspectSession(string $sessionId): IntrospectionResult;

    /**
     * Revoke an active external session centrally. Idempotent.
     *
     * @throws \Intl\ExternalAuth\Exceptions\ExternalAuthUnavailable
     */
    public function revokeSession(string $sessionId): void;
}

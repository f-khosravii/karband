<?php

declare(strict_types=1);

namespace Karband\ExternalAuth\Contracts;

use Karband\ExternalAuth\ValueObjects\ExternalIdentity;
use Karband\ExternalAuth\ValueObjects\IntrospectionResult;

/**
 * Pure framework-independent provider boundary for central SSO contracts.
 */
interface ExternalAuthProviderInterface
{
    /**
     * Exchange a transient handoff ticket for a verified ExternalIdentity.
     *
     * @throws \Karband\ExternalAuth\Exceptions\InvalidHandoffTicket
     * @throws \Karband\ExternalAuth\Exceptions\ExternalAuthUnavailable
     * @throws \Karband\ExternalAuth\Exceptions\InvalidProviderResponse
     */
    public function exchangeTicket(string $ticket): ExternalIdentity;

    /**
     * Introspect an active external session.
     *
     * @throws \Karband\ExternalAuth\Exceptions\ExternalAuthUnavailable
     */
    public function introspectSession(string $sessionId): IntrospectionResult;

    /**
     * Revoke an active external session centrally. Idempotent.
     *
     * @throws \Karband\ExternalAuth\Exceptions\ExternalAuthUnavailable
     */
    public function revokeSession(string $sessionId): void;
}

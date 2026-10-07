<?php

declare(strict_types=1);

namespace Karband\AuthPanel\Contracts;

use Illuminate\Http\Request;
use Karband\AuthPanel\Dto\AuthPrincipalDto;

/**
 * Consumer-owned port for Auth Panel presentation layer.
 * Implemented by host/composition bridges to interact with underlying Authentication
 * capability and session handling without coupling the presentation package to internal
 * persistence or host-specific user models.
 */
interface AuthPanelPortInterface
{
    /**
     * Authenticate credentials and return principal DTO.
     * Throws AuthenticationFailedException on invalid credentials or disabled account.
     */
    public function authenticate(string $loginIdentifier, string $password): AuthPrincipalDto;

    /**
     * Establish the application session for the authenticated principal.
     */
    public function establishSession(AuthPrincipalDto $principal, Request $request): void;

    /**
     * Invalidate the current application session.
     */
    public function invalidateSession(Request $request): void;

    /**
     * Retrieve the currently authenticated principal from session if present.
     */
    public function currentPrincipal(Request $request): ?AuthPrincipalDto;
}

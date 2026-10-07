<?php

declare(strict_types=1);

namespace App\Bridges;

use Illuminate\Http\Request;
use Karband\Authentication\Services\AuthenticationService;
use Karband\AuthPanel\Contracts\AuthPanelPortInterface;
use Karband\AuthPanel\Dto\AuthPrincipalDto;

final class SandboxAuthPanelBridge implements AuthPanelPortInterface
{
    public function __construct(
        private readonly AuthenticationService $authenticationService
    ) {
    }

    public function authenticate(string $loginIdentifier, string $password): AuthPrincipalDto
    {
        $principal = $this->authenticationService->authenticate($loginIdentifier, $password);

        return new AuthPrincipalDto(
            accountId: $principal->accountId,
            loginIdentifier: $principal->loginIdentifier,
        );
    }

    public function establishSession(AuthPrincipalDto $principal, Request $request): void
    {
        $request->session()->put('auth_account_id', $principal->accountId);
        $request->session()->put('auth_login_identifier', $principal->loginIdentifier);
    }

    public function invalidateSession(Request $request): void
    {
        $request->session()->forget(['auth_account_id', 'auth_login_identifier']);
    }

    public function currentPrincipal(Request $request): ?AuthPrincipalDto
    {
        $accountId = $request->session()->get('auth_account_id');
        $loginIdentifier = $request->session()->get('auth_login_identifier');

        if (!is_string($accountId) || !is_string($loginIdentifier) || $accountId === '') {
            return null;
        }

        return new AuthPrincipalDto(
            accountId: $accountId,
            loginIdentifier: $loginIdentifier,
        );
    }
}

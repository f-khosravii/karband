<?php

declare(strict_types=1);

namespace Intl\AuthorizationLaravel;

use Illuminate\Support\Facades\Auth;
use Intl\AuthorizationLaravel\Contracts\PrincipalProvider;
use Intl\AuthorizationLaravel\Contracts\ScopeChecker;
use Intl\Contracts\Authorization\AccessRequest;
use Intl\Contracts\Authorization\ActorContext;
use Intl\Contracts\Authorization\AuthorizationDenied;
use Intl\Contracts\Authorization\AuthorizerInterface;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Enforces a single selected role (not the union of an account's roles),
 * an explicit ability, and the host's trusted resource scope. No super-admin bypass.
 */
final readonly class SpatieAuthorizer implements AuthorizerInterface
{
    public function __construct(
        private PrincipalProvider $principals,
        private ScopeChecker $scopes,
    ) {
    }

    public function authorize(ActorContext $actor, AccessRequest $request): void
    {
        try {
            $principal = $this->principals->current();
        } catch (\Throwable) {
            throw new AuthorizationDenied('not_authenticated');
        }
        if ($actor !== $principal->actor && (
            $actor->id !== $principal->actor->id ||
            $actor->role !== $principal->actor->role ||
            $actor->organizationId !== $principal->actor->organizationId
        )) {
            throw new AuthorizationDenied('actor_mismatch');
        }

        try {
            $loggedIn = Auth::guard($principal->guard)->user();
        } catch (\Throwable) {
            throw new AuthorizationDenied('not_authenticated');
        }
        if ($loggedIn === null || $loggedIn::class !== $principal->user::class ||
            (string) $loggedIn->getAuthIdentifier() !== (string) $principal->user->getAuthIdentifier()) {
            throw new AuthorizationDenied('not_authenticated');
        }

        $user = $principal->user;
        if (!method_exists($user, 'hasRole') || !method_exists($user, 'hasPermissionTo')) {
            throw new AuthorizationDenied('permission_model_missing');
        }
        try {
            if (!$user->hasRole($actor->role, $principal->guard)) {
                throw new AuthorizationDenied('role_denied');
            }
            // Role-specific check prevents using permission inherited from another active role.
            $roleClass = config('permission.models.role', \Spatie\Permission\Models\Role::class);
            $role = $roleClass::findByName($actor->role, $principal->guard);
            if (!$role->hasPermissionTo($request->ability, $principal->guard) ||
                !$user->hasPermissionTo($request->ability, $principal->guard)) {
                throw new AuthorizationDenied('permission_denied');
            }
        } catch (PermissionDoesNotExist|\Spatie\Permission\Exceptions\RoleDoesNotExist) {
            throw new AuthorizationDenied('permission_denied');
        }
        // Deliberately not using Gate::before or a role-name-based admin bypass.
        try {
            if (!$this->scopes->allows($actor, $request)) {
                throw new AuthorizationDenied('scope_denied');
            }
        } catch (AuthorizationDenied $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new AuthorizationDenied('scope_denied');
        }
    }
}

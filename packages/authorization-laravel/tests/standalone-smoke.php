<?php

declare(strict_types=1);

/** Local adapter logic smoke test with tiny framework shims. NOT a substitute for Laravel integration tests. */
namespace Illuminate\Contracts\Auth {
    interface Authenticatable { public function getAuthIdentifier(): mixed; }
}
namespace Illuminate\Support\Facades {
    final class Auth {
        public static ?object $guard = null;
        public static function guard(string $name): object { return self::$guard ?? throw new \RuntimeException('No guard'); }
    }
}
namespace Spatie\Permission\Exceptions {
    class PermissionDoesNotExist extends \RuntimeException {}
    class RoleDoesNotExist extends \RuntimeException {}
}
namespace Spatie\Permission\Models {
    final class Role {
        public static array $permissions = [];
        public static function findByName(string $name, string $guard): object {
            return new class($name, $guard) {
                public function __construct(private string $name, private string $guard) {}
                public function hasPermissionTo(string $ability, string $guard): bool {
                    return $guard === $this->guard && in_array($ability, \Spatie\Permission\Models\Role::$permissions[$this->name] ?? [], true);
                }
            };
        }
    }
}
namespace {
    use Illuminate\Contracts\Auth\Authenticatable;
    use Illuminate\Support\Facades\Auth;
    use Intl\AuthorizationLaravel\Contracts\PrincipalProvider;
    use Intl\AuthorizationLaravel\Contracts\ScopeChecker;
    use Intl\AuthorizationLaravel\Principal;
    use Intl\AuthorizationLaravel\SpatieAuthorizer;
    use Intl\Contracts\Authorization\AccessRequest;
    use Intl\Contracts\Authorization\ActorContext;
    use Intl\Contracts\Authorization\AuthorizationDenied;
    use Spatie\Permission\Models\Role;

    spl_autoload_register(static function (string $class): void {
        $prefixes = [
            'Intl\\Contracts\\' => __DIR__ . '/../../contracts/src/',
            'Intl\\AuthorizationLaravel\\' => __DIR__ . '/../src/',
        ];
        foreach ($prefixes as $prefix => $dir) {
            if (str_starts_with($class, $prefix)) {
                $path = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($path)) { require $path; }
            }
        }
    });
    function config(string $key, mixed $default = null): mixed { return $default; }

    final class SmokeUser implements Authenticatable {
        public array $roles = ['agency'];
        public array $permissions = ['workflow.instance.view', 'workflow.definition.publish'];
        public function __construct(public string $id = 'operator-1') {}
        public function getAuthIdentifier(): mixed { return $this->id; }
        public function hasRole(string $role, string $guard): bool { return $guard === 'web' && in_array($role, $this->roles, true); }
        public function hasPermissionTo(string $ability, string $guard): bool { return $guard === 'web' && in_array($ability, $this->permissions, true); }
    }
    final class SmokeGuard {
        public function __construct(public ?SmokeUser $current) {}
        public function user(): ?SmokeUser { return $this->current; }
    }
    final class SmokePrincipals implements PrincipalProvider {
        public function __construct(public ?Principal $principal) {}
        public function current(): Principal { return $this->principal ?? throw new \RuntimeException('Missing'); }
    }
    final class SmokeScopes implements ScopeChecker {
        public bool $fail = false;
        public bool $throw = false;
        public function allows(ActorContext $actor, AccessRequest $request): bool {
            if ($this->throw) { throw new \RuntimeException('Repository offline'); }
            return !$this->fail && $actor->organizationId === 'agency-a' && $request->resourceId === 'mine';
        }
    }
    $checks = 0;
    function check(bool $success): void { global $checks; if (!$success) { throw new \RuntimeException('Smoke assertion failed at check ' . ($checks + 1)); } $checks++; }
    function denied(callable $callback, string $reason): void {
        try { $callback(); throw new \RuntimeException('Expected authorization denial: ' . $reason); }
        catch (AuthorizationDenied $e) { check($e->reasonCode === $reason); }
    }
    $user = new SmokeUser();
    Auth::$guard = new SmokeGuard($user);
    Role::$permissions = ['agency' => ['workflow.instance.view'], 'platform_admin' => ['workflow.definition.publish']];
    $actor = new ActorContext('operator-1', 'agency', 'agency-a');
    $principals = new SmokePrincipals(new Principal($user, $actor, 'web'));
    $scope = new SmokeScopes();
    $auth = new SpatieAuthorizer($principals, $scope);
    $own = new AccessRequest('workflow.instance.view', 'sample.request', 'mine');
    $auth->authorize($actor, $own); check(true);
    denied(fn() => $auth->authorize($actor, new AccessRequest('workflow.instance.view','sample.request','foreign')), 'scope_denied');
    denied(fn() => $auth->authorize($actor, new AccessRequest('workflow.definition.publish','workflow.definition','mine')), 'permission_denied');
    denied(fn() => $auth->authorize(new ActorContext('operator-1','agency','agency-b'), $own), 'actor_mismatch');
    denied(fn() => $auth->authorize(new ActorContext('operator-1','platform_admin','agency-a'), $own), 'actor_mismatch');
    $scope->throw = true; denied(fn() => $auth->authorize($actor, $own), 'scope_denied'); $scope->throw = false;
    $scope->fail = true; denied(fn() => $auth->authorize($actor, $own), 'scope_denied'); $scope->fail = false;
    $user->roles = []; denied(fn() => $auth->authorize($actor, $own), 'role_denied'); $user->roles = ['agency'];
    $user->permissions = []; denied(fn() => $auth->authorize($actor, $own), 'permission_denied'); $user->permissions = ['workflow.instance.view', 'workflow.definition.publish'];
    Auth::$guard = new SmokeGuard(null); denied(fn() => $auth->authorize($actor, $own), 'not_authenticated');
    Auth::$guard = new SmokeGuard(new SmokeUser('attacker')); denied(fn() => $auth->authorize($actor, $own), 'not_authenticated');
    Auth::$guard = new SmokeGuard($user); $principals->principal = null;
    denied(fn() => $auth->authorize($actor, $own), 'not_authenticated');
    check($checks === 12);
    echo "Authorization adapter logic: {$checks} checks passed (framework shims)\n";
}

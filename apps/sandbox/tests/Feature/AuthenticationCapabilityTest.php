<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bridges\SandboxAuthPanelBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Karband\Authentication\Contracts\AuthenticationStoragePortInterface;
use Karband\Authentication\Contracts\PasswordHasherPortInterface;
use Karband\Authentication\Enums\AuthenticationAccountStatus;
use Karband\Authentication\Exceptions\AuthenticationAccountConflictException;
use Karband\Authentication\Exceptions\AuthenticationAccountNotFoundException;
use Karband\Authentication\Exceptions\AuthenticationFailedException;
use Karband\Authentication\Exceptions\AuthenticationValidationException;
use Karband\Authentication\Services\AuthenticationService;
use Karband\AuthenticationLaravel\Services\AuthenticationStorage;
use Karband\AuthPanel\Contracts\AuthPanelPortInterface;
use Tests\TestCase;

final class AuthenticationCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private AuthenticationService $service;
    private AuthenticationStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(AuthenticationService::class);
        $this->storage = $this->app->make(AuthenticationStorage::class);
    }

    public function test_service_provider_bindings(): void
    {
        $this->assertInstanceOf(AuthenticationService::class, $this->service);
        $this->assertInstanceOf(AuthenticationStorage::class, $this->storage);
        $this->assertInstanceOf(AuthenticationStoragePortInterface::class, $this->app->make(AuthenticationStoragePortInterface::class));
        $this->assertInstanceOf(PasswordHasherPortInterface::class, $this->app->make(PasswordHasherPortInterface::class));
        $this->assertInstanceOf(AuthPanelPortInterface::class, $this->app->make(AuthPanelPortInterface::class));
        $this->assertInstanceOf(SandboxAuthPanelBridge::class, $this->app->make(AuthPanelPortInterface::class));
    }

    public function test_account_creation_hashes_password_and_persists_account_without_exposing_hash(): void
    {
        $account = $this->service->createAccount('Admin@Example.com', 'SuperSecret123!');

        $this->assertNotEmpty($account->id);
        $this->assertSame('admin@example.com', $account->loginIdentifier);
        $this->assertTrue($account->isActive());

        // Prove returned entity has zero password hash properties or methods
        $reflection = new \ReflectionClass($account);
        $this->assertFalse($reflection->hasProperty('passwordHash'));
        $this->assertFalse($reflection->hasProperty('password_hash'));
        $this->assertFalse($reflection->hasMethod('exportPasswordHash'));

        // Prove JSON serialization never leaks password hash
        $json = json_encode($account);
        $this->assertIsString($json);
        $this->assertStringNotContainsString('SuperSecret123!', $json);
        $this->assertStringNotContainsString('password', $json);
        $this->assertStringNotContainsString('hash', $json);

        // Prove database has the hashed password
        $this->assertDatabaseHas('authentication_accounts', [
            'id' => $account->id,
            'login_identifier' => 'admin@example.com',
            'status' => 'active',
        ]);

        // Prove authenticating with correct password succeeds and wrong fails
        $principal = $this->service->authenticate('admin@example.com', 'SuperSecret123!');
        $this->assertSame($account->id, $principal->accountId);
    }

    public function test_account_creation_rejects_empty_password(): void
    {
        $this->expectException(AuthenticationValidationException::class);
        $this->service->createAccount('user@example.com', '   ');
    }

    public function test_account_creation_rejects_duplicate_normalized_identifier(): void
    {
        $this->service->createAccount('User@Example.COM', 'Password123!');

        $this->expectException(AuthenticationAccountConflictException::class);
        $this->service->createAccount('user@example.com', 'AnotherPassword!');
    }

    public function test_successful_credential_authentication(): void
    {
        $account = $this->service->createAccount('User@Example.com', 'SecretPass123');

        $principal = $this->service->authenticate('user@example.com', 'SecretPass123');

        $this->assertSame($account->id, $principal->accountId);
        $this->assertSame('user@example.com', $principal->loginIdentifier);
    }

    public function test_wrong_password_fails_with_generic_exception(): void
    {
        $this->service->createAccount('user@example.com', 'SecretPass123');

        $this->expectException(AuthenticationFailedException::class);
        $this->service->authenticate('user@example.com', 'WrongPass');
    }

    public function test_nonexistent_account_fails_with_generic_exception(): void
    {
        $this->expectException(AuthenticationFailedException::class);
        $this->service->authenticate('nonexistent@example.com', 'AnyPassword');
    }

    public function test_disabled_account_cannot_authenticate_and_fails_generically(): void
    {
        $account = $this->service->createAccount('user@example.com', 'SecretPass123');
        $this->service->disableAccount($account->id);

        $this->expectException(AuthenticationFailedException::class);
        $this->service->authenticate('user@example.com', 'SecretPass123');
    }

    public function test_enable_account_restores_authentication(): void
    {
        $account = $this->service->createAccount('user@example.com', 'SecretPass123');
        $this->service->disableAccount($account->id);
        $this->service->enableAccount($account->id);

        $principal = $this->service->authenticate('user@example.com', 'SecretPass123');
        $this->assertSame($account->id, $principal->accountId);
    }

    public function test_replace_password_invalidates_old_password_and_accepts_new(): void
    {
        $account = $this->service->createAccount('user@example.com', 'OldPassword123');
        $this->service->replacePassword($account->id, 'NewPassword456');

        try {
            $this->service->authenticate('user@example.com', 'OldPassword123');
            $this->fail('Old password should not authenticate');
        } catch (AuthenticationFailedException) {
            // Expected
        }

        $principal = $this->service->authenticate('user@example.com', 'NewPassword456');
        $this->assertSame($account->id, $principal->accountId);
    }

    public function test_stable_account_id_survives_status_and_password_changes(): void
    {
        $account = $this->service->createAccount('stable@example.com', 'InitialPass123');
        $initialId = $account->id;

        $this->service->disableAccount($initialId);
        $this->service->enableAccount($initialId);
        $this->service->replacePassword($initialId, 'UpdatedPass456');

        $found = $this->service->findAccountById($initialId);
        $this->assertNotNull($found);
        $this->assertSame($initialId, $found->id);
        $this->assertSame('stable@example.com', $found->loginIdentifier);

        $reflection = new \ReflectionClass($found);
        $this->assertFalse($reflection->hasProperty('passwordHash'));
        $this->assertFalse($reflection->hasMethod('exportPasswordHash'));
    }

    public function test_auth_panel_login_page_renders_with_csrf(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertSee('Sign In');
        $response->assertSee('login_identifier');
        $response->assertSee('password');
        $response->assertSee('_token');
    }

    public function test_auth_panel_login_flow_success_via_sandbox_bridge(): void
    {
        $account = $this->service->createAccount('paneluser@example.com', 'CorrectPass123');

        $response = $this->post('/login', [
            'login_identifier' => 'PanelUser@example.com',
            'password' => 'CorrectPass123',
        ]);

        $response->assertRedirect('/');
        $this->assertEquals($account->id, session('auth_account_id'));
        $this->assertEquals('paneluser@example.com', session('auth_login_identifier'));
    }

    public function test_auth_panel_login_failure_does_not_repopulate_password(): void
    {
        $this->service->createAccount('paneluser@example.com', 'CorrectPass123');

        $response = $this->from('/login')->post('/login', [
            'login_identifier' => 'paneluser@example.com',
            'password' => 'WrongPass',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors(['login_identifier']);
        $this->assertNull(session('auth_account_id'));

        // Check that old input does not include password
        $this->assertSame('paneluser@example.com', session('_old_input.login_identifier'));
        $this->assertArrayNotHasKey('password', session('_old_input', []));
    }

    public function test_auth_panel_login_rate_limiting(): void
    {
        RateLimiter::clear('auth_panel_login:' . sha1('rate@example.com|127.0.0.1'));

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', [
                'login_identifier' => 'rate@example.com',
                'password' => 'wrong',
            ]);
        }

        // 6th attempt triggers throttle validation error
        $response = $this->from('/login')->post('/login', [
            'login_identifier' => 'rate@example.com',
            'password' => 'wrong',
        ]);

        $response->assertSessionHasErrors(['login_identifier']);
        $errors = session('errors')->get('login_identifier');
        $this->assertStringContainsString('Too many login attempts', $errors[0]);
    }

    public function test_auth_panel_logout_clears_session_via_sandbox_bridge(): void
    {
        $this->service->createAccount('logoutuser@example.com', 'Secret123');

        $this->post('/login', [
            'login_identifier' => 'logoutuser@example.com',
            'password' => 'Secret123',
        ]);

        $this->assertNotNull(session('auth_account_id'));

        $response = $this->post('/logout');

        $response->assertRedirect('/login');
        $this->assertNull(session('auth_account_id'));
        $this->assertNull(session('auth_login_identifier'));
    }

    public function test_auth_panel_fails_closed_when_port_is_unbound(): void
    {
        $this->withoutExceptionHandling();

        // Unbind the port in a child container scope
        $this->app->forgetInstance(AuthPanelPortInterface::class);
        $this->app->offsetUnset(AuthPanelPortInterface::class);

        $this->expectException(\Illuminate\Contracts\Container\BindingResolutionException::class);
        $this->post('/login', [
            'login_identifier' => 'user@example.com',
            'password' => 'pass',
        ]);
    }
}

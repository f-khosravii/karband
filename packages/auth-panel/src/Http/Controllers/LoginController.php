<?php

declare(strict_types=1);

namespace Karband\AuthPanel\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Karband\Authentication\Exceptions\AuthenticationFailedException;
use Karband\AuthPanel\Contracts\AuthPanelPortInterface;

final class LoginController extends Controller
{
    public function showLoginForm(): View
    {
        return view('auth-panel::login');
    }

    public function login(Request $request, AuthPanelPortInterface $authPort): RedirectResponse
    {
        $validated = $request->validate([
            'login_identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $loginIdentifier = (string) $validated['login_identifier'];
        $password = (string) $validated['password'];

        $throttleKey = 'auth_panel_login:' . sha1(mb_strtolower(trim($loginIdentifier), 'UTF-8') . '|' . ($request->ip() ?? '127.0.0.1'));
        $maxAttempts = (int) config('auth-panel.rate_limit_max_attempts', 5);
        $decaySeconds = (int) config('auth-panel.rate_limit_decay_seconds', 60);

        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'login_identifier' => [
                    "Too many login attempts. Please try again in {$seconds} seconds.",
                ],
            ]);
        }

        try {
            $principal = $authPort->authenticate($loginIdentifier, $password);
        } catch (AuthenticationFailedException) {
            RateLimiter::hit($throttleKey, $decaySeconds);

            return back()
                ->withErrors([
                    'login_identifier' => 'These credentials do not match our records.',
                ])
                ->withInput($request->except('password'));
        }

        RateLimiter::clear($throttleKey);

        $authPort->establishSession($principal, $request);

        $request->session()->regenerate();

        $redirectUrl = (string) config('auth-panel.post_login_redirect', '/');

        return redirect()->intended($redirectUrl);
    }

    public function logout(Request $request, AuthPanelPortInterface $authPort): RedirectResponse
    {
        $authPort->invalidateSession($request);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $redirectUrl = (string) config('auth-panel.post_logout_redirect', '/login');

        return redirect($redirectUrl);
    }
}

<?php

declare(strict_types=1);

namespace Karband\DirectoryPanel\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Karband\DirectoryPanel\Contracts\DirectoryPanelAuthorizationInterface;
use Karband\DirectoryPanel\Contracts\DirectoryPanelPortInterface;

final readonly class UserCreateController
{
    public function __construct(
        private DirectoryPanelPortInterface $port,
        private DirectoryPanelAuthorizationInterface $auth,
    ) {
    }

    public function create(): View
    {
        if (!$this->auth->canCreateUser()) {
            abort(403, 'Unauthorized to create users.');
        }

        return view('directory-panel::users.create');
    }

    public function store(Request $request): RedirectResponse
    {
        if (!$this->auth->canCreateUser()) {
            abort(403, 'Unauthorized to create users.');
        }

        $validated = $request->validate([
            'id' => ['required', 'string', 'max:191'],
            'display_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
        ]);

        $user = $this->port->createUser(
            id: (string) $validated['id'],
            displayName: (string) $validated['display_name'],
            email: isset($validated['email']) && $validated['email'] !== null ? (string) $validated['email'] : null,
            phone: isset($validated['phone']) && $validated['phone'] !== null ? (string) $validated['phone'] : null,
        );

        $routePrefix = (string) config('directory-panel.route_name_prefix', 'directory.');
        return redirect()->route($routePrefix . 'users.show', ['id' => $user->id])
            ->with('success', "User [{$user->displayName}] created successfully.");
    }
}

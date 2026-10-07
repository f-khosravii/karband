<?php

declare(strict_types=1);

namespace Karband\DirectoryPanel\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Karband\DirectoryPanel\Contracts\DirectoryPanelAuthorizationInterface;
use Karband\DirectoryPanel\Contracts\DirectoryPanelPortInterface;

final readonly class UserEditController
{
    public function __construct(
        private DirectoryPanelPortInterface $port,
        private DirectoryPanelAuthorizationInterface $auth,
    ) {
    }

    public function edit(string $id): View
    {
        if (!$this->auth->canUpdateUser($id)) {
            abort(403, 'Unauthorized to edit this user.');
        }

        $user = $this->port->getUser($id);

        return view('directory-panel::users.edit', [
            'user' => $user,
        ]);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        if (!$this->auth->canUpdateUser($id)) {
            abort(403, 'Unauthorized to edit this user.');
        }

        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
        ]);

        $updated = $this->port->updateUser(
            id: $id,
            displayName: (string) $validated['display_name'],
            email: isset($validated['email']) && $validated['email'] !== null ? (string) $validated['email'] : null,
            phone: isset($validated['phone']) && $validated['phone'] !== null ? (string) $validated['phone'] : null,
        );

        $routePrefix = (string) config('directory-panel.route_name_prefix', 'directory.');
        return redirect()->route($routePrefix . 'users.show', ['id' => $id])
            ->with('success', "User [{$updated->displayName}] updated successfully.");
    }
}

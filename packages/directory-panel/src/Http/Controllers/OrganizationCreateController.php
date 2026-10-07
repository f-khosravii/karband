<?php

declare(strict_types=1);

namespace Karband\DirectoryPanel\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Karband\DirectoryPanel\Contracts\DirectoryPanelAuthorizationInterface;
use Karband\DirectoryPanel\Contracts\DirectoryPanelPortInterface;

final readonly class OrganizationCreateController
{
    public function __construct(
        private DirectoryPanelPortInterface $port,
        private DirectoryPanelAuthorizationInterface $auth,
    ) {
    }

    public function create(): View
    {
        if (!$this->auth->canCreateOrganization()) {
            abort(403, 'Unauthorized to create organizations.');
        }

        return view('directory-panel::organizations.create');
    }

    public function store(Request $request): RedirectResponse
    {
        if (!$this->auth->canCreateOrganization()) {
            abort(403, 'Unauthorized to create organizations.');
        }

        $validated = $request->validate([
            'id' => ['required', 'string', 'max:191'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
        ]);

        $org = $this->port->createOrganization(
            id: (string) $validated['id'],
            name: (string) $validated['name'],
            email: isset($validated['email']) && $validated['email'] !== null ? (string) $validated['email'] : null,
            phone: isset($validated['phone']) && $validated['phone'] !== null ? (string) $validated['phone'] : null,
        );

        $routePrefix = (string) config('directory-panel.route_name_prefix', 'directory.');
        return redirect()->route($routePrefix . 'organizations.show', ['id' => $org->id])
            ->with('success', "Organization [{$org->name}] created successfully.");
    }
}

<?php

declare(strict_types=1);

namespace Karband\DirectoryPanel\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Karband\DirectoryPanel\Contracts\DirectoryPanelAuthorizationInterface;
use Karband\DirectoryPanel\Contracts\DirectoryPanelPortInterface;

final readonly class MembershipController
{
    public function __construct(
        private DirectoryPanelPortInterface $port,
        private DirectoryPanelAuthorizationInterface $auth,
    ) {
    }

    public function store(Request $request): RedirectResponse
    {
        if (!$this->auth->canCreateMembership()) {
            abort(403, 'Unauthorized to add memberships.');
        }

        $validated = $request->validate([
            'user_id' => ['required', 'string', 'max:191'],
            'organization_id' => ['required', 'string', 'max:191'],
            'role' => ['nullable', 'string', 'max:64'],
            'return_to' => ['nullable', 'string', 'in:user,organization'],
        ]);

        $role = isset($validated['role']) && trim((string) $validated['role']) !== ''
            ? trim((string) $validated['role'])
            : null;

        $this->port->addMembership(
            userId: (string) $validated['user_id'],
            organizationId: (string) $validated['organization_id'],
            role: $role,
        );

        $routePrefix = (string) config('directory-panel.route_name_prefix', 'directory.');
        $returnTo = $validated['return_to'] ?? 'organization';

        if ($returnTo === 'user') {
            return redirect()->route($routePrefix . 'users.show', ['id' => $validated['user_id']])
                ->with('success', 'Membership added successfully.');
        }

        return redirect()->route($routePrefix . 'organizations.show', ['id' => $validated['organization_id']])
            ->with('success', 'Membership added successfully.');
    }

    public function end(Request $request): RedirectResponse
    {
        if (!$this->auth->canEndMembership()) {
            abort(403, 'Unauthorized to end memberships.');
        }

        $validated = $request->validate([
            'user_id' => ['required', 'string', 'max:191'],
            'organization_id' => ['required', 'string', 'max:191'],
            'return_to' => ['nullable', 'string', 'in:user,organization'],
        ]);

        $this->port->endMembership(
            userId: (string) $validated['user_id'],
            organizationId: (string) $validated['organization_id']
        );

        $routePrefix = (string) config('directory-panel.route_name_prefix', 'directory.');
        $returnTo = $validated['return_to'] ?? 'organization';

        if ($returnTo === 'user') {
            return redirect()->route($routePrefix . 'users.show', ['id' => $validated['user_id']])
                ->with('success', 'Membership ended successfully.');
        }

        return redirect()->route($routePrefix . 'organizations.show', ['id' => $validated['organization_id']])
            ->with('success', 'Membership ended successfully.');
    }

    public function restore(Request $request): RedirectResponse
    {
        if (!$this->auth->canUpdateMembership()) {
            abort(403, 'Unauthorized to restore memberships.');
        }

        $validated = $request->validate([
            'user_id' => ['required', 'string', 'max:191'],
            'organization_id' => ['required', 'string', 'max:191'],
            'return_to' => ['nullable', 'string', 'in:user,organization'],
        ]);

        $this->port->restoreMembership(
            userId: (string) $validated['user_id'],
            organizationId: (string) $validated['organization_id']
        );

        $routePrefix = (string) config('directory-panel.route_name_prefix', 'directory.');
        $returnTo = $validated['return_to'] ?? 'organization';

        if ($returnTo === 'user') {
            return redirect()->route($routePrefix . 'users.show', ['id' => $validated['user_id']])
                ->with('success', 'Membership restored successfully.');
        }

        return redirect()->route($routePrefix . 'organizations.show', ['id' => $validated['organization_id']])
            ->with('success', 'Membership restored successfully.');
    }
}

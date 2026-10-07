<?php

declare(strict_types=1);

namespace Karband\DirectoryPanel\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Karband\DirectoryPanel\Contracts\DirectoryPanelAuthorizationInterface;
use Karband\DirectoryPanel\Contracts\DirectoryPanelPortInterface;

final readonly class UserLifecycleController
{
    public function __construct(
        private DirectoryPanelPortInterface $port,
        private DirectoryPanelAuthorizationInterface $auth,
    ) {
    }

    public function archive(string $id): RedirectResponse
    {
        if (!$this->auth->canArchiveUser($id)) {
            abort(403, 'Unauthorized to archive this user.');
        }

        $this->port->archiveUser($id);

        $routePrefix = (string) config('directory-panel.route_name_prefix', 'directory.');
        return redirect()->route($routePrefix . 'users.show', ['id' => $id])
            ->with('success', 'User archived successfully.');
    }

    public function restore(string $id): RedirectResponse
    {
        if (!$this->auth->canRestoreUser($id)) {
            abort(403, 'Unauthorized to restore this user.');
        }

        $this->port->restoreUser($id);

        $routePrefix = (string) config('directory-panel.route_name_prefix', 'directory.');
        return redirect()->route($routePrefix . 'users.show', ['id' => $id])
            ->with('success', 'User restored successfully.');
    }
}

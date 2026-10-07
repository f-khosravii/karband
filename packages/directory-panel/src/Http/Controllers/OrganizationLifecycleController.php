<?php

declare(strict_types=1);

namespace Karband\DirectoryPanel\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Karband\DirectoryPanel\Contracts\DirectoryPanelAuthorizationInterface;
use Karband\DirectoryPanel\Contracts\DirectoryPanelPortInterface;

final readonly class OrganizationLifecycleController
{
    public function __construct(
        private DirectoryPanelPortInterface $port,
        private DirectoryPanelAuthorizationInterface $auth,
    ) {
    }

    public function archive(string $id): RedirectResponse
    {
        if (!$this->auth->canArchiveOrganization($id)) {
            abort(403, 'Unauthorized to archive this organization.');
        }

        $this->port->archiveOrganization($id);

        $routePrefix = (string) config('directory-panel.route_name_prefix', 'directory.');
        return redirect()->route($routePrefix . 'organizations.show', ['id' => $id])
            ->with('success', 'Organization archived successfully.');
    }

    public function restore(string $id): RedirectResponse
    {
        if (!$this->auth->canRestoreOrganization($id)) {
            abort(403, 'Unauthorized to restore this organization.');
        }

        $this->port->restoreOrganization($id);

        $routePrefix = (string) config('directory-panel.route_name_prefix', 'directory.');
        return redirect()->route($routePrefix . 'organizations.show', ['id' => $id])
            ->with('success', 'Organization restored successfully.');
    }
}

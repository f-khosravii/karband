<?php

use App\Providers\AppServiceProvider;
use Karband\CatalogPanel\CatalogPanelServiceProvider;

return [
    AppServiceProvider::class,
    CatalogPanelServiceProvider::class,
    \Karband\DirectoryPanel\DirectoryPanelServiceProvider::class,
    \Karband\AuthenticationLaravel\AuthenticationLaravelServiceProvider::class,
    \Karband\AuthPanel\AuthPanelServiceProvider::class,
];

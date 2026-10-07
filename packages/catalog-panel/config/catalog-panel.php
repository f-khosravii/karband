<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Catalog Panel Route Configuration
    |--------------------------------------------------------------------------
    |
    | Prefix, name prefix, and middleware for mounting the catalog panel.
    | Host applications (Tax, Legal, Insurance, NCR, etc.) can customize these.
    |
    */
    'route_prefix' => env('CATALOG_PANEL_ROUTE_PREFIX', 'catalog'),
    'route_name_prefix' => env('CATALOG_PANEL_NAME_PREFIX', 'catalog.'),
    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Layout Shell
    |--------------------------------------------------------------------------
    |
    | The Blade layout view used by panel views. Can be replaced with a host layout.
    |
    */
    'layout' => 'catalog-panel::layouts.app',
];

<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Route Prefix and Name Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix for all directory panel URLs and route names.
    |
    */
    'route_prefix' => env('DIRECTORY_PANEL_PREFIX', 'directory'),
    'route_name_prefix' => env('DIRECTORY_PANEL_NAME_PREFIX', 'directory.'),

    /*
    |--------------------------------------------------------------------------
    | Route Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware applied to directory panel routes.
    |
    */
    'middleware' => ['web'],
];

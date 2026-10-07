<?php

declare(strict_types=1);

return [
    'route_prefix' => env('AUTH_PANEL_ROUTE_PREFIX', ''),
    'route_name_prefix' => env('AUTH_PANEL_ROUTE_NAME_PREFIX', 'auth.'),
    'middleware' => ['web'],
    'post_login_redirect' => env('AUTH_PANEL_POST_LOGIN_REDIRECT', '/'),
    'post_logout_redirect' => env('AUTH_PANEL_POST_LOGOUT_REDIRECT', '/login'),
    'rate_limit_max_attempts' => (int) env('AUTH_PANEL_RATE_LIMIT_MAX', 5),
    'rate_limit_decay_seconds' => (int) env('AUTH_PANEL_RATE_LIMIT_DECAY', 60),
];

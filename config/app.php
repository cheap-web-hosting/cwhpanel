<?php

return [
    'name' => 'Control Panel',
    'env' => env('APP_ENV', 'production'),
    'debug' => env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://127.0.0.1:8080'),
    // Separate admin panel port (TLS). The public host serves the customer
    // panel + login by default; /admin and /login live on this port.
    'admin_port' => (int) env('PANEL_ADMIN_PORT', 8443),
    'timezone' => 'UTC',
    'key' => env('APP_KEY', ''),
];

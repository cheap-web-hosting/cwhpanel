<?php

return [
    'driver' => env('SESSION_DRIVER', 'database'),
    'table' => 'sessions',
    'name' => 'panel_session',
    'lifetime' => (int) env('SESSION_LIFETIME', 120),
    // 'auto' (default): assert Secure whenever the panel URL is https.
    // Explicit true/false in SESSION_SECURE_COOKIE still wins (e.g. false
    // for plain-http dev servers, true to force it anywhere).
    'secure' => env('SESSION_SECURE_COOKIE', 'auto'),
    'same_site' => 'Lax',
    'domain' => env('SESSION_DOMAIN', ''),
];

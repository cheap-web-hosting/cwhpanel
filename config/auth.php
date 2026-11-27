<?php

return [
    'min_password_length' => 10,
    'login' => [
        'max_attempts' => 5,
        'decay_seconds' => 300,
        // Automatic firewall block for distributed panel-login guessing:
        // an IP with this many failed logins inside the window is blocked
        // (see LoginAttackService). Loopback + the panel's own public IP
        // are never blocked.
        'auto_block_threshold' => 15,
        'auto_block_window' => 900,
    ],
];

<?php

return [
    'enabled' => env('PROVISIONING_ENABLED', false),
    'socket' => env('PROVISIONING_SOCKET', '/run/cwhpanel/agent.sock'),
    'timeout' => (int) env('PROVISIONING_TIMEOUT', 30),
    // Ticket-minting keys (agent trust model). Distinct keys per role; the
    // agent holds the verification copies (CWH_AGENT_ADMIN_KEY / _CUSTOMER_KEY).
    'admin_key' => env('CWH_PANEL_ADMIN_KEY', ''),
    'customer_key' => env('CWH_PANEL_CUSTOMER_KEY', ''),
];
<?php

declare(strict_types=1);

return [
    /*
     * GitHub repository used by the panel self-update check (releases/tags
     * API + the signed runtime release assets).
     *
     * Accepts either "owner/repo" or a full clone URL — the service
     * normalises it. This is the canonical repository for the Free Edition;
     * an operator can override it with PANEL_UPSTREAM_REPO in .env.
     */
    'upstream_repo' => env('PANEL_UPSTREAM_REPO', 'https://github.com/cheap-web-hosting/cwhpanel.git'),
];

<?php

return [
    // Let's Encrypt staging by default; switch to the production directory in prod.
    'acme_directory' => env('ACME_DIRECTORY', 'https://acme-staging-v02.api.letsencrypt.org/directory'),
    'acme_account_email' => env('ACME_EMAIL', ''),
];
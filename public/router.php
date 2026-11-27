<?php

declare(strict_types=1);

// Router for PHP's built-in development server.
// Serves existing static files directly; everything else goes through the front controller.

$uri = urldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

// Confine every served path below public/ — the dev server (php -S) is
// documented to run as root, and a traversal ("/../.env") must never resolve
// outside the docroot. Reject any path with a ".." segment outright.
if (str_contains($uri, '..')) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

$docroot = realpath(__DIR__);
$resolved = $docroot === false ? false : realpath(__DIR__ . '/' . ltrim($uri, '/'));

if ($uri !== '/' && $resolved !== false
    && str_starts_with($resolved, $docroot . DIRECTORY_SEPARATOR)
    && is_file($resolved) && !is_dir($resolved)) {
    return false;
}

require __DIR__ . '/index.php';

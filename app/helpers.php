<?php

declare(strict_types=1);

use App\Foundation\Application;

if (!function_exists('app')) {
    function app(?string $id = null)
    {
        $app = Application::instance();

        if ($id === null) {
            return $app;
        }

        return $app->container()->get($id);
    }
}

if (!function_exists('config')) {
    function config(?string $key = null, $default = null)
    {
        $config = app('config');

        if ($key === null) {
            return $config;
        }

        return $config->get($key, $default);
    }
}

if (!function_exists('env')) {
    function env(string $key, $default = null)
    {
        return app('env')->get($key, $default);
    }
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        $base = rtrim(config('app.url', ''), '/');
        // Keep absolute URLs on the ACTIVE origin's port. Split-panel admin
        // pages are served on PANEL_ADMIN_PORT (e.g. 8443) while APP_URL
        // carries no port, so a bare APP_URL action would post to the public
        // vhost and round-trip through the area guard (a 302 there used to
        // drop POST bodies; now 307, but posting straight to the origin port
        // is strictly safer — same host, so the session cookie still applies).
        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $port = (int) ($_SERVER['SERVER_PORT'] ?? 0);
        if ($port > 0 && $port !== $defaultPort && !isset($parts['port'])) {
            $host = $parts['host'] ?? '';
            if ($host !== '') {
                $base = $scheme . '://' . $host . ':' . $port;
            }
        }
        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        // Root-relative on purpose: static assets carry no session, so they
        // must load identically on every host/scheme (server IP, hostname,
        // http/https). Absolute APP_URL assets caused unstyled pages + mixed
        // content when the panel was reached via the hostname. Route URLs
        // (url()) stay absolute to APP_URL — session stickiness depends on it.
        // The ?v= mtime busts CDN/browser caches on every deploy change.
        $url = '/assets/' . ltrim($path, '/');
        if (defined('BASE_PATH')) {
            $mtime = @filemtime(BASE_PATH . '/public' . $url);
            if ($mtime !== false) {
                $url .= '?v=' . $mtime;
            }
        }
        return $url;
    }
}

if (!function_exists('redirect')) {
    function redirect(string $path)
    {
        return \App\Foundation\Response::redirect($path);
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        $token = app('csrf')->token();
        return '<input type="hidden" name="_token" value="' . e($token) . '">';
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return app('csrf')->token();
    }
}

if (!function_exists('old')) {
    function old(string $key, $default = '')
    {
        return app('session')->old($key, $default);
    }
}

if (!function_exists('session')) {
    function session()
    {
        return app('session');
    }
}

if (!function_exists('auth')) {
    function auth()
    {
        return app('auth');
    }
}

if (!function_exists('module_enabled')) {
    /**
     * True when a module is enabled in the DB AND its code is present.
     * Scripts/cron entry points use this to exit cleanly instead of fatalling
     * on a service class that a disabled/removed module no longer provides.
     */
    function module_enabled(string $module): bool
    {
        try {
            return app()->moduleManager()->isEnabled($module)
                && class_exists('Modules\\' . ucfirst($module) . '\\Module');
        } catch (\Throwable) {
            return false;
        }
    }
}

if (!function_exists('feature_enabled')) {
    function feature_enabled(string $feature): bool
    {
        // Fail open when the package-gating module is disabled or its code is
        // absent (free tree): hiding features because the gate itself is gone
        // would break cross-feature links for no policy reason.
        try {
            if (!app()->moduleManager()->isEnabled('packages')
                || !class_exists(\Modules\Packages\Services\FeatureService::class)
            ) {
                return true;
            }
        } catch (\Throwable) {
            return true;
        }

        return app(\Modules\Packages\Services\FeatureService::class)->enabledForUser(auth()->user(), $feature);
    }
}

if (!function_exists('view')) {
    function view(string $template, array $data = [], ?string $layout = null)
    {
        return app('view')->make($template, $data, $layout);
    }
}

if (!function_exists('icon')) {
    function icon(string $name, int $size = 18): string
    {
        return app('icons')->render($name, $size);
    }
}

if (!function_exists('audit')) {
    function audit(string $action, ?string $resourceType = null, $resourceId = null, array $metadata = []): void
    {
        app('audit')->log($action, $resourceType, $resourceId, $metadata);
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return BASE_PATH . ($path ? DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : '');
    }
}

if (!function_exists('event')) {
    function event(string $name, array $payload = []): \App\Foundation\Events\Event
    {
        return app('events')->dispatch($name, $payload);
    }
}

if (!function_exists('hash_password')) {
    /**
     * Hash a password with the strongest algorithm the runtime actually
     * supports. `defined('PASSWORD_ARGON2ID')` is NOT a reliable signal —
     * the constant exists even in PHP builds compiled without libargon2,
     * where `password_hash(PASSWORD_ARGON2ID)` throws and
     * `password_verify()` silently fails on argon2id hashes. Probe
     * `password_algos()` instead and fall back to bcrypt.
     */
    function hash_password(string $plain): string
    {
        if (in_array(PASSWORD_ARGON2ID, password_algos(), true)) {
            return password_hash($plain, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1]);
        }

        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
    }
}

if (!function_exists('empty_state')) {
    /**
     * Standard table empty-state row. $html is rendered as-is (it may carry
     * action links), so escape any plain text at the call site.
     */
    function empty_state(int $colspan = 6, string $html = 'Nothing here yet.'): string
    {
        return '<tr><td colspan="' . max(1, $colspan) . '" class="empty">' . $html . '</td></tr>';
    }
}

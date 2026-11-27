<?php

declare(strict_types=1);

namespace App\Foundation;

use App\Foundation\Auth\Auth;
use App\Foundation\Auth\Gate;
use App\Foundation\Database\Connection;
use App\Foundation\Events\Dispatcher;
use App\Foundation\Module\ModuleManager;
use App\Foundation\Navigation\Icon;
use App\Foundation\Navigation\NavigationRegistry;
use App\Foundation\Provisioning\AuthTicketService;
use App\Foundation\Provisioning\PrivilegedOperationService;
use Closure;
use Throwable;

class Application
{
    /**
     * Package-gate keys used when the Packages module is unavailable. Must
     * stay in sync with FeatureService::FEATURES (a drift check lives in
     * scripts/module-matrix-test.php).
     */
    private const FALLBACK_FEATURES = [
        'websites', 'domains', 'dns', 'email', 'webmail', 'databases', 'files',
        'ftp', 'ssl', 'cron', 'backups', 'applications', 'appinstaller',
        'monitoring', 'security',
    ];

    private static ?Application $instance = null;

    private string $basePath;

    private Container $container;

    private Config $config;

    private Router $router;

    private ModuleManager $moduleManager;

    private ExceptionHandler $exceptionHandler;

    private array $middleware = [];

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
        static::$instance = $this;

        $this->container = new Container();
        $this->container->instance(Application::class, $this);
        $this->container->instance(Container::class, $this->container);
    }

    public static function instance(): self
    {
        return static::$instance;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function moduleManager(): ModuleManager
    {
        return $this->moduleManager;
    }

    public function bootstrap(): void
    {
        $env = Env::load($this->basePath . '/.env');
        $this->container->instance(Env::class, $env);
        $this->container->alias(Env::class, 'env');

        $this->config = new Config($this->basePath . '/config');
        $this->container->instance(Config::class, $this->config);

        date_default_timezone_set($this->config->get('app.timezone', 'UTC'));

        $logger = new Logger($this->basePath . '/storage/logs');
        $this->container->instance(Logger::class, $logger);

        $this->exceptionHandler = new ExceptionHandler($logger, (bool) $this->config->get('app.debug', false));
        $this->exceptionHandler->register();
        $this->container->instance(ExceptionHandler::class, $this->exceptionHandler);

        $this->registerCoreServices();

        $this->registerMiddleware();

        $this->router = new Router();
        $this->container->instance(Router::class, $this->router);

        $this->moduleManager = new ModuleManager(
            $this->basePath . '/modules',
            $this->container,
            $this->router,
            $this->container->get(NavigationRegistry::class)
        );
        $this->container->instance(ModuleManager::class, $this->moduleManager);

        $this->moduleManager->register();

        $this->registerCoreRoutes();
    }

    public function handle(): Response
    {
        $request = new Request();
        $this->container->instance(Request::class, $request);

        try {
            $this->startSession($request);

            $areaRedirect = $this->areaGuard($request);
            if ($areaRedirect !== null) {
                return $areaRedirect;
            }

            if ($this->requiresCsrf($request)) {
                $token = $request->input('_token', $request->header('X-CSRF-TOKEN'));

                if (!$this->container->get(Csrf::class)->validate((string) $token)) {
                    $this->container->get(Session::class)->flash('error', 'Your session has expired or the security token is invalid. Please try again.');

                    if ($request->wantsJson()) {
                        return Response::json(['error' => 'CSRF token mismatch.'], 419);
                    }

                    // Fall back to the CURRENT path (relative), never APP_URL:
                    // an absolute fallback would hop to the other split-panel
                    // port whose session cookie differs, losing the flash.
                    return Response::redirectBack('/' . ltrim($request->path(), '/'));
                }
            }

            $route = $this->router->match($request->method(), $request->path());

            if ($route === null) {
                if ($request->wantsJson()) {
                    return Response::json(['error' => 'Not found.'], 404);
                }

                return new Response((string) $this->container->get(View::class)->make('errors/404', [
                    'title' => 'Not Found',
                ], 'layouts.auth'), 404);
            }

            $core = function (Request $req) use ($route) {
                return $this->dispatch($route->handler, $route->params);
            };

            return $this->runMiddleware($route->middleware, $request, $core);
        } catch (Throwable $e) {
            return $this->exceptionHandler->render($e);
        } finally {
            // Promote this request's flashes for the NEXT request on EVERY
            // outcome, including early returns (area guard, CSRF rejection,
            // 404) and rendered exceptions. Ageing only the success path left
            // a rejected login's message parked in `_flash.new` until some
            // later page load aged it — the user saw a silent refresh instead
            // of "session expired" / "invalid credentials" (bitten live).
            try {
                $this->container->get(Session::class)->ageFlash();
            } catch (Throwable $e) {
                // Session never started (bootstrap failure): nothing to age.
            }
        }
    }

    /**
     * Re-execute a stored state-changing request after step-up verification.
     *
     * The confirm handler calls this so a verified destructive POST is
     * actually applied instead of the browser being redirected to the POST
     * endpoint as a GET (which 404s on POST-only routes and silently drops
     * the change). Runs through the normal route middleware chain; the
     * step-up middleware passes because auth_verified_at was just set.
     *
     * @return Response|null null when the replay cannot run (stale/unknown
     *         route, invalid CSRF, exception) — the caller falls back to a
     *         page instead of the endpoint.
     */
    public function replayRequest(string $method, string $path, array $input): ?Response
    {
        try {
            $server = $_SERVER;
            $server['REQUEST_METHOD'] = strtoupper($method);
            $server['REQUEST_URI'] = $path;
            $request = new Request(null, $input, null, $server);
            $this->container->instance(Request::class, $request);

            if ($this->requiresCsrf($request)) {
                $token = $request->input('_token', $request->header('X-CSRF-TOKEN'));
                if (!$this->container->get(Csrf::class)->validate((string) $token)) {
                    return null;
                }
            }

            $route = $this->router->match($request->method(), $request->path());
            if ($route === null) {
                return null;
            }

            $core = function (Request $req) use ($route) {
                return $this->dispatch($route->handler, $route->params);
            };

            return $this->runMiddleware($route->middleware, $request, $core);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Split the panel into two entry points:
     *  - admin port (config app.admin_port, default 8443): admin area
     *    (/admin, /login) only; customer paths bounce to the public port.
     *  - public port (APP_URL): customer panel (/panel, /panel/login) only;
     *    admin paths bounce to the admin port.
     */
    private function areaGuard(Request $request): ?Response
    {
        $port = (int) ($_SERVER['SERVER_PORT'] ?? 443);
        $adminPort = (int) $this->config->get('app.admin_port', 8443);
        $path = ltrim($request->path(), '/');

        // Misconfiguration guard: if the admin port collides with the public
        // port, split is a no-op (avoiding a redirect loop between the two).
        if ($adminPort === $port) {
            return null;
        }

        if ($port === $adminPort) {
            if (str_starts_with($path, 'panel')) {
                $status = $request->method() === 'POST' ? 307 : 302;
                return Response::redirect($this->areaUrl(false, $path), $status);
            }
            return null;
        }

        if (str_starts_with($path, 'admin') || str_starts_with($path, 'login')) {
            // Preserve POST requests across the port bounce: a 302 makes
            // browsers convert the POST into a GET, silently dropping the body
            // (bitten live — every admin form that POSTed to the public APP_URL
            // origin died here: reboot, install-now, mode, root-forward, …).
            // 307 keeps method + body intact for the admin-port handler.
            $status = $request->method() === 'POST' ? 307 : 302;
            return Response::redirect($this->areaUrl(true, $path), $status);
        }

        return null;
    }

    private function areaUrl(bool $admin, string $path): string
    {
        $parts = parse_url((string) $this->config->get('app.url'));
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? (gethostname() ?: 'localhost');
        if ($admin) {
            $port = ':' . (int) $this->config->get('app.admin_port', 8443);
        } else {
            // Keep a non-standard public port (e.g. 8080 in dev).
            $public = isset($parts['port']) ? (int) $parts['port'] : 0;
            $default = $scheme === 'https' ? 443 : 80;
            $port = ($public > 0 && $public !== $default) ? ':' . $public : '';
        }
        return $scheme . '://' . $host . $port . '/' . ltrim($path, '/');
    }

    private function startSession(Request $request): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        try {
            $this->container->get(Session::class)->start();
        } catch (Throwable $e) {
            $this->container->get(Logger::class)->warning('Session start failed: ' . $e->getMessage());
        }
    }

    private function requiresCsrf(Request $request): bool
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return false;
        }

        // Only the public app webhook receiver is CSRF-exempt (it authenticates
        // with an HMAC signature before writing anything). The exemption is
        // route-specific — never prefix-wide — so a future state-changing
        // /api/hooks/* route cannot silently inherit it.
        if ($request->method() === 'POST'
            && preg_match('#^/api/hooks/apps/\d+$#', $request->path()) === 1
        ) {
            return false;
        }

        // Public API v1 (external billing clients): every route is
        // authenticated by Bearer API key middleware — CSRF tokens do not
        // apply to non-session callers.
        if (preg_match('#^/api/v1/#', $request->path()) === 1) {
            return false;
        }

        return true;
    }

    private function registerCoreServices(): void
    {
        $dbConfig = $this->config->get('database');

        $db = new Connection(
            (string) ($dbConfig['host'] ?? '127.0.0.1'),
            (int) ($dbConfig['port'] ?? 3306),
            (string) ($dbConfig['database'] ?? 'cwhpanel'),
            (string) ($dbConfig['username'] ?? 'root'),
            (string) ($dbConfig['password'] ?? ''),
            (string) ($dbConfig['charset'] ?? 'utf8mb4')
        );
        $this->container->instance(Connection::class, $db);

        $session = new Session($db, (string) $this->config->get('session.table', 'sessions'));
        $this->container->instance(Session::class, $session);

        $audit = new Audit($db, $session);
        $this->container->instance(Audit::class, $audit);

        $rateLimiter = new RateLimiter($db);
        $this->container->instance(RateLimiter::class, $rateLimiter);

        $auth = new Auth($db, $session, $rateLimiter, $audit);
        $this->container->instance(Auth::class, $auth);

        $gate = new Gate($db);
        $this->container->instance(Gate::class, $gate);

        $csrf = new Csrf($session);
        $this->container->instance(Csrf::class, $csrf);

        $view = new View($this->basePath . '/resources/views');
        $this->container->instance(View::class, $view);

        $icons = new Icon();
        $this->container->instance(Icon::class, $icons);

        $nav = new NavigationRegistry();
        $this->container->instance(NavigationRegistry::class, $nav);

        $events = new Dispatcher($this->container);
        $this->container->instance(Dispatcher::class, $events);

        $tickets = new AuthTicketService(
            (string) $this->config->get('provisioning.admin_key', ''),
            (string) $this->config->get('provisioning.customer_key', '')
        );
        $provisioning = new PrivilegedOperationService(
            $db,
            (bool) ($this->config->get('provisioning.enabled', false)),
            (string) ($this->config->get('provisioning.socket', '/run/cwhpanel/agent.sock')),
            (int) ($this->config->get('provisioning.timeout', 30)),
            $tickets
        );
        $this->container->instance(PrivilegedOperationService::class, $provisioning);
        $this->container->instance(AuthTicketService::class, $tickets);

        $validator = new Validator();
        $this->container->instance(Validator::class, $validator);

        foreach ([
            'env' => Env::class,
            'config' => Config::class,
            'logger' => Logger::class,
            'db' => Connection::class,
            'session' => Session::class,
            'audit' => Audit::class,
            'rate-limiter' => RateLimiter::class,
            'auth' => Auth::class,
            'gate' => Gate::class,
            'csrf' => Csrf::class,
            'view' => View::class,
            'icons' => Icon::class,
            'navigation' => NavigationRegistry::class,
            'events' => Dispatcher::class,
            'provisioning' => PrivilegedOperationService::class,
            'validator' => Validator::class,
            'request' => Request::class,
        ] as $alias => $class) {
            $this->container->alias($class, $alias);
        }
    }

    private function registerMiddleware(): void
    {
        $this->middleware['auth'] = function (Request $request, Closure $next): Response {
            if (!app('auth')->check()) {
                if ($request->wantsJson()) {
                    return Response::json(['error' => 'Unauthenticated.'], 401);
                }

                return Response::redirect('/login');
            }

            return $next($request);
        };

        $this->middleware['auth.admin'] = function (Request $request, Closure $next): Response {
            if (!app('auth')->check()) {
                if ($request->wantsJson()) {
                    return Response::json(['error' => 'Unauthenticated.'], 401);
                }

                return Response::redirect('/login');
            }

            if (!app('auth')->isAdmin()) {
                if ($request->wantsJson()) {
                    return Response::json(['error' => 'Forbidden.'], 403);
                }

                return Response::redirect('/panel');
            }

            // Agent trust model: admin-origin provisioning calls mint admin
            // tickets (the agent's admin policy allows all allowlisted ops).
            app(\App\Foundation\Provisioning\PrivilegedOperationService::class)
                ->setActor(['role' => 'admin', 'account' => null]);

            return $next($request);
        };

        $this->middleware['auth.customer'] = function (Request $request, Closure $next): Response {
            if (!app('auth')->check()) {
                if ($request->wantsJson()) {
                    return Response::json(['error' => 'Unauthenticated.'], 401);
                }

                return Response::redirect('/panel/login');
            }

            // Agent trust model: customer-origin provisioning calls mint
            // customer tickets bound to the customer's own hosting account.
            $account = null;
            $user = app('auth')->user();
            if ($user !== null) {
                $row = app('db')->selectOne('SELECT `username` FROM `accounts` WHERE `user_id` = ? LIMIT 1', [$user->id]);
                if ($row !== null) {
                    $account = (string) $row['username'];
                }
            }
            app(\App\Foundation\Provisioning\PrivilegedOperationService::class)
                ->setActor(['role' => 'customer', 'account' => $account]);

            return $next($request);
        };

        // API v1: Bearer-token authentication via the api_keys table (hashed
        // key, active status, owner user). API access is ADMIN-ONLY: the
        // customer panel no longer exposes key management, and a key whose
        // owner is not an administrator is refused here (defence in depth
        // against a hand-inserted or legacy row).
        $this->middleware['auth.api_key'] = function (Request $request, Closure $next): Response {
            $authHeader = (string) $request->header('Authorization', '');
            if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m) !== 1) {
                return Response::json(['error' => 'Missing API key (Authorization: Bearer <key>).'], 401);
            }
            $key = trim($m[1]);
            $row = app('db')->selectOne(
                'SELECT * FROM `api_keys` WHERE `key` = ? AND `status` = ?',
                [hash('sha256', $key), 'active']
            );
            if ($row === null) {
                return Response::json(['error' => 'Invalid or revoked API key.'], 401);
            }
            $user = app('db')->selectOne('SELECT * FROM `users` WHERE `id` = ?', [(int) $row['user_id']]);
            if ($user === null || ($user['status'] ?? 'active') !== 'active') {
                return Response::json(['error' => 'API key owner is unavailable.'], 401);
            }
            $isAdmin = (int) ($user['is_admin'] ?? 0) === 1;
            if (!$isAdmin) {
                return Response::json(['error' => 'API access is restricted to administrator keys.'], 403);
            }
            app('db')->update('api_keys', ['last_used_at' => date('Y-m-d H:i:s')], '`id` = ?', [(int) $row['id']]);
            app(\App\Foundation\Container::class)->instance('api_key', $row);
            app(\App\Foundation\Container::class)->instance('api_user', $user);

            app(\App\Foundation\Provisioning\PrivilegedOperationService::class)
                ->setActor(['role' => 'admin', 'account' => null]);

            return $next($request);
        };

        // Step-up authentication for destructive admin actions: requires a
        // fresh password/TOTP verification (auth_verified_at) within the
        // configured window. A stolen-but-idle session cannot be used to run
        // high-impact mutations without re-proving the credentials.
        $this->middleware['stepup'] = function (Request $request, Closure $next): Response {
            $auth = app('auth');
            if (!$auth->check() || !$auth->isAdmin()) {
                return $next($request);
            }
            $window = max(60, (int) config('auth.stepup_window', 600));
            $verifiedAt = (int) app('session')->get('auth_verified_at', 0);
            if ($verifiedAt > 0 && (time() - $verifiedAt) <= $window) {
                return $next($request);
            }
            if ($request->wantsJson()) {
                return Response::json(['error' => 'Step-up authentication required.'], 403);
            }
            // The request path already carries the /admin prefix (the admin
            // area is mounted there) — store it verbatim. Prepending another
            // /admin produced /admin/admin/… and a 404 after confirmation.
            $path = '/' . ltrim($request->path(), '/');
            app('session')->put('stepup_next', $path);
            // Landing page if the action cannot be replayed (never redirect
            // to the endpoint itself — as a GET it would 404 on POST-only
            // routes). Prefer the referring admin page.
            $page = '/admin';
            $ref = (string) $request->header('Referer');
            if ($ref !== '') {
                $refPath = (string) (parse_url($ref, PHP_URL_PATH) ?: '');
                if (str_starts_with($refPath, '/admin')) {
                    $page = $refPath;
                }
            }
            app('session')->put('stepup_page', $page);
            // For POST actions remember the full request so the confirm
            // handler can replay it after verification — otherwise the
            // change is silently lost (the browser would GET the endpoint).
            if ($request->method() === 'POST') {
                $input = $request->all();
                if (strlen((string) json_encode($input, JSON_UNESCAPED_SLASHES)) <= 65536) {
                    app('session')->put('stepup_replay', [
                        'method' => $request->method(),
                        'path' => $path,
                        'input' => $input,
                    ]);
                }
            }
            app('session')->flash('error', 'Please confirm your password to continue.');
            return Response::redirect('/admin/confirm');
        };

        $this->middleware['guest'] = function (Request $request, Closure $next): Response {
            if (app('auth')->check()) {
                $user = app('auth')->user();

                return Response::redirect($user !== null && $user->isAdmin ? '/admin' : '/panel');
            }

            return $next($request);
        };

        // Package-tied feature gates: `feature.<module>` denies customers
        // whose package disables the module. Admins always pass. Denial
        // redirects (all customer POSTs are form submits) with a flash.
        //
        // The gate list lives in the Packages module. Registration must not
        // hard-depend on it: with Packages disabled/removed the keys come
        // from the fallback below and every gate becomes a pass-through
        // (fail-open). Registering no middleware at all would be worse —
        // runMiddleware denies unknown middleware names (fail-closed), so
        // every route carrying `feature.*` would 403 the whole panel.
        $featureKeys = self::FALLBACK_FEATURES;
        if (class_exists(\Modules\Packages\Services\FeatureService::class)) {
            $featureKeys = \Modules\Packages\Services\FeatureService::keys();
        }

        foreach ($featureKeys as $feature) {
            $this->middleware['feature.' . $feature] = function (Request $request, Closure $next) use ($feature): Response {
                try {
                    $packages = app()->moduleManager()->isEnabled('packages');
                } catch (Throwable) {
                    $packages = false;
                }

                if (!$packages) {
                    return $next($request);
                }

                try {
                    app(\Modules\Packages\Services\FeatureService::class)->require($feature);
                } catch (\App\Foundation\Auth\ForbiddenException $e) {
                    if ($request->wantsJson()) {
                        return Response::json(['error' => $e->getMessage()], 403);
                    }
                    app('session')->flash('error', $e->getMessage());
                    return Response::redirect('/panel');
                }

                return $next($request);
            };
        }
    }

    private function registerCoreRoutes(): void
    {
        $this->router->get('/', function () {
            if (app('auth')->check()) {
                $user = app('auth')->user();

                return Response::redirect($user !== null && $user->isAdmin ? '/admin' : '/panel');
            }

            // Public entry point: the customer login page by default (admin
            // lives on its dedicated port).
            return Response::redirect('/panel/login');
        });
    }

    private function runMiddleware(array $names, Request $request, Closure $core): Response
    {
        $pipeline = array_reduce(
            array_reverse($names),
            function (Closure $next, string $name): Closure {
                return function (Request $req) use ($next, $name): Response {
                    // Parameterized form `can:perm.one,perm.two` (colon +
                    // comma): any-of semantics via Gate::any. Unknown
                    // `can:*` names fail closed — a typo must deny, never
                    // silently pass.
                    if (str_starts_with($name, 'can:')) {
                        $perms = array_values(array_filter(
                            array_map('trim', explode(',', substr($name, 4))),
                            fn ($p) => $p !== ''
                        ));

                        if ($perms === []) {
                            return $this->deny($req, 'Misconfigured permission gate.');
                        }

                        $user = app('auth')->user();
                        $gate = app('gate');
                        // Mutating requests may not be satisfied by a read-only
                        // `.view` permission when the module declares a
                        // mutating permission (see Gate::canMutating).
                        $mutating = !in_array($req->method(), ['GET', 'HEAD', 'OPTIONS'], true);

                        foreach ($perms as $perm) {
                            if ($mutating ? $gate->canMutating($user, $perm) : $gate->can($user, $perm)) {
                                return $next($req);
                            }
                        }

                        return $this->deny($req, 'You do not have permission to access this section.');
                    }

                    $middleware = $this->middleware[$name] ?? null;

                    if ($middleware === null) {
                        // Fail closed: a typo'd/renamed middleware must deny,
                        // never silently run the handler ungated.
                        return $this->deny($req, 'Misconfigured access control.');
                    }

                    return $middleware($req, $next);
                };
            },
            $core
        );

        return $pipeline($request);
    }

    private function deny(Request $request, string $message): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['error' => 'Forbidden.'], 403);
        }

        app('session')->flash('error', $message);

        // /admin is itself gated (dashboard.view), so a user without it
        // lands on a page that bounces them onward — but the flash set
        // above survives the hop and explains why. Never /login directly:
        // the guest gate would bounce a logged-in user back to /admin and
        // loop forever.
        return Response::redirect('/admin');
    }

    private function dispatch($handler, array $params): Response
    {
        $result = $this->container->call($handler, $params);

        return $this->toResponse($result);
    }

    private function toResponse($result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result) || is_object($result)) {
            return Response::json($result);
        }

        return new Response((string) $result);
    }
}

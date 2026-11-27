<?php

declare(strict_types=1);

namespace Modules\Authentication\Controllers;

use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\ValidationException;

class LoginController
{
    public function show(): Response
    {
        return $this->renderLogin('Administrator', 'admin');
    }

    public function showCustomer(): Response
    {
        return $this->renderLogin('Customer', 'customer');
    }

    public function login(Request $request): Response
    {
        return $this->attempt($request, 'admin', '/admin');
    }

    public function loginCustomer(Request $request): Response
    {
        return $this->attempt($request, 'customer', '/panel');
    }

    public function logout(): Response
    {
        app('auth')->logout();

        return Response::redirect('/login');
    }

    public function showConfirm(): Response
    {
        if (!app('auth')->isAdmin()) {
            return Response::redirect('/panel');
        }
        $content = view('auth/confirm', [
            'title' => 'Confirm identity',
            'heading' => 'Confirm identity',
            'postUrl' => '/admin/confirm',
            'requiresTwoFactor' => app('auth')->user() !== null
                && app('auth')->user()->twoFactorEnabled
                && app('auth')->user()->twoFactorSecret,
        ], 'layouts/auth');

        return $this->noStore((string) $content);
    }

    public function confirm(Request $request): Response
    {
        if (!app('auth')->isAdmin()) {
            return Response::redirect('/panel');
        }
        $user = app('auth')->user();
        if ($user === null) {
            return Response::redirect('/admin');
        }

        // Step-up verifies BOTH the password and the TOTP, i.e. it is a
        // credential oracle for anyone holding a stolen admin session. Without
        // a limiter the whole point of the control (surviving session theft)
        // fails: 6-digit codes are brute-forceable at full HTTP rate and the
        // /login limiter is a different route.
        $limiter = app('rate-limiter');
        $uid = (int) $user->id;
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $stepKey = 'stepup:' . $uid;
        $stepIpKey = 'stepup-ip:' . $ip;
        $totpKey = 'stepup-2fa:' . $uid;
        if ($limiter->tooManyAttempts($stepKey, 5, 300)
            || $limiter->tooManyAttempts($stepIpKey, 30, 900)
            || $limiter->tooManyAttempts($totpKey, 10, 900)) {
            app('audit')->log('auth.stepup.throttled', 'user', $uid);
            app('session')->flash('error', 'Too many verification attempts. Try again in a few minutes.');
            return Response::redirect('/admin/confirm');
        }

        $row = app('db')->selectOne('SELECT * FROM `users` WHERE `id` = ? LIMIT 1', [$user->id]);
        $password = (string) $request->input('password', '');
        if ($row === null || !password_verify($password, (string) $row['password'])) {
            $limiter->hit($stepKey, 5, 300);
            $limiter->hit($stepIpKey, 30, 900);
            app('audit')->log('auth.stepup.failed', 'user', $user->id);
            app('session')->flash('error', 'Password verification failed.');
            return Response::redirect('/admin/confirm');
        }
        if (!empty($user->twoFactorEnabled) && $user->twoFactorSecret) {
            // Replay-safe verification (consumes the code's time-step).
            if (!app('auth')->verifyTotpOnce((int) $user->id, (string) $user->twoFactorSecret, (string) $request->input('totp', ''))) {
                $limiter->hit($stepKey, 5, 300);
                $limiter->hit($stepIpKey, 30, 900);
                $limiter->hit($totpKey, 10, 900);
                app('audit')->log('auth.stepup.failed', 'user', $user->id);
                app('session')->flash('error', 'Two-factor code is invalid.');
                return Response::redirect('/admin/confirm');
            }
        }
        $limiter->clear($stepKey);
        $limiter->clear($stepIpKey);
        $limiter->clear($totpKey);
        app('session')->put('auth_verified_at', time());
        app('audit')->log('auth.stepup.ok', 'user', $user->id);
        $next = (string) app('session')->get('stepup_next', '/admin');
        $page = (string) app('session')->get('stepup_page', '/admin');
        app('session')->forget('stepup_next');
        app('session')->forget('stepup_page');
        $replay = app('session')->get('stepup_replay');
        app('session')->forget('stepup_replay');

        // A verified destructive POST is replayed server-side so the change
        // actually applies — redirecting the browser to the endpoint as a GET
        // would 404 on POST-only routes (/admin/admin/… style double prefixes
        // and lost bodies are both historical bugs here).
        if (is_array($replay) && strtoupper((string) ($replay['method'] ?? '')) === 'POST') {
            $replayed = app()->replayRequest(
                (string) $replay['method'],
                (string) ($replay['path'] ?? ''),
                (array) ($replay['input'] ?? [])
            );
            if ($replayed !== null) {
                return $replayed;
            }
            // Replay unavailable — land on the referring admin page, never the
            // POST endpoint (a GET there would 404).
            $page = preg_replace('#[^a-zA-Z0-9/_?&=%.:-]#', '', $page);
            return Response::redirect($page !== '' ? $page : '/admin');
        }

        $next = preg_replace('#[^a-zA-Z0-9/_?&=%.:-]#', '', $next);
        return Response::redirect($next !== '' ? $next : '/admin');
    }

    public function stopImpersonate(): Response
    {
        if (app('auth')->masquerading() === null) {
            return Response::redirect('/panel');
        }
        app('auth')->stopImpersonate();

        return Response::redirect('/admin');
    }

    private function renderLogin(string $heading, string $type): Response
    {
        $content = view('auth/login', [
            'title' => 'Sign in',
            'heading' => $heading,
            'type' => $type,
            'postUrl' => $type === 'admin' ? '/login' : '/panel/login',
        ], 'layouts/auth');

        return $this->noStore((string) $content);
    }

    /**
     * Auth pages carry a one-shot CSRF token: never let the browser serve them
     * from cache/bfcache, or the submitted token belongs to a dead session and
     * the login silently fails with a "session expired" flash the user never
     * sees (bitten live on the admin login page).
     */
    private function noStore(string $body, int $status = 200): Response
    {
        return new Response($body, $status, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    private function attempt(Request $request, string $type, string $successPath): Response
    {
        try {
            $data = $request->validate([
                'email' => 'required|email',
                'password' => 'required',
            ]);
        } catch (ValidationException $e) {
            return $this->fail($request, $e, $type === 'admin' ? '/login' : '/panel/login');
        }

        $email = (string) $data['email'];
        $password = (string) $data['password'];
        $key = app('auth')->throttleKey($email);

        $maxAttempts = (int) config('auth.login.max_attempts', 5);

        if (app('rate-limiter')->tooManyAttempts($key, $maxAttempts)) {
            app('session')->flashInput($request->only(['email']));
            app('session')->flash('error', 'Too many login attempts. Please try again in a few minutes.');

            return Response::redirectBack($type === 'admin' ? '/login' : '/panel/login');
        }

        $result = app('auth')->attempt($email, $password, $key, $type === 'admin' ? 'admin' : 'customer');

        if ($result === '2fa') {
            // Password accepted but 2FA required. Send to the challenge page,
            // preserving the intended destination in the session.
            app('session')->put('2fa_redirect', $type === 'admin' ? '/admin' : '/panel');
            return Response::redirect($type === 'admin' ? '/login/2fa' : '/panel/login/2fa');
        }

        if ($result === '2fa_required') {
            // Administrator 2FA enforcement refusal: the specific reason is
            // flashed by Auth::attempt — never overwrite it with a generic
            // credential error.
            return Response::redirect($type === 'admin' ? '/login' : '/panel/login');
        }

        if ($result !== true) {
            try {
                app(\Modules\Security\Services\LoginAttackService::class)
                    ->recordFailure($request->ip(), $email);
            } catch (\Throwable $e) {
                app('logger')->warning('Login auto-block hook failed: ' . $e->getMessage());
            }
            return $this->fail($request, null, $type === 'admin' ? '/login' : '/panel/login', 'Invalid email or password.');
        }

        $user = app('auth')->user();

        // Wrong-panel accounts never reach this point: Auth::attempt() refuses
        // them as a plain credential failure (same message, same throttle) so
        // the customer panel cannot be used to probe admin credentials and no
        // message is lost across the split-panel port boundary.

        app('session')->regenerate();
        app('session')->flash('success', 'Welcome back, ' . e($user->name) . '.');

        return Response::redirect($successPath);
    }

    public function showTwoFactor(): Response
    {
        $content = view('auth/two-factor', [
            'title' => 'Two-Factor Authentication',
            'postUrl' => '/login/2fa',
        ], 'layouts/auth');

        return $this->noStore((string) $content);
    }

    public function showCustomerTwoFactor(): Response
    {
        $content = view('auth/two-factor', [
            'title' => 'Two-Factor Authentication',
            'postUrl' => '/panel/login/2fa',
        ], 'layouts/auth');

        return $this->noStore((string) $content);
    }

    public function verifyTwoFactor(Request $request): Response
    {
        return $this->verify($request, 'admin', '/login/2fa');
    }

    public function verifyCustomerTwoFactor(Request $request): Response
    {
        return $this->verify($request, 'customer', '/panel/login/2fa');
    }

    private function verify(Request $request, string $type, string $back): Response
    {
        $code = (string) $request->input('code', '');

        if (!app('auth')->completeTwoFactor($code)) {
            app('session')->flash('error', 'Invalid authentication code.');
            return Response::redirect($back);
        }

        $user = app('auth')->user();
        $target = app('session')->pull('2fa_redirect') ?? ($type === 'admin' ? '/admin' : '/panel');
        app('session')->flash('success', 'Welcome back, ' . e($user->name) . '.');

        return Response::redirect($target);
    }

    private function fail(Request $request, ?ValidationException $e, string $back, ?string $message = null): Response
    {
        app('session')->flashInput($request->only(['email']));

        if ($message !== null) {
            app('session')->flash('error', $message);
        }

        if ($e !== null) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $msg) {
                    app('session')->pushError($field, $msg);
                }
            }
        }

        return Response::redirectBack($back);
    }
}

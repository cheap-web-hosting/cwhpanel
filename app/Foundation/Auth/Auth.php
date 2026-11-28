<?php

declare(strict_types=1);

namespace App\Foundation\Auth;

use App\Foundation\Audit;
use App\Foundation\Database\Connection;
use App\Foundation\RateLimiter;
use App\Foundation\Session;

class Auth
{
    private Connection $db;

    private Session $session;

    private RateLimiter $rateLimiter;

    private Audit $audit;

    private ?User $user = null;

    public function __construct(Connection $db, Session $session, RateLimiter $rateLimiter, Audit $audit)
    {
        $this->db = $db;
        $this->session = $session;
        $this->rateLimiter = $rateLimiter;
        $this->audit = $audit;
    }

    /**
     * Attempt password authentication.
     *
     * @return true when fully authenticated, '2fa' when the user has two-factor
     *         auth enabled and must complete a second step, false on failure.
     */
    /**
     * Dummy bcrypt hash used when the email does not exist: verification must
     * run anyway so unknown-user and wrong-password attempts take comparable
     * time (no login timing oracle for account enumeration).
     */
    private const DUMMY_HASH = '$2y$12$R4QMAn50jt5mLz.G8e5Zyu3G4PLu6.cX.46mHPIWKSqf6M7LFb50q';

    public function attempt(string $email, string $password, string $key, string $area = 'any'): bool|string
    {
        // Two keys: per-email (stops targeted guessing) and per-IP (stops
        // distributed guessing across many emails). The IP key uses a higher
        // ceiling / longer window so a shared NAT is not trivially locked out.
        $ipKey = 'login-ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        // Third key: a per-IP GLOBAL ceiling across all accounts. Per-email
        // keys cannot bound an attacker who enumerates many distinct emails
        // from one IP (each email pays its own 5-attempt budget, so bulk
        // enumeration across accounts is otherwise unbounded).
        $globalKey = 'login-global-ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        // Caps come from config; hit() takes the SAME caps so the atomic
        // ceiling (count+insert in one statement) never drifts from the
        // pre-check.
        $maxAttempts = (int) config('auth.login.max_attempts', 5);
        $decaySeconds = (int) config('auth.login.decay_seconds', 300);

        if ($this->rateLimiter->tooManyAttempts($key, $maxAttempts, $decaySeconds)
            || $this->rateLimiter->tooManyAttempts($ipKey, 30, 900)
            || $this->rateLimiter->tooManyAttempts($globalKey, 100, 900)) {
            return false;
        }

        $row = $this->db->selectOne('SELECT * FROM `users` WHERE `email` = ? LIMIT 1', [$email]);

        // Unknown emails still pay a bcrypt verify against a dummy hash so the
        // two failure paths complete in comparable time.
        $hash = $row === null ? self::DUMMY_HASH : (string) $row['password'];
        if ($row === null || !password_verify($password, $hash)) {
            $this->rateLimiter->hit($key, $maxAttempts, $decaySeconds);
            $this->rateLimiter->hit($ipKey, 30, 900);
            $this->rateLimiter->hit($globalKey, 100, 900);
            $this->audit->log('auth.failed', 'user', null, ['email' => $email]);
            return false;
        }

        if (($row['status'] ?? 'active') !== 'active') {
            // Disabled accounts are throttled too (no free oracle).
            $this->rateLimiter->hit($key, $maxAttempts, $decaySeconds);
            $this->rateLimiter->hit($ipKey, 30, 900);
            $this->rateLimiter->hit($globalKey, 100, 900);
            $this->audit->log('auth.failed', 'user', (int) $row['id'], ['email' => $email, 'disabled' => true]);
            return false;
        }

        // Panel class enforcement. An administrator who signs in on the
        // customer panel (or a customer on the admin panel) must fail exactly
        // like a wrong password: same generic message, same throttle, no hint
        // that the credentials are valid somewhere else. Enforcing it HERE,
        // before the 2FA branch, also stops an admin account from completing
        // two-factor through the customer panel and landing as an admin.
        $isAdmin = (int) ($row['is_admin'] ?? 0) === 1;
        if (($area === 'admin' && !$isAdmin) || ($area === 'customer' && $isAdmin)) {
            $this->rateLimiter->hit($key, $maxAttempts, $decaySeconds);
            $this->rateLimiter->hit($ipKey, 30, 900);
            $this->rateLimiter->hit($globalKey, 100, 900);
            $this->audit->log('auth.failed', 'user', (int) $row['id'], ['email' => $email, 'wrong_area' => $area]);
            return false;
        }

        $this->rateLimiter->clear($key);

        $user = new User($row);
        $this->session->regenerate();

        // Optional hard requirement: when enabled, administrator logins must
        // be protected by TOTP (fail-closed until enrolled).
        if ($user->isAdmin && self::adminTwoFactorRequired() && !$user->twoFactorEnabled) {
            $this->rateLimiter->hit($key, $maxAttempts, $decaySeconds);
            $this->rateLimiter->hit($ipKey, 30, 900);
            $this->audit->log('auth.failed', 'user', (int) $user->id, ['email' => $email, '2fa_required' => true]);
            $this->session->flash('error', 'Administrator accounts must enable two-factor authentication before signing in.');
            return '2fa_required';
        }

        if ($user->twoFactorEnabled && $user->twoFactorSecret) {
            // Two-phase: hold the user id pending TOTP verification.
            $this->session->put('pending_2fa_user_id', $user->id);
            $this->session->forget('user_id');
            $this->user = null;
            return '2fa';
        }

        $this->completeLogin($user);
        return true;
    }

    /**
     * Verify the TOTP code and complete the held two-factor login.
     */
    public function completeTwoFactor(string $code): bool
    {
        $pendingId = $this->session->get('pending_2fa_user_id');

        if ($pendingId === null) {
            return false;
        }

        $row = $this->db->selectOne('SELECT * FROM `users` WHERE `id` = ? LIMIT 1', [(int) $pendingId]);

        if ($row === null) {
            $this->session->forget('pending_2fa_user_id');
            return false;
        }

        // Rate-limit second-factor guesses: a known password must not permit
        // unlimited TOTP attempts (the password step already cleared the login
        // limiter, so this is a separate bucket keyed on the pending user).
        $tfKey = '2fa:' . (int) $pendingId;
        if ($this->rateLimiter->tooManyAttempts($tfKey, 10, 900)) {
            return false;
        }

        $totp = new \Modules\Authentication\Services\Totp();

        // Replay-safe: the code's time-step is consumed atomically, so the same
        // 6-digit code cannot be used twice inside its ±1 window.
        if (!$this->verifyTotpOnce((int) $pendingId, (string) $row['two_factor_secret'], $code)) {
            $this->rateLimiter->hit($tfKey, 10, 900);
            $this->audit->log('auth.2fa.failed', 'user', (int) $pendingId);
            return false;
        }

        $this->rateLimiter->clear($tfKey);
        $this->session->forget('pending_2fa_user_id');
        $this->completeLogin(new User($row));
        return true;
    }

    /**
     * Verify a TOTP code AND consume its RFC 6238 time-step.
     *
     * Codes stay valid for a ±1 window (~90s) and verification used to be
     * stateless, so a code observed once (AiTM proxy, screen share, shoulder
     * surf) could be replayed with the password. The UPDATE is atomic: it only
     * matches when the presented step is newer than the stored one, which also
     * closes the concurrent-request race.
     */
    public function verifyTotpOnce(int $userId, string $secret, string $code): bool
    {
        $totp = new \Modules\Authentication\Services\Totp();
        $step = $totp->verifyStep($secret, $code);
        if ($step === null) {
            return false;
        }
        try {
            $stmt = $this->db->statement(
                'UPDATE `users` SET `two_factor_last_step` = ? WHERE `id` = ?'
                . ' AND (`two_factor_last_step` IS NULL OR `two_factor_last_step` < ?)',
                [$step, $userId, $step]
            );
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            // FAIL CLOSED: a code whose replay state cannot be persisted must
            // not be accepted a second time (nor at all — the caller re-prompts
            // and the operator sees the loud error).
            app('logger')->error('TOTP replay store failed (code refused): ' . $e->getMessage());
            return false;
        }
    }

    private function completeLogin(User $user): void
    {
        $this->session->put('user_id', $user->id);
        $this->user = $user;
        // A finished login must not leave a previous identity's challenge
        // behind: on a shared browser, user A abandoning the 2FA prompt and
        // user B logging in fully left A's pending id answerable from B's
        // session (the /login/2fa routes carry no middleware).
        $this->session->forget('pending_2fa_user_id');
        $this->session->forget('2fa_redirect');
        // Fresh step-up credential verification (password/TOTP).
        $this->session->put('auth_verified_at', time());

        $this->db->update('users', ['last_login_at' => date('Y-m-d H:i:s')], '`id` = ?', [$user->id]);

        $this->audit->setUser($user->id);
        $this->audit->log('auth.login', 'user', $user->id);
    }

    /**
     * Whether the auth.require_admin_2fa setting is enabled (default off —
     * turning it on without first enrolling every admin would lock them out).
     */
    private static function adminTwoFactorRequired(): bool
    {
        try {
            return (string) app('db')->scalar("SELECT `value` FROM `settings` WHERE `key` = 'auth.require_admin_2fa' LIMIT 1") === '1';
        } catch (\Throwable $e) {
            // Security control: fail CLOSED. An unreadable settings row must
            // never weaken the admin 2FA gate — a transient DB error would
            // otherwise let a password-only admin login proceed.
            app('logger')->error('Failed to read auth.require_admin_2fa setting; requiring 2FA: ' . $e->getMessage());
            return true;
        }
    }

    /**
     * Admin impersonation ("login as customer"): swaps the session to the
     * target user while remembering the admin. Nested impersonation and
     * admin targets are refused. Both transitions regenerate the session id
     * and are audited. While masquerading, auth.admin gates deny (the
     * session IS the customer) — the only way back is stopImpersonate().
     */
    public function impersonate(int $targetUserId): void
    {
        $admin = $this->user();
        if ($admin === null || !$admin->isAdmin) {
            throw new \RuntimeException('Only administrators can impersonate.');
        }
        if ($this->session->get('masquerade_from') !== null) {
            throw new \RuntimeException('Already impersonating.');
        }
        $row = $this->db->selectOne('SELECT * FROM `users` WHERE `id` = ? LIMIT 1', [$targetUserId]);
        if ($row === null) {
            throw new \RuntimeException('Target user not found.');
        }
        $target = new User($row);
        if ($target->isAdmin) {
            throw new \RuntimeException('Cannot impersonate an administrator.');
        }
        $this->audit->setUser($admin->id);
        $this->audit->log('auth.impersonate.start', 'user', $target->id);
        $this->session->put('masquerade_from', $admin->id);
        $this->session->regenerate();
        $this->completeLogin($target);
    }

    /** Admin id we are masquerading from, or null. */
    public function masquerading(): ?int
    {
        $from = $this->session->get('masquerade_from');
        return $from !== null ? (int) $from : null;
    }

    public function stopImpersonate(): void
    {
        $from = $this->masquerading();
        if ($from === null) {
            return;
        }
        $row = $this->db->selectOne('SELECT * FROM `users` WHERE `id` = ? LIMIT 1', [$from]);
        if ($row === null) {
            $this->logout();
            return;
        }
        $this->audit->setUser($from);
        $this->audit->log('auth.impersonate.stop', 'user', (int) ($this->id() ?? 0));
        $this->session->forget('masquerade_from');
        $this->session->regenerate();
        $this->completeLogin(new User($row));
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function id(): ?int
    {
        $id = $this->session->get('user_id');
        return $id !== null ? (int) $id : null;
    }

    public function user(): ?User
    {
        $id = $this->id();

        if ($id === null) {
            return null;
        }

        if ($this->user !== null && $this->user->id === $id) {
            return $this->user;
        }

        $row = $this->db->selectOne('SELECT * FROM `users` WHERE `id` = ? LIMIT 1', [$id]);

        if ($row === null) {
            $this->logout();
            return null;
        }

        // Re-check status on every request: a suspended/disabled user must lose
        // authenticated access promptly, not at session expiry.
        if (($row['status'] ?? 'active') !== 'active') {
            $this->logout();
            return null;
        }

        return $this->user = new User($row);
    }

    public function isAdmin(): bool
    {
        $user = $this->user();
        return $user !== null && $user->isAdmin;
    }

    public function logout(): void
    {
        $id = $this->id();

        if ($id !== null) {
            $this->audit->setUser($id);
            $this->audit->log('auth.logout', 'user', $id);
        }

        $this->user = null;
        $this->session->flush();
    }

    public function throttleKey(string $email): string
    {
        // Bind the per-email bucket to the source IP. The bucket is stored in a
        // shared table, so an email-only key let ONE attacker pin a known
        // account at the cap indefinitely — the victim's own attempts were
        // refused before the password was ever checked (permanent lockout DoS).
        // With the IP in the key an attacker only locks themselves out; the
        // per-IP (30/15min) and global-per-IP (100/15min) ceilings still bound
        // distributed guessing.
        return 'login:' . mb_strtolower(trim($email)) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }
}

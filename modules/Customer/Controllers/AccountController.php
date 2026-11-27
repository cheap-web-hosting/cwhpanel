<?php

declare(strict_types=1);

namespace Modules\Customer\Controllers;

use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\ValidationException;

class AccountController
{
    public function profile(): Response
    {
        $user = app('auth')->user();

        if ($user === null) {
            return Response::redirect('/panel/login');
        }

        $role = $user->roleId !== null
            ? app('db')->selectOne('SELECT `name` FROM `roles` WHERE `id` = ?', [$user->roleId])
            : null;

        $hosting = app('db')->selectOne('SELECT * FROM `accounts` WHERE `user_id` = ?', [$user->id]);
        $sshMode = null;
        if ($hosting !== null) {
            try {
                $sshMode = app(\Modules\Security\Services\SshService::class)->modeOrNull((string) $hosting['username']);
            } catch (\Throwable $e) {
                $sshMode = null;
            }
        }

        $content = view('customer/account/profile', [
            'title' => 'Profile',
            'user' => $user,
            'role' => $role['name'] ?? '—',
            'hosting' => $hosting,
            'sshMode' => $sshMode,
            'sessions' => $this->sessionsFor($user->id),
            'currentSessionId' => (string) app('session')->id(),
        ], 'layouts/customer');

        return new Response((string)$content);
    }

    public function updateProfile(Request $request): Response
    {
        $user = app('auth')->user();

        try {
            $data = $request->validate([
                'name' => 'required|max:191',
            ]);
        } catch (ValidationException $e) {
            return $this->backWithErrors($request, $e);
        }

        app('db')->update('users', ['name' => $data['name']], '`id` = ?', [$user->id]);
        audit('account.profile.updated', 'user', $user->id);
        app('session')->flash('success', 'Profile updated.');

        return Response::redirect('/panel/account');
    }

    public function changePassword(Request $request): Response
    {
        $user = app('auth')->user();

        try {
            $data = $request->validate([
                'current_password' => 'required',
                'password' => 'required|min:' . config('auth.min_password_length', 10) . '|confirmed',
            ]);
        } catch (ValidationException $e) {
            return $this->backWithErrors($request, $e);
        }

        $row = app('db')->selectOne('SELECT `password` FROM `users` WHERE `id` = ?', [$user->id]);

        if ($row === null || !password_verify($data['current_password'], $row['password'])) {
            app('session')->flashInput([]);
            app('session')->flash('error', 'Current password is incorrect.');
            return Response::redirect('/panel/account');
        }

        $hash = hash_password($data['password']);
        app('db')->update('users', ['password' => $hash], '`id` = ?', [$user->id]);

        // Rotating a credential must kill sessions opened with the old one —
        // otherwise a stolen cookie survives the change (and, before the
        // absolute-expiry fix, indefinitely). The current session stays.
        app(\Modules\Authentication\Services\SessionService::class)
            ->revokeOthers((int) $user->id, app('session')->id());

        audit('account.password.changed', 'user', $user->id);
        app('session')->flash('success', 'Password changed.');

        return Response::redirect('/panel/account');
    }

    /**
     * Change the SYSTEM (SSH) password of the customer's own hosting account.
     * Identity is proven with the CURRENT PANEL password (same re-auth as
     * changePassword) — the panel can never read the system password back
     * to compare it directly.
     */
    public function changeSystemPassword(Request $request): Response
    {
        $user = app('auth')->user();

        try {
            $data = $request->validate([
                'current_password' => 'required',
                'password' => 'required|min:' . config('auth.min_password_length', 10) . '|confirmed',
            ]);
        } catch (ValidationException $e) {
            return $this->backWithErrors($request, $e);
        }

        $row = app('db')->selectOne('SELECT `password` FROM `users` WHERE `id` = ?', [$user->id]);

        if ($row === null || !password_verify($data['current_password'], $row['password'])) {
            app('session')->flashInput([]);
            app('session')->flash('error', 'Current panel password is incorrect.');
            return Response::redirect('/panel/account');
        }

        $hosting = app('db')->selectOne('SELECT * FROM `accounts` WHERE `user_id` = ?', [$user->id]);

        if ($hosting === null) {
            app('session')->flash('error', 'No hosting account is linked to this login.');
            return Response::redirect('/panel/account');
        }

        try {
            app('provisioning')->execute('system.password.set', [
                'account' => (string) $hosting['username'],
                'username' => (string) $hosting['username'],
                'password' => (string) $data['password'],
            ]);
        } catch (\Throwable $e) {
            app('session')->flash('error', 'System password change failed: ' . $e->getMessage());
            return Response::redirect('/panel/account');
        }

        audit('account.system_password.changed', 'user', $user->id);
        app('session')->flash('success', 'System (SSH) password changed.');

        return Response::redirect('/panel/account');
    }

    /** @return array<int, array> never throws (list degrades to empty) */
    private function sessionsFor(int $userId): array
    {
        try {
            $rows = app(\Modules\Authentication\Services\SessionService::class)
                ->forUser($userId, (string) app('session')->id());
        } catch (\Throwable $e) {
            return [];
        }
        // Never hand the raw session id to the view: it IS the cookie value, so
        // rendering it (even in a title/hidden field) turns any same-origin
        // XSS or DOM dump into a replayable session and defeats HttpOnly.
        // The form carries an opaque HMAC token instead, resolved server-side.
        foreach ($rows as &$row) {
            $row['token'] = $this->sessionToken((string) ($row['id'] ?? ''));
            unset($row['id']);
        }
        unset($row);

        return $rows;
    }

    private function sessionToken(string $id): string
    {
        return hash_hmac('sha256', $id, (string) config('app.key', 'cwhpanel'));
    }

    /** Resolve an opaque session token back to one of the user's session ids. */
    private function resolveSessionToken(int $userId, string $token): ?string
    {
        if ($token === '') {
            return null;
        }
        foreach (app(\Modules\Authentication\Services\SessionService::class)->forUser($userId, '') as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id !== '' && hash_equals($this->sessionToken($id), $token)) {
                return $id;
            }
        }
        return null;
    }

    /** Revoke one of the customer's own sessions (never the current one). */
    public function revokeSession(Request $request): Response
    {
        $user = app('auth')->user();
        if ($user === null) {
            return Response::redirect('/panel/login');
        }
        $id = $this->resolveSessionToken($user->id, (string) $request->input('session_id', ''));
        if ($id === null) {
            app('session')->flash('error', 'That session is no longer active.');
            return Response::redirect('/panel/account');
        }
        try {
            app(\Modules\Authentication\Services\SessionService::class)
                ->revoke($user->id, $id, (string) app('session')->id());
            app('session')->flash('success', 'Session signed out.');
        } catch (\RuntimeException $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/panel/account');
    }

    /** Sign out every session except the current one. */
    public function revokeOtherSessions(): Response
    {
        $user = app('auth')->user();
        if ($user === null) {
            return Response::redirect('/panel/login');
        }
        try {
            $n = app(\Modules\Authentication\Services\SessionService::class)
                ->revokeOthers($user->id, (string) app('session')->id());
            app('session')->flash('success', $n > 0 ? "Signed out {$n} other session" . ($n === 1 ? '.' : 's.') : 'No other sessions to sign out.');
        } catch (\RuntimeException $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/panel/account');
    }

    private function backWithErrors(Request $request, ValidationException $e): Response
    {
        app('session')->flashInput($request->only(['name']));

        foreach ($e->errors() as $field => $messages) {
            foreach ($messages as $msg) {
                app('session')->pushError($field, $msg);
            }
        }

        return Response::redirect('/panel/account');
    }
}

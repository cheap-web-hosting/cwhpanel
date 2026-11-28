<?php
declare(strict_types=1);
namespace Modules\Authentication\Controllers;

use App\Foundation\Request;
use App\Foundation\Response;
use Modules\Authentication\Services\Totp;

/**
 * User-facing two-factor authentication management (enable / disable TOTP).
 * Mounted under both /admin/account and /panel/account security sections.
 */
class TwoFactorController
{
    private Totp $totp;

    public function __construct(Totp $totp)
    {
        $this->totp = $totp;
    }

    public function setup(): Response
    {
        $user = app('auth')->user();
        if ($user === null) {
            return Response::redirect('/login');
        }

        // Stored seeds are sealed (audit #14); decrypt for display/otpauth.
        $secret = null;
        if ($user->twoFactorEnabled && $user->twoFactorSecret) {
            try {
                $secret = \App\Foundation\Security\SecretBox::decrypt($user->twoFactorSecret);
            } catch (\Throwable $e) {
                $secret = null;
            }
        }

        if (!$user->twoFactorEnabled) {
            // Generate and stage a new secret for confirmation.
            $secret = $this->totp->generateSecret();
            app('session')->put('pending_totp_secret', $secret);
        }

        $content = view('account/two-factor', [
            'title' => 'Two-Factor Authentication',
            'enabled' => $user->twoFactorEnabled,
            'secret' => $secret,
            'otpauth' => $secret ? $this->totp->provisioningUri($secret, $user->email) : null,
            'area' => $user->isAdmin ? 'admin' : 'customer',
        ], $user->isAdmin ? 'layouts/admin' : 'layouts/customer');

        return new Response((string) $content);
    }

    public function enable(Request $request): Response
    {
        $user = app('auth')->user();
        if ($user === null) {
            return Response::redirect('/login');
        }

        $code = (string) $request->input('code', '');
        $secret = app('session')->get('pending_totp_secret');

        if ($secret === null || !$this->totp->verify((string) $secret, $code)) {
            app('session')->flash('error', 'Invalid code. Two-factor authentication was not enabled.');
            return Response::redirectBack('/panel/account/2fa');
        }

        app('db')->update('users', [
            'two_factor_secret' => \App\Foundation\Security\SecretBox::encrypt((string) $secret),
            'two_factor_enabled' => 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [$user->id]);

        app('session')->forget('pending_totp_secret');
        audit('auth.2fa.enabled', 'user', $user->id);
        app('session')->flash('success', 'Two-factor authentication enabled.');

        return Response::redirect('/' . ($user->isAdmin ? 'admin' : 'panel') . '/account/2fa');
    }

    public function disable(Request $request): Response
    {
        $user = app('auth')->user();
        if ($user === null) {
            return Response::redirect('/login');
        }

        $code = (string) $request->input('code', '');

        // A row with two_factor_enabled=1 but no secret (legacy/corrupt) must
        // NOT be disabling 2FA without a code — refuse loudly instead.
        if (($user->twoFactorEnabled ?? false) && empty($user->twoFactorSecret)) {
            app('session')->flash('error', 'Two-factor is enabled but the secret is missing. Contact support to reset it.');
            return Response::redirectBack('/panel/account/2fa');
        }

        if ($user->twoFactorEnabled && $user->twoFactorSecret && !app('auth')->verifyTotpOnce((int) $user->id, (string) $user->twoFactorSecret, $code)) {
            // Disabling 2FA is a TOTP oracle for a session holder and there is
            // no re-authentication on this route, so cap the guesses the same
            // way /login/2fa does.
            $limiter = app('rate-limiter');
            $key = '2fa-disable:' . (int) $user->id;
            if ($limiter->tooManyAttempts($key, 10, 900)) {
                app('session')->flash('error', 'Too many verification attempts. Try again later.');
                return Response::redirectBack('/panel/account/2fa');
            }
            $limiter->hit($key, 10, 900);
            app('session')->flash('error', 'Invalid code. Two-factor authentication is still enabled.');
            return Response::redirectBack('/panel/account/2fa');
        }

        app('db')->update('users', [
            'two_factor_secret' => null,
            'two_factor_enabled' => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [$user->id]);

        // Removing a second factor weakens the account: drop every OTHER
        // session so a stolen cookie cannot ride along with the downgrade.
        app(\Modules\Authentication\Services\SessionService::class)
            ->revokeOthers((int) $user->id, app('session')->id());

        audit('auth.2fa.disabled', 'user', $user->id);
        app('session')->flash('success', 'Two-factor authentication disabled.');

        return Response::redirect('/' . ($user->isAdmin ? 'admin' : 'panel') . '/account/2fa');
    }
}
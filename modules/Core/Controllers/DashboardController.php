<?php

declare(strict_types=1);

namespace Modules\Core\Controllers;

use App\Foundation\Response;
use Modules\Core\Services\SystemInfo;

class DashboardController
{
    private SystemInfo $systemInfo;

    public function __construct(SystemInfo $systemInfo)
    {
        $this->systemInfo = $systemInfo;
    }

    /**
     * Landing page: full dashboard for dashboard.view holders, otherwise
     * a bounce to the first granted section (with the denial flash from
     * deny() intact — it was set before the redirect here). This is the
     * only /admin route without can: (it IS the denial target), so it
     * must never 403/loop itself.
     */
    public function landing(): Response
    {
        $user = app('auth')->user();

        if ($user !== null && app('gate')->can($user, 'dashboard.view')) {
            return $this->index();
        }

        if ($user !== null) {
            $nav = app('gate')->filterAdminNav(app('navigation')->tree('admin'), $user);
            $walk = function (array $items) use (&$walk): ?string {
                foreach ($items as $it) {
                    // Parents carry the section URL too (/admin) — descend
                    // first so the bounce lands on a real section, not the
                    // ungated landing itself (which would loop). Only
                    // /admin/* targets count: cross-links like /phpmyadmin/
                    // are unmapped and must never become the bounce.
                    if (!empty($it['children'])) {
                        $found = $walk($it['children']);
                        if ($found !== null) {
                            return $found;
                        }
                    }
                    if (isset($it['url']) && str_starts_with((string) $it['url'], '/admin/')) {
                        return (string) $it['url'];
                    }
                }
                return null;
            };
            $first = $walk($nav);

            if ($first !== null) {
                return Response::redirect($first);
            }
        }

        app('session')->flash('error', 'Your role has no admin sections assigned — contact your administrator.');

        return Response::redirect('/login');
    }

    public function index(): Response
    {
        // Direct hits (bookmarks, nav) still require the permission —
        // landing() already forwarded the permissionless elsewhere.
        app('gate')->authorize(app('auth')->user(), 'dashboard.view');

        $db = app('db');

        $recent = 'DATE_SUB(NOW(), INTERVAL 24 HOUR)';

        $counts = [
            'accounts' => (int) $db->scalar('SELECT COUNT(*) FROM `accounts`'),
            'suspended' => (int) $db->scalar("SELECT COUNT(*) FROM `accounts` WHERE `status` = 'suspended'"),
            'websites' => (int) $db->scalar('SELECT COUNT(*) FROM `websites`'),
            'doms' => (int) $db->scalar('SELECT COUNT(*) FROM `domains`'),
        ];
        unset($recent);

        // Operational attention items pulled from real records.
        $failedBackups = $db->select(
            "SELECT b.*, a.`username` FROM `backups` b
             LEFT JOIN `accounts` a ON a.`id` = b.`account_id`
             WHERE b.`status` = 'failed' ORDER BY b.`created_at` DESC LIMIT 6"
        );

        $failedJobs = $db->select(
            "SELECT * FROM `jobs` WHERE `status` IN ('failed','error') ORDER BY `updated_at` DESC LIMIT 6"
        );

        $expiringSsl = $db->select(
            "SELECT c.`domain_name`, c.`expires_at`, c.`status`, a.`username`
             FROM `certificates` c LEFT JOIN `accounts` a ON a.`id` = c.`account_id`
             WHERE c.`status` = 'active' AND c.`expires_at` IS NOT NULL
               AND c.`expires_at` < DATE_ADD(NOW(), INTERVAL 14 DAY)
             ORDER BY c.`expires_at` ASC LIMIT 6"
        );

        $suspendedAccounts = $db->select(
            "SELECT `id`, `username`, `domain`, `status` FROM `accounts`
             WHERE `status` != 'active' ORDER BY `updated_at` DESC LIMIT 6"
        );

        $recentWebsites = $db->select(
            "SELECT w.`label`, d.`name` AS domain_name, a.`username`
             FROM `websites` w LEFT JOIN `domains` d ON d.`id` = w.`domain_id`
             LEFT JOIN `accounts` a ON a.`id` = w.`account_id`
             ORDER BY w.`id` DESC LIMIT 6"
        );

        $audit = $db->select(
            'SELECT a.*, u.`email` FROM `audit_logs` a
             LEFT JOIN `users` u ON u.`id` = a.`user_id`
             ORDER BY a.`id` DESC LIMIT 8'
        );

        $content = view('admin/dashboard', [
            'title' => 'Dashboard',
            'counts' => $counts,
            'system' => $this->systemInfo->snapshot(),
            'audit' => $audit,
            'failedBackups' => $failedBackups,
            'failedJobs' => $failedJobs,
            'expiringSsl' => $expiringSsl,
            'suspendedAccounts' => $suspendedAccounts,
            'recentWebsites' => $recentWebsites,
        ], 'layouts/admin');

        return new Response((string) $content);
    }
}

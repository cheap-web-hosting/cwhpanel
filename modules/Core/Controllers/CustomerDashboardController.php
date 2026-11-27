<?php

declare(strict_types=1);

namespace Modules\Core\Controllers;

use App\Foundation\Response;
use Modules\Core\Services\DiskUsageService;
use Modules\Core\Services\SystemInfo;

class CustomerDashboardController
{
    private SystemInfo $systemInfo;

    public function __construct(SystemInfo $systemInfo)
    {
        $this->systemInfo = $systemInfo;
    }

    public function index(): Response
    {
        $user = app('auth')->user();
        $account = $user ? app('db')->selectOne(
            'SELECT a.`id`, a.`username`, a.`home_dir`, p.`disk_quota_mb` FROM `accounts` a LEFT JOIN `packages` p ON p.`id` = a.`package_id` WHERE a.`user_id` = ?',
            [$user->id]
        ) : null;

        $accountId = $account['id'] ?? null;
        $websites = [];
        $mailboxes = [];
        $diskUsedBytes = null;
        $quotaUsage = null;

        if ($accountId !== null) {
            $websites = app('db')->select(
                'SELECT w.*, d.`name` AS domain_name FROM `websites` w
                 LEFT JOIN `domains` d ON d.`id` = w.`domain_id`
                 WHERE w.`account_id` = ? ORDER BY d.`name` LIMIT 25',
                [$accountId]
            );
            $mailboxes = app('db')->select(
                "SELECT m.`email`, d.`domain` FROM `mailboxes` m
                 JOIN `mail_domains` d ON d.`id` = m.`domain_id`
                 WHERE m.`account_id` = ? ORDER BY m.`email` LIMIT 5",
                [$accountId]
            );
            $diskUsedBytes = app(DiskUsageService::class)->bytesForAccount((int) $accountId);
            $quotaUsage = app(DiskUsageService::class)->quotaUsage((int) $accountId);
        }

        $content = view('customer/dashboard', [
            'title' => 'Dashboard',
            'diskQuotaMb' => $account['disk_quota_mb'] ?? null,
            'diskUsedBytes' => $diskUsedBytes,
            'quotaUsage' => $quotaUsage,
            'accountId' => $accountId,
            // The session user row has no username column — the system account
            // username/home live on accounts. Pass them explicitly.
            'accountUsername' => $account['username'] ?? null,
            'accountHome' => $account['home_dir'] ?? null,
            'websites' => $websites,
            'mailboxes' => $mailboxes,
        ], 'layouts/customer');

        return new Response((string) $content);
    }
}

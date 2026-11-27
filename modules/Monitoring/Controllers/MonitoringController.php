<?php
declare(strict_types=1);
namespace Modules\Monitoring\Controllers;
use App\Foundation\Request;
use App\Foundation\Response;
use Modules\Core\Services\DiskUsageService;
use Modules\Core\Services\SystemInfo;

class MonitoringController {
    private SystemInfo $system;
    public function __construct(SystemInfo $system) { $this->system = $system; }

    public function adminIndex(): Response {        $system = $this->system->snapshot();
        $accounts = app('db')->select("SELECT a.*, p.`name` AS package_name, p.`disk_quota_mb`, p.`max_memory_mb`, p.`max_cpu_percent` FROM `accounts` a LEFT JOIN `packages` p ON p.`id`=a.`package_id` ORDER BY a.`username`");
        $traffic = [];
        try {
            $svc = app(\Modules\Monitoring\Services\TrafficService::class);
            foreach ($accounts as $a) {
                try {
                    $traffic[(int) $a['id']] = $svc->forAccount((int) $a['id'])['totals'];
                } catch (\Throwable $e) {
                    $traffic[(int) $a['id']] = null;
                }
            }
        } catch (\Throwable $e) {
            app('logger')->warning('Admin traffic rollup degraded: ' . $e->getMessage());
        }
        $content = view('admin/monitoring/index', ['title'=>'Resource Usage','system'=>$system,'accounts'=>$accounts,'traffic'=>$traffic], 'layouts/admin');
        return new Response((string)$content);
    }

    public function network(): Response {
        $svc = app(\Modules\Monitoring\Services\NetworkStatsService::class);
        $error = '';
        try {
            $interfaces = $svc->interfaces();
        } catch (\Throwable $e) {
            $interfaces = [];
            $error = $e->getMessage();
        }
        $rates = [];
        if ($error === '') {
            try {
                $rates = $svc->rates();
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }
        $usage = $svc->accountUsage();
        $content = view('admin/monitoring/network', [
            'title' => 'Network & Bandwidth',
            'interfaces' => $interfaces,
            'rates' => $rates['rates'] ?? [],
            'hostRxTotal' => $rates['total_rx_bytes'] ?? 0,
            'hostTxTotal' => $rates['total_tx_bytes'] ?? 0,
            'monthTotal' => $svc->monthlyTotal(),
            'usage' => $usage,
            'agentError' => $error,
        ], 'layouts/admin');
        return new Response((string) $content);
    }

    public function customerIndex(): Response {
        $system = $this->system->snapshot();
        $user = app('auth')->user();
        $account = null;
        $diskUsedBytes = null;
        $traffic = null;
        if ($user) {
            $account = app('db')->selectOne("SELECT a.*, p.`name` AS package_name, p.`disk_quota_mb`, p.`max_memory_mb`, p.`max_cpu_percent` FROM `accounts` a LEFT JOIN `packages` p ON p.`id`=a.`package_id` WHERE a.`user_id`=?",[$user->id]);
            if ($account) {
                $diskUsedBytes = app(DiskUsageService::class)->bytesForAccount((int) $account['id']);
                try {
                    $traffic = app(\Modules\Monitoring\Services\TrafficService::class)->forAccount((int) $account['id']);
                } catch (\Throwable $e) {
                    app('logger')->warning('Customer traffic degraded: ' . $e->getMessage());
                }
            }
        }
        $content = view('customer/monitoring/index', ['title'=>'Resource Usage','system'=>$system,'account'=>$account,'diskUsedBytes'=>$diskUsedBytes,'traffic'=>$traffic], 'layouts/customer');
        return new Response((string)$content);
    }
}
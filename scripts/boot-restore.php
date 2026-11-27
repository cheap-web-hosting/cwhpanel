#!/usr/bin/env php
<?php
/**
 * Boot-time restore of transient units (runs once per boot).
 *
 * Per-account nginx workers (cwh-nginx-*) and catalog apps (cwh-app-*) are
 * transient systemd units: they do not survive a reboot, while everything
 * they depend on does (front-door proxy confs, PHP masters, app dirs).
 * Without this, every reboot leaves isolated sites at 502 and apps stopped
 * until an admin clicks around.
 *
 * Idempotent: starting an already-running unit is a no-op success.
 * Failures are logged per account/app, never fatal to the rest.
 *
 * Dedicated IPs are equally ephemeral (`ip addr` does not survive a
 * reboot): account IPv6 + assigned pool IPv4s are re-bound FIRST, before
 * nginx or any worker starts, so no vhost ever references a missing
 * address at boot.
 */

require __DIR__ . '/_bootstrap.php';

use App\Foundation\Application;

$app = new Application(BASE_PATH);
$app->bootstrap();

$db = app('db');

// Dedicated IPs first (nginx binds them; workers/vhosts reference them).
$ips = 0;
$ipsFail = 0;
try {
    if (app(\Modules\Network\Services\IpAllocatorService::class)->enabled()) {
        // Bound set once up front: re-adding an existing address errors,
        // and boot must stay quiet about the already-correct state.
        $bound = [];
        try {
            $r = app('provisioning')->execute('net.addr.list', []);
            foreach (explode("\n", (string) ($r['output'] ?? '')) as $line) {
                if (preg_match('/\binet6?\s+(\S+)/', $line, $m)) {
                    $bound[strtolower(explode('/', $m[1])[0])] = true;
                }
            }
        } catch (\Throwable $e) {
            echo "address list failed, binding blind: " . substr($e->getMessage(), 0, 120) . "\n";
        }
        foreach ($db->select("SELECT `ipv6_address` FROM `accounts` WHERE `ipv6_address` IS NOT NULL AND `ipv6_address` != ''") as $a) {
            $addr = trim((string) ($a['ipv6_address'] ?? ''));
            if ($addr === '') {
                continue;
            }
            if (isset($bound[strtolower($addr)])) {
                $ips++;
                continue;
            }
            try {
                app('provisioning')->execute('net.ipv6.add', ['address' => $addr]);
                echo "ipv6 bound: {$addr}\n";
                $ips++;
            } catch (\Throwable $e) {
                echo "ipv6 FAILED: {$addr}: " . substr($e->getMessage(), 0, 150) . "\n";
                $ipsFail++;
            }
        }
        try {
            foreach ($db->select("SELECT * FROM `ip_pool` WHERE `status` = 'assigned'") as $p) {
                $addr = trim((string) ($p['address'] ?? ''));
                if ($addr === '' || isset($bound[strtolower($addr)])) {
                    if ($addr !== '') {
                        $ips++;
                    }
                    continue;
                }
                try {
                    app('provisioning')->execute('net.ipv4.add', [
                        'address' => $addr,
                        'prefixlen' => (string) ((int) ($p['prefixlen'] ?? 32)),
                    ]);
                    echo "ipv4 bound: {$addr}\n";
                    $ips++;
                } catch (\Throwable $e) {
                    echo "ipv4 FAILED: {$addr}: " . substr($e->getMessage(), 0, 150) . "\n";
                    $ipsFail++;
                }
            }
        } catch (\Throwable $e) {
            echo "pool restore skipped: " . substr($e->getMessage(), 0, 150) . "\n";
        }
    } else {
        echo "IP management off, skipping address restore.\n";
    }
} catch (\Throwable $e) {
    echo "address restore skipped: " . substr($e->getMessage(), 0, 150) . "\n";
}
$workers = 0;
$workerFail = 0;
foreach ($db->select('SELECT `username`, `mcs_category` FROM `accounts` WHERE `nginx_worker_enabled` = 1 AND `status` = ?', ['active']) as $a) {
    $u = (string) ($a['username'] ?? '');
    if ($u === '') {
        continue;
    }
    $cat = (int) ($a['mcs_category'] ?? 0);
    $args = ['account' => $u, 'mcs_category' => ($cat > 0 && $cat <= 1023) ? 'c' . $cat : ''];
    try {
        $r = app('provisioning')->execute('nginx.worker.start', $args);
        if (($r['status'] ?? '') === 'success') {
            echo "worker started: {$u}\n";
            $workers++;
        } else {
            echo "worker FAILED: {$u}\n";
            $workerFail++;
        }
    } catch (\Throwable $e) {
        echo "worker FAILED: {$u}: " . substr($e->getMessage(), 0, 150) . "\n";
        $workerFail++;
    }
}

$apps = 0;
$appFail = 0;
foreach ($db->select("SELECT a.* FROM `applications` a WHERE a.`status` = 'running'") as $row) {
    $account = $db->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [(int) ($row['account_id'] ?? 0)]);
    if ($account === null) {
        echo "app FAILED: unknown account for {$row['name']}\n";
        $appFail++;
        continue;
    }
    try {
        if (app(\Modules\Applications\Services\AppService::class)->start($account, $row)) {
            echo "app started: {$account['username']}/{$row['name']}\n";
            $apps++;
        } else {
            echo "app FAILED: {$account['username']}/{$row['name']}\n";
            $appFail++;
        }
    } catch (\Throwable $e) {
        echo "app FAILED: {$account['username']}/{$row['name']}: " . substr($e->getMessage(), 0, 150) . "\n";
        $appFail++;
    }
}

echo "boot-restore: {$workers} workers, {$apps} apps ({$workerFail} worker failures, {$appFail} app failures)\n";
exit(($workerFail + $appFail) > 0 ? 1 : 0);

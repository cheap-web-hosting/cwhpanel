<?php
declare(strict_types=1);
namespace Modules\Security\Controllers;
use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\ValidationException;

class SecurityController {
    private function accountId(): ?int {
        $user = app('auth')->user(); if (!$user) return null;
        $row = app('db')->selectOne('SELECT `id` FROM `accounts` WHERE `user_id`=?',[$user->id]);
        return $row ? (int)$row['id'] : null;
    }

    /**
     * Collapse stale duplicate website rows per domain, most-recently-touched
     * wins (matches the vhost writers' dedupe rule). Rows carry website_id
     * plus either updated_at (website row) or website_updated_at.
     *
     * @param array<int,array> $rows
     * @return array<int,array>
     */
    private static function dedupeSitesByDomain(array $rows): array
    {
        $sites = [];
        foreach ($rows as $row) {
            $key = strtolower((string) ($row['domain'] ?? ''));
            $ts = (string) ($row['website_updated_at'] ?? $row['updated_at'] ?? '');
            if (!isset($sites[$key])) {
                $sites[$key] = $row;
                continue;
            }
            $prevTs = (string) ($sites[$key]['website_updated_at'] ?? $sites[$key]['updated_at'] ?? '');
            if (strcmp($ts, $prevTs) > 0
                || ($ts === $prevTs && (int) $row['website_id'] > (int) $sites[$key]['website_id'])) {
                $sites[$key] = $row;
            }
        }
        return array_values($sites);
    }

    // ----- Admin: overview/firewall/audit -----
    public function index(): Response {
        $blockedCount = (int) app('db')->scalar("SELECT COUNT(*) FROM `firewall_rules` WHERE `action`='block'");
        $auditCount = (int) app('db')->scalar("SELECT COUNT(*) FROM `audit_logs`");
        $scanCount = (int) app('db')->scalar("SELECT COUNT(*) FROM `security_scans`");
        $recentEvents = app('db')->select("SELECT a.*, u.`email` FROM `audit_logs` a LEFT JOIN `users` u ON u.`id`=a.`user_id` ORDER BY a.`id` DESC LIMIT 10");
        $attackSvc = app(\Modules\Security\Services\LoginAttackService::class);
        $failedCount = (int) app('db')->scalar(
            "SELECT COUNT(*) FROM `audit_logs` WHERE `action`='auth.failed' AND `created_at` >= ?",
            [date('Y-m-d H:i:s', time() - 86400)]
        );
        $topOffenders = $attackSvc->topOffenders(10, 86400);
        $wafInstalled = app(\Modules\Security\Services\WafService::class)->installed();
        $avInstalled = is_file('/usr/bin/clamscan');
        $content = view('admin/security/index', ['title'=>'Security Overview','blockedCount'=>$blockedCount,'auditCount'=>$auditCount,'scanCount'=>$scanCount,'events'=>$recentEvents,'failedCount'=>$failedCount,'topOffenders'=>$topOffenders,'wafInstalled'=>$wafInstalled,'avInstalled'=>$avInstalled], 'layouts/admin');
        return new Response((string)$content);
    }
    public function firewall(): Response {
        $rules = app('db')->select("SELECT * FROM `firewall_rules` ORDER BY `created_at` DESC");
        $attacks = app('db')->select("SELECT * FROM `firewall_attacks` ORDER BY `created_at` DESC LIMIT 50");
        $ports = $this->firewallState();
        $mysqlRemote = \Modules\Databases\Services\DatabaseService::remoteEnabled();
        try {
            $mysqlHosts = app('db')->select('SELECT h.`host`, u.`username` FROM `hosting_db_hosts` h JOIN `hosting_db_users` u ON u.`id` = h.`db_user_id` ORDER BY h.`host` LIMIT 100');
        } catch (\Throwable $e) {
            $mysqlHosts = [];
        }
        $content = view('admin/security/firewall', ['title'=>'Firewall','rules'=>$rules,'attacks'=>$attacks,'ports'=>$ports,'stateError'=>$ports['error'] ?? '','mysqlRemote'=>$mysqlRemote,'mysqlHosts'=>$mysqlHosts], 'layouts/admin');
        return new Response((string)$content);
    }
    public function block(Request $request): Response {
        $ip = trim((string)$request->input('ip',''));
        $reason = trim((string)$request->input('reason',''));
        $service = trim((string)$request->input('service','manual'));
        if (!filter_var($ip, FILTER_VALIDATE_IP)) { app('session')->flash('error','Invalid IP address.'); return Response::redirect('/admin/security/firewall'); }
        app('db')->statement("INSERT INTO `firewall_rules` (`ip_address`,`action`,`reason`,`created_at`) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE `reason`=VALUES(`reason`)", [$ip,'block',$reason ?: 'blocked',date('Y-m-d H:i:s')]);
        app('db')->insert('firewall_attacks', ['source_ip'=>$ip,'service'=>$service,'action'=>'block','reason'=>$reason ?: 'blocked','created_at'=>date('Y-m-d H:i:s')]);
        try { app('provisioning')->execute('firewall.ip.block', ['ip'=>$ip]); } catch (\Throwable $e) { app('logger')->warning('Firewall block enforce failed: '.$e->getMessage()); }
        audit('firewall.blocked','firewall_rule',null,['ip'=>$ip]);
        app('session')->flash('success','IP blocked.');
        return Response::redirect('/admin/security/firewall');
    }
    public function unblock(int $id): Response {
        $rule = app('db')->selectOne("SELECT * FROM `firewall_rules` WHERE `id`=?",[$id]);
        if ($rule) {
            try { app('provisioning')->execute('firewall.ip.unblock', ['ip'=>$rule['ip_address']]); } catch (\Throwable $e) { app('logger')->warning('Firewall unblock enforce failed: '.$e->getMessage()); }
        }
        app('db')->delete('firewall_rules','`id`=?',[$id]);
        audit('firewall.unblocked','firewall_rule',$id);
        app('session')->flash('success','IP unblocked.');
        return Response::redirect('/admin/security/firewall');
    }
    public function openPort(Request $request): Response {
        $port = trim((string)$request->input('port',''));
        $protocol = trim((string)$request->input('protocol','tcp'));
        if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) { app('session')->flash('error','Invalid port.'); return Response::redirect('/admin/security/firewall'); }
        if (!in_array($protocol, ['tcp','udp'], true)) { app('session')->flash('error','Protocol must be tcp or udp.'); return Response::redirect('/admin/security/firewall'); }
        try { app('provisioning')->execute('firewall.port.open', ['port'=>$port,'protocol'=>$protocol]); app('session')->flash('success',"Port {$port}/{$protocol} opened."); }
        catch (\Throwable $e) { app('session')->flash('error','Could not open port: '.$e->getMessage()); }
        audit('firewall.port.opened','port',null,['port'=>$port,'protocol'=>$protocol]);
        return Response::redirect('/admin/security/firewall');
    }
    public function closePort(Request $request): Response {
        $port = trim((string)$request->input('port',''));
        $protocol = trim((string)$request->input('protocol','tcp'));
        if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) { app('session')->flash('error','Invalid port.'); return Response::redirect('/admin/security/firewall'); }
        if (!in_array($protocol, ['tcp','udp'], true)) { app('session')->flash('error','Protocol must be tcp or udp.'); return Response::redirect('/admin/security/firewall'); }
        try { app('provisioning')->execute('firewall.port.close', ['port'=>$port,'protocol'=>$protocol]); app('session')->flash('success',"Port {$port}/{$protocol} closed."); }
        catch (\Throwable $e) { app('session')->flash('error','Could not close port: '.$e->getMessage()); }
        audit('firewall.port.closed','port',null,['port'=>$port,'protocol'=>$protocol]);
        return Response::redirect('/admin/security/firewall');
    }

    /**
     * Global remote-MySQL toggle (Server → Firewall section).
     * ON opens 3306/tcp in the public zone so remote clients can reach
     * MariaDB (per-host grants + per-IP rich rules still apply on top) and
     * unbinds the server from loopback. OFF closes 3306/tcp AND re-binds
     * MariaDB to loopback, so a firewall lapse alone can never expose the
     * port (defense in depth: exposure needs BOTH the listener and the
     * firewall to allow it); existing per-IP rich rules are left intact so
     * re-enabling restores prior access without re-granting.
     */
    public function mysqlRemote(Request $request): Response {
        $enable = (bool) $request->input('enabled');
        try {
            if ($enable) {
                app('provisioning')->execute('mysql.bind.write', ['mode' => 'all']);
                app('provisioning')->execute('firewall.port.open', ['port' => '3306', 'protocol' => 'tcp']);
            } else {
                app('provisioning')->execute('firewall.port.close', ['port' => '3306', 'protocol' => 'tcp']);
                app('provisioning')->execute('mysql.bind.write', ['mode' => 'local']);
            }
            \Modules\Databases\Services\DatabaseService::setRemoteEnabled($enable);
            audit('firewall.mysql.remote', null, null, ['enabled' => $enable]);
            app('session')->flash('success', $enable ? 'Remote MySQL enabled: bound to all interfaces, 3306/tcp opened.' : 'Remote MySQL disabled: 3306/tcp closed + bound to loopback (per-host grants kept).');
        } catch (\Throwable $e) {
            app('logger')->warning('MySQL remote toggle failed: ' . $e->getMessage());
            app('session')->flash('error', 'Could not change remote MySQL: ' . $e->getMessage());
        }
        return Response::redirect('/admin/security/firewall');
    }

    /** Gather live firewall state (open ports, services, rich rules) from firewalld. */
    private function firewallState(): array {
        $ports = []; $services = []; $rich = []; $errors = [];
        try {
            $r = app('provisioning')->execute('firewall.ports.list', []);
            foreach (preg_split('/\s+/', trim((string)($r['output'] ?? ''))) as $p) { if ($p !== '') $ports[] = $p; }
        } catch (\Throwable $e) { $errors[] = 'ports: ' . $e->getMessage(); }
        try {
            $r = app('provisioning')->execute('firewall.services.list', []);
            foreach (preg_split('/\s+/', trim((string)($r['output'] ?? ''))) as $s) { if ($s !== '') $services[] = $s; }
        } catch (\Throwable $e) { $errors[] = 'services: ' . $e->getMessage(); }
        try {
            $r = app('provisioning')->execute('firewall.rich.list', []);
            foreach (explode("\n", trim((string)($r['output'] ?? ''))) as $line) { if ($line !== '') $rich[] = $line; }
        } catch (\Throwable $e) { $errors[] = 'rules: ' . $e->getMessage(); }
        return ['ports'=>$ports, 'services'=>$services, 'rich'=>$rich, 'error'=>implode('; ', $errors)];
    }
    public function audit(Request $request): Response {
        $page = max(1,(int)$request->query('page',1));
        $perPage = 50;
        $offset = ($page-1)*$perPage;
        $total = (int)app('db')->scalar("SELECT COUNT(*) FROM `audit_logs`");
        $logs = app('db')->select("SELECT a.*, u.`email` FROM `audit_logs` a LEFT JOIN `users` u ON u.`id`=a.`user_id` ORDER BY a.`id` DESC LIMIT {$perPage} OFFSET {$offset}");
        $content = view('admin/security/audit', ['title'=>'Audit Log','logs'=>$logs,'total'=>$total,'page'=>$page,'perPage'=>$perPage], 'layouts/admin');
        return new Response((string)$content);
    }

    // ----- Admin: WAF -----
    public function adminWaf(): Response {
        $wafInstalled = app(\Modules\Security\Services\WafService::class)->installed();
        $sites = [];
        $blocks = [];
        if ($wafInstalled) {
            $rows = app('db')->select(
                'SELECT s.id as sid, s.waf_enabled, s.waf_mode, s.hotlink_protection, w.id as website_id, w.label, w.account_id, w.updated_at, a.username, d.name as domain
                 FROM `websites` w
                 LEFT JOIN `accounts` a ON a.id=w.account_id
                 LEFT JOIN `domains` d ON d.id=w.domain_id
                 LEFT JOIN `security_settings` s ON s.website_id=w.id
                 ORDER BY w.label'
            );
            // Dedupe stale duplicate rows per domain (the WAF conf file is
            // per-domain): most-recently-touched wins, same rule as the
            // vhost writers.
            $sites = self::dedupeSitesByDomain($rows);
            $waf = app(\Modules\Security\Services\WafService::class);
            foreach ($sites as &$s) {
                $s['exclusions'] = $waf->exclusions((int) $s['website_id']);
            }
            unset($s);
            $blocks = $waf->recentBlocks(null, 50);
        }
        $content = view('admin/security/waf', ['title'=>'Web Application Firewall','wafInstalled'=>$wafInstalled,'sites'=>$sites,'blocks'=>$blocks], 'layouts/admin');
        return new Response((string)$content);
    }
    public function adminAntivirus(): Response {
        $scans = app('db')->select('SELECT s.*, a.`username` FROM `security_scans` s LEFT JOIN `accounts` a ON a.`id`=s.`account_id` ORDER BY s.`id` DESC LIMIT 50');
        $accounts = app('db')->select("SELECT * FROM `accounts` WHERE `status`=? ORDER BY `username`", ['active']);
        $content = view('admin/security/antivirus', ['title'=>'Antivirus','scans'=>$scans,'accounts'=>$accounts], 'layouts/admin');
        return new Response((string)$content);
    }
    public function adminAntivirusScan(Request $request): Response {
        $accountId = (int)$request->input('account_id',0);
        $account = app('db')->selectOne("SELECT * FROM `accounts` WHERE `id`=?",[$accountId]);
        if (!$account) { app('session')->flash('error','Invalid account.'); return Response::redirect('/admin/security/antivirus'); }
        $result = $this->runScan($account);
        app('session')->flash($result['status']==='success' ? 'success':'error', $result['message']);
        return Response::redirect('/admin/security/antivirus');
    }

    /** Scan every active account sequentially (admin only). */
    public function adminAntivirusScanAll(Request $request): Response {
        $accounts = app('db')->select("SELECT * FROM `accounts` WHERE `status`=? ORDER BY `username`", ['active']);
        if ($accounts === []) {
            app('session')->flash('error','No active accounts to scan.');
            return Response::redirect('/admin/security/antivirus');
        }
        try {
            set_time_limit(0);
        } catch (\Throwable $e) {
            // best-effort; scan still runs within the request timeout
        }
        $scanned = 0;
        $infected = 0;
        $errors = [];
        foreach ($accounts as $account) {
            $result = $this->runScan($account);
            if (($result['status'] ?? '') === 'success') {
                $scanned++;
                if ((int) ($result['infected'] ?? 0) > 0) {
                    $infected++;
                }
            } else {
                $errors[] = $account['username'] . ': ' . ($result['message'] ?? 'scan failed');
            }
        }
        $msg = "Scan complete: {$scanned} account(s) scanned";
        if ($infected > 0) {
            $msg .= ", {$infected} with detection(s)";
        }
        $msg .= '.';
        if ($errors !== []) {
            $shown = array_slice($errors, 0, 5);
            $msg .= ' Errors: ' . implode('; ', $shown) . (count($errors) > 5 ? ' (+' . (count($errors) - 5) . ' more)' : '');
        }
        app('session')->flash($errors === [] ? 'success' : 'error', $msg);
        audit('antivirus.scan_all', 'security_scan', null, ['accounts' => $scanned, 'infected' => $infected, 'errors' => count($errors)]);
        return Response::redirect('/admin/security/antivirus');
    }

    // ----- Customer: IP blocking -----
    public function customerIndex(): Response {
        $accountId = $this->accountId();
        $user = app('auth')->user();
        $events = [];
        if ($user) { $events = app('db')->select("SELECT * FROM `audit_logs` WHERE `user_id`=? ORDER BY `id` DESC LIMIT 20",[$user->id]); }
        // Blocks this account created, plus legacy customer blocks (no owner
        // columns) for its own IPs. Admin rows never appear here.
        $myIps = ($accountId && $user) ? app('db')->select(
            "SELECT * FROM `firewall_rules` WHERE `account_id`=? OR `user_id`=? OR (`account_id` IS NULL AND `user_id` IS NULL AND `reason`='customer block' AND `ip_address` IN (SELECT DISTINCT ip_address FROM audit_logs WHERE user_id=?)) ORDER BY `created_at` DESC",
            [$accountId, $user->id, $user->id]
        ) : [];
        $sslLink = '/panel/ssl';
        $content = view('customer/security/index', ['title'=>'Security','events'=>$events,'myIps'=>$myIps,'sslLink'=>$sslLink], 'layouts/customer');
        return new Response((string)$content);
    }
    public function customerBlockIp(Request $request): Response {
        $accountId = $this->accountId(); if (!$accountId) return Response::redirect('/panel/security');
        $user = app('auth')->user();
        $ip = trim((string)$request->input('ip',''));
        if (!filter_var($ip, FILTER_VALIDATE_IP)) { app('session')->flash('error','Invalid IP.'); return Response::redirect('/panel/security'); }
        // The firewall rule is server-wide (firewalld rich rule), so customer
        // blocks are tightly bounded to prevent any-account DoS of third
        // parties or other tenants:
        //  1. only PUBLIC addresses may be blocked (never loopback/private/
        //     reserved — that would drop internal/panel traffic);
        //  2. the IP must actually have contacted THIS account recently (it
        //     is an attacker you are defending against, not an arbitrary
        //     victim — this also defeats drive-by blocking of randoms);
        //  3. per-account rule cap (bounded table + bounded blast radius);
        //  4. rate limit on block attempts.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            app('session')->flash('error','Only public IP addresses can be blocked.');
            return Response::redirect('/panel/security');
        }
        $recent = (int) app('db')->scalar(
            "SELECT COUNT(*) FROM `audit_logs` WHERE `user_id` = ? AND `ip_address` = ? AND `created_at` >= ?",
            [$user ? $user->id : 0, $ip, date('Y-m-d H:i:s', time() - 604800)]
        );
        if ($recent === 0) {
            app('session')->flash('error','That IP has not contacted your account recently (blocks are for active attackers).');
            return Response::redirect('/panel/security');
        }
        $ownedRules = (int) app('db')->scalar(
            "SELECT COUNT(*) FROM `firewall_rules` WHERE `account_id` = ? OR `user_id` = ?",
            [$accountId, $user ? $user->id : 0]
        );
        if ($ownedRules >= 32) {
            app('session')->flash('error','Block list is full (max 32 rules). Remove an old block first.');
            return Response::redirect('/panel/security');
        }
        $rl = app(\App\Foundation\RateLimiter::class);
        $rk = 'customer-block:' . ($user ? $user->id : $accountId);
        if ($rl->tooManyAttempts($rk, 10, 3600)) {
            app('session')->flash('error','Too many block requests recently. Please try again later.');
            return Response::redirect('/panel/security');
        }
        $rl->hit($rk, 10, 3600);
        // Never re-own an existing rule: if an admin (or another customer)
        // already blocked this IP, the customer's insert must not steal the
        // row's ownership (which would later let them unblock it). Ownership
        // columns are deliberately left untouched on conflict.
        app('db')->statement("INSERT INTO `firewall_rules` (`ip_address`,`action`,`account_id`,`user_id`,`reason`,`created_at`) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE `action`='block'", [$ip,'block',$accountId,$user ? $user->id : null,'customer block',date('Y-m-d H:i:s')]);
        try {
            app('provisioning')->execute('firewall.ip.block', ['ip'=>$ip]);
        } catch (\Throwable $e) {
            app('logger')->warning('Customer firewall block enforce failed: '.$e->getMessage());
            audit('firewall.customer.blocked','firewall_rule',null,['ip'=>$ip]);
            app('session')->flash('error','IP recorded but the firewall could not be updated: '.$e->getMessage());
            return Response::redirect('/panel/security');
        }
        audit('firewall.customer.blocked','firewall_rule',null,['ip'=>$ip]);
        app('session')->flash('success','IP blocked.');
        return Response::redirect('/panel/security');
    }
    public function customerUnblockIp(int $id): Response {
        $accountId = $this->accountId();
        $user = app('auth')->user();
        if ($accountId === null || $user === null) return Response::redirect('/panel/security');
        // Only blocks this account created (or legacy owner-less customer
        // blocks for its own IPs). Admin rows can never match.
        $owned = app('db')->selectOne(
            'SELECT * FROM `firewall_rules` WHERE `id`=? AND (`account_id`=? OR `user_id`=? OR (`account_id` IS NULL AND `user_id` IS NULL AND `reason`=? AND `ip_address` IN (SELECT DISTINCT ip_address FROM audit_logs WHERE user_id=?)))',
            [$id, $accountId, $user->id, 'customer block', $user->id]
        );
        if ($owned === null) {
            app('session')->flash('error', 'That block is not one of yours.');
            return Response::redirect('/panel/security');
        }
        try {
            app('provisioning')->execute('firewall.ip.unblock', ['ip' => (string) $owned['ip_address']]);
        } catch (\Throwable $e) {
            app('logger')->warning('Customer firewall unblock enforce failed: '.$e->getMessage());
        }
        app('db')->delete('firewall_rules','`id`=?',[$id]);
        app('session')->flash('success','IP unblocked.');
        return Response::redirect('/panel/security');
    }

    private function ownedAccount(): ?array {
        $accountId = $this->accountId(); if (!$accountId) return null;
        return app('db')->selectOne('SELECT * FROM `accounts` WHERE `id`=?', [$accountId]);
    }

    public function customerSsh(): Response {
        $account = $this->ownedAccount();
        if (!$account) return Response::redirect('/panel/security');
        $svc = app(\Modules\Security\Services\SshService::class);
        try {
            $keys = $svc->keys($account['username']);
        } catch (\Throwable $e) {
            $keys = [];
            app('session')->flash('error', 'SSH keys unavailable: ' . $e->getMessage());
        }
        $content = view('customer/security/ssh', [
            'title' => 'SSH Access',
            'account' => $account,
            'keys' => $keys,
            'shell' => $svc->shell($account['username']),
            'sshEnabled' => $svc->sshEnabled($account['username']),
            'sshMode' => $svc->modeOrNull($account['username']) ?? 'unknown',
        ], 'layouts/customer');
        return new Response((string) $content);
    }

    public function customerSshAddKey(Request $request): Response {
        $account = $this->ownedAccount();
        if (!$account) return Response::redirect('/panel/security');
        $result = app(\Modules\Security\Services\SshService::class)->addKey(
            $account['username'], (string) $request->input('key', '')
        );
        if ($result['ok']) {
            audit('ssh.key.added', 'account', (int) $account['id']);
            app('session')->flash('success', 'SSH key added.');
        } else {
            app('session')->flash('error', $result['error'] ?? 'Failed to add key.');
        }
        return Response::redirect('/panel/security/ssh');
    }

    public function customerSshRemoveKey(int $index): Response {
        $account = $this->ownedAccount();
        if (!$account) return Response::redirect('/panel/security');
        $result = app(\Modules\Security\Services\SshService::class)->removeKey($account['username'], $index);
        if ($result['ok']) {
            audit('ssh.key.removed', 'account', (int) $account['id']);
            app('session')->flash('success', 'SSH key removed.');
        } else {
            app('session')->flash('error', $result['error'] ?? 'Failed to remove key.');
        }
        return Response::redirect('/panel/security/ssh');
    }

    public function customerSshShell(Request $request): Response {
        $account = $this->ownedAccount();
        if (!$account) return Response::redirect('/panel/security');
        $mode = strtolower(trim((string) $request->input('mode', '')));
        if ($mode === '') {
            // Legacy checkbox path: enabling means the JAILED shell. `full`
            // is never offered to customers — it tears the chroot down and
            // gives them an unconfined /bin/bash on the host.
            $mode = $request->input('enabled') ? 'jailed' : 'off';
        }
        if (!in_array($mode, ['off', 'jailed'], true)) {
            app('session')->flash('error', 'That SSH mode is not available to customers.');
            return Response::redirect('/panel/security/ssh');
        }
        $svc = app(\Modules\Security\Services\SshService::class);
        $current = null;
        try { $current = $svc->modeOrNull($account['username']); }
        catch (\Throwable $e) {
            app('logger')->warning('SSH mode read failed for ' . $account['username'] . ': ' . $e->getMessage());
        }
        if ($current === null) {
            // Never change SSH access while the live jail state is unknown:
            // "off -> on" is password-gated, and guessing risks enabling a
            // shell without a credential.
            app('session')->flash('error', 'SSH state is unavailable (provisioning agent unreachable). Try again once the agent is back.');
            return Response::redirect('/panel/security/ssh');
        }
        // First activation (off -> jailed/full) requires an SSH password up
        // front: the system password is write-only, so there is no way to
        // know whether one was ever set. Same credential also gates FTP.
        $newPw = (string) $request->input('password', '');
        $newPwConfirm = (string) $request->input('password_confirmation', '');
        if ($current === 'off' && in_array($mode, ['jailed', 'full'], true)) {
            if ($newPw === '' || $newPw !== $newPwConfirm) {
                app('session')->flash('error', 'Enter an SSH password (twice) to enable access.');
                return Response::redirect('/panel/security/ssh');
            }
            if (strlen($newPw) < (int) config('auth.min_password_length', 10)) {
                app('session')->flash('error', 'SSH password must be at least ' . config('auth.min_password_length', 10) . ' characters.');
                return Response::redirect('/panel/security/ssh');
            }
        }
        $result = $svc->setMode($account['username'], $mode);
        if (!$result['ok']) {
            app('session')->flash('error', $result['error'] ?? 'Failed to update SSH access.');
            return Response::redirect('/panel/security/ssh');
        }
        if ($current === 'off' && in_array($mode, ['jailed', 'full'], true)) {
            try {
                app('provisioning')->execute('system.password.set', [
                    'account' => $account['username'],
                    'username' => $account['username'],
                    'password' => $newPw,
                ]);
            } catch (\Throwable $e) {
                app('session')->flash('error', 'SSH enabled, but the password could not be set: ' . $e->getMessage());
                return Response::redirect('/panel/security/ssh');
            }
        }
        $label = \Modules\Security\Services\SshService::MODE_LABELS[$mode] ?? $mode;
        audit('ssh.mode.' . $mode, 'account', (int) $account['id']);
        app('session')->flash('success', 'SSH access: ' . $label . '.');
        return Response::redirect('/panel/security/ssh');
    }

    /**
     * Change the system (SSH + FTP) password from the SSH page. Identity is
     * proven with the current PANEL password — the system secret itself can
     * never be read back to compare it directly.
     */
    public function customerSshPassword(Request $request): Response {
        $account = $this->ownedAccount();
        if (!$account) return Response::redirect('/panel/security');
        $user = app('auth')->user();
        try {
            $data = $request->validate([
                'current_password' => 'required',
                'password' => 'required|min:' . config('auth.min_password_length', 10) . '|confirmed',
            ]);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $f => $ms) { foreach ($ms as $m) app('session')->pushError($f, $m); }
            return Response::redirect('/panel/security/ssh');
        }
        $row = $user ? app('db')->selectOne('SELECT `password` FROM `users` WHERE `id` = ?', [$user->id]) : null;
        if ($row === null || !password_verify($data['current_password'], $row['password'])) {
            app('session')->flash('error', 'Current panel password is incorrect.');
            return Response::redirect('/panel/security/ssh');
        }
        try {
            app('provisioning')->execute('system.password.set', [
                'account' => $account['username'],
                'username' => $account['username'],
                'password' => (string) $data['password'],
            ]);
        } catch (\Throwable $e) {
            app('session')->flash('error', 'System password change failed: ' . $e->getMessage());
            return Response::redirect('/panel/security/ssh');
        }
        audit('account.system_password.changed', 'user', $user->id);
        app('session')->flash('success', 'System (SSH + FTP) password changed.');
        return Response::redirect('/panel/security/ssh');
    }

    // ----- Customer: WAF toggle -----
    public function customerWaf(): Response {
        $accountId = $this->accountId();
        $settings = [];
        $blocks = [];
        if ($accountId) {
            $rows = app('db')->select('SELECT s.*, w.id as website_id, w.label, w.updated_at as website_updated_at, d.name as domain FROM `security_settings` s JOIN `websites` w ON w.id=s.website_id JOIN `domains` d ON d.id=w.domain_id WHERE s.`account_id`=? ORDER BY w.label',[$accountId]);
            // Same stale-duplicate dedupe as the admin listing.
            $tmp = [];
            foreach ($rows as $row) {
                $row['updated_at'] = $row['website_updated_at'] ?? $row['updated_at'] ?? '';
                $tmp[] = $row;
            }
            $settings = self::dedupeSitesByDomain($tmp);
            $waf = app(\Modules\Security\Services\WafService::class);
            foreach ($settings as &$s) {
                $s['exclusions'] = $waf->exclusions((int) $s['website_id']);
            }
            unset($s);
            $blocks = $waf->recentBlocks($accountId, 50);
        }
        $wafInstalled = app(\Modules\Security\Services\WafService::class)->installed();
        $blocksError = isset($waf) ? ($waf->lastError() ?? '') : '';
        $content = view('customer/security/waf', ['title'=>'Web Application Firewall','settings'=>$settings,'wafInstalled'=>$wafInstalled,'blocks'=>$blocks,'blocksError'=>$blocksError], 'layouts/customer');
        return new Response((string)$content);
    }
    public function customerWafToggle(Request $request): Response {
        $accountId = $this->accountId();
        if (!$accountId) return Response::redirect('/panel/security/waf');
        $websiteId = (int)$request->input('website_id',0);
        $web = app('db')->selectOne("SELECT * FROM `websites` WHERE `id`=? AND `account_id`=?",[$websiteId,$accountId]);
        if (!$web) { app('session')->flash('error','Select one of your websites.'); return Response::redirect('/panel/security/waf'); }
        $enabled = (int)$request->input('enabled',0) === 1;
        $mode = (string)$request->input('mode','detection');
        $this->setWaf($accountId, $websiteId, $enabled, $mode);
        audit('waf.toggled','website',$websiteId,['enabled'=>$enabled,'mode'=>$mode]);
        app('session')->flash('success',$enabled ? 'WAF enabled ('.$mode.' mode).':'WAF disabled.');
        return Response::redirect('/panel/security/waf');
    }

    public function adminWafToggle(Request $request): Response {
        $websiteId = (int)$request->input('website_id',0);
        $enabled = (int)$request->input('enabled',0) === 1;
        $mode = (string)$request->input('mode','detection');
        $web = app('db')->selectOne("SELECT * FROM `websites` WHERE `id`=?",[$websiteId]);
        if (!$web) { app('session')->flash('error','Website not found.'); return Response::redirect('/admin/security/waf'); }
        $this->setWaf((int)$web['account_id'], $websiteId, $enabled, $mode);
        audit('waf.toggled','website',$websiteId,['enabled'=>$enabled,'mode'=>$mode]);
        app('session')->flash('success',$enabled ? 'WAF enabled ('.$mode.' mode).':'WAF disabled.');
        return Response::redirect('/admin/security/waf');
    }

    /** Disable one CRS rule for a website (admin, any site). */
    public function adminExclusionAdd(Request $request): Response {
        $websiteId = (int) $request->input('website_id', 0);
        $ruleId = \Modules\Security\Services\WafService::validateRuleId($request->input('rule_id', ''));
        $web = $websiteId > 0 ? app('db')->selectOne('SELECT * FROM `websites` WHERE `id`=?', [$websiteId]) : null;
        if ($web === null || $ruleId === null) {
            app('session')->flash('error', 'Select a website and a numeric rule ID.');
            return Response::redirect('/admin/security/waf');
        }
        try {
            app(\Modules\Security\Services\WafService::class)->addExclusion($websiteId, $ruleId, (string) $request->input('reason', ''));
            audit('waf.rule_disabled', 'website', $websiteId, ['rule' => $ruleId]);
            app('session')->flash('success', "Rule {$ruleId} disabled for this site.");
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Could not disable rule: ' . $e->getMessage());
        }
        return Response::redirect('/admin/security/waf');
    }

    /** Re-enable one CRS rule for a website (admin, any site). */
    public function adminExclusionRemove(Request $request): Response {
        $websiteId = (int) $request->input('website_id', 0);
        $ruleId = \Modules\Security\Services\WafService::validateRuleId($request->input('rule_id', ''));
        $web = $websiteId > 0 ? app('db')->selectOne('SELECT * FROM `websites` WHERE `id`=?', [$websiteId]) : null;
        if ($web === null || $ruleId === null) {
            app('session')->flash('error', 'Invalid website or rule ID.');
            return Response::redirect('/admin/security/waf');
        }
        try {
            app(\Modules\Security\Services\WafService::class)->removeExclusion($websiteId, $ruleId);
            audit('waf.rule_enabled', 'website', $websiteId, ['rule' => $ruleId]);
            app('session')->flash('success', "Rule {$ruleId} re-enabled.");
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Could not re-enable rule: ' . $e->getMessage());
        }
        return Response::redirect('/admin/security/waf');
    }

    /** Disable one CRS rule for one of the customer's own websites. */
    public function customerExclusionAdd(Request $request): Response {
        $accountId = $this->accountId();
        if (!$accountId) return Response::redirect('/panel/security/waf');
        $websiteId = (int) $request->input('website_id', 0);
        $ruleId = \Modules\Security\Services\WafService::validateRuleId($request->input('rule_id', ''));
        $web = $websiteId > 0 ? app('db')->selectOne('SELECT * FROM `websites` WHERE `id`=? AND `account_id`=?', [$websiteId, $accountId]) : null;
        if ($web === null || $ruleId === null) {
            app('session')->flash('error', 'Select one of your websites and a numeric rule ID.');
            return Response::redirect('/panel/security/waf');
        }
        try {
            app(\Modules\Security\Services\WafService::class)->addExclusion($websiteId, $ruleId, (string) $request->input('reason', ''));
            audit('waf.rule_disabled', 'website', $websiteId, ['rule' => $ruleId]);
            app('session')->flash('success', "Rule {$ruleId} disabled for this site.");
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Could not disable rule: ' . $e->getMessage());
        }
        return Response::redirect('/panel/security/waf');
    }

    /** Re-enable one CRS rule for one of the customer's own websites. */
    public function customerExclusionRemove(Request $request): Response {
        $accountId = $this->accountId();
        if (!$accountId) return Response::redirect('/panel/security/waf');
        $websiteId = (int) $request->input('website_id', 0);
        $ruleId = \Modules\Security\Services\WafService::validateRuleId($request->input('rule_id', ''));
        $web = $websiteId > 0 ? app('db')->selectOne('SELECT * FROM `websites` WHERE `id`=? AND `account_id`=?', [$websiteId, $accountId]) : null;
        if ($web === null || $ruleId === null) {
            app('session')->flash('error', 'Invalid website or rule ID.');
            return Response::redirect('/panel/security/waf');
        }
        try {
            app(\Modules\Security\Services\WafService::class)->removeExclusion($websiteId, $ruleId);
            audit('waf.rule_enabled', 'website', $websiteId, ['rule' => $ruleId]);
            app('session')->flash('success', "Rule {$ruleId} re-enabled.");
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Could not re-enable rule: ' . $e->getMessage());
        }
        return Response::redirect('/panel/security/waf');
    }

    public function adminHotlinkToggle(Request $request): Response {
        $websiteId = (int)$request->input('website_id',0);
        $enabled = (int)$request->input('enabled',0) === 1;
        $web = app('db')->selectOne("SELECT * FROM `websites` WHERE `id`=?",[$websiteId]);
        if (!$web) { app('session')->flash('error','Website not found.'); return Response::redirect('/admin/security/waf'); }
        $existing = app('db')->selectOne("SELECT * FROM `security_settings` WHERE `account_id`=? AND `website_id`=?",[(int)$web['account_id'],$websiteId]);
        if ($existing) {
            app('db')->update('security_settings', ['hotlink_protection'=>$enabled ? 1:0,'updated_at'=>date('Y-m-d H:i:s')], '`id`=?',[$existing['id']]);
        } else {
            app('db')->insert('security_settings', ['account_id'=>(int)$web['account_id'],'website_id'=>$websiteId,'hotlink_protection'=>$enabled ? 1:0,'waf_enabled'=>0,'waf_mode'=>'detection','updated_at'=>date('Y-m-d H:i:s')]);
        }
        audit('hotlink.toggled','website',$websiteId,['enabled'=>$enabled]);
        app('session')->flash('success',$enabled ? 'Hotlink protection enabled.':'Hotlink protection disabled.');
        return Response::redirect('/admin/security/waf');
    }

    /** Upsert security_settings, sync the rules file, and regenerate the vhost. */
    private function setWaf(int $accountId, int $websiteId, bool $enabled, string $mode): void {
        $mode = in_array($mode, ['detection','on'], true) ? $mode : 'detection';
        $existing = app('db')->selectOne("SELECT * FROM `security_settings` WHERE `account_id`=? AND `website_id`=?",[$accountId,$websiteId]);
        if ($existing) {
            app('db')->update('security_settings', ['waf_enabled'=>$enabled ? 1:0,'waf_mode'=>$mode,'updated_at'=>date('Y-m-d H:i:s')], '`id`=?',[$existing['id']]);
        } else {
            app('db')->insert('security_settings', ['account_id'=>$accountId,'website_id'=>$websiteId,'hotlink_protection'=>0,'waf_enabled'=>$enabled ? 1:0,'waf_mode'=>$mode,'updated_at'=>date('Y-m-d H:i:s')]);
        }
        $this->applyWaf($websiteId, $enabled, $mode);
    }

    // ----- Customer: hotlink protection -----
    public function customerHotlinkToggle(Request $request): Response {
        $accountId = $this->accountId();
        if (!$accountId) return Response::redirect('/panel/security');
        $websiteId = (int)$request->input('website_id',0);
        $enabled = (int)$request->input('enabled',0) === 1;
        $web = app('db')->selectOne("SELECT * FROM `websites` WHERE `id`=? AND `account_id`=?",[$websiteId,$accountId]);
        if (!$web) { app('session')->flash('error','Select one of your websites.'); return Response::redirect('/panel/security'); }
        $existing = app('db')->selectOne("SELECT * FROM `security_settings` WHERE `account_id`=? AND `website_id`=?",[$accountId,$websiteId]);
        if ($existing) {
            app('db')->update('security_settings', ['hotlink_protection'=>$enabled ? 1:0,'updated_at'=>date('Y-m-d H:i:s')], '`id`=?',[$existing['id']]);
        } else {
            app('db')->insert('security_settings', ['account_id'=>$accountId,'website_id'=>$websiteId,'hotlink_protection'=>$enabled ? 1:0,'waf_enabled'=>0,'waf_mode'=>'detection','updated_at'=>date('Y-m-d H:i:s')]);
        }
        audit('hotlink.toggled','website',$websiteId,['enabled'=>$enabled]);
        app('session')->flash('success',$enabled ? 'Hotlink protection enabled.':'Hotlink protection disabled.');
        return Response::redirect('/panel/security');
    }

    // ----- Customer: antivirus -----
    public function customerAntivirus(): Response {
        $accountId = $this->accountId();
        $scans = $accountId ? app('db')->select("SELECT * FROM `security_scans` WHERE `account_id`=? ORDER BY `id` DESC LIMIT 20",[$accountId]) : [];
        $content = view('customer/security/antivirus', ['title'=>'Antivirus','scans'=>$scans], 'layouts/customer');
        return new Response((string)$content);
    }
    public function customerAntivirusScan(Request $request): Response {
        $accountId = $this->accountId();
        if (!$accountId) return Response::redirect('/panel/security');
        $account = app('db')->selectOne("SELECT * FROM `accounts` WHERE `id`=?",[$accountId]);
        if (!$account) return Response::redirect('/panel/security');
        $result = $this->runScan($account);
        app('session')->flash($result['status']==='success' ? 'success':'error', $result['message']);
        return Response::redirect('/panel/security/antivirus');
    }

    private function runScan(array $account): array {
        $home = $account['home_dir'] ?? '/home/'.$account['username'];
        $start = microtime(true);
        try {
            $r = app('provisioning')->execute('antivirus.scan', ['path'=>$home]);
            $output = trim($r['output'] ?? '');
        } catch (\Throwable $e) {
            return ['status'=>'error','message'=>'Scan failed: '.$e->getMessage()];
        }
        $duration = (int) round(microtime(true)-$start);
        // Parse clamscan output for FOUND lines and summary counts.
        $infected = [];
        $pattern = '/^(\S+):\s+(.+?)\s+FOUND/m';
        $found = 0;
        if (preg_match_all($pattern, $output, $m)) {
            $found = count($m[1]);
            $infected = array_slice($m[1], 0, 30);
        }
        $scanned = (int)(preg_match('/Scanned files:\s+(\d+)/', $output, $mc) ? $mc[1] : 0);
        $id = app('db')->insert('security_scans', [
            'account_id'=>$account['id'],
            'status'=>$found>0 ? 'infected':'clean',
            'scanned_files'=>$scanned,
            'infected_files'=>$found,
            'infected_list'=>$infected ? json_encode($infected) : null,
            'duration_seconds'=>$duration,
            'created_at'=>date('Y-m-d H:i:s'),
        ]);
        audit('antivirus.scan','security_scan',$id,['infected'=>$found]);
        return ['status'=>'success','message'=>"Scan complete: {$found} infected file(s).",'infected'=>$found];
    }

    // Apply WAF: write/remove the per-domain rules file (with the correct
    // SecRuleEngine), then regenerate + rewrite the nginx vhost so the
    // `modsecurity on` directive actually lands and reload is validated.
    private function applyWaf(int $websiteId, bool $enabled, string $mode): void {
        $site = app('db')->selectOne(
            "SELECT w.*, d.`name` AS domain, a.`username` AS username FROM `websites` w
             JOIN `domains` d ON d.id=w.domain_id
             LEFT JOIN `accounts` a ON a.id=w.account_id
             WHERE w.id=?",
            [$websiteId]
        );
        if (!$site) return;

        // ORDER MATTERS. The vhost write runs an atomic `nginx -t`; a config
        // that references a MISSING rules file is rejected. So when ENABLING
        // we must write the file BEFORE regenerating the vhost (the new
        // `modsecurity_rules_file` directive needs a target), and when
        // DISABLING we must regenerate the vhost FIRST (the front-door block
        // drops the directive) and only delete the file afterwards. Deleting
        // first left the old directive pointing at a gone file, so the gate
        // rejected every rewrite and `nginx -t` broke (front-door nginx then
        // refused to start on the next reload/restart).
        if ($enabled) {
            $engine = $mode === 'on' ? 'On' : 'DetectionOnly';
            $rules = app(\Modules\Security\Services\WafService::class)->renderConf(
                $engine,
                app(\Modules\Security\Services\WafService::class)->exclusions($websiteId)
            );
            try { app('provisioning')->execute('waf.rules.write', ['domain'=>$site['domain'],'rules'=>$rules]); }
            catch (\Throwable $e) { app('logger')->warning('WAF rules write failed: '.$e->getMessage()); }
        }

        // Regenerate the vhost so `modsecurity on` is present when enabled and
        // absent when disabled; rewrite via the agent (validated + reload).
        try {
            $account = app('db')->selectOne("SELECT * FROM `accounts` WHERE `id`=?",[(int)$site['account_id']]);
            $domain = app('db')->selectOne("SELECT * FROM `domains` WHERE `id`=?",[(int)$site['domain_id']]);
            if ($account && $domain) {
                $nginx = app(\Modules\Websites\Services\NginxConfigGenerator::class)->generate($site, $domain, $account);
                app('db')->update('websites', ['nginx_config'=>$nginx], '`id`=?',[$websiteId]);
                app(\Modules\Core\Services\NginxIsolationService::class)->pushVhost($account, $domain['name'], $nginx);
            }
        } catch (\Throwable $e) {
            app('logger')->warning('WAF vhost regenerate failed: '.$e->getMessage());
        }

        // Now safe to drop the rules file: no live vhost references it.
        if (!$enabled) {
            try { app('provisioning')->execute('waf.rules.delete', ['domain'=>$site['domain']]); }
            catch (\Throwable $e) { app('logger')->warning('WAF rules delete failed: '.$e->getMessage()); }
        }
    }
}
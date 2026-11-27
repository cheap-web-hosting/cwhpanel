<?php

declare(strict_types=1);

namespace App\Foundation\Provisioning;

use App\Foundation\Database\Connection;

/**
 * PrivilegedOperationService — client for the privileged provisioning agent.
 *
 * The panel PHP process runs UNPRIVILEGED. Real host operations (useradd,
 * groupadd, chown, setquota, cgroups v2 slices) are performed by a separate
 * root-owned Rust daemon (see /agent) reachable over a root-owned UNIX socket.
 *
 * Design constraints (SECURITY_REQUIREMENTS.md):
 *  - allowlisted operations only; never arbitrary shell commands
 *  - strict argument validation (defense in depth, mirrored in the agent)
 *  - structured newline-delimited JSON over a Unix socket, peer-credential auth
 *  - audit logging of every operation
 *
 * When provisioning is disabled, operations are recorded as dry-runs and no
 * socket call is made.
 */
class PrivilegedOperationService
{
    private Connection $db;

    private bool $enabled;

    private string $socketPath;

    private int $timeoutSeconds;

    private ?AuthTicketService $tickets;

    /**
     * Current request actor: ['role' => 'admin'|'customer', 'account' => ?string].
     * Set per request by the auth middlewares. null = no ticket (CLI/worker/
     * unauthenticated paths; non-root calls then fail under agent enforce mode).
     */
    private ?array $actor = null;

    /**
     * Allowlist: operation => required argument names (types validated per op).
     * The authoritative allowlist lives in the agent; this mirror lets the
     * panel reject obviously-bad requests before touching the socket.
     */
    private array $allowlist = [
        'user.create' => ['username', 'home', 'group'],
        'user.delete' => ['username'],
        'user.lock' => ['username'],
        'user.unlock' => ['username'],
        'group.create' => ['group'],
        'group.delete' => ['group'],
        'directory.create' => ['path'],
        'directory.chown' => ['owner', 'path'],
        'directory.remove' => ['account', 'path'],
        'quota.set' => ['username', 'soft_mb', 'hard_mb', '?inode_soft', '?inode_hard'],
        'quota.usage' => ['username'],
        'systemd.slice.create' => ['name', 'memory_mb', 'cpu_percent', 'tasks_max', 'io_mbs', '?io_iops'],
        'systemd.slice.delete' => ['name'],
        'slice.userenv.ensure' => ['username'],
        'slice.userenv.remove' => ['username'],
        'php.versions.list' => [],
        'php.versions.install' => ['version'],
        'php.versions.uninstall' => ['version'],
        'php.modules.list' => [],
        'php.modules.install' => ['version', 'module'],
        'php.master.write' => ['account', 'version', '?base_dir', '?modules', '?mcs_category', '?max_children', '?set_memory_limit', '?set_upload_max_filesize', '?set_post_max_size', '?set_max_execution_time', '?set_allow_url_fopen', '?set_allow_url_include', '?set_display_errors', '?set_expose_php'],
        'php.master.delete' => ['account', 'version'],
        'php.pool.write' => ['account', 'version', '?base_dir', '?max_children', '?set_memory_limit', '?set_upload_max_filesize', '?set_post_max_size', '?set_max_execution_time', '?set_allow_url_fopen', '?set_allow_url_include', '?set_display_errors', '?set_expose_php'],
        'php.pool.delete' => ['account', 'version'],
        'php.master.delete' => ['account', 'version'],
        'php.ini.set' => ['account', 'version', 'module', 'enabled'],
        'php.ini.list' => ['account', 'version'],
        'php.ini.read' => ['version'],
        'php.ini.write' => ['version', 'contents'],
        'nginx.vhost.write' => ['name', 'contents'],
        'nginx.vhost.delete' => ['name'],
        'nginx.logs.touch' => ['name'],
        'nginx.test' => [],
        'nginx.reload' => [],
        'nginx.vhosts.list' => [],
        'nginx.ratelimit.write' => ['contents'],
        'nginx.worker.write' => ['account', 'workers', 'max_body_mb', 'mcs_category'],
        'nginx.worker.vhost.write' => ['account', 'name', 'contents'],
        'nginx.worker.start' => ['account', 'mcs_category'],
        'nginx.worker.stop' => ['account'],
        'nginx.worker.status' => ['account'],
        'nginx.worker.test' => ['account'],
        'spam.global.write' => ['contents'],
        'spam.status' => [],
        'spam.user.write' => ['account', 'contents'],
        'spam.user.delete' => ['account'],
        'spam.bayes.learn' => ['account', 'class', 'message'],
        'mail.sieve.write' => ['account', 'mailbox', 'contents'],
        'mail.sieve.delete' => ['account', 'mailbox'],
        'ssh.keys.list' => ['account'],
        'ssh.keys.write' => ['account', 'contents'],
        'ssh.shell.set' => ['account', 'shell'],
        'ssh.config.read' => [],
        'ssh.config.write' => ['contents'],
        'ssh.jail.create' => ['account'],
        'ssh.jail.destroy' => ['account'],
        'ssh.jail.status' => ['account'],
        'selinux.restorecon' => ['path'],
        'selinux.mcs.label' => ['path', 'category'],
        'selinux.s0.inventory' => ['account'],
        'selinux.s0.fix' => ['account'],
        'selinux.content.inventory' => ['account'],
        'selinux.content.fix' => ['account'],
        'selinux.httpd.homedirs' => [],
        'dns.zone.create' => ['zone', '?ns'],
        'dns.ns.set' => ['zone', 'ns'],
        'dns.soa.set' => ['zone', 'mname'],
        'dns.zone.delete' => ['zone'],
        'dns.zone.list' => ['zone'],
        'dns.zone.secure' => ['zone'],
        'dns.zone.unsecure' => ['zone'],
        'dns.zone.show' => ['zone'],
        'dns.record.upsert' => ['zone', 'name', 'type', 'content', 'ttl'],
        'dns.record.delete' => ['zone', 'name', 'type'],
        'dns.record.value.delete' => ['zone', 'name', 'type', 'match'],
        'ssl.selfsigned.issue' => ['domain', 'days'],
        'ssl.acme.install' => ['domain', 'key', 'cert'],
            'ssl.remove' => ['domain'],
        'mail.virtual.write' => ['domain', 'entries'],
        'mail.virtual.delete' => ['domain'],
        'mail.sender.write' => ['entries'],
        'mail.senders.allow.write' => ['account', 'senders'],
        'mail.sender.submission.write' => ['entries'],
        'mail.domains.sync' => ['domains'],
        'mail.queue.list' => [],
        'mail.queue.flush' => [],
        'mail.queue.delete' => ['id'],
        'mail.queue.requeue' => ['id'],
        'mail.policy.enable' => [],
        'mail.antispam.enable' => [],
        'mail.maildir.create' => ['account', 'mailbox'],
        'mail.dovecot.sync' => ['entries'],
        'mail.setup' => [],
        'mail.smtp.setup' => [],
        'antivirus.scan' => ['path'],
        'antivirus.status' => [],
        'waf.rules.write' => ['domain', 'rules'],
        'waf.rules.delete' => ['domain'],
        'firewall.ports.list' => [],
        'firewall.services.list' => [],
        'firewall.rich.list' => [],
        'firewall.port.open' => ['port', 'protocol'],
        'firewall.port.close' => ['port', 'protocol'],
        'firewall.ip.block' => ['ip'],
        'firewall.ip.unblock' => ['ip'],
        'net.addr.list' => [],
        'network.stats' => [],
        'net.ipv6.add' => ['address'],
        'net.ipv6.del' => ['address'],
        'mail.outbound.sync' => ['senders', 'transports'],
        'net.ipv4.add' => ['address'],
        'net.ipv4.del' => ['address'],
        'firewall.smtp.egress.block' => ['account'],
        'firewall.smtp.egress.unblock' => ['account'],
        'firewall.db.allow' => ['ip'],
        'firewall.db.revoke' => ['ip'],
        'mysql.bind.write' => ['mode'],
        'threat.processes.list' => [],
        'threat.conns.list' => [],
        'threat.path.scan' => ['path', 'needles'],
        'panel.update.apply' => ['version', 'archive', 'sha256', 'target'],
        'service.list' => [],
        'service.status' => ['service'],
        'service.control' => ['service', 'action'],
        'updates.configure' => ['mode'],
        'updates.pending' => [],
        'updates.security' => [],
        'updates.cves' => [],
        'updates.apply' => ['packages', 'mode'],
        'system.reboot.required' => [],
        'system.reboot' => [],
        'reboot.configure' => ['script_path'],
        'php.versions.exact' => [],
        'python.versions.exact' => [],
        'node.versions.exact' => [],
        'log.list' => [],
        'mail.aliases.read' => [],
        'mail.aliases.write' => ['contents'],
        'ftp.setup' => ['guest'],
        'ftp.user.write' => ['username', 'home'],
        'ftp.user.delete' => ['username'],
        'ftp.login.create' => ['account', 'username', 'uid', 'gid', 'home'],
        'ftp.login.delete' => ['username'],
        'system.password.set' => ['account', 'username', 'password'],
        'system.hostname.set' => ['fqdn', 'ip'],
        'mail.hostname.set' => ['fqdn', 'origin'],
        'installer.install' => ['account', 'archive', 'dest', 'format'],
        'installer.config' => ['account', 'path', 'content'],
        'installer.update' => ['account', 'archive', 'dest', 'format'],
        'installer.file.install' => ['account', 'archive', 'dest', 'name'],
        'installer.wordpress.install' => ['account', 'dest', 'title', 'admin_user', 'admin_password', 'admin_email', 'site_url'],
        'installer.php.run' => ['account', 'dest', 'script', 'args'],
        'installer.datadir.ensure' => ['account', 'path'],
        'installer.db.import' => ['account', 'dest', 'schema', 'from_prefix', 'to_prefix', 'database'],
        'installer.extract' => ['account', 'archive', 'dest', 'format'],
        'installer.drupal.install' => ['account', 'dest', 'db_name', 'db_user', 'db_pass', 'admin_user', 'admin_password', 'admin_email', 'title'],
        'installer.piwigo.install' => ['account', 'dest', 'db_name', 'db_user', 'db_pass', 'prefix', 'admin_user', 'admin_password', 'admin_email', 'domain'],
        'installer.dokuwiki.install' => ['account', 'dest', 'title', 'admin_user', 'admin_password', 'admin_email'],
        'installer.matomo.install' => ['account', 'dest', 'db_host', 'db_name', 'db_user', 'db_pass', 'prefix', 'title', 'admin_user', 'admin_password', 'admin_email', 'site_url', 'domain'],
        'cron.set' => ['username', 'entries'],
        'cron.clear' => ['username'],
        'backup.create' => ['home', 'name', 'backup_dir', '?only', '?excludes'],
        'backup.restore' => ['account', 'home', 'name', 'backup_dir'],
        'backup.delete' => ['name', 'backup_dir'],
        'backup.db.delete' => ['name', 'backup_dir'],
        'log.read' => ['path'],
        'backup.db.create' => ['database', 'name', 'backup_dir'],
        'backup.dns.write' => ['name', 'content'],
        'backup.db.restore' => ['database', 'name', 'backup_dir'],
        'disk.usage' => ['account', 'path'],
        'file.list' => ['account', 'path'],
        'file.read' => ['account', 'path'],
        'file.write' => ['account', 'path', 'content'],
        'file.mkdir' => ['account', 'path'],
        'file.delete' => ['account', 'path'],
        'file.rename' => ['account', 'from', 'to'],
        'file.copy' => ['account', 'from', 'to'],
        'file.chmod' => ['account', 'path', 'mode'],
        'file.archive' => ['account', 'path', 'name', 'format'],
        'file.extract' => ['account', 'path', 'name', 'format'],
        'app.start' => ['account', 'name', 'runtime', 'work_dir', 'entry_point', '?runtime_version', '?env', '?netns', '?jailed', '?memory_mb'],
        'app.jail.ensure' => ['account'],
        'app.jail.run' => ['account', 'argv'],
        'cron.run.jailed' => ['account', 'job_id', 'command'],
        'cron.jailed.set' => ['username', 'entries'],
        'cron.jailed.clear' => ['username'],
        'dkim.key.write' => ['domain', 'private_key'],
        'dkim.signing.sync' => ['key_table', 'signing_table'],
        'netns.account.ensure' => ['account', 'id'],
        'netns.account.destroy' => ['account', 'id'],
        'netns.account.status' => ['account'],
        'app.npm.install' => ['account', 'name'],
        'python.app.install' => ['account', 'name', 'package'],
        'app.stop' => ['account', 'name'],
        'app.status' => ['account', 'name'],
        'app.logs' => ['account', 'name'],
        'app.deploy' => ['account', 'name', 'work_dir', 'git_url'],
        'app.venv.create' => ['account', 'name'],
        'app.venv.status' => ['account', 'name'],
        'python.packages.list' => ['account', 'name'],
        'python.packages.install' => ['account', 'name', 'package'],
        'python.packages.uninstall' => ['account', 'name', 'package'],
        'python.requirements.install' => ['account', 'name'],
        'app.runtime.probe' => ['path'],
        'app.runtime.install' => ['runtime', 'version'],
        'cron.run' => ['username', 'job_id', 'command'],
        'cron.state.read' => ['username'],
    ];

    public function __construct(
        Connection $db,
        bool $enabled = false,
        string $socketPath = '/run/cwhpanel/agent.sock',
        int $timeoutSeconds = 30,
        ?AuthTicketService $tickets = null
    ) {
        $this->db = $db;
        $this->enabled = $enabled;
        $this->socketPath = $socketPath;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->tickets = $tickets;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Set the current request actor (called by auth middlewares). Passing
     * null clears it (no ticket minted).
     */
    public function setActor(?array $actor): void
    {
        $this->actor = $actor;
    }

    public function actor(): ?array
    {
        return $this->actor;
    }

    /**
     * Execute an allowlisted operation.
     *
     * @param int|null $timeoutSeconds Per-call socket timeout (setup CLIs
     *      legitimately run minutes: migrations, inits). Clamped to 30..600;
     *      null keeps the configured default. The agent honors the same
     *      budget when the op carries a `timeout_s` argument.
     *
     * @return array{operation:string, status:string, args:array, output:string}
     */
    public function execute(string $operation, array $args = [], ?int $timeoutSeconds = null): array
    {
        if (!isset($this->allowlist[$operation])) {
            throw new ProvisioningException("Operation [{$operation}] is not allowlisted.");
        }

        $this->assertRequiredArgs($operation, $args);

        $preview = $this->preview($operation, $args);

        if (!$this->enabled) {
            $this->record($operation, $preview, 'dry-run', '', $args);

            return [
                'operation' => $operation,
                'status' => 'dry-run',
                'args' => $args,
                'output' => '',
            ];
        }

        try {
            [$status, $output] = $this->callAgent($operation, $args, $timeoutSeconds);
        } catch (ProvisioningException $e) {
            $this->record($operation, $preview, 'error', $e->getMessage(), $args);
            throw $e;
        }

        $this->record($operation, $preview, $status, $output, $args);

        return [
            'operation' => $operation,
            'status' => $status,
            'args' => $args,
            'output' => $output,
        ];
    }

    private function assertRequiredArgs(string $operation, array $args): void
    {
        foreach ($this->allowlist[$operation] as $name) {
            // Leading '?' marks optional arguments (present only when used).
            $required = str_starts_with($name, '?') ? ltrim($name, '?') : $name;
            if (str_starts_with($name, '?')) {
                continue;
            }
            if (!array_key_exists($required, $args)) {
                throw new ProvisioningException("Operation [{$operation}] requires argument [{$required}].");
            }
        }
    }

    /**
     * Talk to the agent over the Unix socket using newline-delimited JSON.
     *
     * @return array{0:string, 1:string} [status, output]
     */
    private function callAgent(string $operation, array $args, ?int $timeoutSeconds = null): array
    {
        $id = bin2hex(random_bytes(16));
        $timeout = $this->timeoutSeconds;
        if ($timeoutSeconds !== null) {
            $timeout = max(30, min(600, $timeoutSeconds));
        }

        $request = [
            'id' => $id,
            'operation' => $operation,
            'args' => (object) $args,
        ];

        $ticket = $this->mintTicket($operation, $args);
        if ($ticket !== null) {
            $request['ticket'] = $ticket;
        }

        $requestJson = json_encode($request, JSON_UNESCAPED_SLASHES);

        if ($requestJson === false) {
            throw new ProvisioningException('Failed to encode agent request.');
        }

        $errno = 0;
        $errstr = '';

        $socket = @stream_socket_client(
            'unix://' . $this->socketPath,
            $errno,
            $errstr,
            (float) $timeout
        );

        if ($socket === false) {
            throw new ProvisioningException("Cannot connect to agent at [{$this->socketPath}]: {$errstr}");
        }

        stream_set_timeout($socket, $timeout);

        $written = @fwrite($socket, $requestJson . "\n");

        if ($written === false) {
            fclose($socket);
            throw new ProvisioningException('Failed to write request to agent.');
        }

        $line = fgets($socket);

        $meta = stream_get_meta_data($socket);
        fclose($socket);

        if ($meta['timed_out']) {
            throw new ProvisioningException("Agent request [{$operation}] timed out.");
        }

        if ($line === false) {
            throw new ProvisioningException('Agent closed the connection without a response.');
        }

        $response = json_decode(trim($line), true);

        if (!is_array($response) || !isset($response['status'])) {
            throw new ProvisioningException('Malformed agent response.');
        }

        if (($response['status'] ?? '') === 'error') {
            throw new ProvisioningException((string) ($response['error'] ?? 'Agent reported an error.'));
        }

        return ['success', (string) ($response['output'] ?? '')];
    }

    /**
     * Mint a ticket binding this request to the current actor. null when no
     * tickets service, no actor, or the role's key is not configured.
     */
    private function mintTicket(string $operation, array $args): ?string
    {
        if ($this->tickets === null || $this->actor === null) {
            return null;
        }

        $role = $this->actor['role'] ?? null;
        if ($role !== 'admin' && $role !== 'customer') {
            return null;
        }

        $account = isset($this->actor['account']) ? (string) $this->actor['account'] : null;
        if ($role === 'customer' && $account === null) {
            // Customer with no linked hosting account: do not mint. The agent
            // refuses in enforce mode (no sub), which is the fail-closed state.
            return null;
        }

        $acct = (string) ($args['account'] ?? ($args['username'] ?? ''));

        return $this->tickets->mint($role, $operation, $args, $account, $acct !== '' ? $acct : null);
    }

    /**
     * Human-readable preview of what will run (for logs and dry-run display).
     * The real argv is constructed by the agent; this is informational only.
     */
    private function preview(string $operation, array $args): string
    {
        $parts = [$operation];
        $args = self::scrubArgs($args);

        foreach ($this->allowlist[$operation] as $name) {
            $name = ltrim($name, '?');
            $value = $args[$name] ?? '';
            if (is_array($value)) {
                $value = implode(' ', array_map(fn ($v) => (string) $v, $value));
            }
            $parts[] = $name . '=' . (string) $value;
        }

        return implode(' ', $parts);
    }

    private function record(string $operation, string $command, string $status, string $output, array $args): void
    {
        $row = [
            'operation' => $operation,
            'status' => $status,
            'command' => $command,
            'args' => json_encode(self::scrubArgs($args), JSON_UNESCAPED_SLASHES),
            'output' => mb_substr($output, 0, 2000),
            'created_at' => date('Y-m-d H:i:s'),
        ];
        try {
            $this->db->insert('provisioning_logs', $row);
            return;
        } catch (\PDOException $e) {
            if (!preg_match('/\b(2006|2013|2014|2055)\b/', $e->getMessage())) {
                throw $e;
            }
        }
        // The op may legitimately restart MariaDB (mysql.bind.write does):
        // the inherited handle is dead. Reconnect once and retry so the
        // audit row is not lost; if the server is still down, log locally
        // and keep the op result truthful instead of masking success.
        try {
            $fresh = new Connection(
                (string) env('DB_HOST', '127.0.0.1'),
                (int) env('DB_PORT', 3306),
                (string) env('DB_DATABASE', 'cwhpanel'),
                (string) env('DB_USERNAME', 'root'),
                (string) env('DB_PASSWORD', '')
            );
            $fresh->insert('provisioning_logs', $row);
        } catch (\Throwable $e2) {
            error_log('cwhpanel provisioning_logs write failed after reconnect: ' . $e2->getMessage());
        }
    }

    /**
     * Mask secret arg values before they hit provisioning_logs: installer
     * setup ops carry plaintext DB/app passwords (transient by design), and
     * the log row would otherwise preserve them indefinitely (bitten live:
     * installer.*.install rows held admin_password/db_pass in args JSON).
     */
    public static function scrubArgs(array $args): array
    {
        foreach ($args as $k => $v) {
            $lk = strtolower((string) $k);
            if (str_contains($lk, 'pass') || str_contains($lk, 'secret') || str_contains($lk, 'token') || str_contains($lk, 'private_key')) {
                $args[$k] = '***';
            }
        }
        return $args;
    }
}

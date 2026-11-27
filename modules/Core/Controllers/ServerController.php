<?php

declare(strict_types=1);

namespace Modules\Core\Controllers;

use App\Foundation\Request;
use App\Foundation\Response;
use Modules\Core\Services\SystemInfo;
use Modules\Core\Services\SshConfigService;

class ServerController
{
    private SystemInfo $systemInfo;

    public function __construct(SystemInfo $systemInfo)
    {
        $this->systemInfo = $systemInfo;
    }

    public function overview(): Response
    {
        $content = view('admin/server/overview', [
            'title' => 'Server Overview',
            'system' => $this->systemInfo->snapshot(),
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    public function system(): Response
    {
        $content = view('admin/server/system', [
            'title' => 'System Information',
            'system' => $this->systemInfo->snapshot(),
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    public function phpVersions(): Response
    {
        $svc = app(\Modules\Core\Services\PhpVersionsService::class);
        $installed = $svc->installed();
        try {
            $usage = app('db')->select(
                'SELECT `php_version` AS v, COUNT(*) AS n, GROUP_CONCAT(`label` ORDER BY `label` SEPARATOR \', \') AS sites
                 FROM `websites` GROUP BY `php_version`'
            );
        } catch (\Throwable $e) {
            $usage = [];
        }
        $content = view('admin/server/php-versions', [
            'title' => 'PHP Versions',
            'installed' => $installed,
            'available' => \Modules\Core\Services\PhpVersionsService::AVAILABLE,
            'usage' => $usage,
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    public function services(): Response
    {
        // Degrade gracefully when the agent is unreachable instead of 500ing:
        // render the page with an error banner and an empty list.
        $statuses = [];
        $agentError = null;
        try {
            $statuses = app(\Modules\Core\Services\ServiceService::class)->statuses();
        } catch (\Throwable $e) {
            $agentError = 'Service status unavailable: ' . $e->getMessage();
            app('logger')->warning('Server services page degraded: ' . $e->getMessage());
        }

        $content = view('admin/server/services', [
            'title' => 'Services',
            'statuses' => $statuses,
            'agentError' => $agentError,
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    public function controlService(Request $request): Response
    {
        $service = (string) $request->input('service', '');
        $action = (string) $request->input('action', '');

        $allowed = ['start', 'stop', 'restart'];
        if ($service === '' || !in_array($action, $allowed, true)) {
            app('session')->flash('error', 'Invalid service action.');
            return Response::redirect('/admin/server/services');
        }

        try {
            $svc = app(\Modules\Core\Services\ServiceService::class);
            if ($svc->control($service, $action)) {
                audit('service.control', 'service', null, ['service' => $service, 'action' => $action]);
                app('session')->flash('success', "Service \"{$service}\" {$action} requested.");
            } else {
                app('session')->flash('error', "Failed to {$action} service \"{$service}\".");
            }
        } catch (\Throwable $e) {
            app('logger')->warning('Service control failed: ' . $e->getMessage());
            app('session')->flash('error', "Could not {$action} service \"{$service}\": " . $e->getMessage());
        }

        return Response::redirect('/admin/server/services');
    }

    public function nginx(): Response
    {
        // Degrade gracefully when the agent is unreachable instead of 500ing.
        $vhosts = [];
        $testOutput = '';
        $agentError = null;
        try {
            $svc = app(\Modules\Core\Services\NginxService::class);
            $vhosts = $svc->vhosts();
            $testOutput = $svc->test();
        } catch (\Throwable $e) {
            $agentError = 'Nginx status unavailable: ' . $e->getMessage();
            app('logger')->warning('Server nginx page degraded: ' . $e->getMessage());
        }

        $content = view('admin/server/nginx', [
            'title' => 'Nginx',
            'vhosts' => $vhosts,
            'testOutput' => $testOutput,
            'agentError' => $agentError,
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    public function nginxAction(Request $request): Response
    {
        $action = (string) $request->input('action', '');
        $svc = app(\Modules\Core\Services\NginxService::class);

        if ($action === 'test') {
            try {
                $out = $svc->test();
                app('session')->flash('success', 'nginx -t result: ' . $out);
            } catch (\Throwable $e) {
                app('logger')->warning('Nginx test failed: ' . $e->getMessage());
                app('session')->flash('error', 'nginx test failed: ' . $e->getMessage());
            }
        } elseif ($action === 'reload') {
            try {
                if ($svc->reload()) {
                    audit('nginx.reload', 'nginx', null);
                    app('session')->flash('success', 'nginx reloaded.');
                } else {
                    app('session')->flash('error', 'nginx reload failed.');
                }
            } catch (\Throwable $e) {
                app('logger')->warning('Nginx reload failed: ' . $e->getMessage());
                app('session')->flash('error', 'nginx reload failed: ' . $e->getMessage());
            }
        } else {
            app('session')->flash('error', 'Invalid action.');
        }

        return Response::redirect('/admin/server/nginx');
    }

    public function ssh(): Response
    {
        $svc = app(\Modules\Core\Services\SshConfigService::class);
        $content = view('admin/server/ssh', [
            'title' => 'SSH',
            'currentPort' => $svc->port(),
            'defaultPort' => SshConfigService::DEFAULT_PORT,
            'readError' => $svc->error(),
        ], 'layouts/admin');
        return new Response((string) $content);
    }

    public function sshPortSave(Request $request): Response
    {
        $svc = app(\Modules\Core\Services\SshConfigService::class);
        $port = (int) $request->input('port', 0);
        $keepOld = (int) $request->input('keep_old', 1) === 1;
        if ($port < 1024 || $port > 65535) {
            app('session')->flash('error', 'Enter a port between 1024 and 65535.');
            return Response::redirect('/admin/server/ssh');
        }
        try {
            $old = $svc->setPort($port, $keepOld);
            $note = $keepOld || $old === $port ? '' : sprintf(' Previous port %d closed in the firewall.', $old);
            app('session')->flash('success', sprintf('SSH port changed from %d to %d (sshd validated + reloaded).%s', $old, $port, $note));
            audit('server.ssh_port', 'ssh', null, ['from' => $old, 'to' => $port, 'keep_old' => $keepOld]);
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Could not change SSH port: ' . $e->getMessage());
        }
        return Response::redirect('/admin/server/ssh');
    }

    public function updates(): Response
    {
        // Degrade gracefully when the agent is unreachable instead of 500ing.
        $mode = 'off';
        $status = [];
        $rootForward = null;
        $agentError = null;
        $reboot = null;
        $runs = [];
        try {
            $svc = app(\Modules\Core\Services\UpdatesService::class);
            $mode = $svc->mode();
            $status = $svc->status();
            $rootForward = $svc->rootForward();
            $reboot = $svc->rebootRequired();
            $runs = $svc->recentRuns();
        } catch (\Throwable $e) {
            $agentError = 'Update status unavailable: ' . $e->getMessage();
            app('logger')->warning('Server updates page degraded: ' . $e->getMessage());
        }

        try {
            $hostname = app(\Modules\Core\Services\HostnameService::class)->current();
        } catch (\Throwable $e) {
            $hostname = (string) gethostname();
        }

        $panelVersion = null;
        $panelUpdate = null;
        $panelUpdateMode = 'notify';
        try {
            $panelVersion = app(\Modules\Core\Services\PanelVersionService::class)->current();
            $updSvc = app(\Modules\Core\Services\PanelUpdateService::class);
            $panelUpdate = $updSvc->snapshot(false);
            $panelUpdateMode = $updSvc->mode();
        } catch (\Throwable $e) {
            app('logger')->warning('Panel version page degraded: ' . $e->getMessage());
        }

        $content = view('admin/server/updates', [
            'title' => 'Updates',
            'mode' => $mode,
            'status' => $status,
            'rootForward' => $rootForward,
            'reboot' => $reboot,
            'runs' => $runs,
            'agentError' => $agentError,
            'hostname' => $hostname,
            'panelVersion' => $panelVersion,
            'panelUpdate' => $panelUpdate,
            'panelUpdateMode' => $panelUpdateMode,
            'panelManagerBlocked' => app(\Modules\Core\Services\PanelManagerService::class)->policyBlocked(),
            'panelManagerStatus' => app(\Modules\Core\Services\PanelManagerService::class)->status(),
            'panelManagerLicenseStatus' => app(\Modules\Core\Services\PanelManagerService::class)->licenseStatus(),
            'panelManagerLicenseReason' => app(\Modules\Core\Services\PanelManagerService::class)->licenseReason(),
            'panelManagerBlockReason' => app(\Modules\Core\Services\PanelManagerService::class)->blockReason(),
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    public function panelCheck(): Response
    {
        try {
            $snapshot = app(\Modules\Core\Services\PanelUpdateService::class)->snapshot(true);
            $latest = $snapshot['latest'] ?? null;
            if ($latest === null) {
                app('session')->flash('error', 'Could not reach the update feed — check network or GitHub rate limits.');
            } elseif (($snapshot['up_to_date'] ?? false)) {
                app('session')->flash('success', 'Panel is up to date (v' . $snapshot['installed'] . ').');
            } else {
                app('session')->flash('success', 'Panel update available: v' . $latest . ' (installed v' . $snapshot['installed'] . ').');
            }
            audit('system.panel_update.check', 'server', null, ['installed' => $snapshot['installed'] ?? null, 'latest' => $latest]);
        } catch (\Throwable $e) {
            app('logger')->warning('Panel update check failed: ' . $e->getMessage());
            app('session')->flash('error', 'Update check failed: ' . $e->getMessage());
        }
        return Response::redirect('/admin/server/updates');
    }

    public function panelApply(Request $request): Response
    {
        $version = (string) $request->input('version', '');
        try {
            $result = app(\Modules\Core\Services\PanelUpdateService::class)->apply($version);
            app('session')->flash('success', 'Panel updated to v' . $version . '. ' . ($result['output'] ?? ''));
            audit('system.panel_update.apply', 'server', null, ['version' => $version, 'result' => $result['output'] ?? null]);
        } catch (\Throwable $e) {
            app('logger')->error('Panel update apply failed: ' . $e->getMessage());
            app('session')->flash('error', 'Panel update failed: ' . $e->getMessage());
            audit('system.panel_update.apply', 'server', null, ['version' => $version, 'error' => $e->getMessage()]);
        }
        return Response::redirect('/admin/server/updates');
    }

    public function panelSettings(Request $request): Response
    {
        $mode = (string) $request->input('mode', 'notify');
        try {
            app(\Modules\Core\Services\PanelUpdateService::class)->setMode($mode);
            app('session')->flash('success', 'Panel auto-update mode set to ' . $mode . '.');
            audit('system.panel_update.mode', 'server', null, ['mode' => $mode]);
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Could not save mode: ' . $e->getMessage());
        }
        return Response::redirect('/admin/server/updates');
    }

    public function updateMode(Request $request): Response
    {
        $mode = (string) $request->input('mode', '');
        if (!in_array($mode, \Modules\Core\Services\UpdatesService::MODES, true)) {
            app('session')->flash('error', 'Invalid update mode.');
            return Response::redirect('/admin/server/updates');
        }
        try {
            app(\Modules\Core\Services\UpdatesService::class)->setMode($mode);
            audit('system.updates.mode', 'server', null, ['mode' => $mode]);
            app('session')->flash('success', $mode === 'off'
                ? 'Automatic updates disabled.'
                : 'Automatic ' . ($mode === 'security' ? 'security' : 'all') . ' updates enabled (daily, 03:30).');
        } catch (\Throwable $e) {
            app('logger')->warning('Update mode change failed: ' . $e->getMessage());
            app('session')->flash('error', 'Could not change update mode: ' . $e->getMessage());
        }
        return Response::redirect('/admin/server/updates');
    }

    public function checkUpdates(): Response
    {
        try {
            app('db')->insert('jobs', [
                'type' => 'system.updates.check',
                'status' => 'pending',
                'payload' => json_encode([]),
                'result' => null,
                'attempts' => 0,
                'max_attempts' => 2,
                'available_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            audit('system.updates.check.queued', 'server', null);
            app('session')->flash('success', 'Update check queued. Refresh in a moment for the result.');
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Could not queue update check: ' . $e->getMessage());
        }
        return Response::redirect('/admin/server/updates');
    }

    public function applyUpdates(Request $request): Response
    {
        $mode = (string) $request->input('mode', '');
        if (!in_array($mode, ['security', 'all'], true)) {
            app('session')->flash('error', 'Invalid update scope.');
            return Response::redirect('/admin/server/updates');
        }
        try {
            app('db')->insert('jobs', [
                'type' => 'system.updates.apply',
                'status' => 'pending',
                'payload' => json_encode(['mode' => $mode]),
                'result' => null,
                'attempts' => 0,
                'max_attempts' => 2,
                'available_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            audit('system.updates.apply.queued', 'server', null, ['mode' => $mode]);
            app('session')->flash('success', ($mode === 'all' ? 'All' : 'Security') . ' update run queued. It runs in the background; refresh for the result.');
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Could not queue update run: ' . $e->getMessage());
        }
        return Response::redirect('/admin/server/updates');
    }

    public function rebootServer(Request $request): Response
    {
        if ((int) $request->input('confirm_reboot', 0) !== 1) {
            app('session')->flash('error', 'Check the confirmation box to reboot the server.');
            return Response::redirect('/admin/server/updates');
        }
        try {
            app('provisioning')->execute('system.reboot');
            audit('system.reboot', 'server', null);
            app('session')->flash('success', 'Reboot initiated — the server is restarting now. The panel will return shortly.');
        } catch (\Throwable $e) {
            app('logger')->warning('Server reboot failed: ' . $e->getMessage());
            app('session')->flash('error', 'Could not reboot the server: ' . $e->getMessage());
        }
        return Response::redirect('/admin/server/updates');
    }

    public function rootForward(Request $request): Response
    {
        $email = trim((string) $request->input('email', ''));
        try {
            app(\Modules\Core\Services\UpdatesService::class)->setRootForward($email === '' ? null : $email);
            audit('system.mail.root_forward', 'server', null, ['email' => $email]);
            app('session')->flash('success', $email === ''
                ? 'Root email forwarding cleared.'
                : "Root email now forwards to {$email}.");
        } catch (\Throwable $e) {
            app('logger')->warning('Root forward change failed: ' . $e->getMessage());
            app('session')->flash('error', 'Could not update root forwarding: ' . $e->getMessage());
        }
        return Response::redirect('/admin/server/updates');
    }

    public function hostname(): Response
    {
        $svc = app(\Modules\Core\Services\HostnameService::class);
        $current = $svc->current();
        $dns = ['state' => 'unknown', 'addrs' => []];
        $cert = null;
        try {
            $dns = $svc->dnsState($current);
            $cert = $svc->certInfo();
        } catch (\Throwable $e) {
            app('logger')->warning('Hostname page probe failed: ' . $e->getMessage());
        }
        $status = null;
        $row = app('db')->selectOne('SELECT `value` FROM `settings` WHERE `key` = ?', ['system.hostname_status']);
        if ($row !== null) {
            $status = json_decode((string) $row['value'], true) ?: null;
        }

        $content = view('admin/server/hostname', [
            'title' => 'Hostname',
            'current' => $current,
            'domain' => $svc->parentDomain($current),
            'dns' => $dns,
            'cert' => $cert,
            'status' => $status,
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    public function hostnameChange(Request $request): Response
    {
        $fqdn = strtolower(trim((string) $request->input('fqdn', '')));
        try {
            $fqdn = app(\Modules\Core\Services\HostnameService::class)->validateFqdn($fqdn);
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
            return Response::redirect('/admin/server/hostname');
        }
        return $this->queueHostnameJob($fqdn, 'Hostname change');
    }

    public function hostnameReprovision(): Response
    {
        $fqdn = app(\Modules\Core\Services\HostnameService::class)->current();
        return $this->queueHostnameJob($fqdn, 'Hostname re-provision');
    }

    private function queueHostnameJob(string $fqdn, string $label): Response
    {
        try {
            app('db')->insert('jobs', [
                'type' => 'system.hostname.change',
                'status' => 'pending',
                'payload' => json_encode(['fqdn' => $fqdn]),
                'result' => null,
                'attempts' => 0,
                'max_attempts' => 1,
                'available_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            audit('system.hostname.change.queued', 'server', null, ['fqdn' => $fqdn]);
            app('session')->flash('success', $label . ' queued for ' . $fqdn . '. It runs in the background; refresh for the result.');
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Could not queue hostname job: ' . $e->getMessage());
        }
        return Response::redirect('/admin/server/hostname');
    }

    public function installPhpVersion(Request $request): Response
    {
        $version = (string) $request->input('version', '');
        $id = \Modules\Core\Services\PhpVersionsService::id($version);
        if ($id === '84') {
            app('session')->flash('error', 'PHP 8.4 is already the system version.');
            return Response::redirect('/admin/server/php-versions');
        }
        try {
            app('db')->insert('jobs', [
                'type' => 'php.install',
                'status' => 'pending',
                'payload' => json_encode(['version' => $version]),
                'result' => null,
                'attempts' => 0,
                'max_attempts' => 2,
                'available_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            audit('php.version.install.queued', 'php_version', null, ['version' => $version]);
            app('session')->flash('success', 'PHP ' . $version . ' installation queued. It will complete in the background.');
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Could not queue PHP version install: ' . $e->getMessage());
        }
        return Response::redirect('/admin/server/php-versions');
    }

    public function uninstallPhpVersion(Request $request): Response
    {
        $version = (string) $request->input('version', '');
        $id = \Modules\Core\Services\PhpVersionsService::id($version);
        if ($id === '84') {
            app('session')->flash('error', 'PHP 8.4 is the system version and cannot be removed.');
            return Response::redirect('/admin/server/php-versions');
        }
        // Pre-checks run now (installed? in use?) so the form rejects fast;
        // the job re-validates at run time in case state changed meanwhile.
        try {
            $svc = app(\Modules\Core\Services\PhpVersionsService::class);
            $installed = $svc->installed();
            if (!isset($installed[$id])) {
                throw new \RuntimeException('That version is not installed.');
            }
            $users = app('db')->select(
                'SELECT `label` FROM `websites` WHERE `php_version` = ? OR `php_version` = ? ORDER BY `label`',
                [\Modules\Core\Services\PhpVersionsService::label($id), $id]
            );
            if ($users !== []) {
                throw new \RuntimeException(
                    'Still used by: ' . implode(', ', array_column($users, 'label')) . '. Move those sites first.'
                );
            }
            app('db')->insert('jobs', [
                'type' => 'php.uninstall',
                'status' => 'pending',
                'payload' => json_encode(['version' => $version]),
                'result' => null,
                'attempts' => 0,
                'max_attempts' => 2,
                'available_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            audit('php.version.uninstall.queued', 'php_version', null, ['version' => $version]);
            app('session')->flash('success', 'PHP ' . $version . ' removal queued. It will complete in the background.');
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Could not queue PHP version removal: ' . $e->getMessage());
        }
        return Response::redirect('/admin/server/php-versions');
    }

    public function phpConfig(string $version): Response
    {
        $svc = app(\Modules\Core\Services\PhpVersionsService::class);
        $id = \Modules\Core\Services\PhpVersionsService::id($version);
        if (!isset($svc->installed()[$id])) {
            app('session')->flash('error', 'PHP ' . \Modules\Core\Services\PhpVersionsService::label($id) . ' is not installed.');
            return Response::redirect('/admin/server/php-versions');
        }

        $ini = '';
        $disabled = [];
        $agentError = null;
        try {
            $ini = $svc->readIni($id);
            $disabled = \Modules\Core\Services\PhpVersionsService::disabledFunctionsFromIni($ini);
        } catch (\Throwable $e) {
            $agentError = 'php.ini unavailable: ' . $e->getMessage();
            app('logger')->warning('Server php-config page degraded: ' . $e->getMessage());
        }

        $content = view('admin/server/php-config', [
            'title' => 'PHP ' . \Modules\Core\Services\PhpVersionsService::label($id) . ' config',
            'version' => $id,
            'label' => \Modules\Core\Services\PhpVersionsService::label($id),
            'iniPath' => \Modules\Core\Services\PhpVersionsService::iniPath($id),
            'ini' => $ini,
            'disabled' => $disabled,
            'agentError' => $agentError,
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    public function phpConfigIniSave(Request $request, string $version): Response
    {
        $svc = app(\Modules\Core\Services\PhpVersionsService::class);
        $id = \Modules\Core\Services\PhpVersionsService::id($version);
        if (!isset($svc->installed()[$id])) {
            app('session')->flash('error', 'PHP ' . \Modules\Core\Services\PhpVersionsService::label($id) . ' is not installed.');
            return Response::redirect('/admin/server/php-versions');
        }
        try {
            $svc->writeIni($id, (string) $request->input('ini', ''));
            audit('php.ini.write', 'php_version', null, $id);
            app('session')->flash('success', 'PHP ' . \Modules\Core\Services\PhpVersionsService::label($id) . ' php.ini saved and validated. The FPM daemon was reloaded.');
        } catch (\Throwable $e) {
            app('logger')->warning('php.ini write failed: ' . $e->getMessage());
            app('session')->flash('error', 'php.ini was NOT changed: ' . $e->getMessage());
        }
        return Response::redirect('/admin/server/php-config/' . $id);
    }

    public function phpConfigFunctionsSave(Request $request, string $version): Response
    {
        $svc = app(\Modules\Core\Services\PhpVersionsService::class);
        $id = \Modules\Core\Services\PhpVersionsService::id($version);
        if (!isset($svc->installed()[$id])) {
            app('session')->flash('error', 'PHP ' . \Modules\Core\Services\PhpVersionsService::label($id) . ' is not installed.');
            return Response::redirect('/admin/server/php-versions');
        }
        // Accept one-per-line or comma/space separated input.
        $raw = (string) $request->input('functions', '');
        $functions = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        try {
            $svc->setDisabledFunctions($id, array_map('strval', $functions));
            app('session')->flash('success', count($functions) > 0
                ? 'Disabled functions updated for PHP ' . \Modules\Core\Services\PhpVersionsService::label($id) . ' (validated + daemon reloaded).'
                : 'Disabled functions cleared for PHP ' . \Modules\Core\Services\PhpVersionsService::label($id) . ' (stock behavior restored).');
        } catch (\Throwable $e) {
            app('logger')->warning('Selective disable_functions write failed: ' . $e->getMessage());
            app('session')->flash('error', 'Disabled functions were NOT changed: ' . $e->getMessage());
        }
        return Response::redirect('/admin/server/php-config/' . $id);
    }
}

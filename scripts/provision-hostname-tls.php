#!/usr/bin/env php
<?php
/**
 * Server-hostname TLS provisioner (panel + mail).
 *
 * Detects the server FQDN, obtains a Let's Encrypt certificate for it via
 * the panel's own ACME client (HTTP-01 against the panel web root), and
 * applies it everywhere applicable:
 *   - nginx panel vhost: 443 + http→https redirect (/.well-known exempt)
 *   - Postfix submission/smtps (smtpd_tls_cert/key_file)
 *   - Dovecot IMAP/POP3 (ssl_cert/ssl_key override)
 *   - APP_URL in the panel .env → https://<hostname>/
 *   - weekly renewal cron (renews only when <30d remain)
 *
 * The HTTP-01 challenge is the real reachability proof: it works through
 * CDN proxies (e.g. Cloudflare) as long as port 80 reaches this server.
 * Pure-DNS pre-checks only warn, except an unresolvable hostname which
 * aborts (LE could never validate it) unless --force.
 *
 * Usage (as root):
 *   php scripts/provision-hostname-tls.php [--hostname=h] [--force]
 *       [--renew-only] [--check-only] [--keep-app-url] [--no-reload]
 *
 * --renew-only is what cron runs: silent success when the cert is fresh.
 * Exits 0 on success/nothing-due, 1 on error. Self-limits Let's Encrypt
 * traffic: never attempts issuance when the installed cert is fresh
 * (unless --force). Respects LE duplicate-cert limits — do not --force
 * in loops (max 5 identical certs/week).
 */

require __DIR__ . '/_bootstrap.php';

use App\Foundation\Application;

$app = new Application(BASE_PATH);
$app->bootstrap();

$opts = getopt('', ['hostname:', 'force', 'renew-only', 'check-only', 'keep-app-url', 'no-reload', 'panel-base:']);
$FORCE = isset($opts['force']);
$RENEW_ONLY = isset($opts['renew-only']);
$CHECK_ONLY = isset($opts['check-only']);
$NO_RELOAD = isset($opts['no-reload']);

// The live panel tree: explicit --panel-base wins, else /opt/cwhpanel when
// present (production), else the tree this script runs from (fresh box).
$PANEL_BASE = isset($opts['panel-base']) && $opts['panel-base'] !== ''
    ? rtrim($opts['panel-base'], '/')
    : (is_dir('/opt/cwhpanel/public') ? '/opt/cwhpanel' : BASE_PATH);
$PANEL_ENV = $PANEL_BASE . '/.env';
$PANEL_PUBLIC = $PANEL_BASE . '/public';

const HOST_CERT_DIR = '/etc/cwhpanel/ssl/hostname';
const HOST_KEY = '/etc/cwhpanel/ssl/hostname/privkey.pem';
const HOST_CHAIN = '/etc/cwhpanel/ssl/hostname/fullchain.pem';
const RENEW_DAYS = 30;
const PANEL_CONF = '/etc/nginx/conf.d/panel.conf';

function say(string $m): void { echo $m . "\n"; }
function fail(string $m): never { fwrite(STDERR, "ERROR: {$m}\n"); exit(1); }
function sh(string $cmd, array &$out = []): int {
    $out = [];
    exec($cmd . ' 2>&1', $out, $code);
    return $code;
}

/** Detect the server FQDN: flag > hostname -f. */
function detectHostname(?string $flag): string {
    if ($flag !== null && $flag !== '') {
        return strtolower(trim($flag));
    }
    $out = [];
    if (sh('hostname -f', $out) === 0 && isset($out[0])) {
        return strtolower(trim($out[0]));
    }
    return '';
}

function validFqdn(string $h): bool {
    if (!preg_match('/^(?=.{4,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $h)) {
        return false;
    }
    foreach (['.local', '.localhost', '.internal', '.invalid', '.test', '.example'] as $bad) {
        if (str_ends_with($h, $bad)) {
            return false;
        }
    }
    return true;
}

/** Locally configured global-scope IPs (v4 + v6). */
function localIps(): array {
    $out = [];
    $ips = [];
    if (sh('ip -o addr show scope global', $out) === 0) {
        foreach ($out as $line) {
            if (preg_match('/\binet6?\s+([0-9a-fA-F:.]+)\//', $line, $m)) {
                $ips[] = strtolower($m[1]);
            }
        }
    }
    $h = [];
    if (sh('hostname -I', $h) === 0 && isset($h[0])) {
        foreach (preg_split('/\s+/', trim($h[0])) as $ip) {
            if ($ip !== '') {
                $ips[] = strtolower($ip);
            }
        }
    }
    return array_values(array_unique($ips));
}

/** DNS state for the hostname: direct (points here), remote/proxied, none. */
function dnsState(string $host): array {
    $addrs = [];
    foreach (['A' => DNS_A, 'AAAA' => DNS_AAAA] as $t => $flag) {
        foreach (@dns_get_record($host, $flag) ?: [] as $r) {
            $ip = strtolower($r['ip'] ?? $r['ipv6'] ?? '');
            if ($ip !== '') {
                $addrs[$t][] = $ip;
            }
        }
    }
    if ($addrs === []) {
        return ['none', []];
    }
    $flat = array_merge($addrs['A'] ?? [], $addrs['AAAA'] ?? []);
    foreach ($flat as $ip) {
        if (in_array($ip, localIps(), true)) {
            return ['direct', $flat];
        }
    }
    return ['remote', $flat];
}

/** Parse installed cert: [expires_ts, cn, sans] or null. */
function installedCert(): ?array {
    if (!is_file(HOST_CHAIN)) {
        return null;
    }
    $pem = @file_get_contents(HOST_CHAIN);
    $info = $pem !== false ? @openssl_x509_parse($pem) : false;
    if ($info === false) {
        return null;
    }
    $sans = [];
    if (!empty($info['extensions']['subjectAltName'])) {
        foreach (explode(',', $info['extensions']['subjectAltName']) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 'DNS:')) {
                $sans[] = strtolower(substr($part, 4));
            }
        }
    }
    return [
        'expires' => (int) ($info['validTo_time_t'] ?? 0),
        'cn' => strtolower((string) ($info['subject']['CN'] ?? '')),
        'sans' => $sans,
    ];
}

function certCovers(string $host, array $cert): bool {
    return $cert['cn'] === $host || in_array($host, $cert['sans'], true);
}

/** Normalize an openssl_x509_parse() result to [cn, sans]. Top-level so it is
 * always defined: conditional function definitions only bind when their
 * branch executes (a fresh-cert converge path would fatal otherwise). */
function installedCertArray(array $info): array {
    $sans = [];
    if (!empty($info['extensions']['subjectAltName'])) {
        foreach (explode(',', $info['extensions']['subjectAltName']) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 'DNS:')) {
                $sans[] = strtolower(substr($part, 4));
            }
        }
    }
    return ['cn' => strtolower((string) ($info['subject']['CN'] ?? '')), 'sans' => $sans];
}

// ------------------------------------------------------------------ main
$hostname = detectHostname($opts['hostname'] ?? null);
if ($hostname === '' || !validFqdn($hostname)) {
    fail("cannot determine a usable server FQDN (got '{$hostname}'). Pass --hostname=panel.example.com");
}
say("Hostname: {$hostname}");

[$dns, $resolved] = dnsState($hostname);
say('DNS: ' . $dns . ($resolved ? ' (' . implode(',', $resolved) . ')' : ''));
if ($dns === 'none' && !$FORCE) {
    fail("{$hostname} does not resolve — Let's Encrypt could never validate it. Fix DNS or pass --force to try anyway.");
}
if ($dns === 'remote') {
    say('NOTE: DNS does not point at this server (CDN/proxy?). HTTP-01 still works if port 80 reaches us.');
}

$cert = installedCert();
if ($cert !== null) {
    $days = (int) floor(($cert['expires'] - time()) / 86400);
    say("Installed cert: CN={$cert['cn']} SANs=[" . implode(',', $cert['sans']) . "] expires in {$days}d");
} else {
    say('Installed cert: none');
}
$fresh = $cert !== null && certCovers($hostname, $cert) && ($cert['expires'] - time()) > RENEW_DAYS * 86400;

if ($CHECK_ONLY) {
    say($fresh ? 'CHECK: fresh, nothing due.' : 'CHECK: issuance/renewal due.');
    exit($fresh ? 0 : 1);
}
$certChanged = false;
// Re-issue when the installed certificate does not match the configured ACME
// directory (a fresh install used to issue a STAGING cert because the example
// env defaults to staging, while turn-up later pins production — browsers then
// reject the panel).
$installedPem = @file_get_contents(HOST_CHAIN);
$installedInfo = $installedPem !== false ? @openssl_x509_parse((string) $installedPem) : false;
$installedIssuer = is_array($installedInfo) ? (string) ($installedInfo['issuer']['CN'] ?? '') : '';
$dirIsStaging = str_contains((string) config('ssl.acme_directory'), 'staging');
$certIsStaging = stripos($installedIssuer, 'staging') !== false || stripos($installedIssuer, 'fake') !== false;
if ($installedIssuer !== '' && $dirIsStaging !== $certIsStaging && !$RENEW_ONLY && !$CHECK_ONLY) {
    say('installed certificate issuer (' . $installedIssuer . ') does not match the ACME directory — forcing re-issue');
    $FORCE = true;
}

if ($fresh && !$FORCE) {
    say('Certificate is fresh — skipping issuance, converging service config.' . ($RENEW_ONLY ? '' : ' (use --force to re-issue)'));
    // Fall through to the apply phase: it re-asserts the vhost blocks, mail
    // certs, renewal cron and APP_URL from the installed files, so a rebuilt
    // vhost (or files installed without the apply phase) heals itself instead
    // of waiting for expiry.
} else {
if ($RENEW_ONLY && !$FORCE && $cert === null) {
    // Cron must never issue the very first cert on its own (operator intent).
    say('No certificate installed yet — run once without --renew-only to provision.');
    exit(0);
}

// ------------------------------------------------------------ ACME issuance
say('Requesting certificate from Let\'s Encrypt (HTTP-01 via panel web root)...');
// cwhpanel self-heal: a previous install can leave a catch-all / TLS vhost
// pointing at a certificate that no longer exists (uninstall keeps nginx
// configs). That makes `nginx -t` fail, so the HTTP-01 challenge below could
// never be served. Remove the stale references and bring nginx up first.
if (!is_file(HOST_CHAIN) || !is_file(HOST_KEY)) {
    $catch = '/etc/nginx/conf.d/00-cwhpanel-default.conf';
    if (is_file($catch)) { @unlink($catch); say('removed stale catch-all (no certificate)'); }
    $pc = @file_get_contents(PANEL_CONF);
    if (is_string($pc) && str_contains($pc, 'BEGIN cwhpanel-hostname-tls')) {
        $stripped = preg_replace('/\n# BEGIN cwhpanel-hostname-tls.*?# END cwhpanel-hostname-tls/s', '', $pc);
        $stripped = preg_replace('/\n# BEGIN cwhpanel-https-redirect.*?# END cwhpanel-https-redirect/s', '', (string) $stripped);
        if (is_string($stripped)) { file_put_contents(PANEL_CONF, $stripped, LOCK_EX); say('stripped stale TLS block from the panel vhost'); }
    }
    $probe = [];
    if (function_exists('sh') && sh('/usr/sbin/nginx -t', $probe) !== 0) {
        say('nginx config still invalid before issuance — fix /etc/nginx/conf.d first');
    } else {
        @shell_exec('/usr/bin/systemctl start nginx 2>/dev/null');
        @shell_exec('/usr/bin/systemctl reload nginx 2>/dev/null');
    }
}

$challengeDir = $PANEL_PUBLIC . '/.well-known/acme-challenge';
@mkdir($challengeDir, 0755, true);
$written = [];
try {
    $svc = app(\Modules\Ssl\Services\SslService::class);
    $client = new \Modules\Ssl\Services\AcmeClient(
        (string) config('ssl.acme_directory'),
        $svc->accountKey()
    );
    $artifacts = $client->issue($hostname, 'hostname', function (string $token, string $keyAuth) use ($challengeDir, &$written) {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $token)) {
            throw new \RuntimeException('bad ACME challenge token');
        }
        $path = $challengeDir . '/' . $token;
        if (@file_put_contents($path, $keyAuth, LOCK_EX) === false) {
            throw new \RuntimeException('failed to write ACME challenge file');
        }
        @chmod($path, 0644);
        $written[] = $path;
    });
} finally {
    foreach ($written as $f) {
        @unlink($f);
    }
}
say('Certificate issued.');

// Validate artifacts before touching any service config.
$key = (string) ($artifacts['key'] ?? '');
$pem = (string) ($artifacts['cert'] ?? '') . (string) ($artifacts['chain'] ?? '');
$info = @openssl_x509_parse($pem);
if ($info === false) {
    fail('issued certificate does not parse as X.509');
}
$got = installedCertArray($info);
if (!certCovers($hostname, $got)) {
    fail('issued certificate does not cover ' . $hostname);
}
$pk = @openssl_pkey_get_private($key);
$ck = $pk !== false ? @openssl_pkey_get_details($pk) : false;
$cp = @openssl_pkey_get_public($pem);
$cd = $cp !== false ? @openssl_pkey_get_details($cp) : false;
if ($ck === false || $cd === false || ($ck['key'] ?? null) !== ($cd['key'] ?? null)) {
    fail('issued key does not match the certificate');
}
$blocks = substr_count($pem, 'BEGIN CERTIFICATE');
say("Validated: covers {$hostname}, key matches, chain blocks={$blocks}, expires " . date('Y-m-d', (int) $info['validTo_time_t']));

// ------------------------------------------------------------ install files
@mkdir(HOST_CERT_DIR, 0700, true);
file_put_contents(HOST_KEY, $key, LOCK_EX);
file_put_contents(HOST_CHAIN, $pem, LOCK_EX);
chmod(HOST_KEY, 0600);
chmod(HOST_CHAIN, 0600);
// Deterministic SELinux label: a newly written file inherits whatever the
// parent carried, and the BASE policy maps any /etc path containing
// "hostname" to hostname_etc_t (which the agent cannot read — it runs
// `nginx -t`). Apply the panel's own fcontext rules here.
if (function_exists('exec')) {
    @exec('/usr/sbin/restorecon -RF ' . escapeshellarg(HOST_CERT_DIR) . ' 2>/dev/null');
}
$certChanged = true;
say('Installed ' . HOST_KEY . ' + ' . HOST_CHAIN . ' (0600 root).');
} // end issuance branch (fresh certs fall straight through to apply)

// ------------------------------------------------------------ nginx
$conf = @file_get_contents(PANEL_CONF);
if ($conf === false) {
    fail('cannot read ' . PANEL_CONF);
}
$nginxBackup = null;
if (!str_contains($conf, 'BEGIN cwhpanel-hostname-tls')) {
    $nginxBackup = PANEL_CONF . '.bak-' . date('Ymd-His');
    copy(PANEL_CONF, $nginxBackup);
    $tls = <<<NGINX

    # BEGIN cwhpanel-hostname-tls (managed by provision-hostname-tls.php)
    listen 443 ssl;
    listen [::]:443 ssl;
    ssl_certificate /etc/cwhpanel/ssl/hostname/fullchain.pem;
    ssl_certificate_key /etc/cwhpanel/ssl/hostname/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305;
    ssl_prefer_server_ciphers on;
    # END cwhpanel-hostname-tls
NGINX;
    // The heredoc ends without a trailing newline; without this the original
    // `server_name` line ends up appended to the END-marker comment (commented
    // out), leaving the vhost nameless and every request hitting the catch-all.
    $conf = preg_replace('/(listen \[::\]:80;\n)/', "$1{$tls}\n", $conf, 1);
    $redir = <<<NGINX
    # BEGIN cwhpanel-https-redirect (managed by provision-hostname-tls.php)
    # Everything over plain HTTP moves to HTTPS except the ACME challenge
    # path, which Let's Encrypt must reach on port 80 (also via CDN proxy).
    if (\$scheme = http) {
        set \$cwh_redir 1;
    }
    if (\$request_uri ~ ^/\.well-known/acme-challenge/) {
        set \$cwh_redir 0;
    }
    if (\$cwh_redir) {
        return 301 https://\$host\$request_uri;
    }
    # END cwhpanel-https-redirect

    # Block dotfiles
NGINX;
    $conf = str_replace('    # Block dotfiles', $redir, $conf);
    file_put_contents(PANEL_CONF, $conf, LOCK_EX);
    say('nginx panel vhost: 443 + http→https redirect added.');
} else {
    say('nginx panel vhost: TLS block already present.');
}

// Admin panel port (split panels). turn-up adds `listen <PANEL_ADMIN_PORT> ssl`
// only when a certificate already exists at install time; if issuance was
// deferred (LE rate limit, DNS not live yet) the operator re-runs THIS script,
// so it has to converge the listen lines too — otherwise the admin panel is
// unreachable forever after the cert arrives.
$conf = (string) @file_get_contents(PANEL_CONF);
$adminPort = (int) (env('PANEL_ADMIN_PORT') ?: 0);
if ($adminPort > 0 && !in_array($adminPort, [80, 443], true)
    && str_contains($conf, 'BEGIN cwhpanel-hostname-tls')
    && !str_contains($conf, "listen {$adminPort} ssl;")) {
    if ($nginxBackup === null) {
        $nginxBackup = PANEL_CONF . '.bak-' . date('Ymd-His');
        copy(PANEL_CONF, $nginxBackup);
    }
    // A pre-TLS install has plain `listen <port>;` lines; adding the ssl
    // variant without removing them makes nginx reject duplicate listens for
    // the same address:port.
    $newConf = preg_replace('/^[ \t]*listen ' . $adminPort . ';\n/m', '', $conf);
    $newConf = preg_replace('/^[ \t]*listen \[::\]:' . $adminPort . ';\n/m', '', (string) $newConf);
    $newConf = preg_replace(
        '/(listen \[::\]:443 ssl;\n)/',
        "$1    listen {$adminPort} ssl;\n    listen [::]:{$adminPort} ssl;\n",
        (string) $newConf,
        1
    );
    if (is_string($newConf) && $newConf !== $conf) {
        file_put_contents(PANEL_CONF, $newConf, LOCK_EX);
        $conf = $newConf;
        say("nginx panel vhost: admin port {$adminPort} added.");
    }
}

$out = [];
if (sh('/usr/sbin/nginx -t', $out) !== 0) {
    if ($nginxBackup !== null && is_file($nginxBackup)) {
        copy($nginxBackup, PANEL_CONF); // restore pre-edit vhost
    }
    fail('nginx -t failed: ' . implode(' | ', array_slice($out, -3)));
}

// ------------------------------------------------------------ postfix
$out = [];
sh("postconf -e 'smtpd_tls_cert_file=" . HOST_CHAIN . "' 'smtpd_tls_key_file=" . HOST_KEY . "'", $out);
say('Postfix submission/smtps now serve the hostname certificate.');

// ------------------------------------------------------------ dovecot
$doveConf = <<<'DOVE'
# Managed by cwhpanel provision-hostname-tls.php — hostname certificate
# for IMAP/POP3. Processed last (99-) so it wins over stock snakeoil paths.
ssl_cert = </etc/cwhpanel/ssl/hostname/fullchain.pem
ssl_key = </etc/cwhpanel/ssl/hostname/privkey.pem
DOVE;
file_put_contents('/etc/dovecot/conf.d/99-cwhpanel-hostname.conf', $doveConf, LOCK_EX);
say('Dovecot now serves the hostname certificate.');

// HSTS: add `preload` on existing vhosts too (idempotent). Preload requires
// max-age >= 1 year + includeSubDomains, both of which we already send.
$panelConf = PANEL_CONF;
if (is_file($panelConf)) {
    $pc = (string) file_get_contents($panelConf);
    $new = preg_replace(
        '/https\s+"max-age=31536000; includeSubDomains";/',
        'https   "max-age=31536000; includeSubDomains; preload";',
        $pc,
        1
    );
    if (is_string($new) && $new !== $pc) {
        file_put_contents($panelConf, $new, LOCK_EX);
        say('panel HSTS upgraded to include preload.');
    }
}

// ------------------------------------------------------------ catch-all
// Unknown SNI/Host must not fall back to the panel vhost. Only installed once
// the certificate exists (a missing cert would fail `nginx -t`).
$catchAll = '/etc/nginx/conf.d/00-cwhpanel-default.conf';
$tpl = dirname(__DIR__) . '/deploy/00-cwhpanel-default.conf';
if (is_file($tpl) && is_file('/etc/cwhpanel/ssl/hostname/fullchain.pem')) {
    $body = str_replace(
        ['__CERT__', '__KEY__'],
        ['/etc/cwhpanel/ssl/hostname/fullchain.pem', '/etc/cwhpanel/ssl/hostname/privkey.pem'],
        (string) file_get_contents($tpl)
    );
    $tmp = $catchAll . '.tmp';
    file_put_contents($tmp, $body, LOCK_EX);
    rename($tmp, $catchAll);
    // Gate immediately: a bad catch-all must never reach a reload.
    if (sh('/usr/sbin/nginx -t', $out) === 0) {
        say('nginx catch-all installed (unknown hosts close with 444).');
    } else {
        @unlink($catchAll);
        say('nginx catch-all REJECTED by nginx -t; removed (panel keeps default_server).');
    }
}

// ------------------------------------------------------------ vsftpd (FTPS, opportunistic)
// Plaintext FTP keeps working (existing clients unaffected); clients that
// opt into explicit TLS get the hostname cert. Only configured once — the
// cert paths never change, so renewals need no vsftpd touch.
$vsftpdFirst = false;
$vsftpdConf = '/etc/vsftpd/vsftpd.conf';
$vsftpd = is_file($vsftpdConf) ? (string) file_get_contents($vsftpdConf) : '';
if ($vsftpd !== '' && !str_contains($vsftpd, 'cwhpanel-hostname-tls')) {
    $vsftpd = rtrim($vsftpd) . "\n\n"
        . "# BEGIN cwhpanel-hostname-tls (managed by provision-hostname-tls.php)\n"
        . "ssl_enable=YES\n"
        . "allow_anon_ssl=NO\n"
        // Forced, not opportunistic: with force_local_*_ssl=NO a client (or
        // anyone on the path) could downgrade and keep sending credentials in
        // the clear. The agent's ftp.setup template carries the same block so
        // an FTP-account change cannot revert this.
        . "force_local_logins_ssl=YES\n"
        . "force_local_data_ssl=YES\n"
        . "require_ssl_reuse=NO\n"
        . "ssl_tlsv1=NO\n"
        . "ssl_sslv2=NO\n"
        . "ssl_sslv3=NO\n"
        . "ssl_tlsv1_2=YES\n"
        . "ssl_tlsv1_3=YES\n"
        . "rsa_cert_file=/etc/cwhpanel/ssl/hostname/fullchain.pem\n"
        . "rsa_private_key_file=/etc/cwhpanel/ssl/hostname/privkey.pem\n"
        . "# END cwhpanel-hostname-tls\n";
    file_put_contents($vsftpdConf, $vsftpd, LOCK_EX);
    $vsftpdFirst = true;
    say('vsftpd: FTPS enabled (TLS required) with hostname cert.');
} else {
    say('vsftpd: TLS block already present.');
}

// ------------------------------------------------------------ APP_URL
if (!isset($opts['keep-app-url']) && is_file($PANEL_ENV)) {
    $env = file_get_contents($PANEL_ENV);
    $want = "APP_URL=https://{$hostname}/";
    if ($env !== false && !str_contains($env, $want)) {
        copy($PANEL_ENV, $PANEL_ENV . '.bak-' . date('Ymd-His'));
        if (preg_match('/^APP_URL=.*$/m', $env)) {
            $env = preg_replace('/^APP_URL=.*$/m', $want, $env);
        } else {
            $env = rtrim($env) . "\n" . $want . "\n";
        }

        // A dev .env often pins SESSION_SECURE_COOKIE=false. Once the panel is
        // served over https that ships a non-Secure session cookie; 'auto'
        // tracks APP_URL (Secure whenever it is https).
        if (preg_match('/^SESSION_SECURE_COOKIE=/mi', $env)) {
            if (preg_match('/^SESSION_SECURE_COOKIE=(false|0|off|)\s*$/mi', $env)) {
                $env = preg_replace('/^SESSION_SECURE_COOKIE=.*$/mi', 'SESSION_SECURE_COOKIE=auto', $env);
            }
        } else {
            $env = rtrim($env) . "\nSESSION_SECURE_COOKIE=auto\n";
        }

        file_put_contents($PANEL_ENV, $env, LOCK_EX);
        say("APP_URL → https://{$hostname}/ (backup kept).");
    } else {
        say('APP_URL already canonical.');
    }
}

// ------------------------------------------------------------ renewal cron
$cron = "/etc/cron.d/cwhpanel-hostname-tls";
$cronBody = "# cwhpanel — weekly server-hostname TLS renewal check.\n"
    . "# Renews only when <30 days remain; silent success otherwise.\n"
    . "SHELL=/bin/bash\n"
    . "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n\n"
    . "17 3 * * 0 root /usr/bin/php {$PANEL_BASE}/scripts/provision-hostname-tls.php --renew-only >> /var/log/cwhpanel-hostname-tls.log 2>&1\n";
if (@file_get_contents($cron) !== $cronBody) {
    file_put_contents($cron, $cronBody, LOCK_EX);
    say('Renewal cron installed (Sundays 03:17).');
}

// ------------------------------------------------------------ reloads
if (!$NO_RELOAD) {
    $out = [];
    $code = sh('/usr/sbin/nginx -t', $out);
    if ($code !== 0) {
        fail('nginx -t failed after edit: ' . implode(' | ', array_slice($out, -3)));
    }
    sh('systemctl reload nginx');
    sh('systemctl reload postfix');
    sh('systemctl reload dovecot');
    // vsftpd reads its cert at startup: restart whenever the files changed
    // or TLS was just enabled (off-peak cron timing; active transfers drop).
    $vsftpdTls = is_file($vsftpdConf) && str_contains((string) @file_get_contents($vsftpdConf), 'cwhpanel-hostname-tls');
    $vsftpdRestart = (!empty($certChanged) || !empty($vsftpdFirst)) && $vsftpdTls;
    if ($vsftpdRestart) {
        sh('systemctl restart vsftpd');
    }
    say('Services reloaded (nginx, postfix, dovecot' . ($vsftpdRestart ? ', vsftpd restarted' : '') . ').');
} else {
    say('Skipped service reloads (--no-reload). Run nginx -t + reload manually.');
}

say("DONE: https://{$hostname}/ serves the panel; mail (587/465/143/993) presents the same cert.");

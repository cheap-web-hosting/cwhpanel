<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 46 forge automation (Gitea + Forgejo unattended admin).
 * Seed an explicit custom/conf/app.ini (SQLite, INSTALL_LOCK) so first
 * boot migrates instead of serving the browser installer, then create
 * the wizard admin through the stock CLI from a JSON-argv driver
 * (setup.cli runs post-start for app installs, after migration).
 */
class UpdateBatch46Forge extends Migration {
    public function up(): void
    {        $this->merge('gitea', [
            'app' => [
                'binary' => 'gitea',
                'args' => ['web', '--port', '{PORT}'],
                'seed_files' => [
                    'custom/conf/app.ini' => <<<'INICFG'
APP_NAME = Gitea: Git with a cup of tea
RUN_MODE = prod
[server]
PROTOCOL = http
DOMAIN = {domain}
HTTP_ADDR = 127.0.0.1
HTTP_PORT = {PORT}
ROOT_URL = {url}/
DISABLE_SSH = true
[database]
DB_TYPE = sqlite3
PATH = data/gitea.db
[security]
INSTALL_LOCK = true
[service]
DISABLE_REGISTRATION = true

INICFG
                    ,
                    'panel-setup.php' => <<<'PANELSETUP'
<?php
// Gitea unattended admin: the unit already booted + migrated the
// database (setup.cli runs post-start for app installs), so the stock
// `admin user create` CLI mints the wizard admin directly. Retries
// boundedly (migration can lag the settle on slow disks); an existing
// admin is success (idempotent reinstalls). On failure the install
// lock is dropped so the browser installer (not a locked-out login)
// greets the owner.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$in = json_decode($argv[1] ?? 'null', true);
if (!is_array($in)) {
    fwrite(STDERR, "gitea setup: bad arguments\n");
    exit(1);
}
foreach (['user', 'pass', 'email'] as $k) {
    if (!isset($in[$k]) || !is_string($in[$k]) || $in[$k] === '' || strlen($in[$k]) > 254) {
        fwrite(STDERR, "gitea setup: missing {$k}\n");
        exit(1);
    }
    if (str_contains($in[$k], "\n") || str_contains($in[$k], "\0")) {
        fwrite(STDERR, "gitea setup: bad {$k}\n");
        exit(1);
    }
}
chdir(__DIR__);
$base = ['./gitea', 'admin', 'user', 'create', '--admin', '--username', $in['user'], '--password', $in['pass'], '--email', $in['email']];
$last = '';
for ($try = 0; $try < 6; $try++) {
    $des = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
    $p = @proc_open($base, $des, $pipes);
    if (is_resource($p)) {
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);
        $text = (string) $out . "\n" . (string) $err;
        if ($code === 0) {
            echo "gitea setup complete\n";
            exit(0);
        }
        if (stripos($text, 'already exists') !== false) {
            echo "gitea admin already exists\n";
            exit(0);
        }
        $last = trim($text) !== '' ? trim($text) : ('exit ' . $code);
    }
    sleep(10);
}
$ini = __DIR__ . '/custom/conf/app.ini';
$c = @file_get_contents($ini);
if ($c !== false) {
    @file_put_contents($ini, str_replace('INSTALL_LOCK = true', 'INSTALL_LOCK = false', $c));
}
fwrite(STDERR, 'gitea setup failed: ' . mb_substr($last, -300) . "\n");
exit(1);
PANELSETUP
                    ,
                ],
            ],
            'setup' => [
                'cli' => [[
                    'script' => 'panel-setup.php',
                    'args' => ['{"user":"{user}","pass":"{pass}","email":"{email}"}'],
                ]],
                'cleanup' => ['panel-setup.php'],
            ],
            'admin' => ['path' => ''],
            'post_install' => 'Installed automatically with the admin account you entered — sign in on the site.',
        ]);

        $this->merge('forgejo', [
            'app' => [
                'binary' => 'forgejo',
                'args' => ['web', '--port', '{PORT}'],
                'seed_files' => [
                    'custom/conf/app.ini' => <<<'INICFG'
APP_NAME = Forgejo: Beyond coding. We forge.
RUN_MODE = prod
[server]
PROTOCOL = http
DOMAIN = {domain}
HTTP_ADDR = 127.0.0.1
HTTP_PORT = {PORT}
ROOT_URL = {url}/
DISABLE_SSH = true
[database]
DB_TYPE = sqlite3
PATH = data/forgejo.db
[security]
INSTALL_LOCK = true
[service]
DISABLE_REGISTRATION = true

INICFG
                    ,
                    'panel-setup.php' => <<<'PANELSETUP'
<?php
// Forgejo unattended admin: same shape as the Gitea driver (soft fork,
// identical CLI). The unit already booted + migrated the database.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$in = json_decode($argv[1] ?? 'null', true);
if (!is_array($in)) {
    fwrite(STDERR, "forgejo setup: bad arguments\n");
    exit(1);
}
foreach (['user', 'pass', 'email'] as $k) {
    if (!isset($in[$k]) || !is_string($in[$k]) || $in[$k] === '' || strlen($in[$k]) > 254) {
        fwrite(STDERR, "forgejo setup: missing {$k}\n");
        exit(1);
    }
    if (str_contains($in[$k], "\n") || str_contains($in[$k], "\0")) {
        fwrite(STDERR, "forgejo setup: bad {$k}\n");
        exit(1);
    }
}
chdir(__DIR__);
$base = ['./forgejo', 'admin', 'user', 'create', '--admin', '--username', $in['user'], '--password', $in['pass'], '--email', $in['email']];
$last = '';
for ($try = 0; $try < 6; $try++) {
    $des = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
    $p = @proc_open($base, $des, $pipes);
    if (is_resource($p)) {
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);
        $text = (string) $out . "\n" . (string) $err;
        if ($code === 0) {
            echo "forgejo setup complete\n";
            exit(0);
        }
        if (stripos($text, 'already exists') !== false) {
            echo "forgejo admin already exists\n";
            exit(0);
        }
        $last = trim($text) !== '' ? trim($text) : ('exit ' . $code);
    }
    sleep(10);
}
$ini = __DIR__ . '/custom/conf/app.ini';
$c = @file_get_contents($ini);
if ($c !== false) {
    @file_put_contents($ini, str_replace('INSTALL_LOCK = true', 'INSTALL_LOCK = false', $c));
}
fwrite(STDERR, 'forgejo setup failed: ' . mb_substr($last, -300) . "\n");
exit(1);
PANELSETUP
                    ,
                ],
            ],
            'setup' => [
                'cli' => [[
                    'script' => 'panel-setup.php',
                    'args' => ['{"user":"{user}","pass":"{pass}","email":"{email}"}'],
                ]],
                'cleanup' => ['panel-setup.php'],
            ],
            'admin' => ['path' => ''],
            'post_install' => 'Installed automatically with the admin account you entered — sign in on the site.',
        ]);
    }

    /**
     * @param array<string,mixed> $keys
     */
    private function merge(string $slug, array $keys): void
    {
        try {
            $row = $this->db->selectOne('SELECT * FROM `ai_applications` WHERE `slug` = ?', [$slug]);
        } catch (\Throwable $e) {
            return;
        }
        if ($row === null) {
            return;
        }
        $manifest = json_decode((string) ($row['manifest'] ?? '{}'), true);
        if (!is_array($manifest)) {
            return;
        }
        foreach ($keys as $k => $v) {
            $manifest[$k] = $v;
        }
        try {
            $this->db->update('ai_applications', [
                'manifest' => json_encode($manifest),
                'updated_at' => date('Y-m-d H:i:s'),
            ], '`slug` = ?', [$slug]);
        } catch (\Throwable $e) {
        }
    }
}
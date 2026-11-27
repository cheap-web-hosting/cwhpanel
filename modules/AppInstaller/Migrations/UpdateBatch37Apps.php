<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 37 unattended setup (Chamilo solo).
 * No CLI installer ships: the driver replays the 7-step wizard headless
 * over the live vhost (front-door :80 with Host header). Steps are
 * POST-flag driven with ?running=1 importing POST into scope (without it
 * every step silently falls back to defaults, e.g. DB user root); the Vue
 * app reposts accumulated stepData every step, so the driver re-sends DB
 * + admin fields on the heavy step 6. State is session cookies
 * (in-memory); secrets ride POST bodies. Serves public/ via webroot.
 * Heavy step needs room (php_settings time + memory ride the auto-pin).
 * Proven live on a hostddl.net subdir: install, front 200, admin login,
 * full uninstall.
 */
class UpdateBatch37Apps extends Migration {
    public function up(): void
    {
        $this->merge('chamilo', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '8.3', 'extensions' => ['pdo_mysql', 'mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'fileinfo', 'intl', 'ldap', 'soap', 'sodium', 'bcmath', 'exif']],
            'database' => ['required' => true, 'prefix' => ''],
            'config' => [],
            'webroot' => 'public',
            'root_only' => true,
            'php_settings' => ['max_execution_time' => '300', 'memory_limit' => '512M'],
            'setup' => [
                'files' => ['panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 10, '');
$title = trim($args[1]);
$email = trim($args[2]);
$user = trim($args[3]);
$pass = $args[4] ?? '';
$dbhost = trim($args[5]);
$dbname = trim($args[6]);
$dbuser = trim($args[7]);
$dbpass = $args[8] ?? '';
$url = rtrim(trim($args[9]), '/');
if ($title === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $user) || strlen($pass) < 8
    || $dbhost === '' || $dbname === '' || $dbuser === '' || $dbpass === ''
    || !preg_match('#^https?://#', $url)
) {
    fwrite(STDERR, "usage: panel-setup.php <title> <email> <user> <pass> <dbhost> <dbname> <dbuser> <dbpass> <url>\n");
    exit(1);
}
$parts = parse_url($url);
$domain = $parts['host'] ?? '';
$path = rtrim($parts['path'] ?? '', '/');
if ($domain === '') {
    fwrite(STDERR, "bad url\n");
    exit(1);
}
// The installer lives under public/ in the tree; at driver time the
// domain docroot still serves the raw tree (the docroot reroute lands
// post-install), so the physical path is deterministic.
$endpoint = 'http://127.0.0.1' . $path . '/public/main/install/index.php';
$cookies = [];
$request = function ($fields) use ($endpoint, $domain, &$cookies) {
    // Restart windows (pin regen + master rewrites restart the worker and
    // FPM around setup time) can answer any single request with a
    // transport error or a stray 404 — retry those briefly, but never mask
    // a genuinely missing installer (final failure stays loud, with a body
    // tail for diagnosis).
    $tries = 0;
    while (true) {
        $tries++;
        $target = $endpoint . '?running=1';
        $ch = curl_init($target);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Host: ' . $domain]);
        if ($cookies !== []) {
            $pairs = [];
            foreach ($cookies as $k => $v) {
                $pairs[] = $k . '=' . $v;
            }
            curl_setopt($ch, CURLOPT_COOKIE, implode('; ', $pairs));
        }
        if ($fields !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            if ($tries < 8) {
                sleep(5);
                continue;
            }
            return [0, '', 'curl error: ' . $err];
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if (($code === 404 || $code === 502 || $code === 503 || $code === 504) && $tries < 8) {
            sleep(5);
            continue;
        }
        foreach (explode("\n", substr($raw, 0, $hsize)) as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                $pair = trim(substr($line, 11));
                $semi = strpos($pair, ';');
                if ($semi !== false) {
                    $pair = substr($pair, 0, $semi);
                }
                $eq = strpos($pair, '=');
                if ($eq !== false) {
                    $cookies[trim(substr($pair, 0, $eq))] = trim(substr($pair, $eq + 1));
                }
            }
        }
        return [$code, substr($raw, $hsize), ''];
    }
};
$fail = function ($msg) {
    fwrite(STDERR, $msg . "\n");
    exit(1);
};
$stepOf = function ($body) {
    if (preg_match('/var installerData = (\{.*?\});/s', $body, $m)) {
        $d = json_decode($m[1], true);
        if (is_array($d) && isset($d['currentStep'])) {
            return (int) $d['currentStep'];
        }
    }
    return 0;
};
$dbFields = ['dbHostForm' => $dbhost, 'dbPortForm' => '3306', 'dbUsernameForm' => $dbuser, 'dbPassForm' => $dbpass, 'dbNameForm' => $dbname];
$adminFields = ['adminFirstName' => 'Site', 'adminLastName' => 'Admin', 'loginForm' => $user, 'passForm' => $pass, 'emailForm' => $email, 'encryptPassForm' => 'bcrypt', 'institutionForm' => $title, 'institutionUrlForm' => $url . '/', 'campusForm' => 'Main campus', 'languageForm' => 'english'];
$r = $request(['language_list' => 'english', 'step1' => '1']);
if ($r[0] !== 200 || $stepOf($r[1]) !== 2) {
    $fail('step 1 did not advance (http ' . $r[0] . ' step ' . $stepOf($r[1]) . ')');
}
$r = $request(['step1' => '1', 'step2_install' => '1']);
if ($r[0] !== 200 || $stepOf($r[1]) !== 3) {
    $fail('step 2 did not advance (http ' . $r[0] . ' step ' . $stepOf($r[1]) . ')');
}
$r = $request(['step3' => '1']);
if ($r[0] !== 200 || $stepOf($r[1]) !== 4) {
    $fail('step 3 did not advance (http ' . $r[0] . ' step ' . $stepOf($r[1]) . ')');
}
$r = $request(['step4' => '1'] + $dbFields);
if ($r[0] !== 200 || $stepOf($r[1]) !== 5) {
    $fail('step 4 did not advance (http ' . $r[0] . ' step ' . $stepOf($r[1]) . ')');
}
$r = $request(['step5' => '1'] + $adminFields);
if ($r[0] !== 200 || $stepOf($r[1]) !== 6) {
    $fail('step 5 did not advance (http ' . $r[0] . ' step ' . $stepOf($r[1]) . ')');
}
$r = $request(['step6' => '1'] + $dbFields + $adminFields);
// The heavy step occasionally dies with an empty 500 on a cold tree
// (full-container build + schema + fixtures); it drops + recreates, so a
// bounded retry is safe. Log the body tail when it finally fails.
$step6tries = 0;
while (($r[0] !== 200 || $stepOf($r[1]) !== 7) && $step6tries < 3) {
    $step6tries++;
    sleep(10);
    $r = $request(['step6' => '1'] + $dbFields + $adminFields);
}
if ($r[0] !== 200 || $stepOf($r[1]) !== 7) {
    $fail('step 6 did not finish (http ' . $r[0] . ' step ' . $stepOf($r[1]) . '): ' . substr(strip_tags($r[1]), -500));
}
// Verify: .env written + admin row present.
if (!is_file(__DIR__ . '/.env')) {
    $fail('.env missing after install');
}
try {
    $c = new mysqli($dbhost, $dbuser, $dbpass, $dbname);
    if ($c->connect_error) {
        $fail('db connect failed after install: ' . $c->connect_error);
    }
    $q = $c->query("SELECT `username` FROM `user` WHERE `username` = '" . $c->real_escape_string($user) . "'");
    $ok = ($q && $q->num_rows > 0);
    $c->close();
    if (!$ok) {
        $fail('admin account missing after install');
    }
} catch (Throwable $e) {
    $fail('admin verify failed');
}
echo "chamilo setup complete\n";
PANELSETUP,
                ],
                'cli' => [
                    ['script' => 'panel-setup.php', 'args' => ['{title}', '{email}', '{user}', '{pass}', '{db_host}', '{db_name}', '{db_user}', '{db_pass}', '{url}']],
                ],
                'cleanup' => ['panel-setup.php', 'public/main/install'],
            ],
            'admin' => ['path' => 'admin'],
            'post_install' => 'Installed automatically with the admin account you entered — log in at /admin/.',
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
            $manifest = [];
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

<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 35 unattended setup (MyBB solo).
 * No CLI installer ships: the driver replays the action-driven stock
 * wizard headless over the live vhost (front-door :80 with Host header).
 * Actions are stateless across requests (config file + DB carry
 * everything); secrets ride POST bodies (never URLs). Board prefs are
 * harvested from the configuration form so request-derived values
 * round-trip exactly. The two-top-dir zip needs strip_dir Upload.
 * Proven live on a hostddl.net subdir: install, front 200, member + ACP
 * login, full uninstall.
 */
class UpdateBatch35Apps extends Migration {
    public function up(): void
    {
        $this->merge('mybb', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '8.0', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl']],
            'database' => ['required' => true, 'prefix' => 'mybb_'],
            'download' => ['url' => 'https://resources.mybb.com/downloads/mybb_1838.zip', 'sha256' => '', 'format' => 'zip', 'strip_root' => true, 'strip_dir' => 'Upload'],
            'config' => [],
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
$endpoint = 'http://127.0.0.1' . $path . '/install/index.php';
$cookies = [];
$request = function ($method, $action, $fields = null) use ($endpoint, $domain, &$cookies) {
    $target = $endpoint . '?action=' . $action;
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
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return [0, '', 'curl error: ' . $err];
    }
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
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
};
$fail = function ($msg) {
    fwrite(STDERR, $msg . "\n");
    exit(1);
};
$titleOf = function ($body) {
    if (preg_match('/<title>MyBB Installation Wizard &gt; ([^<]*)/', $body, $m)) {
        return trim(html_entity_decode($m[1]));
    }
    return '';
};
// Walk the display-only actions (intro/license/requirements render).
foreach (['intro', 'license', 'requirements_check'] as $a) {
    $r = $request('GET', $a);
    if ($r[0] !== 200) {
        $fail($a . ' unreachable (http ' . $r[0] . ')');
    }
}
// Database credentials -> tables are created inline.
$r = $request('POST', 'create_tables', [
    'action' => 'create_tables',
    'dbengine' => 'mysqli',
    'config[mysqli][dbhost]' => $dbhost,
    'config[mysqli][dbuser]' => $dbuser,
    'config[mysqli][dbpass]' => $dbpass,
    'config[mysqli][dbname]' => $dbname,
    'config[mysqli][tableprefix]' => 'mybb_',
    'config[mysqli][encoding]' => 'utf8mb4',
]);
if ($r[0] !== 200 || stripos($titleOf($r[1]), 'Table Creation') === false) {
    $fail('table creation failed (http ' . $r[0] . ' ' . $titleOf($r[1]) . ')');
}
// Data, theme: inline, one action each.
foreach (['populate_tables' => 'Table Population', 'templates' => 'Theme Insertion'] as $a => $expect) {
    $r = $request('POST', $a, ['action' => $a]);
    if ($r[0] !== 200 || stripos($titleOf($r[1]), $expect) === false) {
        $fail($a . ' failed (http ' . $r[0] . ' ' . $titleOf($r[1]) . ')');
    }
}
// Board prefs: harvest the request-derived prefill, override our fields.
$r = $request('GET', 'configuration');
if ($r[0] !== 200) {
    $fail('configuration unreachable (http ' . $r[0] . ')');
}
$prefill = [];
foreach (['bbname', 'bburl', 'websitename', 'websiteurl', 'cookiedomain', 'cookiepath', 'contactemail', 'pin'] as $f) {
    if (preg_match('/name="' . $f . '"[^>]*value="([^"]*)"/', $r[1], $m)) {
        $prefill[$f] = html_entity_decode($m[1]);
    }
}
$prefill['action'] = 'adminuser';
$prefill['bbname'] = $title;
$prefill['contactemail'] = $email;
$prefill['pin'] = '';
$r = $request('POST', 'adminuser', $prefill);
if ($r[0] !== 200 || stripos($titleOf($r[1]), 'Create Administrator Account') === false) {
    $fail('board configuration failed (http ' . $r[0] . ' ' . $titleOf($r[1]) . ')');
}
// Admin account -> finish page.
$r = $request('POST', 'final', [
    'action' => 'final',
    'adminuser' => $user,
    'adminpass' => $pass,
    'adminpass2' => $pass,
    'adminemail' => $email,
]);
if ($r[0] !== 200 || stripos($r[1], 'successfully been installed') === false) {
    $fail('install did not finish (' . $titleOf($r[1]) . ')');
}
// Verify: config written + admin row present.
if (!is_file(__DIR__ . '/inc/config.php')) {
    $fail('inc/config.php missing after install');
}
try {
    $c = new mysqli($dbhost, $dbuser, $dbpass, $dbname);
    if ($c->connect_error) {
        $fail('db connect failed after install: ' . $c->connect_error);
    }
    $q = $c->query("SELECT `username` FROM `mybb_users` WHERE `username` = '" . $c->real_escape_string($user) . "'");
    $ok = ($q && $q->num_rows > 0);
    $c->close();
    if (!$ok) {
        $fail('admin account missing after install');
    }
} catch (Throwable $e) {
    $fail('admin verify failed');
}
echo "mybb setup complete\n";
PANELSETUP,
                ],
                'cli' => [
                    ['script' => 'panel-setup.php', 'args' => ['{title}', '{email}', '{user}', '{pass}', '{db_host}', '{db_name}', '{db_user}', '{db_pass}', '{url}']],
                ],
                'cleanup' => ['panel-setup.php', 'install'],
            ],
            'admin' => ['path' => 'admin/'],
            'post_install' => 'Installed automatically with the admin account you entered — sign in on the front page; the ACP is at /admin/.',
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

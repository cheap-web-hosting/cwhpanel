<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 32 unattended setup (CMS Made Simple solo).
 * The asset is a versioned PHAR installer assistant (release EMBEDDED as
 * data.tar.gz — no download drift). No CLI ships, so the driver replays
 * the 9-step wizard headless over the live vhost (front-door :80 with Host
 * header). Steps advance by GET/POST with a per-install randomized step
 * var harvested from each redirect Location; state rides the session
 * cookie (in-memory harvest-and-resend); secrets ride POST bodies. The
 * PHAR filename embeds the version, so the driver globs it. Proven live
 * on a hostddl.net subdir: install, front 200, browser admin login, full
 * uninstall.
 */
class UpdateBatch32Apps extends Migration {
    public function up(): void
    {
        $this->merge('cmsms', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '8.1', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'tokenizer']],
            'database' => ['required' => true, 'prefix' => 'cms_'],
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
$phars = glob(__DIR__ . '/cmsms-*-install.php');
if (count($phars) !== 1) {
    fwrite(STDERR, 'installer phar not found (or ambiguous)' . "\n");
    exit(1);
}
$front = 'http://127.0.0.1' . $path . '/' . basename($phars[0]) . '/index.php';
$cookies = [];
$harvestCookies = function ($headers) use (&$cookies) {
    foreach (explode("\n", $headers) as $line) {
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
};
$cookieHeader = function () use (&$cookies) {
    $pairs = [];
    foreach ($cookies as $k => $v) {
        $pairs[] = $k . '=' . $v;
    }
    return implode('; ', $pairs);
};
$request = function ($method, $url, $fields = null) use ($domain, &$cookies, $harvestCookies, $cookieHeader) {
    // Absolute Location headers point at the public host; replay them
    // against loopback with the Host header (front-door :443 serves the
    // panel vhost for customer domains).
    $p = parse_url($url);
    $target = 'http://127.0.0.1' . ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    $ch = curl_init($target);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Host: ' . $domain]);
    $jar = $cookieHeader();
    if ($jar !== '') {
        curl_setopt($ch, CURLOPT_COOKIE, $jar);
    }
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return [0, '', '', 'curl error: ' . $err];
    }
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = substr($raw, 0, $hsize);
    $harvestCookies($headers);
    $loc = '';
    foreach (explode("\n", $headers) as $line) {
        if (stripos($line, 'Location:') === 0) {
            $loc = trim(substr($line, 9));
        }
    }
    return [$code, substr($raw, $hsize), $loc, ''];
};
$fail = function ($msg) {
    fwrite(STDERR, $msg . "\n");
    exit(1);
};
$has = function ($body, $needle) {
    return stripos($body, $needle) !== false;
};
// Step 1: language select.
$r = $request('GET', $front);
if ($r[0] !== 200 || stripos($r[1], 'name="lang"') === false && stripos($r[1], "name='lang'") === false) {
    $fail('installer did not offer language select (http ' . $r[0] . ')');
}
$r = $request('POST', $front, ['lang' => 'en_US', 'verbose' => '0', 'next' => 'Next']);
if ($r[0] !== 302 || $r[2] === '') {
    $fail('step 1 did not advance (http ' . $r[0] . ')');
}
// Step 2: detect existing -> fresh install.
$page = $r[2];
$r = $request('GET', $page);
if ($r[0] !== 200 || !($has($r[1], 'name="install"') || $has($r[1], "name='install'"))) {
    $fail('step 2 missing (http ' . $r[0] . ')');
}
$r = $request('POST', $page, ['install' => 'Install']);
if ($r[0] !== 302 || $r[2] === '') {
    $fail('step 2 did not advance (http ' . $r[0] . ')');
}
// Step 3: compatibility tests -> follow the Next link (absent on hard fail).
$page = $r[2];
$r = $request('GET', $page);
if ($r[0] !== 200) {
    $fail('step 3 failed (http ' . $r[0] . ')');
}
$next = null;
if (preg_match('/href=["\']([^"\']*[?&][A-Za-z0-9_]+=\d+[^"\']*)["\']\s*[^>]*>\s*Next/s', $r[1], $m)) {
    $next = html_entity_decode($m[1]);
}
if ($next === null) {
    $fail('step 3 has no Next link (a required test failed)');
}
// Step 4: database credentials.
$page = $next;
$r = $request('GET', $page);
if ($r[0] !== 200 || !($has($r[1], 'name="dbname"') || $has($r[1], "name='dbname'"))) {
    $fail('step 4 missing (http ' . $r[0] . ')');
}
$r = $request('POST', $page, ['dbhost' => $dbhost, 'dbname' => $dbname, 'dbuser' => $dbuser, 'dbpass' => $dbpass, 'timezone' => 'UTC', 'next' => 'Next']);
if ($r[0] !== 302 || $r[2] === '') {
    $fail('step 4 did not advance (http ' . $r[0] . ')');
}
// Step 5: admin account.
$page = $r[2];
$r = $request('GET', $page);
if ($r[0] !== 200 || !($has($r[1], 'name="username"') || $has($r[1], "name='username'"))) {
    $fail('step 5 missing (http ' . $r[0] . ')');
}
$r = $request('POST', $page, ['username' => $user, 'emailaddr' => $email, 'password' => $pass, 'repeatpw' => $pass, 'next' => 'Next']);
if ($r[0] !== 302 || $r[2] === '') {
    $fail('step 5 did not advance (http ' . $r[0] . ' — check username/password rules)');
}
// Step 6: site settings.
$page = $r[2];
$r = $request('GET', $page);
if ($r[0] !== 200 || !($has($r[1], 'name="sitename"') || $has($r[1], "name='sitename'"))) {
    $fail('step 6 missing (http ' . $r[0] . ')');
}
$r = $request('POST', $page, ['sitename' => $title, 'languages' => ['en_US'], 'next' => 'Next']);
if ($r[0] !== 302 || $r[2] === '') {
    $fail('step 6 did not advance (http ' . $r[0] . ')');
}
// Step 7: extracts the embedded release (synchronous, slow).
$page = $r[2];
$r = $request('GET', $page);
if ($r[0] !== 200) {
    $fail('step 7 failed (http ' . $r[0] . ')');
}
$next = null;
if (preg_match('/href=["\']([^"\']*[?&][A-Za-z0-9_]+=\d+[^"\']*)["\']/', $r[1], $m)) {
    $next = html_entity_decode($m[1]);
}
if ($next === null) {
    $fail('step 7 has no Next link (extraction failed)');
}
// Step 8: database work (synchronous, slow).
$page = $next;
$r = $request('GET', $page);
if ($r[0] !== 200) {
    $fail('step 8 failed (http ' . $r[0] . ')');
}
$next = null;
if (preg_match('/href=["\']([^"\']*[?&][A-Za-z0-9_]+=\d+[^"\']*)["\']/', $r[1], $m)) {
    $next = html_entity_decode($m[1]);
}
if ($next === null) {
    $fail('step 8 has no Next link (schema install failed)');
}
// Step 9: finish.
$page = $next;
$r = $request('GET', $page);
if ($r[0] !== 200 || stripos($r[1], 'Finish') === false) {
    $fail('step 9 did not finish (http ' . $r[0] . ')');
}
// Verify: config written + admin row present.
if (!is_file(__DIR__ . '/config.php')) {
    $fail('config.php missing after install');
}
try {
    $c = new mysqli($dbhost, $dbuser, $dbpass, $dbname);
    if ($c->connect_error) {
        $fail('db connect failed after install: ' . $c->connect_error);
    }
    $q = $c->query("SELECT `username` FROM `cms_users` WHERE `username` = '" . $c->real_escape_string($user) . "'");
    $ok = ($q && $q->num_rows > 0);
    $c->close();
    if (!$ok) {
        $fail('admin account missing after install');
    }
} catch (Throwable $e) {
    $fail('admin verify failed');
}
// Remove the installer assistant (step 9 warns it must go).
foreach ($phars as $p) {
    @unlink($p);
}
echo "cmsms setup complete\n";
PANELSETUP,
                ],
                'cli' => [
                    ['script' => 'panel-setup.php', 'args' => ['{title}', '{email}', '{user}', '{pass}', '{db_host}', '{db_name}', '{db_user}', '{db_pass}', '{url}']],
                ],
                'cleanup' => ['panel-setup.php'],
            ],
            'admin' => ['path' => 'admin/'],
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

<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 30 unattended setup (e107 solo).
 * No usable CLI installer ships (the ?create_tables entry needs staged
 * defines + a wired default DB that only the wizard stages provide), so the
 * driver replays the stock 8-stage browser wizard headless over the live
 * vhost (front-door :80 with Host header — :443 serves the panel vhost for
 * customer domains). State travels in the signed previous_steps hidden
 * field (harvested per stage, never cookies); secrets ride POST bodies
 * (never URLs). Proven live on a hostddl.net subdir: install, front 200,
 * browser login into the admin area, full uninstall.
 */
class UpdateBatch30Apps extends Migration {
    public function up(): void
    {
        $this->merge('e107', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '8.0', 'extensions' => ['mysqli', 'pdo_mysql', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl']],
            'database' => ['required' => true, 'prefix' => 'e107_'],
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
$endpoint = 'http://127.0.0.1' . $path . '/install.php';
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
$doGet = function () use ($endpoint, $domain, &$cookies, $harvestCookies) {
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Host: ' . $domain]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return [0, '', 'curl error: ' . $err];
    }
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $harvestCookies(substr($raw, 0, $hsize));
    return [$code, substr($raw, $hsize), ''];
};
$doPost = function (array $fields) use ($endpoint, $domain, &$cookies, $harvestCookies, $cookieHeader) {
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Host: ' . $domain]);
    $jar = $cookieHeader();
    if ($jar !== '') {
        curl_setopt($ch, CURLOPT_COOKIE, $jar);
    }
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return [0, '', 'curl error: ' . $err];
    }
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $harvestCookies(substr($raw, 0, $hsize));
    return [$code, substr($raw, $hsize), ''];
};
$harvest = function ($body, $name) {
    if (preg_match('/name=[\'"]' . $name . '[\'"]\s+value=[\'"]([^\'"]*)/', $body, $m)) {
        return $m[1];
    }
    if (preg_match('/value=[\'"]([^\'"]*)[\'"]\s*[^>]*name=[\'"]' . $name . '[\'"]/', $body, $m)) {
        return $m[1];
    }
    return null;
};
$stepTitle = function ($body) {
    if (preg_match('/<title>e107 Installation :: ([^<]*)/', $body, $m)) {
        return trim($m[1]);
    }
    return '';
};
// Stage 1 -> 2 (language; starts unlocked, mints the lock on advance).
// The opening blob is harvested, never hardcoded.
$g = $doGet();
if ($g[0] !== 200) {
    fwrite(STDERR, 'installer unreachable (http ' . $g[0] . ")\n");
    exit(1);
}
$firstBlob = $harvest($g[1], 'previous_steps');
if ($firstBlob === null) {
    fwrite(STDERR, "installer did not offer a fresh install\n");
    exit(1);
}
$r = $doPost(['language' => 'English', 'previous_steps' => $firstBlob, 'stage' => '2']);
if ($r[0] !== 200 || stripos($stepTitle($r[1]), 'Step 2') === false) {
    fwrite(STDERR, 'stage 2 failed (http ' . $r[0] . ' ' . $stepTitle($r[1]) . ")\n");
    exit(1);
}
$stages = [
    3 => ['server' => $dbhost, 'name' => $dbuser, 'password' => $dbpass, 'db' => $dbname, 'prefix' => 'e107_'],
    4 => [],
    5 => [],
    6 => ['u_name' => $user, 'd_name' => $user, 'pass1' => $pass, 'pass2' => $pass, 'email' => $email],
    7 => ['sitename' => $title, 'sitetheme' => 'bootstrap3', 'generate_content' => '1', 'install_plugins' => '1'],
    8 => [],
];
$body = $r[1];
foreach ($stages as $n => $fields) {
    $blob = $harvest($body, 'previous_steps');
    $next = $harvest($body, 'stage');
    if ($blob === null || (int) $next !== $n) {
        fwrite(STDERR, 'wizard state lost before stage ' . $n . "\n");
        exit(1);
    }
    $fields['previous_steps'] = $blob;
    $fields['stage'] = (string) $n;
    $r = $doPost($fields);
    if ($r[0] !== 200) {
        fwrite(STDERR, 'stage ' . $n . ' failed (http ' . $r[0] . ")\n");
        exit(1);
    }
    $body = $r[1];
}
if (stripos($body, 'Installation Complete') === false) {
    fwrite(STDERR, 'wizard did not finish (' . $stepTitle($body) . ")\n");
    exit(1);
}
echo "e107 setup complete\n";
PANELSETUP,
                ],
                'cli' => [
                    ['script' => 'panel-setup.php', 'args' => ['{title}', '{email}', '{user}', '{pass}', '{db_host}', '{db_name}', '{db_user}', '{db_pass}', '{url}']],
                ],
                'cleanup' => ['panel-setup.php', 'install.php'],
            ],
            'admin' => ['path' => 'e107_admin/'],
            'post_install' => 'Installed automatically with the admin account you entered — sign in on the front page or at /e107_admin/.',
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

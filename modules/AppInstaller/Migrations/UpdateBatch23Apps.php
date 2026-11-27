<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 23 unattended setup (Textpattern solo).
 * Stock CLI installer (textpattern/setup/setup.php --config=...) fed by a
 * generated JSON config: a tiny driver writes it with json_encode
 * (quote-safe for any password) as setup step one, the stock installer
 * runs as step two. Cleanup removes the driver, the JSON, and the
 * installer dir.
 */
class UpdateBatch23Apps extends Migration {
    public function up(): void
    {
        $this->merge('textpattern', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '7.4', 'extensions' => ['mysqli', 'mbstring', 'xml', 'json']],
            'database' => ['required' => true, 'prefix' => ''],
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
$url = rtrim(trim($args[9] ?? ''), '/');
if ($title === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $user) || strlen($pass) < 8
    || $dbhost === '' || $dbname === '' || $dbuser === '' || $dbpass === ''
    || $url === ''
) {
    fwrite(STDERR, "usage: panel-setup.php <title> <email> <user> <pass> <dbhost> <dbname> <dbuser> <dbpass> <url>\n");
    exit(1);
}
chdir(__DIR__);
$conf = [
    'site' => ['language_code' => 'en', 'public_url' => $url],
    'database' => ['user' => $dbuser, 'password' => $dbpass, 'host' => $dbhost, 'db_name' => $dbname, 'table_prefix' => ''],
    'user' => ['full_name' => $user, 'email' => $email, 'login_name' => $user, 'password' => $pass],
];
file_put_contents(__DIR__ . '/txp-setup.json', json_encode($conf, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "config written\n";
PANELSETUP,
                ],
                'cli' => [
                    ['script' => 'panel-setup.php', 'args' => ['{title}', '{email}', '{user}', '{pass}', '{db_host}', '{db_name}', '{db_user}', '{db_pass}', '{url}']],
                    ['script' => 'textpattern/setup/setup.php', 'args' => ['--config={dest}/txp-setup.json']],
                ],
                'cleanup' => ['panel-setup.php', 'txp-setup.json', 'textpattern/setup'],
            ],
            'post_install' => 'Installed automatically with the admin account you entered — log in under /textpattern/.',
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

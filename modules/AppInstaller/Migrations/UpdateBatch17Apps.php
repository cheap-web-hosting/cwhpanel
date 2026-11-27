<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 17 unattended setup (Shaarli solo).
 * Shaarli boots from data/config.json.php (PHP-wrapped JSON) and skips its
 * installer once the mandatory credentials exist, so the driver mirrors
 * InstallController::save (sha1(pass.login.salt), header_link, api secret)
 * without bootstrapping the container.
 */
class UpdateBatch17Apps extends Migration {
    public function up(): void
    {
        $this->merge('shaarli', [
            'type' => 'php',
            'requirements' => ['php_min' => '8.1', 'extensions' => ['curl', 'mbstring', 'zip', 'json', 'openssl']],
            'database' => ['required' => false],
            'config' => [],
            'setup' => [
                'files' => ['panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 5, '');
$title = trim($args[1]);
$user = trim($args[2]);
$pass = $args[3] ?? '';
$urlpath = trim($args[4] ?? '');
if ($title === '' || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $user)
    || strlen($pass) < 8 || $urlpath === '' || $urlpath[0] !== '/'
) {
    fwrite(STDERR, "usage: panel-setup.php <title> <login> <password> <urlpath>\n");
    exit(1);
}
chdir(__DIR__);
$salt = sha1(uniqid('', true) . '_' . mt_rand());
$conf = [
    'credentials' => [
        'login' => $user,
        'hash' => sha1($pass . $user . $salt),
        'salt' => $salt,
    ],
    'general' => [
        'timezone' => 'UTC',
        'title' => mb_substr($title, 0, 200),
        'header_link' => $urlpath,
    ],
    'security' => ['session_protection_disabled' => false],
    'privacy' => ['default_private_links' => false],
    'translation' => ['language' => 'en'],
    'updates' => ['check_updates' => false],
    'api' => [
        'enabled' => false,
        'secret' => str_shuffle(substr(hash_hmac('sha512', uniqid($salt), $user), 10, 12)),
    ],
];
@mkdir(__DIR__ . '/data', 0755, true);
file_put_contents(
    __DIR__ . '/data/config.json.php',
    '<?php /*' . json_encode($conf, JSON_PRETTY_PRINT) . '*/ ?>'
);
echo "installed\n";
PANELSETUP,
                ],
                'cli' => [
                    ['script' => 'panel-setup.php', 'args' => ['{title}', '{user}', '{pass}', '{urlpath}']],
                ],
                'cleanup' => ['panel-setup.php'],
            ],
            'post_install' => 'Installed automatically with the login you entered — open /login to manage bookmarks.',
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

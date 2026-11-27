<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 27 unattended setup (OrangeHRM solo).
 * The modern console installer is interactive-only, so this drives the
 * deprecated (but working) cli_install.php in existing-database mode —
 * the panel DB is empty, no privileged user needed. The YAML is emitted
 * by a driver (Symfony Yaml::dump — quote-safe for any password), then
 * the stock installer runs as step two.
 */
class UpdateBatch27Apps extends Migration {
    public function up(): void
    {
        $this->merge('orangehrm', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '8.1', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl']],
            'database' => ['required' => true, 'prefix' => ''],
            'config' => [],
            'setup' => [
                'files' => ['panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 9, '');
$title = trim($args[1]);
$email = trim($args[2]);
$user = trim($args[3]);
$pass = $args[4] ?? '';
$dbhost = trim($args[5]);
$dbname = trim($args[6]);
$dbuser = trim($args[7]);
$dbpass = $args[8] ?? '';
if ($title === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $user) || strlen($pass) < 8
    || $dbhost === '' || $dbname === '' || $dbuser === '' || $dbpass === ''
) {
    fwrite(STDERR, "usage: panel-setup.php <title> <email> <user> <pass> <dbhost> <dbname> <dbuser> <dbpass>\n");
    exit(1);
}
chdir(__DIR__);
require __DIR__ . '/src/vendor/autoload.php';
$conf = [
    'database' => [
        'hostName' => $dbhost,
        'hostPort' => 3306,
        'databaseName' => $dbname,
        'privilegedDatabaseUser' => $dbuser,
        'privilegedDatabasePassword' => $dbpass,
        'useSameDbUserForOrangeHRM' => 'y',
        'isExistingDatabase' => 'y',
        'enableDataEncryption' => 'n',
    ],
    'organization' => ['name' => mb_substr($title, 0, 200), 'country' => 'US'],
    'admin' => [
        'adminUserName' => $user,
        'adminPassword' => $pass,
        'adminEmployeeFirstName' => 'Admin',
        'adminEmployeeLastName' => 'Admin',
        'workEmail' => $email,
        'contactNumber' => null,
        'registrationConsent' => true,
    ],
    'license' => ['agree' => 'y'],
];
file_put_contents(
    __DIR__ . '/installer/cli_install_config.yaml',
    \Symfony\Component\Yaml\Yaml::dump($conf, 4)
);
echo "config written\n";
PANELSETUP,
                ],
                'cli' => [
                    ['script' => 'panel-setup.php', 'args' => ['{title}', '{email}', '{user}', '{pass}', '{db_host}', '{db_name}', '{db_user}', '{db_pass}']],
                    ['script' => 'installer/cli_install.php', 'args' => []],
                ],
                'cleanup' => ['panel-setup.php', 'installer/cli_install_config.yaml'],
            ],
            'admin' => ['path' => 'auth/login'],
            'post_install' => 'Installed automatically with the admin account you entered — log in at /auth/login.',
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

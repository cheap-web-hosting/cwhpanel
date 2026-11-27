<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 24 unattended setup (thirty bees solo).
 * Same console installer as PrestaShop (shared lineage):
 * install/index_cli.php against the panel-managed DB. The -php8.1 asset
 * carries vendor/ and gates older PHP itself (hence the 8.1 floor).
 */
class UpdateBatch24Apps extends Migration {
    public function up(): void
    {
        $this->merge('thirtybees', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '8.1', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'intl']],
            'database' => ['required' => true, 'prefix' => 'tb_'],
            'config' => [],
            'setup' => [
                'files' => ['panel-finish.php' => <<<'PANELFINISH'
<?php
// Post-install: claim a fixed non-default admin dir name. The console
// installer leaves admin/ behind, and the FIRST admin hit renames a
// bare admin/ to adminNNNxxxxxx/ (then redirects there) — nondeterministic
// and login-breaking for every saved bookmark. backend/ never matches
// that trigger, so the URL stays stable. CLI-only.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
chdir(__DIR__);
foreach (['admin', 'admin-dev'] as $candidate) {
    if ($candidate !== 'backend' && is_dir(__DIR__ . '/' . $candidate) && !is_dir(__DIR__ . '/backend')) {
        if (!@rename(__DIR__ . '/' . $candidate, __DIR__ . '/backend')) {
            fwrite(STDERR, "admin rename failed\n");
            exit(1);
        }
        break;
    }
}
if (!is_dir(__DIR__ . '/backend')) {
    fwrite(STDERR, "no admin dir to claim\n");
    exit(1);
}
echo "renamed\n";
PANELFINISH,
                ],
                'cli' => [
                    ['script' => 'install/index_cli.php', 'args' => ['--domain={domain}', '--base_uri={scriptpath}', '--db_server={db_host}', '--db_user={db_user}', '--db_password={db_pass}', '--db_name={db_name}', '--db_clear=0', '--db_create=0', '--prefix={prefix}', '--name={title}', '--firstname=Admin', '--lastname=Admin', '--password={pass}', '--email={email}', '--country=us', '--language=en']],
                    ['script' => 'panel-finish.php', 'args' => []],
                ],
                'cleanup' => ['install', 'panel-finish.php'],
            ],
            'admin' => ['path' => 'backend/'],
            'post_install' => 'Installed automatically with the admin account you entered — log in under /backend/ (a fixed non-default name).',
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

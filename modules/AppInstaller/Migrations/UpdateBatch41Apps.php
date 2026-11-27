<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 41 serve-as-is tools (phpSysInfo ini seed + no_creds setups).
 * phpSysInfo served an error page out of the box: the tree ships
 * phpsysinfo.ini.new and requires copying it to phpsysinfo.ini. A tiny
 * in-tree driver does the copy. The tool has no login and no wizard, so
 * the manifest opts into setup.no_creds: files/cli steps run without
 * wizard values (and must not interpolate wizard placeholders), and the
 * customer form skips the admin-credential validation for it.
 */
class UpdateBatch41Apps extends Migration {
    public function up(): void
    {
        $this->merge('phpsysinfo', [
            'setup' => [
                'no_creds' => true,
                'files' => ['panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$dst = __DIR__ . '/phpsysinfo.ini';
if (!is_file($dst)) {
    $src = __DIR__ . '/phpsysinfo.ini.new';
    if (!is_file($src) || !@copy($src, $dst)) {
        fwrite(STDERR, "phpsysinfo setup: cannot seed phpsysinfo.ini\n");
        exit(1);
    }
}
echo "phpsysinfo setup complete\n";
PANELSETUP,
                ],
                'cli' => [['script' => 'panel-setup.php', 'args' => []]],
                'cleanup' => ['panel-setup.php'],
            ],
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

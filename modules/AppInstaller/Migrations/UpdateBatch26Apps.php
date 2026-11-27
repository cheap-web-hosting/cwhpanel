<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 26 unattended setup (CMSimple_XH solo, no database).
 * Password-only login with bcrypt in cmsimple/config.php: the driver
 * swaps just the password line (argv carries the pre-hashed pass_hash),
 * leaving the shipped config untouched.
 */
class UpdateBatch26Apps extends Migration {
    public function up(): void
    {
        $this->merge('cmsimple-xh', [
            'type' => 'php',
            'requirements' => ['php_min' => '7.4', 'extensions' => ['mbstring', 'json', 'openssl']],
            'database' => ['required' => false],
            'config' => [],
            'setup' => [
                'files' => ['panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 2, '');
$hash = trim($args[1] ?? '');
// Bcrypt alphabet plus $ separators — anything else is refused.
if ($hash === '' || strlen($hash) > 255 || !preg_match('#^[A-Za-z0-9./$]+$#', $hash)) {
    fwrite(STDERR, "usage: panel-setup.php <bcrypt-hash>\n");
    exit(1);
}
chdir(__DIR__);
$file = __DIR__ . '/cmsimple/config.php';
$conf = @file_get_contents($file);
if (!is_string($conf)) {
    fwrite(STDERR, "config.php missing\n");
    exit(1);
}
$out = null;
if (preg_match('/^\$cf\[\'security\'\]\[\'password\'\]="[^"]*";/m', $conf, $m)) {
    // str_replace (never preg_replace) for the swap: the bcrypt hash is
    // full of $n sequences that a preg REPLACEMENT would eat as
    // backreferences (bitten live: $2y$10$… came out as y$…).
    // Escape $ upstream-style so PHP keeps the hash intact on load.
    $line = '$cf[\'security\'][\'password\']="' . str_replace('$', '\\$', $hash) . '";';
    $out = str_replace($m[0], $line, $conf);
}
if (!is_string($out)) {
    fwrite(STDERR, "password line not found\n");
    exit(1);
}
file_put_contents($file, $out);
echo "installed\n";
PANELSETUP,
                ],
                'cli' => [
                    ['script' => 'panel-setup.php', 'args' => ['{pass_hash}']],
                ],
                'cleanup' => ['panel-setup.php'],
            ],
            'post_install' => 'Installed automatically — log in with the password you entered (no username needed).',
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

<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 16 unattended setup (media). Ampache (two first-party CLIs, no
 * driver needed: bin/installer creates the schema + writes
 * ampache.cfg.php, bin/cli mints the level-100 admin). Also switches the
 * download to the installable _all_php8.5 asset — the bare source asset
 * ships no vendor/ — and floors PHP at 8.4 (panel id 84 is the system 8.5
 * behind a stale label; the composer platform check needs real >= 8.5).
 */
class UpdateBatch16Apps extends Migration {
    public function up(): void
    {
        $this->merge('ampache', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '8.4', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl']],
            'database' => ['required' => true, 'prefix' => ''],
            'download' => ['github' => ['repo' => 'ampache/ampache', 'asset' => 'ampache-{version}_all_php8.5.zip', 'tag_prefix' => ''], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
            'config' => [],
            'setup' => [
                'cli' => [
                    ['script' => 'bin/installer', 'args' => ['install', '-U', '{db_user}', '-P', '{db_pass}', '-H', '{db_host}', '-o', '3306', '-d', '{db_name}', '-w', '{webroot}', '-f']],
                    ['script' => 'bin/cli', 'args' => ['admin:addUser', '{user}', '-p', '{pass}', '-e', '{email}', '-l', '100']],
                ],
            ],
            'post_install' => 'Installed automatically with the admin account you entered — log in at the site URL, then add your media folders under Admin → Catalog.',
            'webroot' => 'public',
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

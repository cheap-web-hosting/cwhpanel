<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Unattended Nextcloud setup: panel-managed DB + occ-driven install
 * (maintenance:install, trusted domain, CLI URL, subdir webroot).
 * Key-scoped manifest merge — admin edits to other keys survive.
 */
class UpdateNextcloudAutoSetup extends Migration {
    public function up(): void
    {
        try {
            $row = $this->db->selectOne('SELECT * FROM `ai_applications` WHERE `slug` = ?', ['nextcloud']);
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
        $manifest['type'] = 'php-mysql';
        $manifest['requirements'] = ['php_min' => '8.3', 'extensions' => ['gd', 'curl', 'mbstring', 'intl', 'zip', 'xml']];
        $manifest['database'] = ['required' => true, 'prefix' => 'oc_'];
        $manifest['config'] = [];
        $manifest['setup'] = ['cli' => [
            ['script' => 'occ', 'php_version' => '{php}', 'args' => ['maintenance:install', '--database=mysql', '--database-host={db_host}', '--database-port=3306', '--database-name={db_name}', '--database-user={db_user}', '--database-pass={db_pass}', '--admin-user={user}', '--admin-pass={pass}', '--admin-email={email}', '--data-dir={home}/ncdata-{dir}']],
            ['script' => 'occ', 'php_version' => '{php}', 'args' => ['config:system:set', 'trusted_domains', '1', '--value={domain}']],
            ['script' => 'occ', 'php_version' => '{php}', 'args' => ['config:system:set', 'overwrite.cli.url', '--value={url}']],
            ['script' => 'occ', 'php_version' => '{php}', 'skip_if' => ['webroot' => '/'], 'args' => ['config:system:set', 'overwritewebroot', '--value={webroot}']],
        ],
        'datadirs' => ['ncdata-{dir}']];
        $manifest['post_install'] = 'Installed automatically with the admin account you entered (data directory ncdata lives beside public_html) — just log in at the site URL.';
        try {
            $this->db->update('ai_applications', [
                'manifest' => json_encode($manifest),
                'updated_at' => date('Y-m-d H:i:s'),
            ], '`slug` = ?', ['nextcloud']);
        } catch (\Throwable $e) {
        }
    }
}

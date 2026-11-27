<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Unattended CLI setups for ownCloud (occ) and MediaWiki
 * (maintenance/install.php): panel-managed DB + recipe-driven setup.
 * Key-scoped manifest merge — admin edits to other keys survive.
 */
class UpdateCliSetupApps extends Migration {
    public function up(): void
    {
        // ownCloud: occ maintenance:install + trusted domain + CLI URL.
        $this->merge('owncloud', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '7.4', 'php_max' => '7.99', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl']],
            'database' => ['required' => true, 'prefix' => 'oc_'],
            'config' => [],
            'setup' => [
                'cli' => [
                    ['script' => 'occ', 'php_version' => '{php}', 'args' => ['maintenance:install', '--database=mysql', '--database-host={db_host}', '--database-name={db_name}', '--database-user={db_user}', '--database-pass={db_pass}', '--database-table-prefix=oc_', '--admin-user={user}', '--admin-pass={pass}', '--data-dir={home}/ocdata-{dir}']],
                    ['script' => 'occ', 'php_version' => '{php}', 'args' => ['config:system:set', 'trusted_domains', '0', '--value={domain}']],
                    ['script' => 'occ', 'php_version' => '{php}', 'args' => ['config:system:set', 'overwrite.cli.url', '--value={url}']],
                ],
                'datadirs' => ['ocdata-{dir}'],
            ],
            'post_install' => 'Installed automatically with the admin account you entered (data directory ocdata lives beside public_html) — just log in at the site URL.',
        ]);

        // MediaWiki: maintenance/install.php (positional name + admin user).
        $this->merge('mediawiki', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '8.1', 'extensions' => ['mysqli', 'curl', 'mbstring', 'xml', 'json', 'openssl', 'intl']],
            'database' => ['required' => true, 'prefix' => 'wiki_'],
            'config' => [],
            'setup' => [
                'cli' => [
                    ['script' => 'maintenance/install.php', 'php_version' => '{php}', 'args' => ['{title}', '{user}', '--pass={pass}', '--dbtype=mysql', '--dbserver={db_host}', '--dbname={db_name}', '--dbuser={db_user}', '--dbpass={db_pass}', '--dbprefix={prefix}', '--server={origin}', '--scriptpath={scriptpath}', '--lang=en', '--with-extensions']],
                ],
            ],
            'post_install' => 'Installed automatically with the admin account you entered — just log in at the site URL.',
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

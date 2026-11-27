<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 6 unattended setups. Serendipity: config file + db.sql import with
 * template-token + prefix swaps + bcrypt author row.
 */
class UpdateBatch6Apps extends Migration {
    public function up(): void
    {
        $this->merge('serendipity', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '8.0', 'extensions' => ['mysqli', 'gd', 'mbstring', 'xml', 'json', 'openssl']],
            'database' => ['required' => true, 'prefix' => 'serendipity_'],
            'config' => ['template' => 'serendipity', 'file' => 'serendipity_config_local.inc.php', 'prefix' => 'serendipity_'],
            'setup' => ['sql' => [
                'schema' => 'sql/db.sql',
                'prefix_from' => '{PREFIX}',
                'prefix_to' => 'serendipity_',
                'tokens' => ['{AUTOINCREMENT}' => 'int(11) not null auto_increment', '{PRIMARY}' => 'primary key', '{UNSIGNED}' => 'unsigned', '{FULLTEXT}' => 'FULLTEXT', '{FULLTEXT_MYSQL}' => 'FULLTEXT', '{BOOLEAN}' => 'tinyint(1)', '{TEXT}' => 'LONGTEXT', '{UTF_8}' => 'DEFAULT CHARSET=utf8mb4'],
                'admin' => ['table' => 'authors', 'user_col' => 'username', 'pass_col' => 'password', 'email_col' => 'email', 'hash' => 'bcrypt', 'extra' => ['realname' => 'Administrator', 'userlevel' => '255', 'right_publish' => '1', 'hashtype' => '2', 'mail_comments' => '1', 'mail_trackbacks' => '1']],
            ]],
            'post_install' => 'Installed automatically with the admin account you entered — log in at /serendipity_admin.php.',
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

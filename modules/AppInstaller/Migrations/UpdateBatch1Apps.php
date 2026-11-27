<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 1 unattended setups: phpMyAdmin (preconfigured cookie-auth config),
 * Grav (precreated bcrypt admin account), Roundcube (panel DB + config +
 * schema import). Key-scoped manifest merge — admin edits survive.
 */
class UpdateBatch1Apps extends Migration {
    public function up(): void
    {
        $this->merge('phpmyadmin', [
            'config' => ['template' => 'phpmyadmin', 'file' => 'config.inc.php'],
            'post_install' => 'Preconfigured (cookie auth against the local server) — log in with any database user you create under Databases.',
        ]);

        $this->merge('grav', [
            'setup' => ['files' => [
                'user/accounts/{user}.yaml' => "email: '{email}'\nfullname: Administrator\ntitle: Administrator\nstate: enabled\nlanguage: en\naccess:\n  admin:\n    login: true\n    super: true\n  site:\n    login: true\nhashed_password: '{pass_hash}'\n",
            ]],
            'post_install' => 'Installed automatically with the admin account you entered — log in at /admin.',
        ]);

        $this->merge('roundcube', [
            'type' => 'php-mysql',
            'database' => ['required' => true, 'prefix' => ''],
            'config' => ['template' => 'roundcube', 'file' => 'config/config.inc.php'],
            'setup' => ['sql' => ['schema' => 'SQL/mysql.initial.sql']],
            'post_install' => 'Configured for the local IMAP/SMTP servers — log in with any mailbox you create under Email.',
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

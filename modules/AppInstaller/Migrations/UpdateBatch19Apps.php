<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 19 unattended setup (SnappyMail solo, no database).
 * Admin credentials live as bcrypt in application.ini, overlayed onto
 * the built-in defaults — a minimal setup.files entry suffices, no
 * container bootstrap, no CLI.
 */
class UpdateBatch19Apps extends Migration {
    public function up(): void
    {
        $this->merge('snappymail', [
            'type' => 'php',
            'requirements' => ['php_min' => '8.1', 'extensions' => ['curl', 'mbstring', 'xml', 'zip', 'json', 'openssl']],
            'database' => ['required' => false],
            'config' => [],
            'setup' => [
                'files' => ['data/_data_/_default_/configs/application.ini' => "[security]\nadmin_login = \"{user}\"\nadmin_password = \"{pass_hash}\"\n"],
            ],
            'post_install' => 'Installed automatically — open ?/Admin and log in with the credentials you entered, then add mail domains.',
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

<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Unattended Matomo + DokuWiki setups: flip the two catalog rows to the new
 * manifests (Matomo gets a panel-managed DB + matomo setup template,
 * DokuWiki a dokuwiki setup template). Key-scoped merge — any admin edits
 * to other manifest keys survive.
 */
class UpdateMatomoDokuwikiAutoSetup extends Migration {
    public function up(): void {
        // NOTE: `type` lives inside the manifest JSON (ai_applications has
        // no type column).
        $this->mergeManifest('matomo', [
            'type' => 'php-mysql',
            'database' => ['required' => true, 'prefix' => 'matomo_'],
            'config' => ['template' => 'matomo', 'prefix' => 'matomo_'],
            'post_install' => 'Installed automatically with the title, admin account and first website you entered — just log in at the site URL.',
        ]);
        $this->mergeManifest('dokuwiki', [
            'database' => ['required' => false],
            'config' => ['template' => 'dokuwiki'],
            'post_install' => 'Installed automatically with the wiki title and admin account you entered — just log in at doku.php.',
        ]);
    }

    /**
     * @param array<string,mixed> $keys
     */
    private function mergeManifest(string $slug, array $keys): void
    {
        try {
            $row = $this->db->selectOne('SELECT * FROM `ai_applications` WHERE `slug` = ?', [$slug]);
        } catch (\Throwable $e) {
            return; // pre-migration table
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

<?php

declare(strict_types=1);

namespace Modules\Backups\Services\Backup;

use Modules\Backups\Services\RemoteSecret;
use Modules\Backups\Services\S3Client;

/**
 * S3-compatible remote storage operations for BackupService.
 */
trait RemoteStorageTrait
{
    /** Build an S3 client for a remote row (unseals the secret). */
    public function remoteClient(array $remote): S3Client
    {
        if (($remote['driver'] ?? '') !== 's3') {
            throw new \RuntimeException('Unknown remote driver.');
        }
        return new S3Client([
            'endpoint' => $remote['endpoint'],
            'bucket' => $remote['bucket'],
            'region' => $remote['region'] ?? 'us-east-1',
            'key' => $remote['access_key'],
            'secret' => RemoteSecret::open((string) $remote['secret_encrypted']),
        ]);
    }

    public function remotePrefix(array $remote, string $username): string
    {
        $prefix = trim((string) ($remote['path_prefix'] ?? ''), '/');
        $prefix = $prefix !== '' ? $prefix . '/' : '';
        return $prefix . $username . '/';
    }

    /**
     * Upload every local artifact of a backup row. Returns artifact count.
     * Records backup_copies rows (idempotent per path).
     */
    public function uploadBackup(int $accountId, int $backupId, int $remoteId): int
    {
        $backup = app('db')->selectOne(
            'SELECT * FROM `backups` WHERE `id` = ? AND `account_id` = ?',
            [$backupId, $accountId]
        );
        if ($backup === null || ($backup['status'] ?? '') !== 'completed') {
            throw new \RuntimeException('Backup not available for upload.');
        }
        $remote = app('db')->selectOne(
            'SELECT * FROM `backup_remotes` WHERE `id` = ? AND `account_id` = ?',
            [$remoteId, $accountId]
        );
        if ($remote === null) {
            throw new \RuntimeException('Remote storage not found.');
        }
        $account = $this->account($accountId);
        $client = $this->remoteClient($remote);
        $prefix = $this->remotePrefix($remote, (string) ($account['username'] ?? 'unknown'));
        $n = 0;
        foreach ($this->artifacts($backup) as $a) {
            $local = self::BACKUP_DIR . '/' . $a['file'];
            $dest = $prefix . $a['file'];
            $client->put($local, $dest);
            $exists = app('db')->selectOne(
                'SELECT `id` FROM `backup_copies` WHERE `backup_id` = ? AND `remote_id` = ? AND `remote_path` = ?',
                [$backupId, $remoteId, $dest]
            );
            if ($exists === null) {
                app('db')->insert('backup_copies', [
                    'backup_id' => $backupId, 'remote_id' => $remoteId,
                    'remote_path' => $dest, 'size_bytes' => $a['size'],
                    'status' => 'completed',
                    'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
            $n++;
        }
        if ($n === 0) {
            throw new \RuntimeException('No local artifacts to upload.');
        }
        return $n;
    }

    /**
     * Keep the newest $keep remote objects under the account prefix (keysort
     * desc = newest first thanks to the sortable Ymd-His names). Returns the
     * deleted count. keep=0 empties the prefix entirely.
     */
    public function pruneRemote(int $remoteId, int $keep): int
    {
        $remote = app('db')->selectOne('SELECT * FROM `backup_remotes` WHERE `id` = ?', [$remoteId]);
        if ($remote === null) {
            throw new \RuntimeException('Remote storage not found.');
        }
        $account = $this->account((int) $remote['account_id']);
        $client = $this->remoteClient($remote);
        $prefix = $this->remotePrefix($remote, (string) ($account['username'] ?? 'unknown'));
        $keys = $client->list($prefix, 10000);
        rsort($keys, SORT_STRING);
        $n = 0;
        foreach (array_slice($keys, max(0, $keep)) as $gone) {
            $client->delete($gone);
            app('db')->delete('backup_copies', '`remote_id` = ? AND `remote_path` = ?', [$remoteId, $gone]);
            $n++;
        }
        return $n;
    }

    /**
     * Fetch a remote copy back into /var/backups for restore (ownership
     * chain verified: copy → backup → account). Skips download when the
     * artifact already exists locally. Returns the local filename.
     */
    public function fetchCopy(int $accountId, int $copyId): string
    {
        $copy = app('db')->selectOne(
            'SELECT c.*, b.`account_id`, b.`type` FROM `backup_copies` c
             JOIN `backups` b ON b.`id` = c.`backup_id`
             WHERE c.`id` = ? AND b.`account_id` = ?',
            [$copyId, $accountId]
        );
        if ($copy === null) {
            throw new \RuntimeException('Remote copy not found.');
        }
        $remote = app('db')->selectOne(
            'SELECT * FROM `backup_remotes` WHERE `id` = ? AND `account_id` = ?',
            [(int) $copy['remote_id'], $accountId]
        );
        if ($remote === null) {
            throw new \RuntimeException('Remote storage not found.');
        }
        $file = basename((string) $copy['remote_path']);
        if ($file === '' || str_contains($file, '/') || !preg_match('/^[A-Za-z0-9._-]+$/', $file)) {
            throw new \RuntimeException('Unsafe remote filename.');
        }
        $local = self::BACKUP_DIR . '/' . $file;
        $real = realpath($local);
        if ($real !== false && str_starts_with($real, self::BACKUP_DIR . '/') && is_file($real)) {
            return $file;
        }
        $tmp = $local . '.dl-' . getmypid();
        try {
            $this->remoteClient($remote)->get((string) $copy['remote_path'], $tmp);
            rename($tmp, $local);
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e;
        }
        return $file;
    }

    /**
     * Connectivity + credential proof for a remote row: PUT a probe object,
     * HEAD it, DELETE it. Returns a summary (server/version when visible).
     */
    public function testRemote(array $remote): string
    {
        $client = $this->remoteClient($remote);
        $probe = 'cwhpanel-probe-' . bin2hex(random_bytes(6)) . '.txt';
        $account = $this->account((int) ($remote['account_id'] ?? 0));
        $prefix = $this->remotePrefix($remote, (string) ($account['username'] ?? 'probe'));
        $tmp = tempnam(sys_get_temp_dir(), 's3probe');
        file_put_contents($tmp, 'cwhpanel remote-storage probe ' . date('c'));
        try {
            $client->put($tmp, $prefix . $probe, 'text/plain');
            if (!$client->exists($prefix . $probe)) {
                throw new \RuntimeException('probe object not visible after PUT');
            }
            $client->delete($prefix . $probe);
        } finally {
            @unlink($tmp);
        }
        return 'PUT+HEAD+DELETE round-trip ok';
    }
}

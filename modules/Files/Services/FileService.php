<?php
declare(strict_types=1);
namespace Modules\Files\Services;

/**
 * File-manager operations, delegated to the privileged agent with path
 * confinement to the account's home directory.
 *
 * The agent re-validates every path (rejects "..", absolute paths, and symlink
 * escapes via canonicalization). This service mirrors the same rules as a first
 * line of defense and returns friendly structured data.
 */
class FileService
{
    private function home(string $account): string
    {
        return '/home/' . $account;
    }

    private function assertSafeRelPath(string $path): void
    {
        if (str_starts_with($path, '/')) {
            throw new \RuntimeException('Absolute paths are not allowed.');
        }
        if (str_contains($path, "\0") || str_contains($path, "\n")) {
            throw new \RuntimeException('Invalid path.');
        }
        foreach (explode('/', $path) as $comp) {
            if ($comp === '..') {
                throw new \RuntimeException('Path traversal is not allowed.');
            }
        }
    }

    public function baseDir(string $account): array
    {
        return [
            'account' => $account,
            'home' => $this->home($account),
        ];
    }

    public function list(string $account, string $path = ''): array
    {
        // The agent pins a parent directory; an empty path is not a component.
        // The file manager opens at the account home, so treat '' as '.'.
        if ($path === '') { $path = '.'; }
        $this->assertSafeRelPath($path);
        $result = app('provisioning')->execute('file.list', ['account' => $account, 'path' => $path]);
        $entries = $this->parseListing($result['output'] ?? '');

        return ['path' => $path, 'entries' => $entries];
    }

    public function read(string $account, string $path): string
    {
        $this->assertSafeRelPath($path);
        $result = app('provisioning')->execute('file.read', ['account' => $account, 'path' => $path]);
        $b64 = trim($result['output'] ?? '');
        $decoded = base64_decode($b64, true);
        return $decoded === false ? '' : $decoded;
    }

    public function write(string $account, string $path, string $content): bool
    {
        $this->assertSafeRelPath($path);
        $result = app('provisioning')->execute('file.write', [
            'account' => $account,
            'path' => $path,
            'content' => base64_encode($content),
        ]);
        return $result['status'] === 'success';
    }

    public function mkdir(string $account, string $path): bool
    {
        $this->assertSafeRelPath($path);
        $result = app('provisioning')->execute('file.mkdir', ['account' => $account, 'path' => $path]);
        return $result['status'] === 'success';
    }

    public function delete(string $account, string $path): bool
    {
        $this->assertSafeRelPath($path);
        $result = app('provisioning')->execute('file.delete', ['account' => $account, 'path' => $path]);
        return $result['status'] === 'success';
    }

    public function rename(string $account, string $from, string $to): bool
    {
        $this->assertSafeRelPath($from);
        $this->assertSafeRelPath($to);
        $result = app('provisioning')->execute('file.rename', ['account' => $account, 'from' => $from, 'to' => $to]);
        return $result['status'] === 'success';
    }

    public function copy(string $account, string $from, string $to): bool
    {
        $this->assertSafeRelPath($from);
        $this->assertSafeRelPath($to);
        $result = app('provisioning')->execute('file.copy', ['account' => $account, 'from' => $from, 'to' => $to]);
        return $result['status'] === 'success';
    }

    public function chmod(string $account, string $path, string $mode): bool
    {
        $this->assertSafeRelPath($path);
        if (!preg_match('/^[0-7]{3}$/', $mode)) {
            throw new \RuntimeException('Mode must be three octal digits (e.g. 644).');
        }
        $result = app('provisioning')->execute('file.chmod', ['account' => $account, 'path' => $path, 'mode' => $mode]);
        return $result['status'] === 'success';
    }

    public function archive(string $account, string $path, string $name, string $format = 'tar'): bool
    {
        $this->assertSafeRelPath($path);
        $result = app('provisioning')->execute('file.archive', ['account' => $account, 'path' => $path, 'name' => $name, 'format' => $format]);
        return $result['status'] === 'success';
    }

    public function extract(string $account, string $name, string $path, string $format = 'tar'): bool
    {
        $this->assertSafeRelPath($path);
        $result = app('provisioning')->execute('file.extract', ['account' => $account, 'path' => $path, 'name' => $name, 'format' => $format]);
        return $result['status'] === 'success';
    }

    /**
     * Parse `ls -la --time-style=...` output into structured entries.
     */
    private function parseListing(string $output): array
    {
        $entries = [];
        foreach (explode("\n", trim($output)) as $line) {
            if ($line === '' || str_starts_with($line, 'total')) {
                continue;
            }
            // Columns (--time-style with T separator => 7 cols):
            // perms links owner group size date name
            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 7) {
                continue;
            }

            $perms = $parts[0];
            $name = implode(' ', array_slice($parts, 6));
            if ($name === '.' || $name === '..') {
                continue;
            }

            $entries[] = [
                'name' => $name,
                'type' => $perms[0] === 'd' ? 'dir' : ($perms[0] === 'l' ? 'link' : 'file'),
                'size' => (int) $parts[4],
                'date' => $parts[5],
                'perms' => $perms,
            ];
        }

        return $entries;
    }
}
<?php

declare(strict_types=1);

namespace Modules\Core\Services;

class SystemInfo
{
    public static function formatBytes(float $bytes): string
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = (int) max(0, min(floor(log($bytes, 1024)), count($units) - 1));
        return round($bytes / pow(1024, $i), 1) . ' ' . $units[$i];
    }

    public function os(): array
    {
        $release = [];

        if (is_file('/etc/os-release')) {
            foreach (file('/etc/os-release', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (preg_match('/^([A-Z_]+)=(.*)$/', $line, $m)) {
                    $release[$m[1]] = trim($m[2], '"\'');
                }
            }
        }

        return [
            'name' => $release['NAME'] ?? 'Linux',
            'version' => $release['VERSION'] ?? 'Unknown',
            'pretty' => $release['PRETTY_NAME'] ?? (($release['NAME'] ?? 'Linux') . ' ' . ($release['VERSION'] ?? '')),
        ];
    }

    public function kernel(): string
    {
        return php_uname('r');
    }

    public function hostname(): string
    {
        return gethostname() ?: php_uname('n');
    }

    public function uptime(): array
    {
        $raw = @file_get_contents('/proc/uptime');
        $seconds = $raw !== false ? (float) explode(' ', trim($raw))[0] : 0;

        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        return [
            'seconds' => (int) $seconds,
            'human' => "{$days}d {$hours}h {$minutes}m",
        ];
    }

    public function load(): array
    {
        $raw = @file_get_contents('/proc/loadavg');
        $parts = $raw !== false ? explode(' ', trim($raw)) : [];

        return [
            '1m' => (float) ($parts[0] ?? 0),
            '5m' => (float) ($parts[1] ?? 0),
            '15m' => (float) ($parts[2] ?? 0),
        ];
    }

    public function cpu(): array
    {
        $raw = @file_get_contents('/proc/cpuinfo');
        $count = substr_count((string) $raw, 'processor');
        $model = 'Unknown';

        if ($raw !== false && preg_match('/^model name\s*:\s*(.+)$/m', $raw, $m)) {
            $model = trim($m[1]);
        }

        return [
            'cores' => max(1, $count),
            'model' => $model,
        ];
    }

    /** Memory values are in KiB, as reported by /proc/meminfo. */
    public function memory(): array
    {
        $info = $this->parseProcPairs('/proc/meminfo');

        $total = (float) ($info['MemTotal'] ?? 0);
        $available = (float) ($info['MemAvailable'] ?? 0);
        $used = $total - $available;

        return [
            'total' => $total,
            'available' => $available,
            'used' => $used,
            'used_percent' => $total > 0 ? round($used / $total * 100, 1) : 0,
        ];
    }

    /** Disk values are in bytes, as returned by PHP's disk space functions. */
    public function disk(string $path = '/'): array
    {
        // The panel FPM pool runs under open_basedir, which denies statfs on
        // '/' (and any path outside the sandbox) — disk_total_space/free_space
        // return false there, which used to zero the dashboard Disk card. Probe
        // an allowed path on the same filesystem instead: statvfs answers are
        // per-mount, so any path on the root FS returns identical totals.
        $total = (float) @disk_total_space($path);
        $free = (float) @disk_free_space($path);

        if ($total <= 0 || $free <= 0) {
            foreach (['/var/tmp', '/opt/cwhpanel'] as $alt) {
                $t = (float) @disk_total_space($alt);
                $f = (float) @disk_free_space($alt);
                if ($t > 0 && $f > 0) {
                    $total = $t;
                    $free = $f;
                    break;
                }
            }
        }

        $used = $total - $free;

        return [
            'total' => $total,
            'free' => $free,
            'used' => $used,
            'used_percent' => $total > 0 ? round($used / $total * 100, 1) : 0,
        ];
    }

    public function phpVersion(): string
    {
        return PHP_VERSION;
    }

    public function databaseVersion(): string
    {
        try {
            return (string) app('db')->scalar('SELECT VERSION()');
        } catch (\Throwable $e) {
            return 'Unavailable';
        }
    }

    private function parseProcPairs(string $file): array
    {
        $raw = @file_get_contents($file);

        if ($raw === false) {
            return [];
        }

        $out = [];

        foreach (explode("\n", $raw) as $line) {
            if (preg_match('/^([^:]+):\s*(\d+)\s*kB?$/', trim($line), $m)) {
                $out[$m[1]] = (int) $m[2];
            }
        }

        return $out;
    }

    public function snapshot(): array
    {
        return [
            'os' => $this->os(),
            'kernel' => $this->kernel(),
            'hostname' => $this->hostname(),
            'uptime' => $this->uptime(),
            'load' => $this->load(),
            'cpu' => $this->cpu(),
            'memory' => $this->memory(),
            'disk' => $this->disk(),
            'php' => $this->phpVersion(),
            'database' => $this->databaseVersion(),
        ];
    }
}

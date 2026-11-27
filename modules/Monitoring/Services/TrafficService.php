<?php

declare(strict_types=1);

namespace Modules\Monitoring\Services;

/**
 * TrafficService — per-website bandwidth/visitor analytics parsed from nginx
 * access logs.
 *
 * A website's live log path varies by serving mode (direct vhost logs to
 * `<user>-<domain>.access.log`, the front-door proxy to `<domain>.access.log`),
 * so every candidate file is tried and the results merged. Log bytes come
 * from the agent (log.read, root-privileged) because rotations vary in
 * ownership. The parser itself is pure and unit-testable.
 */
class TrafficService
{
    /**
     * Parse combined-format access log text (with trailing X-Forwarded-For).
     *
     * @return array{requests:int, bytes:int, visitors:int, skipped:int,
     *   byStatus:array<string,int>, topPaths:array<int,array{path:string,hits:int}>,
     *   byDay:array<string,array{requests:int,bytes:int}>, files:array<int,string>}
     */
    public static function parse(string $text): array
    {
        $requests = 0;
        $bytes = 0;
        $visitors = [];
        $byStatus = ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0, 'other' => 0];
        $paths = [];
        $byDay = [];
        $skipped = 0;
        $lines = 0;

        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (++$lines > 100000) {
                break;
            }
            $m = [];
            if (!preg_match(
                '/^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+)\s+(\S+)[^"]*" (\d{3}) (\S+) "[^"]*" "[^"]*"( "([^"]*)")?/',
                $line,
                $m
            )) {
                $skipped++;
                continue;
            }

            $ip = $m[1];
            $time = $m[2];
            $url = $m[4];
            $status = $m[5];
            $byteStr = $m[6];
            $xff = $m[8] ?? '';

            $visitor = $ip;
            if ($xff !== '' && $xff !== '-') {
                $first = trim(explode(',', $xff)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) {
                    $visitor = $first;
                }
            }
            $visitors[$visitor] = true;

            $code = (int) $status;
            $bucket = $code >= 200 && $code < 300 ? '2xx'
                : ($code >= 300 && $code < 400 ? '3xx'
                : ($code >= 400 && $code < 500 ? '4xx'
                : ($code >= 500 && $code < 600 ? '5xx' : 'other')));
            $byStatus[$bucket]++;

            $n = $byteStr === '-' ? 0 : (int) $byteStr;
            $bytes += $n;
            $requests++;

            $path = explode('?', $url, 2)[0];
            if ($path === '') {
                $path = '/';
            }
            $paths[$path] = ($paths[$path] ?? 0) + 1;

            $dt = \DateTime::createFromFormat('d/M/Y:H:i:s O', $time);
            if ($dt !== false) {
                $day = $dt->format('Y-m-d');
                $byDay[$day] = $byDay[$day] ?? ['requests' => 0, 'bytes' => 0];
                $byDay[$day]['requests']++;
                $byDay[$day]['bytes'] += $n;
            }
        }

        arsort($paths);
        $topPaths = [];
        foreach (array_slice($paths, 0, 10, true) as $path => $hits) {
            $topPaths[] = ['path' => $path, 'hits' => $hits];
        }
        ksort($byDay);

        return [
            'requests' => $requests,
            'bytes' => $bytes,
            'visitors' => count($visitors),
            'skipped' => $skipped,
            'byStatus' => $byStatus,
            'topPaths' => $topPaths,
            'byDay' => $byDay,
            'files' => [],
        ];
    }

    /**
     * Candidate access-log paths for a website: the path baked into its stored
     * vhost config plus the front-door proxy convention.
     *
     * @return array<int,string>
     */
    public function logPaths(array $website): array
    {
        $paths = [];

        if (preg_match('/^\s*access_log\s+(\S+);/m', (string) ($website['nginx_config'] ?? ''), $m)) {
            $paths[] = $m[1];
        }

        $label = strtolower(trim((string) ($website['label'] ?? '')));
        if ($label !== '' && preg_match('/^[a-z0-9.-]+$/', $label)) {
            $paths[] = '/var/log/nginx/' . $label . '.access.log';
        }

        return array_values(array_unique($paths));
    }

    /**
     * Candidate error-log paths: front-door proxy convention first (it sees
     * live traffic on isolated accounts), then the stored vhost config path.
     *
     * @return array<int,string>
     */
    public function errorLogPaths(array $website): array
    {
        $paths = [];

        $label = strtolower(trim((string) ($website['label'] ?? '')));
        if ($label !== '' && preg_match('/^[a-z0-9.-]+$/', $label)) {
            $paths[] = '/var/log/nginx/' . $label . '.error.log';
        }

        if (preg_match('/^\s*error_log\s+(\S+);/m', (string) ($website['nginx_config'] ?? ''), $m)) {
            $paths[] = $m[1];
        }

        return array_values(array_unique($paths));
    }

    /**
     * Raw tail text for a website's access + error logs (first readable
     * candidate of each kind wins; unreadable/missing → empty string).
     *
     * @return array{access:string, error:string, accessFile:?string, errorFile:?string}
     */
    public function tailLogs(array $website, int $maxBytes = 65536): array
    {
        // Front-door convention first so live traffic is preferred.
        $accessPaths = array_reverse($this->logPaths($website));
        $out = ['access' => '', 'error' => '', 'accessFile' => null, 'errorFile' => null];

        foreach ($accessPaths as $path) {
            $text = $this->readLog($path, $maxBytes);
            if ($text !== null) {
                $out['access'] = $text;
                $out['accessFile'] = $path;
                break;
            }
        }
        foreach ($this->errorLogPaths($website) as $path) {
            $text = $this->readLog($path, $maxBytes);
            if ($text !== null) {
                $out['error'] = $text;
                $out['errorFile'] = $path;
                break;
            }
        }

        return $out;
    }

    private function readLog(string $path, int $maxBytes): ?string
    {
        // The agent kills log.read children over its 64 KiB output cap
        // (surfaces as `exit signal`), so clamp here — every caller
        // (stats, tails, downloads) degrades to a recent window instead of
        // dying. Callers passing larger windows get the newest 64 KiB.
        $maxBytes = min($maxBytes, 65536);
        try {
            $result = app('provisioning')->execute('log.read', [
                'path' => $path,
                'max_bytes' => $maxBytes,
            ]);
        } catch (\Throwable $e) {
            return null;
        }
        if (($result['status'] ?? '') !== 'success') {
            return null;
        }
        return (string) ($result['output'] ?? '');
    }

    /**
     * Merged traffic stats across a website's candidate log files.
     */
    public function forWebsite(array $website, int $maxBytes = 2097152): array
    {
        // Same 64 KiB agent cap as readLog(): larger windows kill the child
        // and yield silent empty stats, so clamp (stats cover the newest
        // window either way).
        $maxBytes = min($maxBytes, 65536);
        $merged = $this->emptyStats();
        $files = [];

        foreach ($this->logPaths($website) as $path) {
            try {
                $result = app('provisioning')->execute('log.read', [
                    'path' => $path,
                    'max_bytes' => $maxBytes,
                ]);
            } catch (\Throwable $e) {
                continue;
            }
            if (($result['status'] ?? '') !== 'success') {
                continue;
            }
            $files[] = $path;
            $merged = $this->merge($merged, self::parse((string) ($result['output'] ?? '')));
        }

        $merged['files'] = $files;
        return $merged;
    }

    /**
     * Per-website stats plus account totals.
     *
     * @return array{sites:array<int,array{label:string,stats:array}>, totals:array}
     */
    public function forAccount(int $accountId): array
    {
        $sites = [];
        $totals = $this->emptyStats();

        $websites = app('db')->select(
            'SELECT * FROM `websites` WHERE `account_id` = ? AND `status` = ? ORDER BY `label`',
            [$accountId, 'active']
        );

        foreach ($websites as $w) {
            try {
                $stats = $this->forWebsite($w);
            } catch (\Throwable $e) {
                $stats = $this->emptyStats();
            }
            $sites[] = ['label' => (string) ($w['label'] ?? ''), 'stats' => $stats];
            $totals = $this->merge($totals, $stats);
        }

        return ['sites' => $sites, 'totals' => $totals];
    }

    /** @return array */
    private function emptyStats(): array
    {
        return [
            'requests' => 0, 'bytes' => 0, 'visitors' => 0, 'skipped' => 0,
            'byStatus' => ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0, 'other' => 0],
            'topPaths' => [], 'byDay' => [], 'files' => [],
        ];
    }

    /** @param array $a @param array $b @return array */
    private function merge(array $a, array $b): array
    {
        $paths = [];
        foreach (array_merge($a['topPaths'], $b['topPaths']) as $row) {
            $paths[$row['path']] = ($paths[$row['path']] ?? 0) + $row['hits'];
        }
        arsort($paths);
        $topPaths = [];
        foreach (array_slice($paths, 0, 10, true) as $path => $hits) {
            $topPaths[] = ['path' => $path, 'hits' => $hits];
        }

        $byDay = $a['byDay'];
        foreach ($b['byDay'] as $day => $row) {
            $byDay[$day] = $byDay[$day] ?? ['requests' => 0, 'bytes' => 0];
            $byDay[$day]['requests'] += $row['requests'];
            $byDay[$day]['bytes'] += $row['bytes'];
        }
        ksort($byDay);

        // Visitor sets can't be merged exactly post-aggregation; the merged
        // count is the sum (an upper bound) — documented, not silently exact.
        return [
            'requests' => $a['requests'] + $b['requests'],
            'bytes' => $a['bytes'] + $b['bytes'],
            'visitors' => $a['visitors'] + $b['visitors'],
            'skipped' => $a['skipped'] + $b['skipped'],
            'byStatus' => [
                '2xx' => $a['byStatus']['2xx'] + $b['byStatus']['2xx'],
                '3xx' => $a['byStatus']['3xx'] + $b['byStatus']['3xx'],
                '4xx' => $a['byStatus']['4xx'] + $b['byStatus']['4xx'],
                '5xx' => $a['byStatus']['5xx'] + $b['byStatus']['5xx'],
                'other' => $a['byStatus']['other'] + $b['byStatus']['other'],
            ],
            'topPaths' => $topPaths,
            'byDay' => $byDay,
            'files' => array_values(array_unique(array_merge($a['files'], $b['files']))),
        ];
    }
}

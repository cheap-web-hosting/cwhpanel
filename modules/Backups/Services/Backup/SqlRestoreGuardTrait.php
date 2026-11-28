<?php

declare(strict_types=1);

namespace Modules\Backups\Services\Backup;

/**
 * Client-side SQL dump safety scanner for database restores.
 *
 * Mirror of the agent's validate_sql_dump: refuses dumps that manage accounts
 * or privileges, switch schemas, use client-only commands, or reference system
 * schemas / server-side files before handing the dump to the agent.
 */
trait SqlRestoreGuardTrait
{
    public static function assertSafeSqlDump(string $path, string $expectedDb): void
    {
        if (!is_file($path)) {
            throw new \RuntimeException('SQL dump not found for restore validation.');
        }
        $h = @fopen($path, 'rb');
        if ($h === false) {
            throw new \RuntimeException('Cannot open SQL dump for restore validation.');
        }

        $stmt = '';
        $trunc = false;
        $no = 0;
        $sq = $dq = $bt = $lc = $bc = false;
        $prev = '';
        $bad = null;

        while (($line = fgets($h, 65536)) !== false) {
            $len = strlen($line);
            for ($i = 0; $i < $len; $i++) {
                $ch = $line[$i];
                if (($sq || $dq || $bt) && $ch === '\\') {
                    continue;
                }
                if ($lc) {
                    if ($ch === "\n") { $lc = false; }
                    continue;
                }
                if ($bc) {
                    if ($ch === '/' && $prev === '*') { $bc = false; }
                    $prev = $ch;
                    continue;
                }
                if ($sq) {
                    if ($ch === "'") { $sq = false; }
                    $prev = $ch;
                    continue;
                }
                if ($dq) {
                    if ($ch === '"') { $dq = false; }
                    $prev = $ch;
                    continue;
                }
                if ($bt) {
                    if ($ch === '`') { $bt = false; }
                    else {
                        if (strlen($stmt) < 4096) {
                            $stmt .= $ch;
                        } elseif (strlen($stmt) === 4096) {
                            $trunc = true;
                        }
                    }
                    $prev = $ch;
                    continue;
                }
                if ($ch === "'") { $sq = true; $prev = $ch; continue; }
                if ($ch === '"') { $dq = true; $prev = $ch; continue; }
                if ($ch === '`') { $bt = true; $prev = $ch; continue; }
                if ($ch === '#') { $lc = true; $prev = $ch; continue; }
                if ($ch === '-' && $prev === '-') { $lc = true; $prev = ''; continue; }
                if ($ch === '*' && $prev === '/') { $bc = true; $prev = ''; continue; }
                if ($ch === ';') {
                    $no++;
                    if (($v = self::classifySqlStatement($stmt, $trunc, $no, $expectedDb)) !== null) {
                        $bad = $v;
                        break 2;
                    }
                    $stmt = '';
                    $trunc = false;
                    $prev = $ch;
                    continue;
                }
                if ($ch === '!' && $prev === '*' && substr($stmt, -2) === '/*') {
                    $bc = false;
                }
                if (strlen($stmt) < 4096) {
                    $stmt .= $ch;
                } elseif (strlen($stmt) === 4096) {
                    $trunc = true;
                }
                $prev = $ch;
            }
        }
        fclose($h);

        if ($bad === null) {
            $no++;
            $bad = self::classifySqlStatement($stmt, $trunc, $no, $expectedDb);
        }
        if ($bad !== null) {
            throw new \RuntimeException("Unsafe SQL dump: {$bad}");
        }
    }

    private static function classifySqlStatement(string $stmt, bool $trunc, int $no, string $expectedDb): ?string
    {
        $s = trim($stmt);
        if ($s === '') {
            return null;
        }
        if (strncmp($s, '/*!', 3) === 0) {
            $s = substr($s, 3);
            $s = ltrim($s, '0123456789');
        }
        $lower = strtolower($s);
        $t = ltrim($lower);
        if (str_starts_with($t, '\\') || str_starts_with($t, 'system ') || str_starts_with($t, 'source ')
            || $t === 'system' || $t === 'source' || str_starts_with($t, 'delimiter ')
        ) {
            return "statement {$no} uses a client-only command";
        }

        preg_match_all('/[a-z0-9_`]+/', $lower, $m);
        $tokens = array_values(array_filter($m[0], fn ($x) => $x !== ''));
        if ($tokens === []) {
            return null;
        }
        $a = $tokens[0];
        $b = $tokens[1] ?? '';

        if (in_array($a, ['grant', 'revoke', 'install', 'uninstall', 'flush', 'reset'], true)) {
            return "statement {$no} modifies server privileges/state";
        }
        if ((in_array($a, ['create', 'alter', 'drop', 'rename'], true) && $b === 'user')
            || ($a === 'create' && $b === 'role')
            || ($a === 'drop' && $b === 'role')
            || ($a === 'set' && $b === 'role')
            || ($a === 'set' && $b === 'password')
        ) {
            return "statement {$no} manages accounts/roles";
        }
        if ($a === 'use' && !self::dbNameMatches($b, $expectedDb)) {
            return "statement {$no} switches to another database";
        }
        // Server-wide state and dynamic SQL: a dump is data, not a script. The
        // import runs as the database superuser today, so these are refused
        // outright (SET @@session.* is still allowed — dumps use it).
        if ($a === 'set' && ($b === 'global' || $b === 'persist')) {
            return "statement {$no} changes global server settings";
        }
        if (in_array($a, ['prepare', 'execute', 'deallocate', 'handler'], true)) {
            return "statement {$no} uses dynamic SQL execution";
        }
        if ($a === 'load' && in_array($b, ['data', 'xml', 'index'], true)) {
            return "statement {$no} loads server-side files";
        }
        // Server-wide state and dynamic SQL: a dump is data, not a script. The
        // import runs as the database superuser today, so these are refused
        // outright (SET @@session.* is still allowed — dumps use it).
        if ($a === 'set' && ($b === 'global' || $b === 'persist')) {
            return "statement {$no} changes global server settings";
        }
        if (in_array($a, ['prepare', 'execute', 'deallocate', 'handler'], true)) {
            return "statement {$no} uses dynamic SQL execution";
        }
        if ($a === 'load' && in_array($b, ['data', 'xml', 'index'], true)) {
            return "statement {$no} loads server-side files";
        }
        if (($a === 'create' || $a === 'drop') && $b === 'database') {
            $c = $tokens[2] ?? '';
            if (!self::dbNameMatches($c, $expectedDb)) {
                return "statement {$no} affects another database";
            }
        }
        if (str_contains($lower, 'mysql.') || str_contains($lower, 'information_schema.')
            || str_contains($lower, 'performance_schema.') || str_contains($lower, 'sys.')
        ) {
            return "statement {$no} references a system schema";
        }
        if ($a === 'select') {
            $prevInto = false;
            foreach (array_slice($tokens, 1) as $tk) {
                if ($prevInto && in_array($tk, ['outfile', 'dumpfile'], true)) {
                    return "statement {$no} writes server-side files";
                }
                $prevInto = $tk === 'into';
                if ($tk === 'load_file') {
                    return "statement {$no} reads server-side files";
                }
            }
        }
        if ($trunc) {
            return "statement {$no} exceeds the scan limit";
        }
        return null;
    }

    private static function dbNameMatches(string $tok, string $expected): bool
    {
        $t = trim($tok, "`'\"");
        $base = explode('.', $t)[0];
        return strcasecmp($base, $expected) === 0;
    }
}

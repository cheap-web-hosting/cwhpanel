<?php

declare(strict_types=1);

namespace Modules\Backups\Services\Backup;

/**
 * Client-side SQL dump safety scanner for database restores.
 *
 * Mirror of the agent's `validate_sql_dump` (agent/src/ops/validators.rs).
 * This is a cheap fail-closed pre-check; the agent re-parses the dump with an
 * authoritative scanner, rewrites every DEFINER clause, and imports as the
 * account's own database user. The scanner understands:
 *
 *  - single/double-quoted strings and backtick identifiers (with escapes),
 *  - `#`, `-- ` (whitespace required, like MySQL) and block comments,
 *  - executable comments `/*! ... *\/` and `/*M! ... *\/` whose content is
 *    real SQL (a payload hidden in one must not slip past validation).
 *
 * Statements larger than 16 MiB are refused. Real mysqldump extended inserts
 * are bounded by `--net-buffer-length` (1 MiB by default), so the cap only
 * trips on adversarial input.
 */
trait SqlRestoreGuardTrait
{
    private const SQL_SCAN_CAP = 16 * 1024 * 1024;
    private const SQL_TEXT_HEAD = 262_144;
    private const SQL_RAW_PREFIX = 65_536;

    public static function assertSafeSqlDump(string $path, string $expectedDb): void
    {
        if (!is_file($path)) {
            throw new \RuntimeException('SQL dump not found for restore validation.');
        }
        $h = @fopen($path, 'rb');
        if ($h === false) {
            throw new \RuntimeException('Cannot open SQL dump for restore validation.');
        }

        $sq = $dq = $bt = $lc = $bc = $ec = false;
        $delimDouble = false;
        $view = '';
        $raw = '';
        $no = 0;

        try {
            while (($line = fgets($h, 1048576)) !== false) {
                // mysqldump wraps stored programs in `DELIMITER ;;` ...
                // `DELIMITER ;`. The mysql client interprets those lines.
                if (!$sq && !$dq && !$bt && !$bc && !$ec && trim($view) === '') {
                    $mode = self::parseDelimiterLine($line);
                    if ($mode !== null) {
                        $delimDouble = $mode;
                        continue;
                    }
                }
                $len = strlen($line);
                $i = 0;
                while ($i < $len) {
                    $ch = $line[$i];
                    $next = ($i + 1 < $len) ? $line[$i + 1] : '';
                    if (strlen($raw) < self::SQL_RAW_PREFIX) {
                        $raw .= $ch;
                    }

                    // ---- line comment -----------------------------------
                    if ($lc) {
                        if ($ch === "\n") {
                            $lc = false;
                            $view .= ' ';
                        }
                        $i++;
                        continue;
                    }

                    // ---- quoted contexts --------------------------------
                    if ($sq || $dq || $bt) {
                        $quote = $sq ? "'" : ($dq ? '"' : '`');
                        $backslash = $sq || $dq;
                        if ($backslash && $ch === '\\') {
                            $i += 2;
                            continue;
                        }
                        if ($ch === $quote) {
                            if ($next === $quote) {
                                $i += 2;
                                continue;
                            }
                            $sq = $dq = $bt = false;
                            $view .= ' ';
                            $i++;
                            continue;
                        }
                        if ($bt) {
                            $view .= $ch;
                        }
                        $i++;
                        continue;
                    }

                    // ---- regular block comment --------------------------
                    if ($bc) {
                        if ($ch === '*' && $next === '/') {
                            $bc = false;
                            $i += 2;
                            continue;
                        }
                        $i++;
                        continue;
                    }

                    // ---- executable comment -----------------------------
                    if ($ec && $ch === '*' && $next === '/') {
                        $ec = false;
                        $view .= ' ';
                        $i += 2;
                        continue;
                    }

                    // ---- code -------------------------------------------
                    if ($ch === '/' && $next === '*') {
                        $third = ($i + 2 < $len) ? $line[$i + 2] : '';
                        $fourth = ($i + 3 < $len) ? $line[$i + 3] : '';
                        $maria = ($third === 'M' && $fourth === '!');
                        if ($third === '!' || $maria) {
                            // Skip `/*!` or `/*M!` plus the optional version
                            // digits (comment machinery, not SQL).
                            $i += $maria ? 4 : 3;
                            $digits = '';
                            while ($i < $len && ctype_digit($line[$i])) {
                                $digits .= $line[$i];
                                $i++;
                            }
                            // MariaDB dump header: `/*M!999999\- enable the
                            // sandbox mode */`. Version 999999 is above any
                            // server, so the server ignores the comment and
                            // `\-` is a client directive — treat it as a
                            // normal skipped comment.
                            if ($digits === '999999' && substr($line, $i, 2) === '\\-') {
                                $bc = true;
                                continue;
                            }
                            $ec = true;
                            $view .= ' ';
                            continue;
                        }
                        $bc = true;
                        $view .= ' ';
                        $i += 2;
                        continue;
                    }
                    if ($ch === '#') {
                        $lc = true;
                        $view .= ' ';
                        $i++;
                        continue;
                    }
                    if ($ch === '-' && $next === '-') {
                        // MySQL starts a `--` comment only when the second
                        // dash is followed by whitespace/control or EOF.
                        // `1--1` is arithmetic; treating it as a comment would
                        // hide every later statement from validation.
                        $third = ($i + 2 < $len) ? $line[$i + 2] : '';
                        if ($third === '' || ord($third) <= 0x20) {
                            $lc = true;
                            $view .= ' ';
                            $i += 2;
                            continue;
                        }
                    }
                    if ($ch === "'") { $sq = true; $view .= ' '; $i++; continue; }
                    if ($ch === '"') { $dq = true; $view .= ' '; $i++; continue; }
                    if ($ch === '`') { $bt = true; $view .= ' '; $i++; continue; }

                    if (strlen($view) > self::SQL_SCAN_CAP) {
                        throw new \RuntimeException('Unsafe SQL dump: statement exceeds the scan limit');
                    }
                    if ($ch === ';') {
                        // Inside `DELIMITER ;;` a single semicolon belongs to
                        // the stored-program body; only a doubled one ends it.
                        if ($delimDouble && $next !== ';') {
                            $view .= $ch;
                            $i++;
                            continue;
                        }
                        $no++;
                        if (($v = self::classifySqlStatement($view, $raw, $expectedDb, $no)) !== null) {
                            throw new \RuntimeException("Unsafe SQL dump: {$v}");
                        }
                        $view = '';
                        $raw = '';
                        $i += $delimDouble ? 2 : 1;
                        continue;
                    }

                    $view .= $ch;
                    $i++;
                }
            }
        } finally {
            fclose($h);
        }

        $no++;
        if (($v = self::classifySqlStatement($view, $raw, $expectedDb, $no)) !== null) {
            throw new \RuntimeException("Unsafe SQL dump: {$v}");
        }
    }

    private static function classifySqlStatement(string $view, string $raw, string $expectedDb, int $no): ?string
    {
        $s = trim($view);
        if ($s === '') {
            return null;
        }
        $lower = strtolower($s);
        $t = ltrim($lower);

        if (str_starts_with($t, '\\') || str_starts_with($t, 'system ') || str_starts_with($t, 'source ')
            || $t === 'system' || $t === 'source' || str_starts_with($t, 'delimiter ')
        ) {
            return "statement {$no} uses a client-only command";
        }

        preg_match_all('/[a-z0-9_`]+/', substr($lower, 0, self::SQL_TEXT_HEAD), $m);
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
        if ($a === 'set' && ($b === 'global' || $b === 'persist')) {
            return "statement {$no} changes global server settings";
        }
        if (in_array($a, ['prepare', 'execute', 'deallocate', 'handler'], true)) {
            return "statement {$no} uses dynamic SQL execution";
        }
        if ($a === 'load' && in_array($b, ['data', 'xml', 'index'], true)) {
            return "statement {$no} loads server-side files";
        }
        if ($a === 'use' && !self::dbNameMatches($b, $expectedDb)) {
            return "statement {$no} switches to another database";
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
        // sql_mode changes can alter how the rest of the dump is parsed. The
        // value is a string literal, so inspect the raw prefix.
        $rawLower = strtolower($raw);
        if ($a === 'set' && str_contains($rawLower, 'sql_mode')
            && (str_contains($rawLower, 'no_backslash_escapes') || str_contains($rawLower, 'ansi_quotes'))
        ) {
            return "statement {$no} changes the SQL parsing mode";
        }
        if ($a === 'select'
            && (str_contains($lower, 'outfile') || str_contains($lower, 'dumpfile') || str_contains($lower, 'load_file'))
        ) {
            return "statement {$no} writes/reads server-side files";
        }
        return null;
    }

    /**
     * mysql-client DELIMITER line: null when the line is not a directive,
     * true for `DELIMITER ;;`, false for `DELIMITER ;`. Other delimiters are
     * refused (only mysqldump's forms are supported).
     */
    private static function parseDelimiterLine(string $line): ?bool
    {
        $line = trim($line);
        if (stripos($line, 'delimiter') !== 0) {
            return null;
        }
        $rest = substr($line, strlen('delimiter'));
        if ($rest === '' || !preg_match('/^\s/', $rest)) {
            return null;
        }
        $token = trim($rest);
        if ($token === ';;') {
            return true;
        }
        if ($token === ';') {
            return false;
        }
        throw new \RuntimeException('Unsafe SQL dump: unsupported DELIMITER line');
    }

    private static function dbNameMatches(string $tok, string $expected): bool
    {
        $t = trim($tok, "`'\"");
        $base = explode('.', $t)[0];
        return strcasecmp($base, $expected) === 0;
    }
}

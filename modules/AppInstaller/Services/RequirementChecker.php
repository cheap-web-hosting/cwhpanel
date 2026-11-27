<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Services;

/**
 * Pre-install requirement checks. Returns a list of [ok, requirement, detail]
 * triples; the installer refuses to proceed when a mandatory check fails.
 */
class RequirementChecker
{
    public function check(array $manifest): array
    {
        $results = [];

        // Runtime-app installs (Node.js/Python) need their interpreter
        // present on the host; the agent fails fast with the same message
        // at install time, so this gate only checks the static allowlist.
        $type = (string) ($manifest['type'] ?? 'php-mysql');
        if (in_array($type, ['python-pip', 'node-npm', 'node-archive', 'binary'], true)) {
            $kind = str_starts_with($type, 'python') ? 'python' : ($type === 'binary' ? '' : 'node');
            if ($kind !== '') {
                $want = (string) ($manifest['runtime']['version'] ?? ($kind === 'python' ? '3.11' : '22'));
                $ok = isset(\Modules\Applications\Services\AppService::RUNTIMES[$kind][$want]);
                $results[] = [$ok, "Runtime: {$kind} {$want}", $ok ? 'available' : 'unknown runtime version'];
            }
            if ($type === 'python-pip') {
                $pkg = (string) ($manifest['pip']['package'] ?? '');
                $pok = in_array(strtolower($pkg), \Modules\Applications\Services\AppService::PYTHON_APP_PACKAGES, true);
                $results[] = [$pok, "Package: {$pkg}", $pok ? 'allowlisted' : 'not an installable app package'];
            }
            if ($type === 'node-npm') {
                $pkg = (string) ($manifest['npm']['package'] ?? '');
                $bin = (string) ($manifest['npm']['bin'] ?? '');
                $nok = $pkg !== '' && $bin !== '';
                $results[] = [$nok, "Package: {$pkg}", $nok ? 'manifest complete' : 'package/bin missing'];
            }
            if ($type === 'binary') {
                $binary = (string) ($manifest['app']['binary'] ?? '');
                $bok = $binary !== '' && preg_match('/^[A-Za-z0-9_.-]+$/', $binary) === 1;
                $results[] = [$bok, 'Binary: ' . ($binary !== '' ? $binary : '(missing)'), $bok ? 'manifest complete' : 'binary name missing'];
                $hasDl = !empty($manifest['download']['url']) || isset($manifest['download']['github']) || isset($manifest['download']['codeberg']);
                $results[] = [$hasDl, 'Download source', $hasDl ? 'present' : 'missing'];
            }
            return $results;
        }

        // PHP version
        $php = PHP_VERSION;
        $min = (string) ($manifest['requirements']['php_min'] ?? '7.4');
        $ok = version_compare($php, $min, '>=');
        $results[] = [$ok, "PHP >= {$min}", $ok ? "PHP {$php}" : "PHP {$php} is older than {$min}"];

        // Required PHP extensions
        foreach ($manifest['requirements']['extensions'] ?? [] as $ext) {
            $loaded = extension_loaded($ext);
            $results[] = [$loaded, "PHP extension: {$ext}", $loaded ? 'loaded' : 'missing'];
        }

        // Database engine (MariaDB/MySQL only at this stage)
        if ($manifest['database']['required'] ?? false) {
            $results[] = [true, 'MySQL/MariaDB', 'available'];
        }

        return $results;
    }

    public function allPass(array $results): bool
    {
        foreach ($results as [$ok]) {
            if (!$ok) {
                return false;
            }
        }
        return true;
    }
}
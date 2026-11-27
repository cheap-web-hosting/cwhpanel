#!/usr/bin/env php
<?php
/**
 * Verify a signed release artifact against the pinned release key.
 *
 * Usage: php scripts/verify-release.php <version> <tarball>
 *
 * Checks, fail-closed:
 *   1. pinned public key exists and its fingerprint matches the pinned file
 *   2. clearsigned digest (.sha256.asc) verifies under that key
 *   3. digest content matches the actual sha256 of the tarball
 *   4. detached signature (.asc) verifies under that key
 *
 * Exit 0 when every check passes; non-zero + message otherwise.
 */

require __DIR__ . '/_bootstrap.php';

use App\Foundation\Application;

$app = new Application(BASE_PATH);
$app->bootstrap();

if ($argc < 3) {
    fwrite(STDERR, "usage: verify-release.php <version> <tarball>\n");
    exit(2);
}
$version = $argv[1];
$tarball = realpath($argv[2]);
if ($tarball === false || !is_file($tarball)) {
    fwrite(STDERR, "tarball not found: {$argv[2]}\n");
    exit(2);
}

$pinnedKey = BASE_PATH . '/config/release-key.asc';
$pinnedFpr = trim((string) @file_get_contents(BASE_PATH . '/config/release-key.fingerprint'));
if (!is_file($pinnedKey) || $pinnedFpr === '' || !preg_match('/^[0-9A-F]{40}$/i', $pinnedFpr)) {
    fwrite(STDERR, "pinned release key missing/invalid\n");
    exit(1);
}

$shaFile = $tarball . '.sha256';
$shaAsc = $tarball . '.sha256.asc';
$detached = $tarball . '.asc';
foreach ([$shaFile, $shaAsc, $detached] as $f) {
    if (!is_file($f)) {
        fwrite(STDERR, "missing artifact: {$f}\n");
        exit(1);
    }
}

// Isolated keyring: import ONLY the pinned public key, then verify against it.
$gnupgHome = BASE_PATH . '/storage/tmp/release-verify-' . getmypid();
@mkdir($gnupgHome, 0700, true);
$cleanup = function () use ($gnupgHome): void {
    foreach ((array) glob($gnupgHome . '/*') as $f) {
        @unlink($f);
    }
    @rmdir($gnupgHome);
};

$gpgCheck = function (array $args, string $what) use ($gnupgHome, $cleanup): void {
    $cmd = array_merge(['gpg', '--homedir', $gnupgHome, '--batch', '--no-tty', '--status-fd', '1'], $args);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        $cleanup();
        throw new RuntimeException('failed to launch gpg');
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0 || !str_contains($out, '[GNUPG:] GOODSIG')) {
        $cleanup();
        throw new RuntimeException("{$what}: bad signature (gpg exit {$code}): " . trim($err));
    }
};

try {
    // 1. fingerprint pin parity
    $imported = [];
    exec('gpg --homedir ' . escapeshellarg($gnupgHome) . ' --batch --import ' . escapeshellarg($pinnedKey) . ' 2>&1', $imported);
    $fprLine = '';
    exec('gpg --homedir ' . escapeshellarg($gnupgHome) . ' --batch --with-colons --list-keys 2>/dev/null | awk -F: \'$1=="fpr"{print $10; exit}\'', $fprLine);
    $keyFpr = trim(implode('', $fprLine));
    if (strtoupper($keyFpr) !== strtoupper($pinnedFpr)) {
        throw new RuntimeException('pinned fingerprint mismatch: key=' . $keyFpr . ' expected=' . $pinnedFpr);
    }

    // 2. clearsigned digest verifies
    $gpgCheck(['--verify', $shaAsc], 'clearsigned digest');

    // 3. digest content matches the tarball
    $line = trim((string) @file_get_contents($shaFile));
    if (!preg_match('/^([0-9a-f]{64})\s+(\S+)$/', $line, $m)) {
        throw new RuntimeException('digest file malformed');
    }
    $actual = hash_file('sha256', $tarball);
    if ($actual === false || strtolower($actual) !== strtolower($m[1])) {
        throw new RuntimeException('digest mismatch: file says ' . $m[1] . ', computed ' . $actual);
    }

    // 4. detached signature of the tarball itself
    $gpgCheck(['--verify', $detached, $tarball], 'detached signature');
} catch (Throwable $e) {
    $cleanup();
    fwrite(STDERR, 'VERIFY FAILED for v' . $version . ': ' . $e->getMessage() . "\n");
    exit(1);
}

$cleanup();
echo "VERIFY OK for v{$version}: fingerprint={$keyFpr} digest=sha256 detached=sig\n";

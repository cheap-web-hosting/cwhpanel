#!/usr/bin/env php
<?php
/**
 * Rotate the agent trust-model ticket keys.
 *
 *   php scripts/rotate-agent-ticket-keys.php            # rotate both keys
 *   php scripts/rotate-agent-ticket-keys.php --drop-old # drop the grace keys
 *
 * Rotation: generates fresh CWH_PANEL_ADMIN_KEY / CWH_PANEL_CUSTOMER_KEY
 * values in the panel .env (the mint side), then moves the current agent
 * verification keys into CWH_AGENT_*_KEY_OLD (grace window) and installs the
 * new ones as CWH_AGENT_*_KEY, and restarts the agent. The agent accepts a
 * ticket signed by ANY of its current keys, so tickets minted just before the
 * rotation keep verifying until the old keys are dropped (--drop-old).
 *
 * Safety: refuses when the .env or the agent override are missing; backs up
 * the override before writing; verifies the agent restarts active.
 */

declare(strict_types=1);

$ENV = getenv('PANEL_ENV') ?: '/opt/cwhpanel/.env';
$OVERRIDE = '/etc/systemd/system/cwhpanel-agent.service.d/override.conf';

$dropOld = in_array('--drop-old', $argv, true);

if (!is_file($ENV)) {
    fwrite(STDERR, "ENV file not found: $ENV\n");
    exit(1);
}
if (!is_file($OVERRIDE)) {
    fwrite(STDERR, "Agent override not found: $OVERRIDE\n");
    exit(1);
}

function readEnv(string $path): array
{
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $out[trim($k)] = trim($v);
    }
    return $out;
}

function writeEnv(string $path, array $values): void
{
    $lines = [];
    $seen = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            $lines[] = $line;
            continue;
        }
        [$k] = explode('=', $line, 2);
        $k = trim($k);
        if (isset($values[$k])) {
            $lines[] = "{$k}={$values[$k]}";
            $seen[$k] = true;
        } else {
            $lines[] = $line;
        }
    }
    foreach ($values as $k => $v) {
        if (!isset($seen[$k])) {
            $lines[] = "{$k}={$v}";
        }
    }
    file_put_contents($path, implode("\n", $lines) . "\n");
}

function newKey(): string
{
    return substr(strtr(base64_encode(random_bytes(32)), '+/', '-_'), 0, 44);
}

$env = readEnv($ENV);

if ($dropOld) {
    $override = (string) file_get_contents($OVERRIDE);
    $updated = preg_replace('/^Environment=CWH_AGENT_(ADMIN|CUSTOMER)_KEY_OLD=.*$/m', '', $override);
    if ($updated === $override) {
        echo "No old keys to drop.\n";
        exit(0);
    }
    file_put_contents($OVERRIDE . '.pre-drop-old', $override);
    file_put_contents($OVERRIDE, $updated);
    shell_exec('systemctl daemon-reload && systemctl restart cwhpanel-agent 2>&1');
    echo "Dropped old ticket keys; agent restarted.\n";
    exit(0);
}

$missing = [];
foreach (['CWH_PANEL_ADMIN_KEY', 'CWH_PANEL_CUSTOMER_KEY'] as $var) {
    if (empty($env[$var])) {
        $missing[] = $var;
    }
}
if ($missing !== []) {
    fwrite(STDERR, "Missing keys in $ENV: " . implode(', ', $missing) . " (run install.sh first)\n");
    exit(1);
}

$new = [
    'CWH_PANEL_ADMIN_KEY' => newKey(),
    'CWH_PANEL_CUSTOMER_KEY' => newKey(),
];

// 1. Update the panel .env (mint side). PHP reads it on every request.
$oldEnv = $env;
writeEnv($ENV, $new);

// 2. Update the agent override: current -> _OLD, new -> current.
$override = (string) file_get_contents($OVERRIDE);
foreach ([['ADMIN', 'CWH_PANEL_ADMIN_KEY'], ['CUSTOMER', 'CWH_PANEL_CUSTOMER_KEY']] as [$role, $envVar]) {
    $old = $oldEnv[$envVar] ?? '';
    $fresh = $new[$envVar];
    $override = preg_replace(
        "/^Environment=CWH_AGENT_{$role}_KEY=.*$/m",
        "Environment=CWH_AGENT_{$role}_KEY={$fresh}" . ($old !== '' ? "\nEnvironment=CWH_AGENT_{$role}_KEY_OLD={$old}" : ''),
        $override,
        1
    );
}
file_put_contents($OVERRIDE . '.pre-rotate', (string) file_get_contents($OVERRIDE));
file_put_contents($OVERRIDE, $override);

// 3. Restart the agent and verify.
$out = shell_exec('systemctl daemon-reload && systemctl restart cwhpanel-agent 2>&1');
sleep(1);
$active = trim((string) shell_exec('systemctl is-active cwhpanel-agent'));
if ($active !== 'active') {
    fwrite(STDERR, "Agent did not come back active ($active); restore $OVERRIDE.pre-rotate and restart.\n");
    exit(1);
}

echo "Rotated ticket keys. New keys installed; old keys kept as grace (_OLD).\n";
echo "Drop the old keys after the grace window (tickets are 45s TTL):\n";
echo "  php scripts/rotate-agent-ticket-keys.php --drop-old\n";
exit(0);

<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use App\Foundation\Application;

$app = new Application(BASE_PATH);
$app->bootstrap();

$db = app('db');
$manager = $app->moduleManager();

echo "Syncing permissions...\n";
foreach ($manager->permissions() as $name => $info) {
    $db->statement(
        'INSERT INTO `permissions` (`name`, `module`, `created_at`, `updated_at`)
         VALUES (?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE `module` = VALUES(`module`), `updated_at` = NOW()',
        [$name, $info['module']]
    );
}

echo "Seeding roles...\n";
$roles = [
    ['name' => 'Super Administrator', 'slug' => 'superadmin', 'is_system' => 1],
    ['name' => 'Administrator', 'slug' => 'admin', 'is_system' => 1],
    ['name' => 'Customer', 'slug' => 'customer', 'is_system' => 1],
];

$roleIds = [];
foreach ($roles as $role) {
    $existing = $db->selectOne('SELECT `id` FROM `roles` WHERE `slug` = ?', [$role['slug']]);

    if ($existing) {
        $roleIds[$role['slug']] = (int) $existing['id'];
    } else {
        $roleIds[$role['slug']] = $db->insert('roles', [
            'name' => $role['name'],
            'slug' => $role['slug'],
            'is_system' => $role['is_system'],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

echo "Assigning role permissions...\n";
$allPermissionIds = array_column(
    $db->select('SELECT `id` FROM `permissions`'),
    'id'
);

$customerWanted = ['dashboard.view', 'account.profile.view', 'account.profile.edit'];
$placeholders = implode(',', array_fill(0, count($customerWanted), '?'));
$customerPermissionIds = array_column(
    $db->select("SELECT `id` FROM `permissions` WHERE `name` IN ({$placeholders})", $customerWanted),
    'id'
);

$assignments = [
    'superadmin' => $allPermissionIds,
    'admin' => $allPermissionIds,
    'customer' => $customerPermissionIds,
];

$db->transaction(function () use ($db, $roleIds, $assignments) {
    foreach ($assignments as $slug => $permissionIds) {
        $roleId = $roleIds[$slug] ?? null;
        if ($roleId === null) continue;

        $db->delete('role_permissions', '`role_id` = ?', [$roleId]);

        foreach ($permissionIds as $permissionId) {
            $db->statement(
                'INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`) VALUES (?, ?)',
                [$roleId, $permissionId]
            );
        }
    }
});

echo "Seeding administrator...\n";
$adminEmail = env('ADMIN_EMAIL', 'admin@example.com');
$adminPassword = env('ADMIN_PASSWORD', '');
$adminPasswordGenerated = false;

if ($adminPassword === '') {
    $adminPassword = bin2hex(random_bytes(16));
    $adminPasswordGenerated = true;
}

$adminExists = $db->selectOne('SELECT `id` FROM `users` WHERE `email` = ?', [$adminEmail]);

if ($adminExists) {
    $adminUserId = (int) $adminExists['id'];
    if ($adminPasswordGenerated) {
        // The admin may have been created by a previous install while .env lost
        // the password (or never had one). Rotate and persist so the documented
        // "log in with ADMIN_EMAIL/ADMIN_PASSWORD from .env" is always true.
        $db->update('users', ['password' => hash_password($adminPassword)], '`id` = ?', [$adminUserId]);
        $envFile = BASE_PATH . '/.env';
        if (is_file($envFile) && is_writable($envFile)) {
            $contents = (string) file_get_contents($envFile);
            if (preg_match('/^ADMIN_PASSWORD=.*$/m', $contents)) {
                $contents = preg_replace('/^ADMIN_PASSWORD=.*$/m', 'ADMIN_PASSWORD=' . $adminPassword, $contents);
            } else {
                $contents = rtrim($contents, "\n") . "\nADMIN_PASSWORD={$adminPassword}\n";
            }
            file_put_contents($envFile, $contents, LOCK_EX);
        }
        echo "  Administrator [{$adminEmail}] already exists — password rotated and written to .env (ADMIN_PASSWORD).\n";
    } else {
        echo "  Administrator [{$adminEmail}] already exists (skipping).\n";
    }
} else {
    $hash = hash_password($adminPassword);

    $db->insert('users', [
        'email' => $adminEmail,
        'password' => $hash,
        'name' => 'Administrator',
        'role_id' => $roleIds['superadmin'],
        'is_admin' => 1,
        'status' => 'active',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    echo "  Administrator created: {$adminEmail}\n";
    if ($adminPasswordGenerated) {
        // Persist the generated password: printing it in an install log is
        // too easy to lose (logs get captured by CI/journald), and a fresh
        // production box must be sign-in-able from .env like every other
        // secret the installer writes.
        $envFile = BASE_PATH . '/.env';
        $written = false;
        if (is_file($envFile) && is_writable($envFile)) {
            $contents = (string) file_get_contents($envFile);
            if (preg_match('/^ADMIN_PASSWORD=.*$/m', $contents)) {
                $contents = preg_replace('/^ADMIN_PASSWORD=.*$/m', 'ADMIN_PASSWORD=' . $adminPassword, $contents);
            } else {
                $contents = rtrim($contents, "\n") . "\nADMIN_PASSWORD={$adminPassword}\n";
            }
            $written = file_put_contents($envFile, $contents, LOCK_EX) !== false;
        }
        echo $written
            ? "  Password written to .env (ADMIN_PASSWORD)\n"
            : "  Password: {$adminPassword}\n";
    }
}

// Demo content is TEST DATA. Production installs must start clean: create it
// only when explicitly requested (CI/e2e use SEED_DEMO=1 or --with-demo).
$seedDemo = getenv('SEED_DEMO') === '1' || in_array('--with-demo', $argv ?? [], true);
if (!$seedDemo) {
    echo "Demo content skipped (set SEED_DEMO=1 or --with-demo to create the demo customer/package/account).\n";
} else {
    echo "Seeding demo customer...\n";
    $customerEmail = env('CUSTOMER_EMAIL', 'customer@example.com');
    $customerPassword = env('CUSTOMER_PASSWORD', '');
    $passwordFromEnv = $customerPassword !== '';

    if ($customerPassword === '') {
        $customerPassword = bin2hex(random_bytes(8));
    }

    $customerExists = $db->selectOne('SELECT `id` FROM `users` WHERE `email` = ?', [$customerEmail]);

    if ($customerExists) {
        echo "  Customer [{$customerEmail}] already exists (skipping).\n";
        $customerUserId = (int) $customerExists['id'];
        // The env password may be missing (fresh re-seed, wiped .env): without
        // it every customer HTTP suite and the docs are useless. Rotate and
        // persist so .env always matches the database.
        if (!$passwordFromEnv) {
            $customerPassword = bin2hex(random_bytes(8));
            $db->update('users', ['password' => hash_password($customerPassword)], '`id` = ?', [$customerUserId]);
            $envPath = BASE_PATH . '/.env';
            if (is_file($envPath) && is_writable($envPath)) {
                $contents = (string) file_get_contents($envPath);
                if (preg_match('/^CUSTOMER_PASSWORD=.*$/m', $contents)) {
                    $contents = preg_replace('/^CUSTOMER_PASSWORD=.*$/m', 'CUSTOMER_PASSWORD=' . $customerPassword, $contents);
                } else {
                    $contents = rtrim($contents, "\n") . "\nCUSTOMER_PASSWORD={$customerPassword}\n";
                }
                file_put_contents($envPath, $contents, LOCK_EX);
            }
            echo "  Customer password rotated and written to .env (CUSTOMER_PASSWORD).\n";
        }
    } else {
        $hash = hash_password($customerPassword);

        $customerUserId = $db->insert('users', [
            'email' => $customerEmail,
            'password' => $hash,
            'name' => 'Demo Customer',
            'role_id' => $roleIds['customer'],
            'is_admin' => 0,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        echo "  Customer created: {$customerEmail}\n";
        // Persist the generated password too: printing it in an install log is
        // too easy to lose (and the docs/suites read CUSTOMER_PASSWORD).
        if (!$passwordFromEnv) {
            $envPath = BASE_PATH . '/.env';
            if (is_file($envPath) && is_writable($envPath)) {
                $contents = (string) file_get_contents($envPath);
                if (preg_match('/^CUSTOMER_PASSWORD=.*$/m', $contents)) {
                    $contents = preg_replace('/^CUSTOMER_PASSWORD=.*$/m', 'CUSTOMER_PASSWORD=' . $customerPassword, $contents);
                } else {
                    $contents = rtrim($contents, "\n") . "\nCUSTOMER_PASSWORD={$customerPassword}\n";
                }
                file_put_contents($envPath, $contents, LOCK_EX);
                echo "  Password written to .env (CUSTOMER_PASSWORD)\n";
            } else {
                echo "  Password: {$customerPassword}\n";
            }
        } else {
            echo "  Password: (from .env)\n";
        }
    }

    echo "Seeding demo package...\n";
    $demoPackage = $db->selectOne('SELECT `id` FROM `packages` WHERE `slug` = ?', ['starter']);

    if ($demoPackage) {
        $demoPackageId = (int) $demoPackage['id'];
        echo "  Package [starter] already exists (skipping).\n";
    } else {
        $demoPackageId = $db->insert('packages', [
            'name' => 'Starter',
            'slug' => 'starter',
            'description' => 'Entry-level shared hosting package.',
            'disk_quota_mb' => 10240,
            'bandwidth_mb' => 102400,
            'max_domains' => 5,
            'max_subdomains' => 20,
            'max_databases' => 5,
            'max_email_accounts' => 25,
            'max_ftp_accounts' => 10,
            'max_cron_jobs' => 10,
            'max_nodejs_apps' => 1,
            'max_python_apps' => 1,
            'max_cpu_percent' => 100,
            'max_memory_mb' => 1024,
            'max_processes' => 100,
            'is_active' => 1,
            'is_system' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        echo "  Package created: Starter\n";
    }

    // New packages created in the UI get explicit feature rows (PackageController);
    // the seeded package needs the same baseline or gating fail-opens. Only fill
    // packages with zero rows — never clobber an admin-customized set.
    $featRows = $db->selectOne('SELECT `id` FROM `package_features` WHERE `package_id` = ?', [$demoPackageId]);
    if ($featRows === null) {
        app(\Modules\Packages\Services\FeatureService::class)->setForPackage($demoPackageId, \Modules\Packages\Services\FeatureService::keys());
        echo "  Package features backfilled (all on).\n";
    }

    echo "Linking customer to a hosting account...\n";
    $accountExists = $db->selectOne('SELECT `id` FROM `accounts` WHERE `user_id` = ?', [$customerUserId]);

    if ($accountExists) {
        echo "  Account for customer already exists (skipping).\n";
    } else {
        $accountId = $db->insert('accounts', [
            'user_id' => $customerUserId,
            'username' => 'demo' . substr((string) $customerUserId, -4),
            'domain' => 'demo.example.com',
            'package_id' => $demoPackageId,
            'status' => 'active',
            'home_dir' => '/home/demo' . substr((string) $customerUserId, -4),
            'email' => $customerEmail,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        echo "  Account created for customer.\n";
    }

}

echo "Done.\n";
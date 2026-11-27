#!/usr/bin/php
<?php
/**
 * cwhpanel Matomo unattended installer helper.
 *
 * Runs as the hosting account user (via runuser from the privileged agent):
 *   php mt-install.php /home/<user>[/<subdir>]
 *
 * Data travels via environment (no shell interpolation anywhere):
 *   MT_DB_HOST  database host (127.0.0.1 or localhost)
 *   MT_DB_NAME  database name ([A-Za-z0-9_], 1-64)
 *   MT_DB_USER  database user ([A-Za-z0-9_], 1-32)
 *   MT_DB_PASS  database password (1-256 chars, no NUL/newlines)
 *   MT_PREFIX   table prefix ([a-z0-9_], 1-16)
 *   MT_USER     superuser login ([A-Za-z0-9_.-], 2-100)
 *   MT_PASS     superuser password (8-256 chars, no NUL/newlines)
 *   MT_EMAIL    superuser email (valid address, <=254)
 *   MT_TITLE    first website name (1-200 chars, no NUL/newlines)
 *   MT_URL      first website URL (http(s)://host[/path], <=255)
 *   MT_DOMAIN   trusted host (bare hostname, <=253)
 *
 * Replicates the Installation plugin's controller steps with Matomo's own
 * classes (same calls, minus the newsletter signup and the sysadmin mail):
 *   config write (database + salt + trusted host, installation_in_progress)
 *   → createTables + anonymous user + install-version record + component
 *   updates → superuser → first website → security files → mark completed.
 *
 * Resumable: every stage is guarded, so a retry after a crash only runs
 * the missing stages. A fully installed tree prints ALREADY and exits 0.
 *
 * Exit codes: 0 ok (INSTALLED or ALREADY), 1 usage/validation, 2 failure.
 */

declare(strict_types=1);

function fail(int $code, string $msg): never
{
    fwrite(STDERR, $msg . "\n");
    exit($code);
}

/**
 * Flatten an exception chain (Matomo wraps API failures in rendering
 * exceptions that hide the root cause — walk getPrevious()).
 */
function stageError(Throwable $e): string
{
    $parts = [$e->getMessage()];
    $prev = $e->getPrevious();
    $depth = 0;
    while ($prev !== null && $depth < 5) {
        $parts[] = $prev->getMessage();
        $prev = $prev->getPrevious();
        $depth++;
    }
    return implode(' <= ', array_filter($parts));
}

$dest = $argv[1] ?? '';
if ($dest === '' || str_contains($dest, "\0") || str_contains($dest, "\n")) {
    fail(1, 'missing install path');
}
$dest = rtrim($dest, '/');
if (!is_dir($dest)) {
    fail(1, "install path is not a directory: {$dest}");
}
foreach (['index.php', 'core/bootstrap.php', 'config/global.ini.php', 'plugins/Installation/Controller.php'] as $probe) {
    if (!is_file($dest . '/' . $probe)) {
        fail(1, "not a Matomo tree ({$probe} missing)");
    }
}

$dbHost = trim((string) (getenv('MT_DB_HOST') ?: ''));
$dbName = trim((string) (getenv('MT_DB_NAME') ?: ''));
$dbUser = trim((string) (getenv('MT_DB_USER') ?: ''));
$dbPass = (string) (getenv('MT_DB_PASS') ?: '');
$prefix = trim((string) (getenv('MT_PREFIX') ?: ''));
$user = trim((string) (getenv('MT_USER') ?: ''));
$pass = (string) (getenv('MT_PASS') ?: '');
$email = trim((string) (getenv('MT_EMAIL') ?: ''));
$siteTitle = trim((string) (getenv('MT_TITLE') ?: ''));
$siteUrl = trim((string) (getenv('MT_URL') ?: ''));
$domain = strtolower(trim((string) (getenv('MT_DOMAIN') ?: '')));

if (!in_array($dbHost, ['127.0.0.1', 'localhost'], true)) {
    fail(1, 'invalid database host');
}
if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $dbName) !== 1) {
    fail(1, 'invalid database name');
}
if (preg_match('/^[A-Za-z0-9_]{1,32}$/', $dbUser) !== 1) {
    fail(1, 'invalid database user');
}
if ($dbPass === '' || strlen($dbPass) > 256
    || str_contains($dbPass, "\0") || str_contains($dbPass, "\n") || str_contains($dbPass, "\r")
) {
    fail(1, 'invalid database password');
}
if (preg_match('/^[a-z0-9_]{1,16}$/', $prefix) !== 1) {
    fail(1, 'invalid table prefix');
}
if (preg_match('/^[A-Za-z0-9_.-]{2,100}$/', $user) !== 1) {
    fail(1, 'invalid superuser login');
}
if (strlen($pass) < 8 || strlen($pass) > 256
    || str_contains($pass, "\0") || str_contains($pass, "\n") || str_contains($pass, "\r")
) {
    fail(1, 'invalid superuser password (need 8-256 chars)');
}
if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fail(1, 'invalid superuser email');
}
if ($siteTitle === '' || strlen($siteTitle) > 200
    || str_contains($siteTitle, "\0") || str_contains($siteTitle, "\n") || str_contains($siteTitle, "\r")
) {
    fail(1, 'invalid website name');
}
if (strlen($siteUrl) > 255
    || preg_match('#^https?://[A-Za-z0-9.-]+(:\d+)?(/[A-Za-z0-9._/-]*)?$#', $siteUrl) !== 1
    || str_contains($siteUrl, '..')
) {
    fail(1, 'invalid website URL');
}
if (strlen($domain) > 253
    || preg_match('/^(?!-)[A-Za-z0-9.-]{1,253}(?<!-)$/', $domain) !== 1
    || str_contains($domain, '..')
) {
    fail(1, 'invalid trusted host');
}

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

// The embedded environment reads the local config file during init and
// throws when it is missing entirely — so plant an empty stub first (the
// real config is written below once we know this is a fresh tree). Runs
// as the account user, so ownership is correct from the start.
$configPath = $dest . '/config/config.ini.php';
$fresh = !is_file($configPath);
if ($fresh) {
    if (@file_put_contents($configPath, "; Auto-generated by cwhpanel Application Installer\n") === false) {
        fail(2, 'config file is not writable');
    }
}

try {
    // Standalone-script bootstrap, mirroring Matomo's own
    // misc/cron/updatetoken.php: index.php for paths/autoloader, then an
    // embedded 'cli' environment (this builds the DI container that
    // Config/Access/APIs need outside web + console entry points).
    if (!defined('PIWIK_DOCUMENT_ROOT')) {
        define('PIWIK_DOCUMENT_ROOT', $dest);
    }
    if (!defined('PIWIK_ENABLE_DISPATCH')) {
        define('PIWIK_ENABLE_DISPATCH', false);
    }
    if (!defined('PIWIK_ENABLE_ERROR_HANDLER')) {
        define('PIWIK_ENABLE_ERROR_HANDLER', false);
    }
    if (!defined('PIWIK_ENABLE_SESSION_START')) {
        define('PIWIK_ENABLE_SESSION_START', false);
    }
    require PIWIK_DOCUMENT_ROOT . '/index.php';
    $environment = new \Piwik\Application\Environment('cli');
    $environment->init();
    // Same plugin loading the web dispatcher performs
    // (FrontController::init): without it getLoadedPlugins() is empty, so
    // installLoadedPlugins() installs nothing (missing plugin tables like
    // custom_dimensions) and the 'website' site type is unknown (bitten
    // live: "Invalid website type website").
    \Piwik\Plugin\Manager::getInstance()->loadPluginTranslations();
    \Piwik\Plugin\Manager::getInstance()->loadActivatedPlugins();
} catch (Throwable $e) {
    fail(2, 'matomo bootstrap failed: ' . $e->getMessage());
}

// Fully installed (no flag in progress)? Leave it alone. A stub-only file
// reads as not-installed (no database username), so fresh trees proceed.
try {
    if (\Piwik\SettingsPiwik::isMatomoInstalled()) {
        echo "ALREADY\n";
        exit(0);
    }
} catch (Throwable $e) {
    fail(2, 'install-state check failed: ' . $e->getMessage());
}

try {
    // 1. Config file (same fields the wizard's createConfigFile writes).
    // Written only for fresh trees: a resume keeps the existing file
    // (same credentials — this install owns the database).
    if ($fresh) {
        $config = \Piwik\Config::getInstance();
        // NOTE: fixed utf8mb4 (NOT DbHelper::getDefaultCharset()): that
        // helper OPENS a DB connection to probe the server — a chicken-and-
        // egg here, since the connection needs exactly this config
        // (bitten live: SQLSTATE 2002 before anything was written).
        // Panel-created databases default to utf8mb4 anyway.
        $config->database = [
            'host' => $dbHost,
            'username' => $dbUser,
            'password' => $dbPass,
            'dbname' => $dbName,
            'tables_prefix' => $prefix,
            'adapter' => 'PDO\\MYSQL',
            'port' => 3306,
            'schema' => 'Mysql',
            'type' => 'InnoDB',
            'enable_ssl' => false,
            'charset' => 'utf8mb4',
        ];
        $config->database['collation'] = 'utf8mb4_general_ci';
        $config->General['salt'] = \Piwik\Common::generateUniqId();
        $config->General['installation_in_progress'] = 1;
        $config->General['trusted_hosts'] = [$domain];
        $config->forceSave();
        $dbPass = '';
    }

    // 2. Tables (guarded: a resumed run skips existing ones).
    // NOTE: createTables() only builds CORE tables. In web installs the
    // plugin tables (custom_dimensions et al) appear via the dispatcher's
    // per-request installLoadedPlugins() side effect — which never runs in
    // this embedded CLI environment — so call it explicitly (idempotent,
    // hence outside the guard: resumes need it too).
    try {
        if (count(\Piwik\DbHelper::getTablesInstalled()) === 0) {
            \Piwik\DbHelper::createTables();
            \Piwik\DbHelper::createAnonymousUser();
            \Piwik\DbHelper::recordInstallVersion();
        }
        \Piwik\Plugin\Manager::getInstance()->installLoadedPlugins();
        \Piwik\Access::doAsSuperUser(static function () {
            $updater = new \Piwik\Updater();
            $withUpdates = $updater->getComponentUpdates();
            if (!empty($withUpdates)) {
                $updater->updateComponents($withUpdates);
            }
        });
        \Piwik\Updater::recordComponentSuccessfullyUpdated('core', \Piwik\Version::VERSION);
    } catch (Throwable $e) {
        fail(2, 'matomo setup failed at tables stage: ' . stageError($e));
    }

    // 3. Superuser (guarded).
    try {
        $hasSuper = \Piwik\Access::doAsSuperUser(static function () {
            return count(\Piwik\Plugins\UsersManager\API::getInstance()->getUsersHavingSuperUserAccess()) > 0;
        });
        if (!$hasSuper) {
            \Piwik\Access::doAsSuperUser(static function () use ($user, $pass, $email) {
                $model = new \Piwik\Plugins\UsersManager\Model();
                // A previous attempt may have created the login without the
                // flag (resume must not duplicate it).
                if (!$model->userExists($user)) {
                    \Piwik\Plugins\UsersManager\API::getInstance()->addUser($user, $pass, $email);
                }
                // NOTE: NOT UserUpdater::setSuperUserAccessWithoutCurrentPassword():
                // that helper routes through Request::processRequest(), whose
                // response renderer has no 'console' format in this embedded
                // CLI environment (bitten live). The model writes the flag
                // directly — same row, no rendering involved.
                $model->setSuperUserAccess($user, true);
            });
            $pass = '';
        }
    } catch (Throwable $e) {
        fail(2, 'matomo setup failed at superuser stage: ' . stageError($e));
    }

    // 4. First website (guarded).
    try {
        $siteCount = \Piwik\Access::doAsSuperUser(static function () {
            return count(\Piwik\Plugins\SitesManager\API::getInstance()->getAllSitesId());
        });
        if ($siteCount === 0) {
            \Piwik\Access::doAsSuperUser(static function () use ($siteTitle, $siteUrl) {
                \Piwik\Plugins\SitesManager\API::getInstance()->addSite($siteTitle, $siteUrl, 0);
            });
        }
    } catch (Throwable $e) {
        fail(2, 'matomo setup failed at website stage: ' . stageError($e));
    }

    // 5. Security files + mark completed (the wizard's finished step).
    try {
        \Piwik\Plugins\Installation\ServerFilesGenerator::createFilesForSecurity();
        $config = \Piwik\Config::getInstance();
        unset($config->General['installation_in_progress']);
        unset($config->General['installation_first_accessed']);
        $config->forceSave();
        $environment->destroy();
    } catch (Throwable $e) {
        fail(2, 'matomo setup failed at finalize stage: ' . stageError($e));
    }
} catch (Throwable $e) {
    fail(2, 'matomo setup failed: ' . stageError($e));
}

echo "INSTALLED user={$user} site={$siteUrl}\n";
exit(0);

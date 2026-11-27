<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Seed;

/**
 * One-click catalog seed data (92 applications).
 *
 * Each entry: slug/name/category/version/description/website/license/vendor
 * plus a manifest honoring the engine contract:
 *   type php-mysql|php — docroot install (database.required=true gets a
 *     panel-managed DB + generated wp-style config; required=false means
 *     the app's own web wizard finishes setup, with a post_install note)
 *   type python-pip    — pip package + generated runpy launcher + process
 *   type node-npm      — npm package + generated require launcher + process
 *   type node-archive  — archive extract + bare npm install + launcher
 *   type binary        — single binary (format file) or an archive holding
 *     the binary (+ its data dirs) → +x → launcher execs it
 *
 * Download sources, in order of preference:
 *   1. evergreen static URL (download.url) — never rots
 *   2. templated GitHub/Codeberg release (download.github/download.codeberg
 *      {repo, asset} with {tag}/{version} placeholders; resolved from
 *      `version` at install time, auto-bumped by the weekly catalog refresh)
 *   3. version-templated URL (download.url with {version}/{tag}) — same
 *      auto-follow behavior for versioned upstream URLs (MediaWiki…)
 *   4. pinned URL (download.url, fixed version) — rots; admin-maintained,
 *      no automatic tracking (checker static)
 *
 * update_check: {type: github|codeberg, repo} | {type: pypi|npm, package}
 *   | {type: wordpress} (core API; display-only). Evergreen-URL rows use a
 *   check purely to display the real upstream version (never auto-applied).
 * (informational for pip/npm — installs resolve latest anyway) | static.
 * Only version-templated rows auto-apply (their URLs derive from version);
 * pip/npm installs resolve latest at install time regardless.
 *
 * Conventions: requirements list only extensions the host actually ships
 * (see RequirementChecker); wizard-finished apps set database.required=false
 * with a post_install note; admin paths are site-relative.
 */
class CatalogApps
{
    /** @return array<int, array> */
    public static function all(): array
    {
        return [
            [
                'slug' => 'wordpress', 'name' => 'WordPress', 'category' => 'CMS',
                'version' => 'latest', 'featured' => 1, 'sort' => 0,
                'description' => 'The world\'s most popular CMS. Build blogs, sites and stores.',
                'website' => 'https://wordpress.org', 'license' => 'GPL-2.0', 'vendor' => 'WordPress Foundation',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '7.4', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'json', 'openssl']],
                    'database' => ['required' => true, 'prefix' => 'wp_'],
                    'download' => ['url' => 'https://wordpress.org/latest.tar.gz', 'sha256' => '', 'format' => 'tar', 'strip_root' => true],
                    'config' => ['template' => 'wp', 'file' => 'wp-config.php', 'prefix' => 'wp_'],
                    'admin' => ['path' => 'wp-admin/'],
                    'update_check' => ['type' => 'wordpress'],
                ],
            ],
            [
                'slug' => 'classicpress', 'name' => 'ClassicPress', 'category' => 'CMS',
                'version' => 'latest', 'featured' => 0, 'sort' => 1,
                'description' => 'A business-focused CMS forked from WordPress — familiar dashboard without the block editor. The database is configured automatically; finish by creating your admin account in the web installer.',
                'website' => 'https://www.classicpress.net', 'license' => 'GPL-2.0', 'vendor' => 'ClassicPress',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '7.4', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'json', 'openssl']],
                    'database' => ['required' => true, 'prefix' => 'cp_'],
                    'download' => ['url' => 'https://www.classicpress.net/latest.tar.gz', 'sha256' => '', 'format' => 'tar', 'strip_root' => true],
                    'config' => ['template' => 'wp', 'file' => 'wp-config.php', 'prefix' => 'cp_'],
                    'admin' => ['path' => 'wp-admin/'],
                    // Evergreen tarball (no versioned asset to bump to), but
                    // the -release repo tags track the real number for the
                    // card display (latest_version only — nothing auto-applies
                    // on a static download URL). NOTE: the main repo's feed is
                    // all +dev staging tags — it must NOT be used here.
                    'update_check' => ['type' => 'github', 'repo' => 'ClassicPress/ClassicPress-release'],
                ],
            ],
            [
                'slug' => 'nextcloud', 'name' => 'Nextcloud', 'category' => 'File Sharing',
                'version' => 'latest', 'featured' => 1, 'sort' => 2,
                'description' => 'Self-hosted file sync, share and collaboration.',
                'website' => 'https://nextcloud.com', 'license' => 'AGPL-3.0', 'vendor' => 'Nextcloud GmbH',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.3', 'extensions' => ['gd', 'curl', 'mbstring', 'intl', 'zip', 'xml']],
                    'database' => ['required' => true, 'prefix' => 'oc_'],
                    'download' => ['url' => 'https://download.nextcloud.com/server/releases/latest.zip', 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    // No panel-written config: occ writes config.php itself.
                    // Unattended via occ maintenance:install + trusted domain
                    // + CLI URL (+ webroot override for subdir installs).
                    'config' => [],
                    'setup' => ['cli' => [
                        ['script' => 'occ', 'php_version' => '{php}', 'args' => ['maintenance:install', '--database=mysql', '--database-host={db_host}', '--database-port=3306', '--database-name={db_name}', '--database-user={db_user}', '--database-pass={db_pass}', '--admin-user={user}', '--admin-pass={pass}', '--admin-email={email}', '--data-dir={home}/ncdata-{dir}']],
                        ['script' => 'occ', 'php_version' => '{php}', 'args' => ['config:system:set', 'trusted_domains', '1', '--value={domain}']],
                        ['script' => 'occ', 'php_version' => '{php}', 'args' => ['config:system:set', 'overwrite.cli.url', '--value={url}']],
                        ['script' => 'occ', 'php_version' => '{php}', 'skip_if' => ['webroot' => '/'], 'args' => ['config:system:set', 'overwritewebroot', '--value={webroot}']],
                        ['script' => 'occ', 'php_version' => '{php}', 'args' => ['config:system:set', 'backgroundjobs_mode', '--value=cron']],
                    ],
                    'cron' => [
                        ['schedule' => '*/5 * * * *', 'command' => '{phpbin} -f {dest}/cron.php'],
                    ],
                    // Outside-docroot data dir: account-owned + labeled for
                    // the confined worker (else every write 503s).
                    'datadirs' => ['ncdata-{dir}']],
                    'admin' => ['path' => ''],
                    'post_install' => 'Installed automatically with the admin account you entered (data directory ncdata lives beside public_html) — just log in at the site URL. Background cron is enabled automatically.',
                    'update_check' => ['type' => 'github', 'repo' => 'nextcloud/server'],
                ],
            ],
            [
                'slug' => 'matomo', 'name' => 'Matomo', 'category' => 'Analytics',
                'version' => 'latest', 'featured' => 0, 'sort' => 3,
                'description' => 'Self-hosted web analytics — the privacy-friendly Google Analytics alternative.',
                'website' => 'https://matomo.org', 'license' => 'GPL-3.0', 'vendor' => 'Matomo',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '7.4', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip']],
                    'database' => ['required' => true, 'prefix' => 'matomo_'],
                    'download' => ['url' => 'https://builds.matomo.org/matomo-latest.zip', 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    // No panel-written config file: the setup helper writes
                    // config.ini.php via Matomo's own Config class (exact
                    // INI format), then replays the Installation plugin
                    // (tables + superuser + first website) with its classes.
                    'config' => ['template' => 'matomo', 'prefix' => 'matomo_'],
                    'admin' => ['path' => ''],
                    'post_install' => 'Installed automatically with the title, admin account and first website you entered — just log in at the site URL.',
                    'update_check' => ['type' => 'url', 'url' => 'https://builds.matomo.org/LATEST', 'pattern' => '/^(\\d+\\.\\d+\\.\\d+)\\s*$/'],
                ],
            ],
            [
                'slug' => 'dokuwiki', 'name' => 'DokuWiki', 'category' => 'Wikis',
                'version' => 'latest', 'featured' => 0, 'sort' => 4,
                'description' => 'A simple wiki that stores pages as files — no database needed.',
                'website' => 'https://www.dokuwiki.org', 'license' => 'GPL-2.0', 'vendor' => 'DokuWiki',
                'manifest' => [
                    'type' => 'php',
                    'requirements' => ['php_min' => '7.4', 'extensions' => ['mbstring', 'gd', 'xml']],
                    'database' => ['required' => false],
                    'download' => ['url' => 'https://download.dokuwiki.org/src/dokuwiki/dokuwiki-stable.tgz', 'sha256' => '', 'format' => 'tar', 'strip_root' => true],
                    // Template marker only (no panel-written file, no DB):
                    // the setup helper replicates install.php for a closed
                    // wiki and removes install.php afterwards.
                    'config' => ['template' => 'dokuwiki'],
                    'admin' => ['path' => 'doku.php'],
                    'post_install' => 'Installed automatically with the wiki title and admin account you entered — just log in at doku.php.',
                    'update_check' => ['type' => 'github', 'repo' => 'dokuwiki/dokuwiki'],
                ],
            ],
            [
                'slug' => 'grav', 'name' => 'Grav + Admin', 'category' => 'CMS',
                'version' => 'latest', 'featured' => 0, 'sort' => 5,
                'description' => 'A fast flat-file CMS with admin panel — no database needed.',
                'website' => 'https://getgrav.org', 'license' => 'MIT', 'vendor' => 'Grav',
                'manifest' => [
                    'type' => 'php',
                    'requirements' => ['php_min' => '8.1', 'extensions' => ['gd', 'curl', 'mbstring', 'zip', 'xml', 'json', 'openssl']],
                    'database' => ['required' => false],
                    'download' => ['url' => 'https://getgrav.org/download/core/grav-admin/latest', 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => ['files' => [
                        'user/accounts/{user}.yaml' => "email: '{email}'
fullname: Administrator
title: Administrator
state: enabled
language: en
access:
  admin:
    login: true
    super: true
  site:
    login: true
hashed_password: '{pass_hash}'
",
                    ]],
                    'admin' => ['path' => 'admin'],
                    'post_install' => 'Installed automatically with the admin account you entered — log in at /admin.',
                    'update_check' => ['type' => 'github', 'repo' => 'getgrav/grav'],
                ],
            ],
            [
                'slug' => 'owncloud', 'name' => 'ownCloud', 'category' => 'File Sharing',
                'version' => 'latest', 'featured' => 0, 'sort' => 6,
                'description' => 'File sync and share server with calendar, contacts and office integration.',
                'website' => 'https://owncloud.com', 'license' => 'AGPL-3.0', 'vendor' => 'ownCloud GmbH',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.1', 'php_max' => '8.1', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl']],
                    'database' => ['required' => true, 'prefix' => 'oc_'],
                    'download' => ['url' => 'https://download.owncloud.com/server/stable/owncloud-complete-latest.zip', 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    // occ writes config.php; unattended install + trusted
                    // domain + CLI URL (ownCloud uses trusted_domains[0]).
                    'config' => [],
                    'setup' => ['cli' => [
                        ['script' => 'occ', 'php_version' => '{php}', 'args' => ['maintenance:install', '--database=mysql', '--database-host={db_host}', '--database-name={db_name}', '--database-user={db_user}', '--database-pass={db_pass}', '--database-table-prefix=oc_', '--admin-user={user}', '--admin-pass={pass}', '--data-dir={home}/ocdata-{dir}']],
                        ['script' => 'occ', 'php_version' => '{php}', 'args' => ['config:system:set', 'trusted_domains', '0', '--value={domain}']],
                        ['script' => 'occ', 'php_version' => '{php}', 'args' => ['config:system:set', 'overwrite.cli.url', '--value={url}']],
                    ],
                    'datadirs' => ['ocdata-{dir}']],
                    'admin' => ['path' => ''],
                    'post_install' => 'Installed automatically with the admin account you entered (data directory ocdata lives beside public_html) — just log in at the site URL.',
                    'update_check' => ['type' => 'github', 'repo' => 'owncloud/core'],
                ],
            ],
            [
                'slug' => 'drupal', 'name' => 'Drupal', 'category' => 'CMS',
                'version' => 'latest', 'featured' => 0, 'sort' => 7,
                'description' => 'Enterprise-grade CMS for ambitious sites, intranets and portals.',
                'website' => 'https://www.drupal.org', 'license' => 'GPL-2.0', 'vendor' => 'Drupal Association',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.3', 'db_min' => '10.6', 'extensions' => ['gd', 'curl', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'intl', 'pdo_mysql']],
                    'database' => ['required' => true, 'prefix' => ''],
                    'download' => ['url' => 'https://www.drupal.org/download-latest/tar.gz', 'sha256' => '', 'format' => 'tar', 'strip_root' => true],
                    'config' => ['template' => 'drupal'],
                    'admin' => ['path' => 'user/login'],
                    'post_install' => 'Installed automatically with the admin account you entered — log in at /user/login.',
                    'update_check' => ['type' => 'github', 'repo' => 'drupal/drupal'],
                ],
            ],
            [
                'slug' => 'piwigo', 'name' => 'Piwigo', 'category' => 'Media',
                'version' => 'latest', 'featured' => 0, 'sort' => 8,
                'description' => 'Photo gallery software for the web — organize albums, users and sharing.',
                'website' => 'https://piwigo.org', 'license' => 'GPL-2.0', 'vendor' => 'Piwigo',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '7.4', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'exif', 'intl']],
                    'database' => ['required' => true, 'prefix' => 'piwigo_'],
                    'download' => ['url' => 'https://piwigo.org/download/dlcounter.php?code=latest', 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => ['template' => 'piwigo'],
                    'admin' => ['path' => 'admin.php'],
                    'post_install' => 'Installed automatically with the admin account you entered — log in at /admin.php.',
                    'update_check' => ['type' => 'github', 'repo' => 'Piwigo/Piwigo'],
                ],
            ],
            [
                'slug' => 'tiddlywiki', 'name' => 'TiddlyWiki', 'category' => 'Wikis',
                'version' => 'latest', 'featured' => 0, 'sort' => 10,
                'description' => 'A complete personal wiki in a single HTML file — no setup at all.',
                'website' => 'https://tiddlywiki.com', 'license' => 'BSD-3-Clause', 'vendor' => 'TiddlyWiki',
                'manifest' => [
                    'type' => 'php',
                    'requirements' => ['php_min' => '7.4', 'extensions' => []],
                    'database' => ['required' => false],
                    'download' => ['url' => 'https://tiddlywiki.com/empty.html', 'sha256' => '', 'format' => 'file'],
                    'file' => ['name' => 'index.html'],
                    'config' => [],
                    'admin' => ['path' => ''],
                    'post_install' => 'No setup needed — open the site and start writing. Save back through the browser download (or TiddlyWiki saver plugins).',
                    // Single-file evergreen build (nothing versioned to bump
                    // to), but the GitHub tags track the real number for the
                    // card display (latest_version only).
                    'update_check' => ['type' => 'github', 'repo' => 'TiddlyWiki/TiddlyWiki5'],
                ],
            ],
            [
                'slug' => 'phpmyadmin', 'name' => 'phpMyAdmin', 'category' => 'Developer Tools',
                'version' => '5.2.1', 'featured' => 0, 'sort' => 11,
                'description' => 'Web interface for MySQL/MariaDB administration (per-site copy).',
                'website' => 'https://www.phpmyadmin.net', 'license' => 'GPL-2.0', 'vendor' => 'phpMyAdmin',
                'manifest' => [
                    'type' => 'php',
                    'requirements' => ['php_min' => '7.4', 'extensions' => ['mysqli', 'mbstring', 'zip', 'json', 'openssl']],
                    'database' => ['required' => false],
                    'download' => ['url' => 'https://files.phpmyadmin.net/phpMyAdmin/5.2.1/phpMyAdmin-5.2.1-all-languages.zip', 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => ['template' => 'phpmyadmin', 'file' => 'config.inc.php'],
                    'admin' => ['path' => ''],
                    'post_install' => 'Preconfigured (cookie auth against the local server) — log in with any database user you create under Databases.',
                    'update_check' => ['type' => 'static'],
                ],
            ],
            [
                'slug' => 'openemr', 'name' => 'OpenEMR', 'category' => 'Utilities',
                'version' => '8.4.0', 'featured' => 0, 'sort' => 12,
                'description' => 'Electronic health records and medical practice management.',
                'website' => 'https://www.open-emr.org', 'license' => 'GPL-3.0', 'vendor' => 'OpenEMR',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.3', 'extensions' => ['pdo_mysql', 'mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'fileinfo', 'intl', 'ldap', 'soap', 'sodium', 'bcmath', 'imagick', 'calendar', 'ctype', 'iconv', 'tokenizer', 'sockets']],
                    'database' => ['required' => true, 'prefix' => ''],
                    'download' => ['url' => 'https://github.com/openemr/openemr/releases/download/v8_4_0/openemr-8.4.0.zip', 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        // No usable stock CLI entry: InstallerAuto.php reads
                        // key=value argv (values containing '=' truncate) and
                        // needs an env gate the runner cannot set, so this
                        // driver constructs the stock Installer directly with
                        // JSON argv (quote-safe for any password) and runs its
                        // full quick_install() in no_root_db_access mode
                        // against the panel-managed database (schema + sqlconf
                        // + version + globals + bcrypt initial user + gacl).
                        // Runs as the account (never root — the tree's own
                        // RootCliGuard refuses UID 0). setup.php + the stock
                        // auto-script removed by cleanup below.
                        'files' => ['panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$in = json_decode($argv[1] ?? 'null', true);
if (($__e = getenv('CWH_SETUP_PASS')) !== false && $__e !== '') { $in['pass'] = $__e; }
if (($__e = getenv('CWH_SETUP_DB_PASS')) !== false && $__e !== '') { $in['db_pass'] = $__e; }
if (!is_array($in)) {
    fwrite(STDERR, "openemr setup: bad arguments\n");
    exit(1);
}
foreach (['db_host', 'db_name', 'db_user', 'db_pass', 'user', 'pass'] as $k) {
    if (!isset($in[$k]) || !is_string($in[$k]) || $in[$k] === '' || strlen($in[$k]) > 255) {
        fwrite(STDERR, "openemr setup: missing {$k}\n");
        exit(1);
    }
    if (str_contains($in[$k], "\n") || str_contains($in[$k], "\0")) {
        fwrite(STDERR, "openemr setup: bad {$k}\n");
        exit(1);
    }
}
chdir(__DIR__);
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/library/classes/Installer.class.php';
$settings = [
    'iuser' => $in['user'],
    'iuname' => 'Administrator',
    'iufname' => 'Site',
    'iuserpass' => $in['pass'],
    'igroup' => 'Default',
    'server' => $in['db_host'],
    'loginhost' => $in['db_host'],
    'port' => '3306',
    'login' => $in['db_user'],
    'pass' => $in['db_pass'],
    'dbname' => $in['db_name'],
    'collate' => 'utf8mb4_unicode_ci',
    'site' => 'default',
    'no_root_db_access' => '1',
];
$installer = new Installer($settings, OpenEMR\BC\ServiceContainer::getLogger());
if (!$installer->quick_install()) {
    fwrite(STDERR, 'openemr setup failed: ' . $installer->error_message . "\n");
    exit(1);
}
echo "openemr setup complete\n";
PANELSETUP
                        ],
                        'cli' => [[
                            'script' => 'panel-setup.php',
                            'args' => ['{"db_host":"{db_host}","db_name":"{db_name}","db_user":"{db_user}","db_pass":"{db_pass}","user":"{user}","pass":"{pass}"}'],
                        ]],
                        'cleanup' => ['panel-setup.php', 'setup.php', 'contrib/util/installScripts/InstallerAuto.php'],
                    ],
                    'admin' => ['path' => 'interface/login/login.php'],
                    'post_install' => 'Installed automatically with the admin account you entered — log in at Interface → Login.',
                    'update_check' => ['type' => 'static'],
                ],
            ],
            [
                'slug' => 'cmsimple-xh', 'name' => 'CMSimple_XH', 'category' => 'CMS',
                'version' => '1.8', 'featured' => 0, 'sort' => 13,
                'description' => 'Tiny flat-file CMS — the whole site lives in one content file.',
                'website' => 'https://www.cmsimple-xh.org', 'license' => 'AGPL-3.0', 'vendor' => 'CMSimple_XH',
                'manifest' => [
                    'type' => 'php',
                    'requirements' => ['php_min' => '7.4', 'extensions' => ['mbstring', 'json', 'openssl']],
                    'database' => ['required' => false],
                    'download' => ['url' => 'https://github.com/cmsimple-xh/cmsimple-xh/releases/download/1.8.0/CMSimple_XH-1.8.zip', 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        // Password-only login, bcrypt in cmsimple/config.php.
                        // The driver swaps just the password line (argv
                        // carries the pre-hashed {pass_hash}), leaving the
                        // rest of the shipped config untouched. CLI-only.
                        'files' => ['panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 2, '');
    if (($__e = getenv('CWH_SETUP_PASS_HASH')) !== false && $__e !== '') { $args[1] = $__e; }
$hash = trim($args[1] ?? '');
// Bcrypt alphabet plus $ separators — anything else is refused.
if ($hash === '' || strlen($hash) > 255 || !preg_match('#^[A-Za-z0-9./$]+$#', $hash)) {
    fwrite(STDERR, "usage: panel-setup.php <bcrypt-hash>\n");
    exit(1);
}
chdir(__DIR__);
$file = __DIR__ . '/cmsimple/config.php';
$conf = @file_get_contents($file);
if (!is_string($conf)) {
    fwrite(STDERR, "config.php missing\n");
    exit(1);
}
$out = null;
if (preg_match('/^\$cf\[\'security\'\]\[\'password\'\]="[^"]*";/m', $conf, $m)) {
    // str_replace (never preg_replace) for the swap: the bcrypt hash is
    // full of $n sequences that a preg REPLACEMENT would eat as
    // backreferences (bitten live: $2y$10$… came out as y$…).
    // Escape $ upstream-style so PHP keeps the hash intact on load.
    $line = '$cf[\'security\'][\'password\']="' . str_replace('$', '\\$', $hash) . '";';
    $out = str_replace($m[0], $line, $conf);
}
if (!is_string($out)) {
    fwrite(STDERR, "password line not found\n");
    exit(1);
}
file_put_contents($file, $out);
echo "installed\n";
PANELSETUP,
                        ],
                        'cli' => [
                            ['script' => 'panel-setup.php', 'args' => ['{pass_hash}']],
                        ],
                        'cleanup' => ['panel-setup.php'],
                    ],
                    'admin' => ['path' => '?&login'],
                    'post_install' => 'Installed automatically — log in with the password you entered (no username needed).',
                    'update_check' => ['type' => 'static'],
                ],
            ],
            [
                'slug' => 'cacti', 'name' => 'Cacti', 'category' => 'Monitoring',
                'version' => '1.2.31', 'featured' => 0, 'sort' => 14,
                'description' => 'Network graphing and monitoring frontend (RRDTool graphs).',
                'website' => 'https://www.cacti.net', 'license' => 'GPL-2.0', 'vendor' => 'Cacti Group',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '7.4', 'extensions' => ['mysqli', 'gd', 'mbstring', 'xml', 'json', 'openssl']],
                    'database' => ['required' => true, 'prefix' => ''],
                    'download' => ['url' => 'https://www.cacti.net/downloads/cacti-1.2.31.tar.gz', 'sha256' => '', 'format' => 'tar', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        'files' => ['include/config.php' => <<<'CACTICONF'
<?php
$database_type     = 'mysql';
$database_default  = '{db_name}';
$database_hostname = '{db_host}';
$database_username = '{db_user}';
$database_password = '{db_pass}';
$database_port     = '3306';
$database_retries  = 5;
$database_ssl      = false;
$database_ssl_key  = '';
$database_ssl_cert = '';
$database_ssl_ca   = '';
$database_persist  = false;
$poller_id = 1;
$url_path = '{urlpath}';
$cacti_session_name = 'Cacti';
$cacti_db_session = false;
$disable_log_rotation = false;
$proxy_headers = null;
CACTICONF,
                            // Schema import driver: the stock CLI installer
                            // refuses an empty database, and setup.sql runs
                            // after setup.cli — so import cacti.sql here with
                            // the mysql client (handles DELIMITER natively).
                            // CLI-only; cleanup removes it.
                            'panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 5, '');
    if (($__e = getenv('CWH_SETUP_DB_PASS')) !== false && $__e !== '') { $args[4] = $__e; }
$dbhost = trim($args[1]);
$dbname = trim($args[2]);
$dbuser = trim($args[3]);
$dbpass = $args[4];
$sqlFile = __DIR__ . '/cacti.sql';
if ($dbhost === '' || $dbname === '' || $dbuser === '' || $dbpass === '' || !is_file($sqlFile)) {
    fwrite(STDERR, "usage: panel-setup.php <dbhost> <dbname> <dbuser> <dbpass>\n");
    exit(1);
}
$cmd = '/usr/bin/mysql -h ' . escapeshellarg($dbhost) . ' -P 3306 -u ' . escapeshellarg($dbuser)
    . ' -p' . escapeshellarg($dbpass) . ' ' . escapeshellarg($dbname)
    . ' < ' . escapeshellarg($sqlFile) . ' 2>&1';
exec($cmd, $out, $code);
echo implode("\n", $out) . "\n";
exit($code === 0 ? 0 : 1);
PANELSETUP,
                        ],
                        'cli' => [
                            ['script' => 'panel-setup.php', 'args' => ['{db_host}', '{db_name}', '{db_user}', '{db_pass}']],
                            ['script' => 'cli/install_cacti.php', 'args' => ['--install', '--accept-eula']],
                        ],
                        'sql_post' => [
                            ['table' => 'user_auth', 'set' => ['password' => '{pass_hash}', 'email_address' => '{email}', 'must_change_password' => ''], 'where' => ['username' => 'admin']],
                        ],
                        'cron' => [
                            ['schedule' => '*/5 * * * *', 'command' => '{phpbin} {dest}/poller.php'],
                        ],
                        'cleanup' => ['install', 'panel-setup.php'],
                    ],
                    'admin' => ['path' => ''],
                    'post_install' => 'Installed automatically — log in as admin with the password you entered. The poller runs every 5 minutes via cron (RRDTool must be installed on the server).',
                    'update_check' => ['type' => 'static'],
                ],
            ],
            [
                'slug' => 'phpsysinfo', 'name' => 'phpSysInfo', 'category' => 'Monitoring',
                'version' => '3.4.7', 'featured' => 0, 'sort' => 15,
                'description' => 'System information dashboard (CPU, memory, disks, network) for your site.',
                'website' => 'https://phpsysinfo.github.io/phpsysinfo/', 'license' => 'GPL-2.0', 'vendor' => 'phpSysInfo',
                'manifest' => [
                    'type' => 'php',
                    'requirements' => ['php_min' => '7.4', 'extensions' => ['mbstring', 'xml', 'json']],
                    'database' => ['required' => false],
                    'download' => ['url' => 'https://github.com/phpsysinfo/phpsysinfo/archive/refs/tags/v3.4.7.zip', 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        // Credential-free (no_creds): this tool has no login
                        // and no wizard, so files/cli run without wizard
                        // values — and must not interpolate wizard
                        // placeholders (suite-asserted).
                        'no_creds' => true,
                        // The tree ships phpsysinfo.ini.new and serves an
                        // error page until it is copied to phpsysinfo.ini
                        // (the documented manual step). The driver does the
                        // copy in-tree (never touches the shipped template)
                        // and refuses loud when either side is off.
                        // CLI-only; removed by cleanup below.
                        'files' => ['panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$dst = __DIR__ . '/phpsysinfo.ini';
if (!is_file($dst)) {
    $src = __DIR__ . '/phpsysinfo.ini.new';
    if (!is_file($src) || !@copy($src, $dst)) {
        fwrite(STDERR, "phpsysinfo setup: cannot seed phpsysinfo.ini\n");
        exit(1);
    }
}
echo "phpsysinfo setup complete\n";
PANELSETUP
                        ],
                        'cli' => [['script' => 'panel-setup.php', 'args' => []]],
                        'cleanup' => ['panel-setup.php'],
                    ],
                    'admin' => ['path' => ''],
                    'post_install' => 'No setup needed — open the site to see the dashboard. Protect the directory (Websites → Directory Privacy) since it exposes system details.',
                    'update_check' => ['type' => 'static'],
                ],
            ],
            [
                'slug' => 'wondercms', 'name' => 'WonderCMS', 'category' => 'CMS',
                'version' => '3.6.0', 'featured' => 0, 'sort' => 16,
                'description' => 'Small flat-file CMS — one-click updates, no database.',
                'website' => 'https://www.wondercms.com', 'license' => 'MIT', 'vendor' => 'WonderCMS',
                'manifest' => [
                    'type' => 'php',
                    'requirements' => ['php_min' => '7.4', 'extensions' => ['curl', 'mbstring', 'zip', 'json', 'openssl']],
                    'database' => ['required' => false],
                    'download' => ['url' => 'https://github.com/WonderCMS/wondercms/releases/download/3.6.0/wondercms-360.zip', 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        // WonderCMS is password-only (no username): {title}
                        // becomes the site title, {pass} the login password.
                        // index.php is includable without side effects by
                        // design (PHPUNIT_TESTING guard), so the driver
                        // reuses the app's own DB layer instead of a static
                        // template. CLI-only.
                        'files' => ['panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 3, '');
    if (($__e = getenv('CWH_SETUP_PASS')) !== false && $__e !== '') { $args[2] = $__e; }
$title = trim($args[1]);
$pass = $args[2] ?? '';
if ($title === '' || strlen($pass) < 8) {
    fwrite(STDERR, "usage: panel-setup.php <title> <password>\n");
    exit(1);
}
define('PHPUNIT_TESTING', true);
// Headless $_SERVER (a URL helper reads these during bootstrap).
$_SERVER['SERVER_PORT'] = $_SERVER['SERVER_PORT'] ?? '80';
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
chdir(__DIR__);
// The shipped zip contains an EMPTY data/database.js, so getDb() sees an
// existing file, skips createDb(), json_decode('') returns null and every
// request dies "Wcms::getDb(): Return value must be of type stdClass".
// Remove the empty placeholder so the constructor builds a real DB.
if (is_file(__DIR__ . '/data/database.js') && filesize(__DIR__ . '/data/database.js') === 0) {
    @unlink(__DIR__ . '/data/database.js');
}
require __DIR__ . '/index.php';
// If the unattended write fails (e.g. a lock/stream warning from save()), the
// constructor can leave a 0-byte database.js behind — getDb() then sees an
// existing file, skips createDb() and every request 500s. Remove it so the
// browser wizard can finish the install.
try {
    $wcms = new Wcms();
} catch (Throwable $e) {
    if (is_file(__DIR__ . '/data/database.js') && filesize(__DIR__ . '/data/database.js') === 0) {
        @unlink(__DIR__ . '/data/database.js');
    }
    fwrite(STDERR, 'setup failed: ' . $e->getMessage() . "\n");
    exit(1);
}
$wcms->set('config', 'siteTitle', mb_substr($title, 0, 200));
$wcms->set('config', 'password', password_hash($pass, PASSWORD_DEFAULT));
echo "installed\n";
PANELSETUP,
                        ],
                        'cli' => [
                            ['script' => 'panel-setup.php', 'args' => ['{title}', '{pass}']],
                        ],
                        'cleanup' => ['panel-setup.php'],
                    ],
                    // Query-arg router (?page=<slug>) + credential-store
                    // denies (data/database.js holds password hashes).
                    'page_param' => 'page',
                    'deny_paths' => ['data/database.js', 'data/cache.json', 'data/security.json'],
                    'admin' => ['path' => 'loginURL'],
                    'post_install' => 'Installed automatically — log in at /loginURL with the password you entered.',
                    'update_check' => ['type' => 'static'],
                ],
            ],
            [
                'slug' => 'joomla', 'name' => 'Joomla', 'category' => 'CMS',
                'version' => '5.1.0', 'featured' => 0, 'sort' => 17,
                'description' => 'Flexible CMS for portals, intranets and e-commerce.',
                'website' => 'https://www.joomla.org', 'license' => 'GPL-2.0', 'vendor' => 'Open Source Matters',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.1', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'intl']],
                    'database' => ['required' => true, 'prefix' => 'jos_'],
                    'download' => ['github' => ['repo' => 'joomla/joomla-cms', 'asset' => 'Joomla_{version}-Stable-Full_Package.zip', 'tag_prefix' => ''], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => ['template' => 'joomla', 'file' => 'configuration.php', 'prefix' => 'jos_'],
                    'setup' => ['sql' => [
                        'schemas' => ['installation/sql/mysql/base.sql', 'installation/sql/mysql/extensions.sql', 'installation/sql/mysql/supports.sql'],
                        'prefix_from' => '#__',
                        'admin' => ['table' => 'users', 'user_col' => 'username', 'pass_col' => 'password', 'email_col' => 'email', 'hash' => 'bcrypt', 'extra' => ['id' => '1', 'name' => 'Administrator', 'sendEmail' => '1', 'registerDate' => '{date}', 'params' => '{"admin_style":"","admin_language":"","language":"","editor":"","timezone":""}']],
                        'related' => [['table' => 'user_usergroup_map', 'cols' => ['user_id' => '{admin_id}', 'group_id' => '8']]],
                        'cleanup' => ['installation'],
                    ]],
                    'admin' => ['path' => 'administrator/'],
                    'post_install' => 'Installed automatically with the admin account you entered — log in at /administrator/.',
                    'update_check' => ['type' => 'github', 'repo' => 'joomla/joomla-cms'],
                ],
            ],
            [
                'slug' => 'mybb', 'name' => 'MyBB', 'category' => 'Forums',
                'version' => '1.8.38', 'featured' => 0, 'sort' => 18,
                'description' => 'Classic forum software with themes, plugins and moderation tools.',
                'website' => 'https://mybb.com', 'license' => 'LGPL-3.0', 'vendor' => 'MyBB Group',
                'manifest' => [
                    'type' => 'php-mysql',
                    // Proven on PHP 8.5 (front + wizard); 1.8.38 supports
                    // PHP 8.0+, so the 8.0 floor keeps installs pin-free on
                    // default sites.
                    'requirements' => ['php_min' => '8.0', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl']],
                    'database' => ['required' => true, 'prefix' => 'mybb_'],
                    'download' => ['url' => 'https://resources.mybb.com/downloads/mybb_1838.zip', 'sha256' => '', 'format' => 'zip', 'strip_root' => true, 'strip_dir' => 'Upload'],
                    'config' => [],
                    'setup' => [
                        // No CLI installer ships: the driver replays the
                        // action-driven stock wizard headless over the live
                        // vhost (front-door :80 with Host header). Actions
                        // are stateless across requests (config file + DB
                        // carry everything — no cookies needed); secrets ride
                        // POST bodies (never URLs). Board prefs (bburl,
                        // cookies) are harvested from the configuration form
                        // so request-derived values round-trip exactly.
                        // install/ removed by cleanup below.
                        'files' => ['panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 10, '');
    if (($__e = getenv('CWH_SETUP_PASS')) !== false && $__e !== '') { $args[4] = $__e; }
    if (($__e = getenv('CWH_SETUP_DB_PASS')) !== false && $__e !== '') { $args[8] = $__e; }
$title = trim($args[1]);
$email = trim($args[2]);
$user = trim($args[3]);
$pass = $args[4] ?? '';
$dbhost = trim($args[5]);
$dbname = trim($args[6]);
$dbuser = trim($args[7]);
$dbpass = $args[8] ?? '';
$url = rtrim(trim($args[9]), '/');
if ($title === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $user) || strlen($pass) < 8
    || $dbhost === '' || $dbname === '' || $dbuser === '' || $dbpass === ''
    || !preg_match('#^https?://#', $url)
) {
    fwrite(STDERR, "usage: panel-setup.php <title> <email> <user> <pass> <dbhost> <dbname> <dbuser> <dbpass> <url>\n");
    exit(1);
}
$parts = parse_url($url);
$domain = $parts['host'] ?? '';
$path = rtrim($parts['path'] ?? '', '/');
if ($domain === '') {
    fwrite(STDERR, "bad url\n");
    exit(1);
}
$endpoint = 'http://127.0.0.1' . $path . '/install/index.php';
$cookies = [];
$request = function ($method, $action, $fields = null) use ($endpoint, $domain, &$cookies) {
    $target = $endpoint . '?action=' . $action;
    $ch = curl_init($target);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Host: ' . $domain]);
    if ($cookies !== []) {
        $pairs = [];
        foreach ($cookies as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        curl_setopt($ch, CURLOPT_COOKIE, implode('; ', $pairs));
    }
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return [0, '', 'curl error: ' . $err];
    }
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    foreach (explode("\n", substr($raw, 0, $hsize)) as $line) {
        if (stripos($line, 'Set-Cookie:') === 0) {
            $pair = trim(substr($line, 11));
            $semi = strpos($pair, ';');
            if ($semi !== false) {
                $pair = substr($pair, 0, $semi);
            }
            $eq = strpos($pair, '=');
            if ($eq !== false) {
                $cookies[trim(substr($pair, 0, $eq))] = trim(substr($pair, $eq + 1));
            }
        }
    }
    return [$code, substr($raw, $hsize), ''];
};
$fail = function ($msg) {
    fwrite(STDERR, $msg . "\n");
    exit(1);
};
$titleOf = function ($body) {
    if (preg_match('/<title>MyBB Installation Wizard &gt; ([^<]*)/', $body, $m)) {
        return trim(html_entity_decode($m[1]));
    }
    return '';
};
// Walk the display-only actions (intro/license/requirements render).
foreach (['intro', 'license', 'requirements_check'] as $a) {
    $r = $request('GET', $a);
    if ($r[0] !== 200) {
        $fail($a . ' unreachable (http ' . $r[0] . ')');
    }
}
// Database credentials -> tables are created inline.
$r = $request('POST', 'create_tables', [
    'action' => 'create_tables',
    'dbengine' => 'mysqli',
    'config[mysqli][dbhost]' => $dbhost,
    'config[mysqli][dbuser]' => $dbuser,
    'config[mysqli][dbpass]' => $dbpass,
    'config[mysqli][dbname]' => $dbname,
    'config[mysqli][tableprefix]' => 'mybb_',
    'config[mysqli][encoding]' => 'utf8mb4',
]);
if ($r[0] !== 200 || stripos($titleOf($r[1]), 'Table Creation') === false) {
    $fail('table creation failed (http ' . $r[0] . ' ' . $titleOf($r[1]) . ')');
}
// Data, theme: inline, one action each.
foreach (['populate_tables' => 'Table Population', 'templates' => 'Theme Insertion'] as $a => $expect) {
    $r = $request('POST', $a, ['action' => $a]);
    if ($r[0] !== 200 || stripos($titleOf($r[1]), $expect) === false) {
        $fail($a . ' failed (http ' . $r[0] . ' ' . $titleOf($r[1]) . ')');
    }
}
// Board prefs: harvest the request-derived prefill, override our fields.
$r = $request('GET', 'configuration');
if ($r[0] !== 200) {
    $fail('configuration unreachable (http ' . $r[0] . ')');
}
$prefill = [];
foreach (['bbname', 'bburl', 'websitename', 'websiteurl', 'cookiedomain', 'cookiepath', 'contactemail', 'pin'] as $f) {
    if (preg_match('/name="' . $f . '"[^>]*value="([^"]*)"/', $r[1], $m)) {
        $prefill[$f] = html_entity_decode($m[1]);
    }
}
$prefill['action'] = 'adminuser';
$prefill['bbname'] = $title;
$prefill['contactemail'] = $email;
$prefill['pin'] = '';
$r = $request('POST', 'adminuser', $prefill);
if ($r[0] !== 200 || stripos($titleOf($r[1]), 'Create Administrator Account') === false) {
    $fail('board configuration failed (http ' . $r[0] . ' ' . $titleOf($r[1]) . ')');
}
// Admin account -> finish page.
$r = $request('POST', 'final', [
    'action' => 'final',
    'adminuser' => $user,
    'adminpass' => $pass,
    'adminpass2' => $pass,
    'adminemail' => $email,
]);
if ($r[0] !== 200 || stripos($r[1], 'successfully been installed') === false) {
    $fail('install did not finish (' . $titleOf($r[1]) . ')');
}
// Verify: config written + admin row present.
if (!is_file(__DIR__ . '/inc/config.php')) {
    $fail('inc/config.php missing after install');
}
try {
    $c = new mysqli($dbhost, $dbuser, $dbpass, $dbname);
    if ($c->connect_error) {
        $fail('db connect failed after install: ' . $c->connect_error);
    }
    $q = $c->query("SELECT `username` FROM `mybb_users` WHERE `username` = '" . $c->real_escape_string($user) . "'");
    $ok = ($q && $q->num_rows > 0);
    $c->close();
    if (!$ok) {
        $fail('admin account missing after install');
    }
} catch (Throwable $e) {
    $fail('admin verify failed');
}
echo "mybb setup complete\n";
PANELSETUP,
                        ],
                        'cli' => [
                            ['script' => 'panel-setup.php', 'args' => ['{title}', '{email}', '{user}', '{pass}', '{db_host}', '{db_name}', '{db_user}', '{db_pass}', '{url}']],
                        ],
                        'cleanup' => ['panel-setup.php', 'install'],
                    ],
                    'admin' => ['path' => 'admin/'],
                    'post_install' => 'Installed automatically with the admin account you entered — sign in on the front page; the ACP is at /admin/.',
                    'update_check' => ['type' => 'static'],
                ],
            ],
            [
                'slug' => 'wallabag', 'name' => 'wallabag', 'category' => 'Utilities',
                'version' => '2.6.14', 'featured' => 0, 'sort' => 19,
                'description' => 'Save and read web articles later — self-hosted read-it-later.',
                'website' => 'https://wallabag.org', 'license' => 'MIT', 'vendor' => 'wallabag',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.1', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'json', 'openssl', 'intl', 'tidy']],
                    'database' => ['required' => true, 'prefix' => 'wallabag_'],
                    'download' => ['github' => ['repo' => 'wallabag/wallabag', 'asset' => 'wallabag-{version}.tar.gz', 'tag_prefix' => ''], 'sha256' => '', 'format' => 'tar', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        'files' => ['app/config/parameters.yml' => <<<'WALLAPARAMS'
parameters:
    database_driver: pdo_mysql
    database_host: '{db_host}'
    database_port: 3306
    database_name: '{db_name}'
    database_user: '{db_user}'
    database_password: '{db_pass}'
    database_path: null
    database_table_prefix: wallabag_
    database_socket: null
    database_charset: utf8mb4
    domain_name: '{url}'
    server_name: '{title}'
    mailer_dsn: smtp://127.0.0.1
    locale: en
    secret: '{app_key}'
    twofactor_sender: no-reply@example.com
    fosuser_registration: false
    fosuser_confirmation: false
    fos_oauth_server_access_token_lifetime: 3600
    fos_oauth_server_refresh_token_lifetime: 1209600
    from_email: no-reply@example.com
    rss_limit: 50
    rabbitmq_host: localhost
    rabbitmq_port: 5672
    rabbitmq_user: guest
    rabbitmq_password: guest
    rabbitmq_prefetch_count: 10
    redis_scheme: tcp
    redis_host: localhost
    redis_port: 6379
    redis_path: null
    redis_password: null
    sentry_dsn: ~
WALLAPARAMS,
                        ],
                        // wallabag:install seeds a fixed wallabag/wallabag
                        // super-admin (its admin prompt has no flags and
                        // FOSUser hashes sha512+salt, which no SQL can
                        // reproduce): create ours via FOSUser's own command,
                        // then neutralize the default (demote + random
                        // password + deactivate).
                        'cli' => [
                            ['script' => 'bin/console', 'args' => ['wallabag:install', '--no-interaction', '--env=prod']],
                            ['script' => 'bin/console', 'args' => ['fos:user:create', '{user}', '{email}', '{pass}', '--super-admin', '--env=prod']],
                            ['script' => 'bin/console', 'args' => ['fos:user:demote', '--super', 'wallabag', '--env=prod']],
                            ['script' => 'bin/console', 'args' => ['fos:user:change-password', 'wallabag', '{app_key}', '--env=prod']],
                            ['script' => 'bin/console', 'args' => ['fos:user:deactivate', 'wallabag', '--env=prod']],
                        ],
                    ],
                    'webroot' => 'web',
                    'front' => 'app.php',
                    'admin' => ['path' => ''],
                    'post_install' => 'Installed automatically with the admin account you entered — log in at the site URL.',
                    'update_check' => ['type' => 'github', 'repo' => 'wallabag/wallabag'],
                ],
            ],
            [
                'slug' => 'prestashop', 'name' => 'PrestaShop', 'category' => 'E-commerce',
                'version' => '8.1.5', 'featured' => 0, 'sort' => 20,
                'description' => 'Full-featured online store builder with themes and modules.',
                'website' => 'https://www.prestashop-project.org', 'license' => 'OSL-3.0', 'vendor' => 'PrestaShop',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '7.4', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'intl']],
                    'database' => ['required' => true, 'prefix' => 'ps_'],
                    'download' => ['github' => ['repo' => 'PrestaShop/PrestaShop', 'asset' => 'prestashop_{version}.zip', 'tag_prefix' => ''], 'sha256' => '', 'format' => 'zip', 'strip_root' => true, 'nested' => 'prestashop.zip'],
                    'config' => [],
                    'setup' => ['cli' => [
                        ['script' => 'install/index_cli.php', 'args' => ['--domain={domain}', '--base_uri={scriptpath}', '--db_server={db_host}', '--db_user={db_user}', '--db_password={db_pass}', '--db_name={db_name}', '--db_clear=0', '--db_create=0', '--prefix={prefix}', '--name={title}', '--firstname=Admin', '--lastname=Admin', '--password={pass}', '--email={email}', '--country=us', '--language=en']],
                    ], 'cleanup' => ['install']],
                    'admin' => ['path' => 'admin/'],
                    'post_install' => 'Installed automatically with the admin account you entered — log in at /admin/.',
                    'update_check' => ['type' => 'github', 'repo' => 'PrestaShop/PrestaShop'],
                ],
            ],
            [
                'slug' => 'mediawiki', 'name' => 'MediaWiki', 'category' => 'Wikis',
                'version' => '1.44.6', 'featured' => 0, 'sort' => 21,
                'description' => 'The wiki engine behind Wikipedia — collaboration at any scale.',
                'website' => 'https://www.mediawiki.org', 'license' => 'GPL-2.0', 'vendor' => 'Wikimedia',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.1', 'extensions' => ['mysqli', 'curl', 'mbstring', 'xml', 'json', 'openssl', 'intl']],
                    'database' => ['required' => true, 'prefix' => 'wiki_'],
                    'download' => ['url' => 'https://releases.wikimedia.org/mediawiki/1.44/mediawiki-core-1.44.6.tar.gz', 'sha256' => '', 'format' => 'tar', 'strip_root' => true],
                    // maintenance/install.php writes LocalSettings.php itself.
                    // Positional args: wiki name, then admin user.
                    'config' => [],
                    'setup' => ['cli' => [
                        ['script' => 'maintenance/install.php', 'php_version' => '{php}', 'args' => ['{title}', '{user}', '--pass={pass}', '--dbtype=mysql', '--dbserver={db_host}', '--dbname={db_name}', '--dbuser={db_user}', '--dbpass={db_pass}', '--dbprefix={prefix}', '--server={origin}', '--scriptpath={scriptpath}', '--lang=en', '--with-extensions']],
                    ]],
                    'webroot' => 'public',
                    'admin' => ['path' => ''],
                    'post_install' => 'Installed automatically with the admin account you entered — just log in at the site URL.',
                    'update_check' => ['type' => 'static'],
                ],
            ],
            [
                'slug' => 'firefly', 'name' => 'Firefly III', 'category' => 'Finance',
                'version' => '6.1.18', 'featured' => 0, 'sort' => 22,
                'description' => 'Personal finance manager — budgets, reports and transaction rules.',
                'website' => 'https://www.firefly-iii.org', 'license' => 'AGPL-3.0', 'vendor' => 'Firefly III',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.3', 'extensions' => ['mysqli', 'curl', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'intl', 'bcmath', 'sodium']],
                    'database' => ['required' => true, 'prefix' => ''],
                    'download' => ['github' => ['repo' => 'firefly-iii/firefly-iii', 'asset' => 'FireflyIII-v{version}.zip', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        'files' => ['.env' => "APP_ENV=production\nAPP_DEBUG=false\nAPP_KEY={app_key}\nAPP_URL={url}\nSITE_OWNER={email}\nTRUSTED_PROXIES=*\nTZ=UTC\nLOG_CHANNEL=stack\nCACHE_DRIVER=file\nSESSION_DRIVER=file\nDB_CONNECTION=mysql\nDB_HOST={db_host}\nDB_PORT=3306\nDB_SOCKET=\nDB_DATABASE={db_name}\nDB_USERNAME={db_user}\nDB_PASSWORD={db_pass}\nMAIL_MAILER=log\nMAIL_FROM={email}\n",
                            // One-shot owner provisioner (Firefly ships no
                            // create-user CLI): argv carries the secrets (no
                            // shell/quote layer — JSON argv), then it deletes
                            // itself. CLI-only, never linked anywhere.
                            'panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 3, '');
    if (($__e = getenv('CWH_SETUP_PASS')) !== false && $__e !== '') { $args[2] = $__e; }
$email = $args[1];
$pass = $args[2];
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
    fwrite(STDERR, "usage: panel-setup.php <email> <password>\n");
    exit(1);
}
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!FireflyIII\User::where('email', $email)->exists()) {
    $user = FireflyIII\User::create(['email' => $email, 'password' => Illuminate\Support\Facades\Hash::make($pass)]);
    $group = FireflyIII\Models\UserGroup::create(['title' => $email]);
    $role = FireflyIII\Models\UserRole::where('title', 'owner')->firstOrFail();
    FireflyIII\Models\GroupMembership::create(['user_id' => $user->id, 'user_group_id' => $group->id, 'user_role_id' => $role->id]);
    $user->user_group_id = $group->id;
    $user->save();
    echo "owner ready\n";
} else {
    echo "owner exists\n";
}
@unlink(__FILE__);
PANELSETUP,
                        ],
                        'cli' => [
                            ['script' => 'artisan', 'args' => ['migrate', '--seed', '--force']],
                            ['script' => 'artisan', 'args' => ['passport:keys', '--force']],
                            ['script' => 'artisan', 'args' => ['passport:client', '--personal', '--name=Firefly III Personal Access Client']],
                            ['script' => 'artisan', 'args' => ['passport:client', '--password', '--name=Firefly III Password Grant Client']],
                            ['script' => 'artisan', 'args' => ['firefly-iii:upgrade-database']],
                            ['script' => 'panel-setup.php', 'args' => ['{email}', '{pass}']],
                        ],
                        'cron' => [
                            ['schedule' => '* * * * *', 'command' => '{phpbin} {dest}/artisan firefly-iii:cron'],
                        ],
                    ],
                    'webroot' => 'public',
                    'admin' => ['path' => ''],
                    'post_install' => 'Installed automatically with the admin account you entered — log in with your email address at the site URL. Recurring-task cron is enabled automatically.',
                    'update_check' => ['type' => 'github', 'repo' => 'firefly-iii/firefly-iii'],
                ],
            ],
            [
                'slug' => 'akaunting', 'name' => 'Akaunting', 'category' => 'Finance',
                'version' => '3.1.12', 'featured' => 0, 'sort' => 23,
                'description' => 'Online accounting for small businesses — invoicing, expenses, reports.',
                'website' => 'https://akaunting.com', 'license' => 'GPL-3.0', 'vendor' => 'Akaunting',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.1', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'intl', 'bcmath']],
                    'database' => ['required' => true, 'prefix' => 'ak_'],
                    'download' => ['github' => ['repo' => 'akaunting/akaunting', 'asset' => 'Akaunting_{version}-Stable.zip', 'tag_prefix' => ''], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => ['cli' => [
                        ['script' => 'artisan', 'args' => ['install', '--db-host={db_host}', '--db-port=3306', '--db-name={db_name}', '--db-username={db_user}', '--db-password={db_pass}', '--db-prefix={prefix}', '--company-name={title}', '--company-email={email}', '--admin-email={email}', '--admin-password={pass}']],
                    ]],
                    'admin' => ['path' => ''],
                    'post_install' => 'Installed automatically — log in with the admin email you entered.',
                    'update_check' => ['type' => 'github', 'repo' => 'akaunting/akaunting'],
                ],
            ],
            [
                'slug' => 'elgg', 'name' => 'Elgg', 'category' => 'Social',
                'version' => '6.0.4', 'featured' => 0, 'sort' => 24,
                'description' => 'Social networking engine for communities, intranets and education.',
                'website' => 'https://elgg.org', 'license' => 'GPL-2.0', 'vendor' => 'Elgg Foundation',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.3', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'json', 'openssl', 'intl']],
                    'database' => ['required' => true, 'prefix' => 'elgg_'],
                    'download' => ['github' => ['repo' => 'Elgg/Elgg', 'asset' => 'elgg-{version}.zip', 'tag_prefix' => ''], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        // elgg-cli installs from a config file (no unattended
                        // flags): write it under a throwaway subdir with the
                        // install secrets, then remove the whole dir via
                        // cleanup. Dataroot lives outside the docroot.
                        'files' => ['elgg-setup/install-config.php' => <<<'ELGGCONF'
<?php
return array(
    'displayname' => '{user}',
    'username' => '{user}',
    'password' => '{pass}',
    'email' => '{email}',
    'dbhost' => '{db_host}',
    'dbport' => '3306',
    'dbuser' => '{db_user}',
    'dbpassword' => '{db_pass}',
    'dbname' => '{db_name}',
    'dbprefix' => '{prefix}',
    'sitename' => '{title}',
    'siteemail' => '{email}',
    'wwwroot' => '{url}/',
    'dataroot' => '{home}/elggdata-{dir}',
    'timezone' => 'UTC',
);
ELGGCONF,
                        ],
                        'cli' => [
                            ['script' => 'vendor/bin/elgg-cli', 'args' => ['install', '--config={dest}/elgg-setup/install-config.php', '--no-interaction']],
                        ],
                        'cron' => [
                            ['schedule' => '* * * * *', 'command' => '/usr/bin/curl -fsS -o /dev/null {url}/cron/minute/'],
                            ['schedule' => '*/5 * * * *', 'command' => '/usr/bin/curl -fsS -o /dev/null {url}/cron/fiveminute/'],
                            ['schedule' => '@hourly', 'command' => '/usr/bin/curl -fsS -o /dev/null {url}/cron/hourly/'],
                            ['schedule' => '@daily', 'command' => '/usr/bin/curl -fsS -o /dev/null {url}/cron/daily/'],
                        ],
                        'datadirs' => ['elggdata-{dir}'],
                        'cleanup' => ['elgg-setup', 'install.php', 'upgrade.php'],
                    ],
                    'admin' => ['path' => 'admin/'],
                    'post_install' => 'Installed automatically with the admin account you entered — log in at the site URL. Background cron (minute/hourly/daily) is enabled automatically.',
                    'update_check' => ['type' => 'github', 'repo' => 'Elgg/Elgg'],
                ],
            ],
            [
                'slug' => 'osticket', 'name' => 'osTicket', 'category' => 'Utilities',
                'version' => '1.18.1', 'featured' => 0, 'sort' => 26,
                'description' => 'Open-source support ticket system with email integration.',
                'website' => 'https://osticket.com', 'license' => 'GPL-2.0', 'vendor' => 'osTicket',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.1', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'json', 'openssl', 'imap']],
                    'database' => ['required' => true, 'prefix' => 'ost_'],
                    'download' => ['github' => ['repo' => 'osTicket/osTicket', 'asset' => 'osTicket-v{version}.zip', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'zip', 'strip_root' => true, 'strip_dir' => 'upload'],
                    'config' => [],
                    'setup' => [
                        // No CLI installer ships with 1.18: drive the upstream
                        // browser wizard headless (schema + default data +
                        // admin + welcome ticket + config rewrite, all by its
                        // own code). Secrets ride argv (JSON, quote-safe);
                        // the script never stays reachable (setup/ is removed
                        // by cleanup below) and refuses non-CLI runs.
                        'files' => ['panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 13, '');
    if (($__e = getenv('CWH_SETUP_ADMIN_PASS')) !== false && $__e !== '') { $args[6] = $__e; }
    if (($__e = getenv('CWH_SETUP_DB_PASS')) !== false && $__e !== '') { $args[10] = $__e; }
$title = trim($args[1]);
$sysemail = trim($args[2]);
$fname = trim($args[3]);
$lname = trim($args[4]);
$adminuser = trim($args[5]);
$adminpass = $args[6];
$dbhost = trim($args[7]);
$dbname = trim($args[8]);
$dbuser = trim($args[9]);
$dbpass = $args[10];
$adminemail = trim($args[11] ?? '');
$siteurl = rtrim(trim($args[12] ?? ''), '/');
if ($title === '' || $fname === '' || $lname === ''
    || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $adminuser) || strlen($adminpass) < 8
    || $dbhost === '' || $dbname === '' || $dbuser === '' || $dbpass === ''
    || !filter_var($adminemail, FILTER_VALIDATE_EMAIL) || $siteurl === ''
) {
    fwrite(STDERR, "usage: panel-setup.php <title> <sysemail> <fname> <lname> <adminuser> <adminpass> <dbhost> <dbname> <dbuser> <dbpass> <adminemail> <siteurl>\n");
    exit(1);
}
chdir(__DIR__);
// The installer's own bootstrap (paths, validator, translations, DB
// layer). NOT Bootstrap::loadCode(): that pulls filter classes whose table
// constants don't exist until the installer defines them below.
require __DIR__ . '/setup/setup.inc.php';
require __DIR__ . '/setup/inc/class.installer.php';
$config = INCLUDE_DIR . 'ost-config.php';
if (!file_exists($config) && file_exists(INCLUDE_DIR . 'ost-sampleconfig.php')) {
    copy(INCLUDE_DIR . 'ost-sampleconfig.php', $config);
}
$installer = new Installer($config);
$ok = $installer->install(array(
    'name' => $title,
    'email' => $sysemail,
    'fname' => $fname,
    'lname' => $lname,
    'admin_email' => $adminemail,
    'username' => $username,
    'passwd' => $passwd,
    'passwd2' => $passwd,
    'prefix' => 'ost_',
    'dbhost' => $dbhost,
    'dbname' => $dbname,
    'dbuser' => $dbuser,
    'dbpass' => $dbpass,
    'lang_id' => 'en_US',
));
if (!$ok) {
    foreach ((array) $installer->getErrors() as $e) {
        fwrite(STDERR, (is_string($e) ? $e : print_r($e, true)) . "\n");
    }
    exit(1);
}
echo "installed\n";
PANELSETUP,
                        ],
                        'cli' => [
                            ['script' => 'panel-setup.php', 'args' => ['{title}', 'support@{domain}', '{user}', 'Admin', '{email}', '{user}', '{pass}', '{db_host}', '{db_name}', '{db_user}', '{db_pass}']],
                        ],
                        'cron' => [
                            ['schedule' => '*/5 * * * *', 'command' => '{phpbin} {dest}/api/cron.php'],
                        ],
                        'cleanup' => ['setup'],
                    ],
                    'admin' => ['path' => 'scp/'],
                    'post_install' => 'Installed automatically with the admin account you entered — log in at /scp/. Mail fetching runs every 5 minutes via cron.',
                    'update_check' => ['type' => 'github', 'repo' => 'osTicket/osTicket'],
                ],
            ],
            [
                'slug' => 'roundcube', 'name' => 'Roundcube', 'category' => 'Utilities',
                'version' => '1.7.4', 'featured' => 0, 'sort' => 27,
                'description' => 'Browser-based IMAP email client with address book and folders.',
                'website' => 'https://roundcube.net', 'license' => 'GPL-3.0', 'vendor' => 'Roundcube',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.1', 'extensions' => ['mysqli', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'intl']],
                    'database' => ['required' => true, 'prefix' => ''],
                    'download' => ['github' => ['repo' => 'roundcube/roundcubemail', 'asset' => 'roundcubemail-{version}-complete.tar.gz', 'tag_prefix' => ''], 'sha256' => '', 'format' => 'tar', 'strip_root' => true],
                    'config' => ['template' => 'roundcube', 'file' => 'config/config.inc.php'],
                    'setup' => ['sql' => ['schema' => 'SQL/mysql.initial.sql']],
                    'admin' => ['path' => ''],
                    'post_install' => 'Configured for the local IMAP/SMTP servers — log in with any mailbox you create under Email.',
                    'update_check' => ['type' => 'github', 'repo' => 'roundcube/roundcubemail'],
                ],
            ],
            [
                'slug' => 'snappymail', 'name' => 'SnappyMail', 'category' => 'Utilities',
                'version' => '2.38.2', 'featured' => 0, 'sort' => 28,
                'description' => 'Lightweight modern webmail with a fast responsive interface.',
                'website' => 'https://snappymail.eu', 'license' => 'AGPL-3.0', 'vendor' => 'SnappyMail',
                'manifest' => [
                    'type' => 'php',
                    'requirements' => ['php_min' => '8.1', 'extensions' => ['curl', 'mbstring', 'xml', 'zip', 'json', 'openssl']],
                    'database' => ['required' => false],
                    'download' => ['github' => ['repo' => 'the-djmaze/snappymail', 'asset' => 'snappymail-{version}.zip', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        // Admin credentials live as bcrypt in application.ini
                        // (overlayed onto built-in defaults, so a minimal
                        // file suffices — no container bootstrap needed).
                        'files' => ['data/_data_/_default_/configs/application.ini' => "[security]\nadmin_login = \"{user}\"\nadmin_password = \"{pass_hash}\"\n"],
                    ],
                    'admin' => ['path' => '?/Admin'],
                    'post_install' => 'Installed automatically — open ?/Admin and log in with the credentials you entered, then add mail domains.',
                    'update_check' => ['type' => 'github', 'repo' => 'the-djmaze/snappymail'],
                ],
            ],
            [
                'slug' => 'serendipity', 'name' => 'Serendipity', 'category' => 'Blogs',
                'version' => '2.6.1', 'featured' => 0, 'sort' => 29,
                'description' => 'Veteran blog engine with plugins, themes and multi-author support.',
                'website' => 'https://docs.s9y.org', 'license' => 'BSD-3-Clause', 'vendor' => 'Serendipity',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.0', 'extensions' => ['mysqli', 'gd', 'mbstring', 'xml', 'json', 'openssl']],
                    'database' => ['required' => true, 'prefix' => 'serendipity_'],
                    'download' => ['github' => ['repo' => 's9y/Serendipity', 'asset' => 'serendipity-{version}.zip', 'tag_prefix' => ''], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => ['template' => 'serendipity', 'file' => 'serendipity_config_local.inc.php', 'prefix' => 'serendipity_'],
                    'setup' => ['sql' => [
                        'schema' => 'sql/db.sql',
                        'prefix_from' => '{PREFIX}',
                        'prefix_to' => 'serendipity_',
                        'tokens' => ['{AUTOINCREMENT}' => 'int(11) not null auto_increment', '{PRIMARY}' => 'primary key', '{UNSIGNED}' => 'unsigned', '{FULLTEXT}' => 'FULLTEXT', '{FULLTEXT_MYSQL}' => 'FULLTEXT', '{BOOLEAN}' => 'tinyint(1)', '{TEXT}' => 'LONGTEXT', '{UTF_8}' => 'DEFAULT CHARSET=utf8mb4'],
                        'admin' => ['table' => 'authors', 'user_col' => 'username', 'pass_col' => 'password', 'email_col' => 'email', 'hash' => 'bcrypt', 'extra' => ['realname' => 'Administrator', 'userlevel' => '255', 'right_publish' => '1', 'hashtype' => '2', 'mail_comments' => '1', 'mail_trackbacks' => '1']],
                    ]],
                    'admin' => ['path' => 'serendipity_admin.php'],
                    'post_install' => 'Installed automatically with the admin account you entered — log in at /serendipity_admin.php.',
                    'update_check' => ['type' => 'github', 'repo' => 's9y/Serendipity'],
                ],
            ],
            [
                'slug' => 'textpattern', 'name' => 'Textpattern', 'category' => 'Blogs',
                'version' => '4.9.1', 'featured' => 0, 'sort' => 30,
                'description' => 'Lean blogging CMS with a powerful textile editor and plugin system.',
                'website' => 'https://textpattern.com', 'license' => 'GPL-2.0', 'vendor' => 'Textpattern',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '7.4', 'extensions' => ['mysqli', 'mbstring', 'xml', 'json']],
                    'database' => ['required' => true, 'prefix' => ''],
                    'download' => ['github' => ['repo' => 'textpattern/textpattern', 'asset' => 'textpattern-{version}.zip', 'tag_prefix' => ''], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        // Stock CLI installer (textpattern/setup/setup.php
                        // --config=...) driven by a generated JSON config.
                        // The JSON is written by a driver (json_encode —
                        // quote-safe for any password) rather than static
                        // setup.files text, then the stock installer runs
                        // as step two. CLI-only.
                        'files' => ['panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 10, '');
    if (($__e = getenv('CWH_SETUP_PASS')) !== false && $__e !== '') { $args[4] = $__e; }
    if (($__e = getenv('CWH_SETUP_DB_PASS')) !== false && $__e !== '') { $args[8] = $__e; }
$title = trim($args[1]);
$email = trim($args[2]);
$user = trim($args[3]);
$pass = $args[4] ?? '';
$dbhost = trim($args[5]);
$dbname = trim($args[6]);
$dbuser = trim($args[7]);
$dbpass = $args[8] ?? '';
$url = rtrim(trim($args[9] ?? ''), '/');
if ($title === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $user) || strlen($pass) < 8
    || $dbhost === '' || $dbname === '' || $dbuser === '' || $dbpass === ''
    || $url === ''
) {
    fwrite(STDERR, "usage: panel-setup.php <title> <email> <user> <pass> <dbhost> <dbname> <dbuser> <dbpass> <url>\n");
    exit(1);
}
chdir(__DIR__);
$conf = [
    'site' => ['language_code' => 'en', 'public_url' => $url],
    'database' => ['user' => $dbuser, 'password' => $dbpass, 'host' => $dbhost, 'db_name' => $dbname, 'table_prefix' => ''],
    'user' => ['full_name' => $user, 'email' => $email, 'login_name' => $user, 'password' => $pass],
];
file_put_contents(__DIR__ . '/txp-setup.json', json_encode($conf, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "config written\n";
PANELSETUP,
                        ],
                        'cli' => [
                            ['script' => 'panel-setup.php', 'args' => ['{title}', '{email}', '{user}', '{pass}', '{db_host}', '{db_name}', '{db_user}', '{db_pass}', '{url}']],
                            ['script' => 'textpattern/setup/setup.php', 'args' => ['--config={dest}/txp-setup.json']],
                        ],
                        'cleanup' => ['panel-setup.php', 'txp-setup.json', 'textpattern/setup'],
                    ],
                    'admin' => ['path' => 'textpattern/'],
                    'post_install' => 'Installed automatically with the admin account you entered — log in under /textpattern/.',
                    'update_check' => ['type' => 'github', 'repo' => 'textpattern/textpattern'],
                ],
            ],
            [
                'slug' => 'shlink', 'name' => 'Shlink', 'category' => 'Utilities',
                'version' => '5.1.6', 'featured' => 0, 'sort' => 31,
                'description' => 'Self-hosted URL shortener with QR codes, tracking and API.',
                'website' => 'https://shlink.io', 'license' => 'MIT', 'vendor' => 'Shlink',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.4', 'extensions' => ['mysqli', 'curl', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'intl']],
                    // Shlink's router matches absolute paths: like Invoice
                    // Ninja it must live at a website root.
                    'root_only' => true,
                    'database' => ['required' => true, 'prefix' => ''],
                    'download' => ['github' => ['repo' => 'shlinkio/shlink', 'asset' => 'shlink{version}_php8.4_dist.zip', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        // Shlink reads config from config/params/generated_config.php
                        // (written by its interactive installer) — write the
                        // essential keys directly. init spawns child PHP
                        // processes inheriting CWD, and the agent runs with a
                        // cwd the account cannot see, so a driver chdirs into
                        // the tree first and execs init (schema + initial API
                        // key, printed to the install log). CLI-only.
                        'files' => ['config/params/generated_config.php' => <<<'SHLINKCONF'
<?php

/* Shlink config generated by the panel installer */

return [
    'DB_DRIVER' => 'mysql',
    'DB_HOST' => '{db_host}',
    'DB_PORT' => '3306',
    'DB_NAME' => '{db_name}',
    'DB_USER' => '{db_user}',
    'DB_PASSWORD' => '{db_pass}',
    'DEFAULT_DOMAIN' => '{domain}',
    'IS_HTTPS_ENABLED' => true,
];
SHLINKCONF,
                            'panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
chdir(__DIR__);
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/vendor/bin/shlink-installer')
    . ' init -n --initial-api-key 2>&1';
exec($cmd, $out, $code);
echo implode("\n", $out) . "\n";
PANELSETUP,
                        ],
                        'cli' => [
                            ['script' => 'panel-setup.php', 'args' => []],
                        ],
                        'cleanup' => ['panel-setup.php'],
                    ],
                    'webroot' => 'public',
                    'admin' => ['path' => ''],
                    'post_install' => 'Installed automatically — manage it via the API (find your initial API key in the install log). Pair with a Shlink web client for a UI.',
                    'update_check' => ['type' => 'github', 'repo' => 'shlinkio/shlink'],
                ],
            ],
            [
                'slug' => 'chamilo', 'name' => 'Chamilo', 'category' => 'Education',
                'version' => '3.0.0', 'featured' => 0, 'sort' => 32,
                'description' => 'E-learning and course management platform for schools and companies.',
                'website' => 'https://chamilo.org', 'license' => 'GPL-3.0', 'vendor' => 'Chamilo',
                'manifest' => [
                    'type' => 'php-mysql',
                    // PHP 8.3+ (composer floor); the heavy step-6 install
                    // needs room (time + memory) that rides the auto-pin via
                    // php_settings. Serves public/ via the webroot alias.
                    'requirements' => ['php_min' => '8.3', 'extensions' => ['pdo_mysql', 'mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'fileinfo', 'intl', 'ldap', 'soap', 'sodium', 'bcmath', 'exif']],
                    'database' => ['required' => true, 'prefix' => ''],
                    'download' => ['github' => ['repo' => 'chamilo/chamilo-lms', 'asset' => 'chamilo-{version}.zip', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'webroot' => 'public',
                    'root_only' => true,
                    'php_settings' => ['max_execution_time' => '300', 'memory_limit' => '512M'],
                    'setup' => [
                        // No CLI installer ships: the driver replays the
                        // 7-step wizard headless over the live vhost
                        // (front-door :80 with Host header). Steps are
                        // POST-flag driven with ?running=1 importing POST into
                        // scope (without it every step silently falls back to
                        // defaults, e.g. DB user root); the Vue app reposts
                        // accumulated stepData every step, so the driver
                        // re-sends DB + admin fields on the heavy step 6.
                        // State is session cookies (in-memory
                        // harvest-and-resend); secrets ride POST bodies
                        // (never URLs). Transport errors and stray 404s
                        // (worker/FPM restarts around setup time) retry
                        // briefly; genuine breakage still fails loud. The
                        // heavy step 6 retries boundedly (it drops +
                        // recreates, so re-runs are safe). Root-only: the
                        // frontend uses domain-absolute asset URLs, so
                        // subdirectories 404 its assets (upstream answers
                        // subfolder installs with manual config surgery —
                        // out of scope); root installs serve docroot/webroot
                        // with no surgery.
                        // install/ removed by cleanup below.
                        'files' => ['panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 10, '');
    if (($__e = getenv('CWH_SETUP_PASS')) !== false && $__e !== '') { $args[4] = $__e; }
    if (($__e = getenv('CWH_SETUP_DB_PASS')) !== false && $__e !== '') { $args[8] = $__e; }
$title = trim($args[1]);
$email = trim($args[2]);
$user = trim($args[3]);
$pass = $args[4] ?? '';
$dbhost = trim($args[5]);
$dbname = trim($args[6]);
$dbuser = trim($args[7]);
$dbpass = $args[8] ?? '';
$url = rtrim(trim($args[9]), '/');
if ($title === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $user) || strlen($pass) < 8
    || $dbhost === '' || $dbname === '' || $dbuser === '' || $dbpass === ''
    || !preg_match('#^https?://#', $url)
) {
    fwrite(STDERR, "usage: panel-setup.php <title> <email> <user> <pass> <dbhost> <dbname> <dbuser> <dbpass> <url>\n");
    exit(1);
}
$parts = parse_url($url);
$domain = $parts['host'] ?? '';
$path = rtrim($parts['path'] ?? '', '/');
if ($domain === '') {
    fwrite(STDERR, "bad url\n");
    exit(1);
}
// The installer lives under public/ in the tree; at driver time the
// domain docroot still serves the raw tree (the docroot reroute lands
// post-install), so the physical path is deterministic.
$endpoint = 'http://127.0.0.1' . $path . '/public/main/install/index.php';
$cookies = [];
$request = function ($fields) use ($endpoint, $domain, &$cookies) {
    // Restart windows (pin regen + master rewrites restart the worker and
    // FPM around setup time) can answer any single request with a
    // transport error or a stray 404 — retry those briefly, but never mask
    // a genuinely missing installer (final failure stays loud, with a body
    // tail for diagnosis).
    $tries = 0;
    while (true) {
        $tries++;
        $target = $endpoint . '?running=1';
        $ch = curl_init($target);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Host: ' . $domain]);
        if ($cookies !== []) {
            $pairs = [];
            foreach ($cookies as $k => $v) {
                $pairs[] = $k . '=' . $v;
            }
            curl_setopt($ch, CURLOPT_COOKIE, implode('; ', $pairs));
        }
        if ($fields !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            if ($tries < 8) {
                sleep(5);
                continue;
            }
            return [0, '', 'curl error: ' . $err];
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if (($code === 404 || $code === 502 || $code === 503 || $code === 504) && $tries < 8) {
            sleep(5);
            continue;
        }
        foreach (explode("\n", substr($raw, 0, $hsize)) as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                $pair = trim(substr($line, 11));
                $semi = strpos($pair, ';');
                if ($semi !== false) {
                    $pair = substr($pair, 0, $semi);
                }
                $eq = strpos($pair, '=');
                if ($eq !== false) {
                    $cookies[trim(substr($pair, 0, $eq))] = trim(substr($pair, $eq + 1));
                }
            }
        }
        return [$code, substr($raw, $hsize), ''];
    }
};
$fail = function ($msg) {
    fwrite(STDERR, $msg . "\n");
    exit(1);
};
$stepOf = function ($body) {
    if (preg_match('/var installerData = (\{.*?\});/s', $body, $m)) {
        $d = json_decode($m[1], true);
        if (is_array($d) && isset($d['currentStep'])) {
            return (int) $d['currentStep'];
        }
    }
    return 0;
};
$dbFields = ['dbHostForm' => $dbhost, 'dbPortForm' => '3306', 'dbUsernameForm' => $dbuser, 'dbPassForm' => $dbpass, 'dbNameForm' => $dbname];
$adminFields = ['adminFirstName' => 'Site', 'adminLastName' => 'Admin', 'loginForm' => $user, 'passForm' => $pass, 'emailForm' => $email, 'encryptPassForm' => 'bcrypt', 'institutionForm' => $title, 'institutionUrlForm' => $url . '/', 'campusForm' => 'Main campus', 'languageForm' => 'english'];
$r = $request(['language_list' => 'english', 'step1' => '1']);
if ($r[0] !== 200 || $stepOf($r[1]) !== 2) {
    $fail('step 1 did not advance (http ' . $r[0] . ' step ' . $stepOf($r[1]) . ')');
}
$r = $request(['step1' => '1', 'step2_install' => '1']);
if ($r[0] !== 200 || $stepOf($r[1]) !== 3) {
    $fail('step 2 did not advance (http ' . $r[0] . ' step ' . $stepOf($r[1]) . ')');
}
$r = $request(['step3' => '1']);
if ($r[0] !== 200 || $stepOf($r[1]) !== 4) {
    $fail('step 3 did not advance (http ' . $r[0] . ' step ' . $stepOf($r[1]) . ')');
}
$r = $request(['step4' => '1'] + $dbFields);
if ($r[0] !== 200 || $stepOf($r[1]) !== 5) {
    $fail('step 4 did not advance (http ' . $r[0] . ' step ' . $stepOf($r[1]) . ')');
}
$r = $request(['step5' => '1'] + $adminFields);
if ($r[0] !== 200 || $stepOf($r[1]) !== 6) {
    $fail('step 5 did not advance (http ' . $r[0] . ' step ' . $stepOf($r[1]) . ')');
}
$r = $request(['step6' => '1'] + $dbFields + $adminFields);
// The heavy step occasionally dies with an empty 500 on a cold tree
// (full-container build + schema + fixtures); it drops + recreates, so a
// bounded retry is safe. Log the body tail when it finally fails.
$step6tries = 0;
while (($r[0] !== 200 || $stepOf($r[1]) !== 7) && $step6tries < 3) {
    $step6tries++;
    sleep(10);
    $r = $request(['step6' => '1'] + $dbFields + $adminFields);
}
if ($r[0] !== 200 || $stepOf($r[1]) !== 7) {
    $fail('step 6 did not finish (http ' . $r[0] . ' step ' . $stepOf($r[1]) . '): ' . substr(strip_tags($r[1]), -500));
}
// Verify: .env written + admin row present.
if (!is_file(__DIR__ . '/.env')) {
    $fail('.env missing after install');
}
try {
    $c = new mysqli($dbhost, $dbuser, $dbpass, $dbname);
    if ($c->connect_error) {
        $fail('db connect failed after install: ' . $c->connect_error);
    }
    $q = $c->query("SELECT `username` FROM `user` WHERE `username` = '" . $c->real_escape_string($user) . "'");
    $ok = ($q && $q->num_rows > 0);
    $c->close();
    if (!$ok) {
        $fail('admin account missing after install');
    }
} catch (Throwable $e) {
    $fail('admin verify failed');
}
echo "chamilo setup complete\n";
PANELSETUP,
                        ],
                        'cli' => [
                            ['script' => 'panel-setup.php', 'args' => ['{title}', '{email}', '{user}', '{pass}', '{db_host}', '{db_name}', '{db_user}', '{db_pass}', '{url}']],
                        ],
                        'cleanup' => ['panel-setup.php', 'public/main/install'],
                    ],
                    'admin' => ['path' => 'admin'],
                    'post_install' => 'Installed automatically with the admin account you entered — log in at /admin/.',
                    'update_check' => ['type' => 'github', 'repo' => 'chamilo/chamilo-lms'],
                ],
            ],
            [
                'slug' => 'lychee', 'name' => 'Lychee', 'category' => 'Media',
                'version' => '7.8.3', 'featured' => 0, 'sort' => 33,
                'description' => 'Elegant self-hosted photo management with albums and sharing.',
                'website' => 'https://lycheeorg.github.io', 'license' => 'MIT', 'vendor' => 'LycheeOrg',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.4', 'extensions' => ['mysqli', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'bcmath']],
                    'database' => ['required' => true, 'prefix' => ''],
                    'webroot' => 'public',
                    'download' => ['github' => ['repo' => 'LycheeOrg/Lychee', 'asset' => 'Lychee.zip', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        'files' => ['.env' => "APP_NAME=Lychee\nAPP_ENV=production\nAPP_KEY={app_key}\nAPP_DEBUG=false\nAPP_URL={url}\nAPP_TIMEZONE=UTC\nLOG_CHANNEL=stack\nDB_CONNECTION=mysql\nDB_HOST={db_host}\nDB_PORT=3306\nDB_DATABASE={db_name}\nDB_USERNAME={db_user}\nDB_PASSWORD={db_pass}\nSESSION_DRIVER=file\nQUEUE_CONNECTION=sync\nCACHE_DRIVER=file\n"],
                        'cli' => [
                            ['script' => 'artisan', 'args' => ['migrate', '--force']],
                            ['script' => 'artisan', 'args' => ['lychee:create_user', '{user}', '{pass}', '--may-administrate']],
                        ],
                    ],
                    'admin' => ['path' => ''],
                    'post_install' => 'Installed automatically with the admin account you entered — just log in at the site URL.',
                    'update_check' => ['type' => 'github', 'repo' => 'LycheeOrg/Lychee'],
                ],
            ],
            [
                'slug' => 'espocrm', 'name' => 'EspoCRM', 'category' => 'CRM',
                'version' => '10.0.8', 'featured' => 0, 'sort' => 34,
                'description' => 'CRM for sales, marketing and support with a clean interface.',
                'website' => 'https://www.espocrm.com', 'license' => 'GPL-3.0', 'vendor' => 'EspoCRM',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.3', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl']],
                    'database' => ['required' => true, 'prefix' => ''],
                    'download' => ['github' => ['repo' => 'espocrm/espocrm', 'asset' => 'EspoCRM-{version}.zip', 'tag_prefix' => ''], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        // Headless run of install/cli.php: each -a action
                        // consumes urlencoded -d data into the persisted
                        // installer session (saveSettings → buildDatabase →
                        // createUser → finish). The driver builds the query
                        // strings (raw & or = in secrets would corrupt a
                        // static manifest) and runs the full sequence in one
                        // step. CLI-only.
                        'files' => ['panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 13, '');
    if (($__e = getenv('CWH_SETUP_ADMIN_PASS')) !== false && $__e !== '') { $args[6] = $__e; }
    if (($__e = getenv('CWH_SETUP_DB_PASS')) !== false && $__e !== '') { $args[10] = $__e; }
$title = trim($args[1]);
$sysemail = trim($args[2]);
$fname = trim($args[3]);
$lname = trim($args[4]);
$adminuser = trim($args[5]);
$adminpass = $args[6];
$dbhost = trim($args[7]);
$dbname = trim($args[8]);
$dbuser = trim($args[9]);
$dbpass = $args[10];
$adminemail = trim($args[11] ?? '');
$siteurl = rtrim(trim($args[12] ?? ''), '/');
if ($title === '' || $fname === '' || $lname === ''
    || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $adminuser) || strlen($adminpass) < 8
    || $dbhost === '' || $dbname === '' || $dbuser === '' || $dbpass === ''
    || !filter_var($adminemail, FILTER_VALIDATE_EMAIL) || $siteurl === ''
) {
    fwrite(STDERR, "usage: panel-setup.php <title> <sysemail> <fname> <lname> <adminuser> <adminpass> <dbhost> <dbname> <dbuser> <dbpass> <adminemail> <siteurl>\n");
    exit(1);
}
chdir(__DIR__);
$run = function ($action, $data = array()) {
    $query = http_build_query(array_merge(array('action' => $action), $data));
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/install/cli.php')
        . ' -a ' . escapeshellarg($action) . ' -d ' . escapeshellarg($query) . ' 2>&1';
    exec($cmd, $out, $code);
    $text = implode("\n", $out);
    echo $text . "\n";
    if ($code !== 0) {
        return false;
    }
    $decoded = json_decode($text, true);
    if (is_array($decoded) && array_key_exists('success', $decoded) && $decoded['success'] !== true) {
        return false;
    }
    return true;
};
$ok = $run('saveSettings', array(
    'db-name' => $dbname,
    'db-platform' => 'Mysql',
    'db-user-name' => $dbuser,
    'db-user-password' => $dbpass,
    'host-name' => $dbhost,
    'site-url' => $siteurl,
    'user-lang' => 'en_US',
));
$ok = $ok && $run('buildDatabase');
$ok = $ok && $run('createUser', array('user-name' => $adminuser, 'user-pass' => $adminpass));
$ok = $ok && $run('finish');
exit($ok ? 0 : 1);
PANELSETUP,
                        ],
                        'cli' => [
                            ['script' => 'panel-setup.php', 'args' => ['{title}', '{email}', '{user}', 'Admin', '{user}', '{pass}', '{db_host}', '{db_name}', '{db_user}', '{db_pass}', '{email}', '{url}']],
                        ],
                        'cron' => [
                            ['schedule' => '* * * * *', 'command' => '{phpbin} {dest}/cron.php'],
                        ],
                        'cleanup' => ['install', 'panel-setup.php'],
                    ],
                    'webroot' => 'public',
                    'webroot_static' => ['client'],
                    'webroot_routes' => ['api' => 'api/v1/index.php', 'portal' => 'portal/index.php'],
                    'admin' => ['path' => ''],
                    'post_install' => 'Installed automatically with the admin account you entered — log in at the site URL. Background jobs run every minute via cron.',
                    'update_check' => ['type' => 'github', 'repo' => 'espocrm/espocrm'],
                ],
            ],
            [
                'slug' => 'mautic', 'name' => 'Mautic', 'category' => 'Marketing',
                'version' => '7.2.0', 'featured' => 0, 'sort' => 35,
                'description' => 'Marketing automation — email campaigns, segments and lead scoring.',
                'website' => 'https://www.mautic.org', 'license' => 'GPL-3.0', 'vendor' => 'Mautic',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.1', 'db_min' => '10.11', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'intl', 'imap']],
                    'database' => ['required' => true, 'prefix' => ''],
                    'download' => ['github' => ['repo' => 'mautic/mautic', 'asset' => '{version}.zip', 'tag_prefix' => ''], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => ['cli' => [
                        ['script' => 'bin/console', 'args' => ['mautic:install', '{url}', '--db_driver=pdo_mysql', '--db_host={db_host}', '--db_port=3306', '--db_name={db_name}', '--db_user={db_user}', '--db_password={db_pass}', '--db_table_prefix={prefix}', '--admin_firstname=Admin', '--admin_lastname=Admin', '--admin_username={user}', '--admin_email={email}', '--admin_password={pass}', '--force', '-n']],
                    ],
                    'cron' => [
                        ['schedule' => '2-57/5 * * * *', 'command' => '{phpbin} {dest}/bin/console mautic:segments:update'],
                        ['schedule' => '3-58/5 * * * *', 'command' => '{phpbin} {dest}/bin/console mautic:campaigns:update'],
                        ['schedule' => '4-59/5 * * * *', 'command' => '{phpbin} {dest}/bin/console mautic:campaigns:trigger'],
                        ['schedule' => '*/5 * * * *', 'command' => '{phpbin} {dest}/bin/console mautic:emails:send'],
                    ]],
                    'admin' => ['path' => 's/login'],
                    'post_install' => 'Installed automatically with the admin account you entered — log in at /s/login. Recommended cron jobs (segments/campaigns/email) are created for you.',
                    'update_check' => ['type' => 'github', 'repo' => 'mautic/mautic'],
                ],
            ],
            [
                'slug' => 'monstra', 'name' => 'Monstra', 'category' => 'CMS',
                'version' => '3.0.4', 'featured' => 0, 'sort' => 36,
                'description' => 'Small and nimble flat-file CMS with a modern admin.',
                'website' => 'https://monstra.org', 'license' => 'MIT', 'vendor' => 'Monstra',
                'manifest' => [
                    'type' => 'php',
                    // PHP 7.4 ONLY: the engine calls get_magic_quotes_gpc()
                    // (removed in PHP 8.0) on every boot, and its Gelato
                    // error page references E_STRICT (deprecated in 8.4).
                    'requirements' => ['php_min' => '7.4', 'php_max' => '7.99', 'extensions' => ['curl', 'mbstring', 'xml', 'zip', 'json', 'openssl']],
                    'database' => ['required' => false],
                    'download' => ['github' => ['repo' => 'monstra-cms/monstra', 'asset' => 'monstra-{version}.zip', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        // No DB, no CLI upstream: the driver bootstraps the
                        // engine headless (BACKEND=true skips the Site front
                        // controller) and replays install.php's option
                        // updates + admin insert with Monstra's own classes.
                        // CLI-only. The driver MUST run under PHP 7.4 (see
                        // above), hence the pinned php_version step.
                        'files' => ['panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 5, '');
    if (($__e = getenv('CWH_SETUP_PASS')) !== false && $__e !== '') { $args[3] = $__e; }
$title = trim($args[1]);
$user = trim($args[2]);
$pass = $args[3] ?? '';
$email = trim($args[4] ?? '');
if ($title === '' || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $user)
    || strlen($pass) < 8 || !filter_var($email, FILTER_VALIDATE_EMAIL)
) {
    fwrite(STDERR, "usage: panel-setup.php <title> <login> <password> <email>\n");
    exit(1);
}
define('DS', DIRECTORY_SEPARATOR);
define('ROOT', __DIR__);
define('BACKEND', true);
define('MONSTRA_ACCESS', true);
$_SERVER['SERVER_PORT'] = $_SERVER['SERVER_PORT'] ?? '80';
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
require_once(ROOT . DS . 'engine' . DS . '_init.php');
Option::update(array('maintenance_status' => 'off',
    'sitename' => mb_substr($title, 0, 200),
    'siteurl' => rtrim('{url}', '/'),
    'description' => 'Site description',
    'keywords' => 'Site keywords',
    'slogan' => 'Site slogan',
    'defaultpage' => 'home',
    'timezone' => 'UTC',
    'system_email' => $email,
    'theme_site_name' => 'default',
    'theme_admin_name' => 'default'));
$users = new Table('users');
$id = $users->insert(array('login' => $user,
    'password' => Security::encryptPassword($pass),
    'email' => $email,
    'hash' => Text::random('alnum', 12),
    'date_registered' => time(),
    'role' => 'admin'));
if (empty($id)) {
    fwrite(STDERR, "admin creation failed\n");
    exit(1);
}
echo "installed admin\n";
PANELSETUP,
                        ],
                        'cli' => [
                            ['script' => 'panel-setup.php', 'args' => ['{title}', '{user}', '{pass}', '{email}'], 'php_version' => '74'],
                        ],
                        'cleanup' => ['panel-setup.php', 'install.php'],
                    ],
                    'admin' => ['path' => 'admin'],
                    'post_install' => 'Installed automatically with the admin account you entered — log in at /admin.',
                    'update_check' => ['type' => 'github', 'repo' => 'monstra-cms/monstra'],
                ],
            ],
            [
                'slug' => 'cmsms', 'name' => 'CMS Made Simple', 'category' => 'CMS',
                'version' => '2.2.22', 'featured' => 0, 'sort' => 37,
                'description' => 'Accessible CMS that stays out of the designer\'s way.',
                'website' => 'https://www.cmsmadesimple.org', 'license' => 'GPL-2.0', 'vendor' => 'CMSMS',
                'manifest' => [
                    'type' => 'php-mysql',
                    // PHP 8.1+ (installer + CMS floor) with the phar ext.
                    'requirements' => ['php_min' => '8.1', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'tokenizer']],
                    'database' => ['required' => true, 'prefix' => 'cms_'],
                    'download' => ['github' => ['repo' => 'cmsmadesimple/cmsmadesimple', 'asset' => 'cmsms-{version}-install.zip', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        // The asset is a versioned PHAR installer assistant
                        // (the release is EMBEDDED as data.tar.gz — no
                        // download drift). No CLI ships (cli.php dies
                        // 'not implemented'), so the driver replays the
                        // 9-step wizard headless over the live vhost
                        // (front-door :80 with Host header). Steps advance by
                        // GET/POST with a per-install randomized step var
                        // harvested from each redirect Location; state rides
                        // the session cookie (in-memory harvest-and-resend);
                        // secrets ride POST bodies (never URLs). The PHAR
                        // filename embeds the version, so the driver globs
                        // cmsms-*-install.php instead of naming it (freshness
                        // bumps must not strand setup). The driver deletes
                        // the installer on success; cleanup removes the
                        // driver itself.
                        'files' => ['panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 10, '');
    if (($__e = getenv('CWH_SETUP_PASS')) !== false && $__e !== '') { $args[4] = $__e; }
    if (($__e = getenv('CWH_SETUP_DB_PASS')) !== false && $__e !== '') { $args[8] = $__e; }
$title = trim($args[1]);
$email = trim($args[2]);
$user = trim($args[3]);
$pass = $args[4] ?? '';
$dbhost = trim($args[5]);
$dbname = trim($args[6]);
$dbuser = trim($args[7]);
$dbpass = $args[8] ?? '';
$url = rtrim(trim($args[9]), '/');
if ($title === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $user) || strlen($pass) < 8
    || $dbhost === '' || $dbname === '' || $dbuser === '' || $dbpass === ''
    || !preg_match('#^https?://#', $url)
) {
    fwrite(STDERR, "usage: panel-setup.php <title> <email> <user> <pass> <dbhost> <dbname> <dbuser> <dbpass> <url>\n");
    exit(1);
}
$parts = parse_url($url);
$domain = $parts['host'] ?? '';
$path = rtrim($parts['path'] ?? '', '/');
if ($domain === '') {
    fwrite(STDERR, "bad url\n");
    exit(1);
}
$phars = glob(__DIR__ . '/cmsms-*-install.php');
if (count($phars) !== 1) {
    fwrite(STDERR, 'installer phar not found (or ambiguous)' . "\n");
    exit(1);
}
$front = 'http://127.0.0.1' . $path . '/' . basename($phars[0]) . '/index.php';
$cookies = [];
$harvestCookies = function ($headers) use (&$cookies) {
    foreach (explode("\n", $headers) as $line) {
        if (stripos($line, 'Set-Cookie:') === 0) {
            $pair = trim(substr($line, 11));
            $semi = strpos($pair, ';');
            if ($semi !== false) {
                $pair = substr($pair, 0, $semi);
            }
            $eq = strpos($pair, '=');
            if ($eq !== false) {
                $cookies[trim(substr($pair, 0, $eq))] = trim(substr($pair, $eq + 1));
            }
        }
    }
};
$cookieHeader = function () use (&$cookies) {
    $pairs = [];
    foreach ($cookies as $k => $v) {
        $pairs[] = $k . '=' . $v;
    }
    return implode('; ', $pairs);
};
$request = function ($method, $url, $fields = null) use ($domain, &$cookies, $harvestCookies, $cookieHeader) {
    // Absolute Location headers point at the public host; replay them
    // against loopback with the Host header (front-door :443 serves the
    // panel vhost for customer domains).
    $p = parse_url($url);
    $target = 'http://127.0.0.1' . ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    $ch = curl_init($target);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Host: ' . $domain]);
    $jar = $cookieHeader();
    if ($jar !== '') {
        curl_setopt($ch, CURLOPT_COOKIE, $jar);
    }
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return [0, '', '', 'curl error: ' . $err];
    }
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = substr($raw, 0, $hsize);
    $harvestCookies($headers);
    $loc = '';
    foreach (explode("\n", $headers) as $line) {
        if (stripos($line, 'Location:') === 0) {
            $loc = trim(substr($line, 9));
        }
    }
    return [$code, substr($raw, $hsize), $loc, ''];
};
$fail = function ($msg) {
    fwrite(STDERR, $msg . "\n");
    exit(1);
};
$has = function ($body, $needle) {
    return stripos($body, $needle) !== false;
};
// Step 1: language select.
$r = $request('GET', $front);
if ($r[0] !== 200 || stripos($r[1], 'name="lang"') === false && stripos($r[1], "name='lang'") === false) {
    $fail('installer did not offer language select (http ' . $r[0] . ')');
}
$r = $request('POST', $front, ['lang' => 'en_US', 'verbose' => '0', 'next' => 'Next']);
if ($r[0] !== 302 || $r[2] === '') {
    $fail('step 1 did not advance (http ' . $r[0] . ')');
}
// Step 2: detect existing -> fresh install.
$page = $r[2];
$r = $request('GET', $page);
if ($r[0] !== 200 || !($has($r[1], 'name="install"') || $has($r[1], "name='install'"))) {
    $fail('step 2 missing (http ' . $r[0] . ')');
}
$r = $request('POST', $page, ['install' => 'Install']);
if ($r[0] !== 302 || $r[2] === '') {
    $fail('step 2 did not advance (http ' . $r[0] . ')');
}
// Step 3: compatibility tests -> follow the Next link (absent on hard fail).
$page = $r[2];
$r = $request('GET', $page);
if ($r[0] !== 200) {
    $fail('step 3 failed (http ' . $r[0] . ')');
}
$next = null;
if (preg_match('/href=["\']([^"\']*[?&][A-Za-z0-9_]+=\d+[^"\']*)["\']\s*[^>]*>\s*Next/s', $r[1], $m)) {
    $next = html_entity_decode($m[1]);
}
if ($next === null) {
    $fail('step 3 has no Next link (a required test failed)');
}
// Step 4: database credentials.
$page = $next;
$r = $request('GET', $page);
if ($r[0] !== 200 || !($has($r[1], 'name="dbname"') || $has($r[1], "name='dbname'"))) {
    $fail('step 4 missing (http ' . $r[0] . ')');
}
$r = $request('POST', $page, ['dbhost' => $dbhost, 'dbname' => $dbname, 'dbuser' => $dbuser, 'dbpass' => $dbpass, 'timezone' => 'UTC', 'next' => 'Next']);
if ($r[0] !== 302 || $r[2] === '') {
    $fail('step 4 did not advance (http ' . $r[0] . ')');
}
// Step 5: admin account.
$page = $r[2];
$r = $request('GET', $page);
if ($r[0] !== 200 || !($has($r[1], 'name="username"') || $has($r[1], "name='username'"))) {
    $fail('step 5 missing (http ' . $r[0] . ')');
}
$r = $request('POST', $page, ['username' => $user, 'emailaddr' => $email, 'password' => $pass, 'repeatpw' => $pass, 'next' => 'Next']);
if ($r[0] !== 302 || $r[2] === '') {
    $fail('step 5 did not advance (http ' . $r[0] . ' — check username/password rules)');
}
// Step 6: site settings.
$page = $r[2];
$r = $request('GET', $page);
if ($r[0] !== 200 || !($has($r[1], 'name="sitename"') || $has($r[1], "name='sitename'"))) {
    $fail('step 6 missing (http ' . $r[0] . ')');
}
$r = $request('POST', $page, ['sitename' => $title, 'languages' => ['en_US'], 'next' => 'Next']);
if ($r[0] !== 302 || $r[2] === '') {
    $fail('step 6 did not advance (http ' . $r[0] . ')');
}
// Step 7: extracts the embedded release (synchronous, slow).
$page = $r[2];
$r = $request('GET', $page);
if ($r[0] !== 200) {
    $fail('step 7 failed (http ' . $r[0] . ')');
}
$next = null;
if (preg_match('/href=["\']([^"\']*[?&][A-Za-z0-9_]+=\d+[^"\']*)["\']/', $r[1], $m)) {
    $next = html_entity_decode($m[1]);
}
if ($next === null) {
    $fail('step 7 has no Next link (extraction failed)');
}
// Step 8: database work (synchronous, slow).
$page = $next;
$r = $request('GET', $page);
if ($r[0] !== 200) {
    $fail('step 8 failed (http ' . $r[0] . ')');
}
$next = null;
if (preg_match('/href=["\']([^"\']*[?&][A-Za-z0-9_]+=\d+[^"\']*)["\']/', $r[1], $m)) {
    $next = html_entity_decode($m[1]);
}
if ($next === null) {
    $fail('step 8 has no Next link (schema install failed)');
}
// Step 9: finish.
$page = $next;
$r = $request('GET', $page);
if ($r[0] !== 200 || stripos($r[1], 'Finish') === false) {
    $fail('step 9 did not finish (http ' . $r[0] . ')');
}
// Verify: config written + admin row present.
if (!is_file(__DIR__ . '/config.php')) {
    $fail('config.php missing after install');
}
try {
    $c = new mysqli($dbhost, $dbuser, $dbpass, $dbname);
    if ($c->connect_error) {
        $fail('db connect failed after install: ' . $c->connect_error);
    }
    $q = $c->query("SELECT `username` FROM `cms_users` WHERE `username` = '" . $c->real_escape_string($user) . "'");
    $ok = ($q && $q->num_rows > 0);
    $c->close();
    if (!$ok) {
        $fail('admin account missing after install');
    }
} catch (Throwable $e) {
    $fail('admin verify failed');
}
// Remove the installer assistant (step 9 warns it must go).
foreach ($phars as $p) {
    @unlink($p);
}
echo "cmsms setup complete\n";
PANELSETUP,
                        ],
                        'cli' => [
                            ['script' => 'panel-setup.php', 'args' => ['{title}', '{email}', '{user}', '{pass}', '{db_host}', '{db_name}', '{db_user}', '{db_pass}', '{url}']],
                        ],
                        'cleanup' => ['panel-setup.php'],
                    ],
                    'admin' => ['path' => 'admin/'],
                    'post_install' => 'Installed automatically with the admin account you entered — log in at /admin/.',
                    'update_check' => ['type' => 'github', 'repo' => 'cmsmadesimple/cmsmadesimple'],
                ],
            ],
            [
                'slug' => 'leantime', 'name' => 'Leantime', 'category' => 'Project Management',
                'version' => '3.9.8', 'featured' => 0, 'sort' => 38,
                'description' => 'Project management for non-project-managers — tasks, milestones, timesheets.',
                'website' => 'https://leantime.io', 'license' => 'GPL-3.0', 'vendor' => 'Leantime',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.1', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl']],
                    'database' => ['required' => true, 'prefix' => ''],
                    'download' => ['github' => ['repo' => 'Leantime/leantime', 'asset' => 'Leantime-{tag}.zip', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        // Leantime loads config/.env (NOT the project-root
                        // .env — environmentPath points at config/).
                        'files' => ['config/.env' => "LEAN_APP_URL={url}\nLEAN_APP_DIR={scriptpath}\nLEAN_DEBUG=0\nLEAN_DB_HOST={db_host}\nLEAN_DB_USER={db_user}\nLEAN_DB_PASSWORD={db_pass}\nLEAN_DB_DATABASE={db_name}\nLEAN_DB_PORT=3306\nLEAN_SESSION_PASSWORD={app_key}\n",
                            // No install CLI ships: drive the install service
                            // headless (schema + company + inactive admin),
                            // then set the bcrypt password and activate via
                            // sql_post. Secrets ride argv (JSON, quote-safe).
                            // CLI-only.
                            'panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 5, '');
$email = trim($args[1]);
$fname = trim($args[2]);
$lname = trim($args[3]);
$company = trim($args[4]);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)
    || $fname === '' || $lname === '' || $company === ''
) {
    fwrite(STDERR, "usage: panel-setup.php <email> <firstname> <lastname> <company>\n");
    exit(1);
}
chdir(__DIR__);
define('LEAN_CLI', true);
define('ARTISAN_BINARY', 'bin/leantime');
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$installer = $app->make(Leantime\Domain\Install\Services\Install::class);
$values = array(
    'email' => $email,
    'firstname' => $fname,
    'lastname' => $lname,
    'company' => $company,
);
try {
    $installer->validateInstallInput($values);
} catch (Throwable $e) {
    fwrite(STDERR, 'invalid install input: ' . $e->getMessage() . "\n");
    exit(1);
}
if (!$installer->runInstall($values)) {
    fwrite(STDERR, "install failed\n");
    exit(1);
}
echo "installed\n";
PANELSETUP,
                        ],
                        'cli' => [
                            ['script' => 'panel-setup.php', 'args' => ['{email}', '{user}', 'Admin', '{title}']],
                        ],
                        'sql_post' => [
                            ['table' => 'zp_user', 'set' => ['password' => '{pass_hash}', 'status' => 'a'], 'where' => ['username' => '{email}']],
                        ],
                        'cleanup' => ['panel-setup.php'],
                    ],
                    'webroot' => 'public',
                    'admin' => ['path' => ''],
                    'post_install' => 'Installed automatically — log in with your email address at the site URL.',
                    'update_check' => ['type' => 'github', 'repo' => 'Leantime/leantime'],
                ],
            ],
            [
                'slug' => 'phpservermon', 'name' => 'PHP Server Monitor', 'category' => 'Monitoring',
                'version' => '3.5.2', 'featured' => 0, 'sort' => 39,
                'description' => 'Monitor websites and services with uptime checks and alerts.',
                'website' => 'https://www.phpservermonitor.org', 'license' => 'GPL-3.0', 'vendor' => 'PHP Server Monitor',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '7.4', 'extensions' => ['mysqli', 'curl', 'mbstring', 'xml', 'json']],
                    'database' => ['required' => true, 'prefix' => 'monitor_'],
                    'download' => ['github' => ['repo' => 'phpservermon/phpservermon', 'asset' => 'phpservermon-{version}.zip', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        // DB defines first (config.php), then a driver that
                        // bootstraps PSM headless (PSM_INSTALL skips the
                        // redirect-or-die block): Installer::install() builds
                        // the schema + seed rows, and the admin row is
                        // inserted with bcrypt directly (mirroring
                        // changePassword's final state). CLI-only.
                        'files' => [
                            'config.php' => <<<'PSMCONFIG'
<?php
define('PSM_DB_PREFIX', '{prefix}');
define('PSM_DB_USER', '{db_user}');
define('PSM_DB_PASS', '{db_pass}');
define('PSM_DB_NAME', '{db_name}');
define('PSM_DB_HOST', '{db_host}');
define('PSM_DB_PORT', '3306');
define('PSM_BASE_URL', '{url}');
define('PSM_WEBCRON_KEY', '');
PSMCONFIG,
                            'panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 8, '');
    if (($__e = getenv('CWH_SETUP_PASS')) !== false && $__e !== '') { $args[2] = $__e; }
    if (($__e = getenv('CWH_SETUP_DB_PASS')) !== false && $__e !== '') { $args[7] = $__e; }
$user = trim($args[1]);
$pass = $args[2] ?? '';
$email = trim($args[3] ?? '');
$dbhost = trim($args[4]);
$dbname = trim($args[5]);
$dbuser = trim($args[6]);
$dbpass = $args[7] ?? '';
if (!preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $user) || strlen($pass) < 8
    || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || $dbhost === '' || $dbname === '' || $dbuser === '' || $dbpass === ''
) {
    fwrite(STDERR, "usage: panel-setup.php <user> <pass> <email> <dbhost> <dbname> <dbuser> <dbpass>\n");
    exit(1);
}
define('PSM_INSTALL', true);
chdir(__DIR__);
require __DIR__ . '/src/bootstrap.php';
$db = new \psm\Service\Database($dbhost, $dbuser, $dbpass, $dbname, '3306');
$installer = new \psm\Util\Install\Installer($db);
$installer->install();
echo "schema done\n";
// Admin row with bcrypt directly (mirrors changePassword final state);
// the installer seeds everything else (servers, config, version).
$pdo = new \PDO(
    'mysql:host=' . $dbhost . ';dbname=' . $dbname . ';charset=utf8',
    $dbuser,
    $dbpass,
    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
);
$stmt = $pdo->prepare(
    'INSERT INTO `' . PSM_DB_PREFIX . 'users`'
    . ' (`user_name`, `password`, `level`, `name`, `mobile`, `pushover_key`,'
    . ' `pushover_device`, `telegram_id`, `jabber`, `email`)'
    . ' VALUES (?, ?, 10, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([$user, password_hash($pass, PASSWORD_BCRYPT), $user, '', '', '', '', '', $email]);
echo "installed admin\n";
PANELSETUP,
                        ],
                        'cli' => [
                            ['script' => 'panel-setup.php', 'args' => ['{user}', '{pass}', '{email}', '{db_host}', '{db_name}', '{db_user}', '{db_pass}']],
                        ],
                        'cleanup' => ['panel-setup.php', 'install.php'],
                    ],
                    'admin' => ['path' => ''],
                    'post_install' => 'Installed automatically with the admin account you entered — log in at the site URL, then add servers to monitor.',
                    'update_check' => ['type' => 'github', 'repo' => 'phpservermon/phpservermon'],
                ],
            ],
            [
                'slug' => 'concrete', 'name' => 'Concrete CMS', 'category' => 'CMS',
                'version' => '9.5.4', 'featured' => 0, 'sort' => 40,
                'description' => 'In-context editing CMS — edit pages directly on the page.',
                'website' => 'https://www.concretecms.com', 'license' => 'MIT', 'vendor' => 'Concrete CMS',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.1', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'intl', 'fileinfo']],
                    'database' => ['required' => true, 'prefix' => 'Concrete'],
                    'download' => ['github' => ['repo' => 'concretecms/concretecms', 'asset' => 'concrete-cms-{version}.zip', 'tag_prefix' => ''], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => ['cli' => [
                        ['script' => 'concrete/bin/concrete', 'args' => ['c5:install', '--db-server={db_host}', '--db-username={db_user}', '--db-password={db_pass}', '--db-database={db_name}', '--site={title}', '--canonical-url={url}/', '--admin-email={email}', '--admin-password={pass}', '--language=en_US', '--timezone=UTC', '--session-handler=file']],
                    ]],
                    'admin' => ['path' => 'index.php/dashboard'],
                    'post_install' => 'Installed automatically — log in at /index.php/login with the admin email you entered (Concrete uses the email as the username).',
                    'update_check' => ['type' => 'github', 'repo' => 'concretecms/concretecms'],
                ],
            ],
            [
                'slug' => 'typesetter', 'name' => 'Typesetter', 'category' => 'CMS',
                'version' => '5.1', 'featured' => 0, 'sort' => 41,
                'description' => 'True WYSIWYG flat-file CMS — edit content right on the page.',
                'website' => 'https://www.typesettercms.com', 'license' => 'GPL-3.0', 'vendor' => 'Typesetter',
                'manifest' => [
                    'type' => 'php',
                    // PHP 7.4 ONLY: the tree uses curly-brace string offsets
                    // ($str{0}), a compile fatal on PHP 8.0+, in core files
                    // (include/tool.php). Hence php_max plus a pinned
                    // php_version CLI step; subdir installs auto-pin 74.
                    'requirements' => ['php_min' => '7.4', 'php_max' => '7.99', 'extensions' => ['curl', 'mbstring', 'zip', 'json', 'openssl']],
                    'database' => ['required' => false],
                    'download' => ['github' => ['repo' => 'Typesetter/Typesetter', 'asset' => 'Typesetter-{version}.zip', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        // No database, no CLI upstream: the driver calls the
                        // supported programmatic API (also used by the
                        // Multi-Site addon) — gpInstall_Check() validates,
                        // Install_DataFiles_New() writes the data tree + the
                        // sha512 admin row (base_install=false skips session
                        // creation). CLI-only. Usernames are alnum plus ._
                        // (a '-' fails the stock check and falls back to the
                        // browser wizard with a warning, never fatal).
                        'files' => ['panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 5, '');
    if (($__e = getenv('CWH_SETUP_PASS')) !== false && $__e !== '') { $args[4] = $__e; }
$title = trim($args[1]);
$email = trim($args[2]);
$user = trim($args[3]);
$pass = $args[4] ?? '';
if ($title === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $user) || strlen($pass) < 8
) {
    fwrite(STDERR, "usage: panel-setup.php <title> <email> <user> <pass>\n");
    exit(1);
}
define('is_running', true);
// The agent runs setup scripts without cd-ing into the tree.
chdir(__DIR__);
global $dataDir;
$dataDir = __DIR__;
require __DIR__ . '/include/common.php';
spl_autoload_register(['\\gp\\tool', 'Autoload']);
\gp\tool::GetLangFile('main.inc', 'en');
$_POST = [
    'username' => $user,
    'password' => $pass,
    'password1' => $pass,
    'email' => $email,
    'site_title' => $title,
];
if (!\gp\install\Tools::gpInstall_Check()) {
    fwrite(STDERR, "install check failed (see log)\n");
    exit(1);
}
if (!\gp\install\Tools::Install_DataFiles_New(__DIR__, ['language' => 'en'], false)) {
    fwrite(STDERR, "data files install failed\n");
    exit(1);
}
echo "typesetter setup complete\n";
PANELSETUP,
                        ],
                        'cli' => [
                            ['script' => 'panel-setup.php', 'args' => ['{title}', '{email}', '{user}', '{pass}'], 'php_version' => '74'],
                        ],
                        'cleanup' => ['panel-setup.php'],
                    ],
                    'admin' => ['path' => 'Admin'],
                    'post_install' => 'Installed automatically with the admin account you entered — log in at /Admin.',
                    'update_check' => ['type' => 'github', 'repo' => 'Typesetter/Typesetter'],
                ],
            ],
            [
                'slug' => 'thirtybees', 'name' => 'thirty bees', 'category' => 'E-commerce',
                'version' => '1.7.0', 'featured' => 0, 'sort' => 42,
                'description' => 'Community-driven e-commerce fork focused on stability and speed.',
                'website' => 'https://thirtybees.com', 'license' => 'OSL-3.0', 'vendor' => 'thirty bees',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.1', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'intl']],
                    'database' => ['required' => true, 'prefix' => 'tb_'],
                    'download' => ['github' => ['repo' => 'thirtybees/thirtybees', 'asset' => 'thirtybees-v{version}-php8.1.zip', 'tag_prefix' => ''], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    // Same console installer as PrestaShop (shared lineage):
                    // install/index_cli.php with the panel-managed DB (the
                    // -php8.1 asset carries vendor/ and refuses older PHP at
                    // the gate, hence the 8.1 floor).
                    'setup' => [
                        'files' => ['panel-finish.php' => <<<'PANELFINISH'
<?php
// Post-install: claim a fixed non-default admin dir name. The console
// installer leaves admin/ behind, and the FIRST admin hit renames a
// bare admin/ to adminNNNxxxxxx/ (then redirects there) — nondeterministic
// and login-breaking for every saved bookmark. backend/ never matches
// that trigger, so the URL stays stable. CLI-only.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
chdir(__DIR__);
foreach (['admin', 'admin-dev'] as $candidate) {
    if ($candidate !== 'backend' && is_dir(__DIR__ . '/' . $candidate) && !is_dir(__DIR__ . '/backend')) {
        if (!@rename(__DIR__ . '/' . $candidate, __DIR__ . '/backend')) {
            fwrite(STDERR, "admin rename failed\n");
            exit(1);
        }
        break;
    }
}
if (!is_dir(__DIR__ . '/backend')) {
    fwrite(STDERR, "no admin dir to claim\n");
    exit(1);
}
echo "renamed\n";
PANELFINISH,
                        ],
                        'cli' => [
                            ['script' => 'install/index_cli.php', 'args' => ['--domain={domain}', '--base_uri={scriptpath}', '--db_server={db_host}', '--db_user={db_user}', '--db_password={db_pass}', '--db_name={db_name}', '--db_clear=0', '--db_create=0', '--prefix={prefix}', '--name={title}', '--firstname=Admin', '--lastname=Admin', '--password={pass}', '--email={email}', '--country=us', '--language=en']],
                            ['script' => 'panel-finish.php', 'args' => []],
                        ],
                        'cleanup' => ['install', 'panel-finish.php'],
                    ],
                    'admin' => ['path' => 'backend/'],
                    'post_install' => 'Installed automatically with the admin account you entered — log in under /backend/ (a fixed non-default name).',
                    'update_check' => ['type' => 'github', 'repo' => 'thirtybees/thirtybees'],
                ],
            ],
            [
                'slug' => 'opencart', 'name' => 'OpenCart', 'category' => 'E-commerce',
                'version' => '3.0.5.1', 'featured' => 0, 'sort' => 43,
                'description' => 'Popular open shopping cart with extensions marketplace.',
                'website' => 'https://www.opencart.com', 'license' => 'GPL-3.0', 'vendor' => 'OpenCart',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.1', 'php_max' => '8.1', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl']],
                    'database' => ['required' => true, 'prefix' => 'oc_'],
                    'download' => ['github' => ['repo' => 'opencart/opencart', 'asset' => 'opencart-{version}.zip', 'tag_prefix' => ''], 'sha256' => '', 'format' => 'zip', 'strip_root' => true, 'strip_dir' => 'upload'],
                    'config' => [],
                    'setup' => ['cli' => [
                        ['script' => 'install/cli_install.php', 'args' => ['install', '--db_driver', 'mysqli', '--db_hostname', '{db_host}', '--db_port', '3306', '--db_username', '{db_user}', '--db_password', '{db_pass}', '--db_database', '{db_name}', '--db_prefix', '{prefix}', '--username', '{user}', '--password', '{pass}', '--email', '{email}', '--http_server', '{url}/']],
                    ], 'cleanup' => ['install']],
                    'admin' => ['path' => 'admin/'],
                    'post_install' => 'Installed automatically with the admin account you entered — log in at /admin/.',
                    'update_check' => ['type' => 'github', 'repo' => 'opencart/opencart'],
                ],
            ],
            [
                'slug' => 'dolphin', 'name' => 'Dolphin', 'category' => 'Social',
                'version' => '7.4.2', 'featured' => 0, 'sort' => 44,
                'description' => 'Social network and dating platform builder with apps and themes.',
                'website' => 'https://www.boonex.com', 'license' => 'CC-BY-3.0', 'vendor' => 'Boonex',
                'manifest' => [
                    'type' => 'php-mysql',
                    // PHP 7.4 ONLY (2019 tree; 8.x untested), hence php_max
                    // plus a pinned php_version CLI step; subdir installs
                    // auto-pin 74. allow_url_include must be Off or the stock
                    // front controller troubleshoots instead of rendering —
                    // it rides the auto-pin via php_settings (and customers
                    // can flip it per site/dir on the PHP Settings page now
                    // that it is a managed option).
                    'requirements' => ['php_min' => '7.4', 'php_max' => '7.99', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json']],
                    'database' => ['required' => true, 'prefix' => ''],
                    'download' => ['github' => ['repo' => 'boonex/dolphin.pro', 'asset' => 'Dolphin-v.{version}.zip', 'tag_prefix' => ''], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'php_settings' => ['allow_url_include' => '0'],
                    'setup' => [
                        // The stock install/cmd.php is a real CLI installer
                        // with clean exit codes (0 ok, 2/3/4/5 named
                        // failures) driven by --opt=value argv — but
                        // install/index.php uses CWD-relative includes while
                        // the agent runs without cd, so the driver chdirs
                        // into install/ and execs the same interpreter
                        // (PHP_BINARY) with =-joined options. Two stock
                        // quirks handled: the permissions gate demands +x on
                        // flash/.../ffmpeg.exe (ships 0644 — chmodded here,
                        // self-service on the owned tree) and
                        // _setSiteConfigVars() eval()s a sql_file def_exp
                        // that has been a Closure for years (patched to a
                        // call, guarded to fail loudly on drift). The CLI has
                        // no host option (localhost default — panel DB users
                        // carry a localhost grant). install/ removed by
                        // cleanup below.
                        'files' => ['panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 10, '');
    if (($__e = getenv('CWH_SETUP_PASS')) !== false && $__e !== '') { $args[4] = $__e; }
    if (($__e = getenv('CWH_SETUP_DB_PASS')) !== false && $__e !== '') { $args[8] = $__e; }
$title = trim($args[1]);
$email = trim($args[2]);
$user = trim($args[3]);
$pass = $args[4] ?? '';
$dbhost = trim($args[5]);
$dbname = trim($args[6]);
$dbuser = trim($args[7]);
$dbpass = $args[8] ?? '';
$url = rtrim(trim($args[9]), '/') . '/';
if ($title === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $user) || strlen($pass) < 8
    || $dbname === '' || $dbuser === '' || $dbpass === ''
    || !preg_match('#^https?://#', $url)
) {
    fwrite(STDERR, "usage: panel-setup.php <title> <email> <user> <pass> <dbhost> <dbname> <dbuser> <dbpass> <url>\n");
    exit(1);
}
// Stock CLI has no host option (localhost default); the panel DB user
// carries a localhost grant, so refuse anything else loudly.
if ($dbhost !== 'localhost' && $dbhost !== '127.0.0.1') {
    fwrite(STDERR, "dolphin installs against the local database only\n");
    exit(1);
}
// The stock permissions gate demands +x on flash/.../ffmpeg.exe, but the
// zip ships it 0644. Mirror the documented install chmod here (the
// account owns the tree, so this is self-service, never privileged).
@chmod(__DIR__ . '/flash/modules/global/app/ffmpeg.exe', 0755);
$installDir = __DIR__ . '/install';
// Upstream cmd.php is stale: _setSiteConfigVars() eval()s the sql_file
// def_exp, but it has been a Closure for years (the web flow invokes it).
// Patch the single line to call it (guarded: fail loudly if the line ever
// changes shape instead of running a broken installer).
$cmdPhp = file_get_contents($installDir . '/cmd.php');
$needle = 'eval($GLOBALS[\'aDbConf\'][\'sql_file\'][\'def_exp\'])';
if (strpos($cmdPhp, $needle) === false) {
    fwrite(STDERR, "cmd.php patch point missing\n");
    exit(1);
}
$cmdPhp = str_replace($needle, 'call_user_func($GLOBALS[\'aDbConf\'][\'sql_file\'][\'def_exp\'])', $cmdPhp);
file_put_contents($installDir . '/cmd.php', $cmdPhp);
if (!is_file($installDir . '/cmd.php')) {
    fwrite(STDERR, "install/cmd.php missing\n");
    exit(1);
}
$opts = [
    '--site_url=' . $url,
    '--site_dir=' . __DIR__ . '/',
    '--php_path=' . PHP_BINARY,
    '--db_name=' . $dbname,
    '--db_user=' . $dbuser,
    '--db_password=' . $dbpass,
    '--site_title=' . $title,
    '--site_email=' . $email,
    '--admin_email=' . $email,
    '--admin_username=' . $user,
    '--admin_password=' . $pass,
];
$cmd = array_merge([PHP_BINARY, '-d', 'memory_limit=1024M', 'cmd.php'], $opts);
$desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open($cmd, $desc, $pipes, $installDir);
if (!is_resource($proc)) {
    fwrite(STDERR, "could not start installer\n");
    exit(1);
}
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($proc);
if ($out !== '') {
    echo $out;
}
if ($err !== '') {
    fwrite(STDERR, $err);
}
exit(is_int($code) ? $code : 1);
PANELSETUP,
                        ],
                        'cli' => [
                            ['script' => 'panel-setup.php', 'args' => ['{title}', '{email}', '{user}', '{pass}', '{db_host}', '{db_name}', '{db_user}', '{db_pass}', '{url}'], 'php_version' => '74'],
                        ],
                        'cleanup' => ['panel-setup.php', 'install'],
                    ],
                    'admin' => ['path' => 'administration/'],
                    'post_install' => 'Installed automatically with the admin account you entered — sign in on the front page or at /administration/. Root installs on PHP 7.4 need allow_url_include Off (Websites → PHP Settings).',
                    'update_check' => ['type' => 'github', 'repo' => 'boonex/dolphin.pro'],
                ],
            ],
            [
                'slug' => 'groupoffice', 'name' => 'Group-Office', 'category' => 'Portals',
                'version' => '26.0.47', 'featured' => 0, 'sort' => 45,
                'description' => 'Online office suite — CRM, projects, email, calendar and files.',
                'website' => 'https://www.group-office.com', 'license' => 'AGPL-3.0', 'vendor' => 'Group-Office',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.1', 'extensions' => ['pdo_mysql', 'curl', 'gd', 'mbstring', 'xml', 'dom', 'fileinfo', 'intl', 'zip', 'json', 'openssl']],
                    'database' => ['required' => true, 'prefix' => ''],
                    'download' => ['github' => ['repo' => 'Intermesh/groupoffice', 'asset' => 'groupoffice-{version}.tar.gz', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'tar', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        // No CLI installer ships: the driver mirrors
                        // install/install.php's POST handler headless — the
                        // stock core Installer::install($admin) (schema +
                        // groups + admin + modules) plus the legacy
                        // GO\Base module auto-installs, the two legacy cron
                        // rows and the admin legacyOnSave. config.php is
                        // static setup.files text (only panel-generated hex
                        // secrets + paths ride it — user input never lands
                        // in static text); the wizard password rides argv.
                        // Data dirs live beside the docroot (isolation) and
                        // are labeled via setup.datadirs; the driver mkdirs
                        // them first because datadirs converge after setup.
                        'files' => ['config.php' => <<<'PANELCONFIG'
<?php

$config = array(
  'file_storage_path' => '{home}/groupoffice-{dir}',
  'tmpdir' => '{home}/groupoffice-{dir}-tmp',
  'db_host' => '{db_host}',
  'db_name' => '{db_name}',
  'db_user' => '{db_user}',
  'db_pass' => '{db_pass}',
  'db_port' => 3306,
);
PANELCONFIG,
                            'panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 5, '');
    if (($__e = getenv('CWH_SETUP_PASS')) !== false && $__e !== '') { $args[4] = $__e; }
$title = trim($args[1]);
$email = trim($args[2]);
$user = trim($args[3]);
$pass = $args[4] ?? '';
if ($title === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || !preg_match('/^[A-Za-z0-9_.\-@]{1,32}$/', $user) || strlen($pass) < 8
) {
    fwrite(STDERR, "usage: panel-setup.php <title> <email> <user> <pass>\n");
    exit(1);
}
// The agent runs setup scripts without cd-ing into the tree.
chdir(__DIR__);
if (!is_file(__DIR__ . '/config.php')) {
    fwrite(STDERR, "config.php missing\n");
    exit(1);
}
$config = [];
require __DIR__ . '/config.php';
foreach (['file_storage_path', 'tmpdir'] as $k) {
    if (!empty($config[$k]) && !is_dir($config[$k])) {
        mkdir($config[$k], 0755, true);
    }
}
require __DIR__ . '/vendor/autoload.php';
try {
    go\core\App::get()->setAuthState(new go\core\auth\TemporaryState());
    $admin = [
        'displayName' => 'System Administrator',
        'username' => $user,
        'password' => $pass,
        'email' => $email,
        'language' => 'en',
    ];
    go\core\App::get()->getInstaller()->install($admin);
    // Legacy (not yet refactored) modules, as install.php does.
    GO::$ignoreAclPermissions = true;
    foreach (GO::modules()->getAvailableModules() as $moduleClass) {
        $moduleController = $moduleClass::get();
        if ($moduleController instanceof go\core\Module) {
            continue;
        }
        if ($moduleController->autoInstall() && $moduleController->isInstallable()) {
            try {
                GO\Base\Model\Module::install($moduleController->name(), false, $moduleController::getDefaultSortOrder());
            } catch (Throwable $e) {
                go\core\ErrorHandler::logException($e);
            }
        }
    }
    foreach ([
        ['Email Reminders', '*/5', '*', 'GO\Base\Cron\EmailReminders'],
        ['Calculate disk usage', '1', '1', 'GO\Base\Cron\CalculateDiskUsage'],
    ] as [$name, $minutes, $hours, $job]) {
        $cron = new GO\Base\Cron\CronJob();
        $cron->name = $name;
        $cron->active = true;
        $cron->runonce = false;
        $cron->minutes = $minutes;
        $cron->hours = $hours;
        $cron->monthdays = '*';
        $cron->months = '*';
        $cron->weekdays = '*';
        $cron->job = $job;
        if (!$cron->save()) {
            throw new RuntimeException('Could not save cron ' . $name);
        }
    }
    GO\Base\Observable::cacheListeners();
    go\core\model\User::findById(1)->legacyOnSave();
    echo "groupoffice setup complete\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'setup failed: ' . $e->getMessage() . "\n");
    exit(1);
}
PANELSETUP,
                        ],
                        'cli' => [
                            ['script' => 'panel-setup.php', 'args' => ['{title}', '{email}', '{user}', '{pass}']],
                        ],
                        'datadirs' => ['groupoffice-{dir}', 'groupoffice-{dir}-tmp'],
                        'cron' => [
                            ['schedule' => '* * * * *', 'command' => '{phpbin} -f {dest}/cron.php {dest}/config.php'],
                        ],
                        'cleanup' => ['panel-setup.php', 'install'],
                    ],
                    'admin' => ['path' => ''],
                    'post_install' => 'Installed automatically with the admin account you entered — log in at the site URL. If the first login asks for a new password, yours appeared in a breach list (Group-Office checks HaveIBeenPwned); the scheduler runs every minute via cron.',
                    'update_check' => ['type' => 'github', 'repo' => 'Intermesh/groupoffice'],
                ],
            ],
            [
                'slug' => 'invoiceplane', 'name' => 'InvoicePlane', 'category' => 'Finance',
                'version' => '1.7.2', 'featured' => 0, 'sort' => 46,
                'description' => 'Quotes, invoices and payments for freelancers and small firms.',
                'website' => 'https://www.invoiceplane.com', 'license' => 'MIT', 'vendor' => 'InvoicePlane',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.1', 'extensions' => ['mysqli', 'curl', 'mbstring', 'json', 'openssl']],
                    'database' => ['required' => true, 'prefix' => 'ip_'],
                    'download' => ['github' => ['repo' => 'InvoicePlane/InvoicePlane', 'asset' => '{tag}.zip', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        // CodeIgniter CLI setup controller (first-party):
                        // migrate builds the tables, create_default_user
                        // reads its credentials from the environment — the
                        // panel-setup.php driver sets them in-process (the
                        // agent passes argv, never env) and re-dispatches
                        // index.php per step. CLI-only, self-deleting.
                        'files' => ['ipconfig.php' => "IP_URL={url}\nENABLE_DEBUG=false\nCI_ENV=production\nSETUP_COMPLETED=true\nDB_HOSTNAME={db_host}\nDB_USERNAME={db_user}\nDB_PASSWORD={db_pass}\nDB_DATABASE={db_name}\nDB_PORT=3306\n",
                            'panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 5, '');
    if (($__e = getenv('CWH_SETUP_PASS')) !== false && $__e !== '') { $args[4] = $__e; }
$mode = $args[1];
$email = trim($args[2]);
$name = trim($args[3]);
$pass = $args[4];
if (!in_array($mode, array('migrate', 'createuser'), true)
    || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || $name === '' || strlen($pass) < 8
) {
    fwrite(STDERR, "usage: panel-setup.php <migrate|createuser> <email> <name> <password>\n");
    exit(1);
}
putenv('DEFAULT_ADMIN_EMAIL=' . $email);
putenv('DEFAULT_ADMIN_NAME=' . $name);
putenv('DEFAULT_ADMIN_PASSWORD=' . $pass);
$_SERVER['argv'] = array(
    'index.php',
    $mode === 'migrate' ? 'setup/cli/migrate' : 'setup/cli/create_default_user',
);
chdir(__DIR__);
require __DIR__ . '/index.php';
PANELSETUP,
                        ],
                        'cli' => [
                            ['script' => 'panel-setup.php', 'args' => ['migrate', '{email}', '{user}', '{pass}']],
                            ['script' => 'panel-setup.php', 'args' => ['createuser', '{email}', '{user}', '{pass}']],
                        ],
                        'cleanup' => ['application/modules/setup', 'panel-setup.php'],
                    ],
                    'admin' => ['path' => ''],
                    'post_install' => 'Installed automatically with the admin account you entered — log in at the site URL.',
                    'update_check' => ['type' => 'github', 'repo' => 'InvoicePlane/InvoicePlane'],
                ],
            ],
            [
                'slug' => 'invoiceninja', 'name' => 'Invoice Ninja', 'category' => 'Finance',
                'version' => '5.13.40', 'featured' => 0, 'sort' => 47,
                'description' => 'Invoicing, quotes, expenses and time-tracking in one app.',
                'website' => 'https://invoiceninja.com', 'license' => 'Elastic-2.0', 'vendor' => 'Invoice Ninja',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.2', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'bcmath']],
                    // Upstream bans subdirectories (React bundle uses
                    // domain-absolute asset URLs): website root only.
                    'root_only' => true,
                    'database' => ['required' => true, 'prefix' => ''],
                    'download' => ['github' => ['repo' => 'invoiceninja/invoiceninja', 'asset' => 'invoiceninja.tar.gz', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'tar', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        'files' => ['.env' => "APP_NAME=\"Invoice Ninja\"\nAPP_ENV=production\nAPP_DEBUG=false\nAPP_KEY={app_key}\nAPP_URL={url}\nREQUIRE_HTTPS=true\nDB_CONNECTION=mysql\nDB_HOST={db_host}\nDB_PORT=3306\nDB_DATABASE={db_name}\nDB_USERNAME={db_user}\nDB_PASSWORD={db_pass}\nMAIL_MAILER=log\nCACHE_DRIVER=file\nSESSION_DRIVER=file\nQUEUE_CONNECTION=sync\n"],
                        'cli' => [
                            ['script' => 'artisan', 'args' => ['storage:link']],
                            ['script' => 'artisan', 'args' => ['migrate', '--seed', '--force']],
                            ['script' => 'artisan', 'args' => ['ninja:create-account', '--email={email}', '--password={pass}']],
                        ],
                        'cron' => [
                            ['schedule' => '* * * * *', 'command' => '{phpbin} {dest}/artisan schedule:run'],
                        ],
                    ],
                    'webroot' => 'public',
                    'admin' => ['path' => ''],
                    'post_install' => 'Installed automatically with the admin account you entered — log in at the site URL. The scheduler runs every minute via cron.',
                    'update_check' => ['type' => 'github', 'repo' => 'invoiceninja/invoiceninja'],
                ],
            ],
            [
                'slug' => 'shaarli', 'name' => 'Shaarli', 'category' => 'Utilities',
                'version' => '0.16.8', 'featured' => 0, 'sort' => 48,
                'description' => 'Minimal bookmark manager and link sharing — your own Pinboard.',
                'website' => 'https://shaarli.readthedocs.io', 'license' => 'Zlib', 'vendor' => 'Shaarli',
                'manifest' => [
                    'type' => 'php',
                    'requirements' => ['php_min' => '8.1', 'extensions' => ['curl', 'mbstring', 'zip', 'json', 'openssl']],
                    'database' => ['required' => false],
                    'download' => ['github' => ['repo' => 'shaarli/Shaarli', 'asset' => 'shaarli-{tag}-full.zip', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        // Shaarli boots from data/config.json.php (PHP-wrapped
                        // JSON) and skips its installer when the mandatory
                        // credentials exist. The driver mirrors
                        // InstallController::save (sha1(pass.login.salt),
                        // header_link, api secret) without bootstrapping the
                        // container. CLI-only.
                        'files' => ['panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 5, '');
    if (($__e = getenv('CWH_SETUP_PASS')) !== false && $__e !== '') { $args[3] = $__e; }
$title = trim($args[1]);
$user = trim($args[2]);
$pass = $args[3] ?? '';
$urlpath = trim($args[4] ?? '');
if ($title === '' || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $user)
    || strlen($pass) < 8 || $urlpath === '' || $urlpath[0] !== '/'
) {
    fwrite(STDERR, "usage: panel-setup.php <title> <login> <password> <urlpath>\n");
    exit(1);
}
chdir(__DIR__);
$salt = sha1(uniqid('', true) . '_' . mt_rand());
$conf = [
    'credentials' => [
        'login' => $user,
        'hash' => sha1($pass . $user . $salt),
        'salt' => $salt,
    ],
    'general' => [
        'timezone' => 'UTC',
        'title' => mb_substr($title, 0, 200),
        'header_link' => $urlpath,
    ],
    'security' => ['session_protection_disabled' => false],
    'privacy' => ['default_private_links' => false],
    'translation' => ['language' => 'en'],
    'updates' => ['check_updates' => false],
    'api' => [
        'enabled' => false,
        'secret' => str_shuffle(substr(hash_hmac('sha512', uniqid($salt), $user), 10, 12)),
    ],
];
@mkdir(__DIR__ . '/data', 0755, true);
file_put_contents(
    __DIR__ . '/data/config.json.php',
    '<?php /*' . json_encode($conf, JSON_PRETTY_PRINT) . '*/ ?>'
);
echo "installed\n";
PANELSETUP,
                        ],
                        'cli' => [
                            ['script' => 'panel-setup.php', 'args' => ['{title}', '{user}', '{pass}', '{urlpath}']],
                        ],
                        'cleanup' => ['panel-setup.php'],
                    ],
                    'admin' => ['path' => 'login'],
                    'post_install' => 'Installed automatically with the login you entered — open /login to manage bookmarks.',
                    'update_check' => ['type' => 'github', 'repo' => 'shaarli/Shaarli'],
                ],
            ],
            [
                'slug' => 'e107', 'name' => 'e107', 'category' => 'CMS',
                'version' => '2.3.12', 'featured' => 0, 'sort' => 49,
                'description' => 'Veteran CMS with forums, news, downloads and user management.',
                'website' => 'https://e107.org', 'license' => 'GPL-2.0', 'vendor' => 'e107',
                'manifest' => [
                    'type' => 'php-mysql',
                    // Proven on PHP 8.0 (front) + 8.5 (wizard); 7.4 untested.
                    'requirements' => ['php_min' => '8.0', 'extensions' => ['mysqli', 'pdo_mysql', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl']],
                    'database' => ['required' => true, 'prefix' => 'e107_'],
                    'download' => ['github' => ['repo' => 'e107inc/e107', 'asset' => 'e107_{version}_full.zip', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        // No usable CLI installer ships (the ?create_tables
                        // entry needs staged defines + a wired default DB that
                        // only the wizard stages provide), so the driver replays
                        // the 8-stage browser wizard headless over the live
                        // vhost (front-door :80 with Host header — :443 serves
                        // the panel vhost for customer domains). State travels
                        // in the signed previous_steps hidden field (harvested
                        // per stage, never cookies); secrets ride POST bodies
                        // (never URLs). CLI-only. install.php is removed by
                        // cleanup below.
                        'files' => ['panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 10, '');
    if (($__e = getenv('CWH_SETUP_PASS')) !== false && $__e !== '') { $args[4] = $__e; }
    if (($__e = getenv('CWH_SETUP_DB_PASS')) !== false && $__e !== '') { $args[8] = $__e; }
$title = trim($args[1]);
$email = trim($args[2]);
$user = trim($args[3]);
$pass = $args[4] ?? '';
$dbhost = trim($args[5]);
$dbname = trim($args[6]);
$dbuser = trim($args[7]);
$dbpass = $args[8] ?? '';
$url = rtrim(trim($args[9]), '/');
if ($title === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $user) || strlen($pass) < 8
    || $dbhost === '' || $dbname === '' || $dbuser === '' || $dbpass === ''
    || !preg_match('#^https?://#', $url)
) {
    fwrite(STDERR, "usage: panel-setup.php <title> <email> <user> <pass> <dbhost> <dbname> <dbuser> <dbpass> <url>\n");
    exit(1);
}
$parts = parse_url($url);
$domain = $parts['host'] ?? '';
$path = rtrim($parts['path'] ?? '', '/');
if ($domain === '') {
    fwrite(STDERR, "bad url\n");
    exit(1);
}
$endpoint = 'http://127.0.0.1' . $path . '/install.php';
$cookies = [];
$harvestCookies = function ($headers) use (&$cookies) {
    foreach (explode("\n", $headers) as $line) {
        if (stripos($line, 'Set-Cookie:') === 0) {
            $pair = trim(substr($line, 11));
            $semi = strpos($pair, ';');
            if ($semi !== false) {
                $pair = substr($pair, 0, $semi);
            }
            $eq = strpos($pair, '=');
            if ($eq !== false) {
                $cookies[trim(substr($pair, 0, $eq))] = trim(substr($pair, $eq + 1));
            }
        }
    }
};
$cookieHeader = function () use (&$cookies) {
    $pairs = [];
    foreach ($cookies as $k => $v) {
        $pairs[] = $k . '=' . $v;
    }
    return implode('; ', $pairs);
};
$doGet = function () use ($endpoint, $domain, &$cookies, $harvestCookies) {
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Host: ' . $domain]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return [0, '', 'curl error: ' . $err];
    }
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $harvestCookies(substr($raw, 0, $hsize));
    return [$code, substr($raw, $hsize), ''];
};
$doPost = function (array $fields) use ($endpoint, $domain, &$cookies, $harvestCookies, $cookieHeader) {
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Host: ' . $domain]);
    $jar = $cookieHeader();
    if ($jar !== '') {
        curl_setopt($ch, CURLOPT_COOKIE, $jar);
    }
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return [0, '', 'curl error: ' . $err];
    }
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $harvestCookies(substr($raw, 0, $hsize));
    return [$code, substr($raw, $hsize), ''];
};
$harvest = function ($body, $name) {
    if (preg_match('/name=[\'"]' . $name . '[\'"]\s+value=[\'"]([^\'"]*)/', $body, $m)) {
        return $m[1];
    }
    if (preg_match('/value=[\'"]([^\'"]*)[\'"]\s*[^>]*name=[\'"]' . $name . '[\'"]/', $body, $m)) {
        return $m[1];
    }
    return null;
};
$stepTitle = function ($body) {
    if (preg_match('/<title>e107 Installation :: ([^<]*)/', $body, $m)) {
        return trim($m[1]);
    }
    return '';
};
// Stage 1 -> 2 (language; starts unlocked, mints the lock on advance).
// The opening blob is harvested, never hardcoded.
$g = $doGet();
if ($g[0] !== 200) {
    fwrite(STDERR, 'installer unreachable (http ' . $g[0] . ")\n");
    exit(1);
}
$firstBlob = $harvest($g[1], 'previous_steps');
if ($firstBlob === null) {
    fwrite(STDERR, "installer did not offer a fresh install\n");
    exit(1);
}
$r = $doPost(['language' => 'English', 'previous_steps' => $firstBlob, 'stage' => '2']);
if ($r[0] !== 200 || stripos($stepTitle($r[1]), 'Step 2') === false) {
    fwrite(STDERR, 'stage 2 failed (http ' . $r[0] . ' ' . $stepTitle($r[1]) . ")\n");
    exit(1);
}
$stages = [
    3 => ['server' => $dbhost, 'name' => $dbuser, 'password' => $dbpass, 'db' => $dbname, 'prefix' => 'e107_'],
    4 => [],
    5 => [],
    6 => ['u_name' => $user, 'd_name' => $user, 'pass1' => $pass, 'pass2' => $pass, 'email' => $email],
    7 => ['sitename' => $title, 'sitetheme' => 'bootstrap3', 'generate_content' => '1', 'install_plugins' => '1'],
    8 => [],
];
$body = $r[1];
foreach ($stages as $n => $fields) {
    $blob = $harvest($body, 'previous_steps');
    $next = $harvest($body, 'stage');
    if ($blob === null || (int) $next !== $n) {
        fwrite(STDERR, 'wizard state lost before stage ' . $n . "\n");
        exit(1);
    }
    $fields['previous_steps'] = $blob;
    $fields['stage'] = (string) $n;
    $r = $doPost($fields);
    if ($r[0] !== 200) {
        fwrite(STDERR, 'stage ' . $n . ' failed (http ' . $r[0] . ")\n");
        exit(1);
    }
    $body = $r[1];
}
if (stripos($body, 'Installation Complete') === false) {
    fwrite(STDERR, 'wizard did not finish (' . $stepTitle($body) . ")\n");
    exit(1);
}
echo "e107 setup complete\n";
PANELSETUP,
                        ],
                        'cli' => [
                            ['script' => 'panel-setup.php', 'args' => ['{title}', '{email}', '{user}', '{pass}', '{db_host}', '{db_name}', '{db_user}', '{db_pass}', '{url}']],
                        ],
                        'cleanup' => ['panel-setup.php', 'install.php'],
                    ],
                    'admin' => ['path' => 'e107_admin/'],
                    'post_install' => 'Installed automatically with the admin account you entered — sign in on the front page or at /e107_admin/.',
                    'update_check' => ['type' => 'github', 'repo' => 'e107inc/e107'],
                ],
            ],
            [
                'slug' => 'q2a', 'name' => 'Question2Answer', 'category' => 'Utilities',
                'version' => '1.8.8', 'featured' => 0, 'sort' => 50,
                'description' => 'Stack-Overflow-style Q&A community platform.',
                'website' => 'https://www.question2answer.org', 'license' => 'GPL-2.0', 'vendor' => 'Question2Answer',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '7.4', 'extensions' => ['mysqli', 'gd', 'mbstring', 'json']],
                    'database' => ['required' => true, 'prefix' => 'qa_'],
                    'download' => ['github' => ['repo' => 'q2a/question2answer', 'asset' => 'question2answer-{version}.zip', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        // DB credentials first (qa-config.php), then a driver
                        // that bootstraps Q2A headless: $qa_autoconnect=false
                        // skips the autoconnect, a lenient fail handler
                        // tolerates the missing tables, install_tables builds
                        // the schema, and the stock create_new_user mints the
                        // level-120 super admin (bcrypt). CLI-only.
                        'files' => [
                            'qa-config.php' => <<<'QACONFIG'
<?php
define('QA_MYSQL_HOSTNAME', '{db_host}');
define('QA_MYSQL_USERNAME', '{db_user}');
define('QA_MYSQL_PASSWORD', '{db_pass}');
define('QA_MYSQL_DATABASE', '{db_name}');
define('QA_MYSQL_TABLE_PREFIX', '{prefix}');
define('QA_EXTERNAL_USERS', false);
QACONFIG,
                            'panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 4, '');
    if (($__e = getenv('CWH_SETUP_PASS')) !== false && $__e !== '') { $args[3] = $__e; }
$email = trim($args[1]);
$user = trim($args[2]);
$pass = $args[3] ?? '';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)
    || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $user) || strlen($pass) < 8
) {
    fwrite(STDERR, "usage: panel-setup.php <email> <username> <password>\n");
    exit(1);
}
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
define('QA_BASE_DIR', __DIR__ . '/');
// (QA_EXTERNAL_USERS comes from qa-config.php below — defining it here
// too would only emit a duplicate-constant warning.)
// Skip the autoconnect (it would die on the empty database); connect
// below with a handler that tolerates missing tables.
$qa_autoconnect = false;
require __DIR__ . '/qa-include/qa-base.php';
function qapanel_fail_handler($type, $errno = null, $error = null, $query = null) {
    return null;
}
qa_db_connect('qapanel_fail_handler');
require_once QA_INCLUDE_DIR . 'db/install.php';
qa_db_install_tables();
require_once QA_INCLUDE_DIR . 'app/users-edit.php';
$uid = qa_create_new_user($email, $pass, $user, QA_USER_LEVEL_SUPER, true);
if (empty($uid)) {
    fwrite(STDERR, "admin creation failed\n");
    exit(1);
}
echo "installed admin {$uid}\n";
PANELSETUP,
                        ],
                        'cli' => [
                            ['script' => 'panel-setup.php', 'args' => ['{email}', '{user}', '{pass}']],
                        ],
                        'cleanup' => ['panel-setup.php'],
                    ],
                    // Pretty URLs (?qa=<path>) need the front-controller
                    // rewrite — plain try_files would serve the homepage for
                    // every subpage (same class as WonderCMS ?page=).
                    'page_param' => 'qa',
                    'admin' => ['path' => 'admin'],
                    'post_install' => 'Installed automatically with the super admin account you entered — log in at /login.',
                    'update_check' => ['type' => 'github', 'repo' => 'q2a/question2answer'],
                ],
            ],
            [
                'slug' => 'ampache', 'name' => 'Ampache', 'category' => 'Media',
                'version' => '8.2.2', 'featured' => 0, 'sort' => 51,
                'description' => 'Web-based audio/video streaming server for your own collection.',
                'website' => 'https://ampache.org', 'license' => 'AGPL-3.0', 'vendor' => 'Ampache',
                'manifest' => [
                    'type' => 'php-mysql',
                    // Vendor needs real PHP >= 8.5 (composer platform check
                    // fatals below it); panel id 84 IS the system 8.5 despite
                    // its stale 8.4 label, so the floor reads 8.4 (the label
                    // the requirement checker compares).
                    'requirements' => ['php_min' => '8.4', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl']],
                    'database' => ['required' => true, 'prefix' => ''],
                    // The bare source asset (ampache-{version}.zip) ships NO
                    // vendor/ (needs composer install) — the _all_php8.5
                    // asset is the installable one. If upstream renames it,
                    // the freshness HEAD-verify holds the version until the
                    // template is fixed.
                    'download' => ['github' => ['repo' => 'ampache/ampache', 'asset' => 'ampache-{version}_all_php8.5.zip', 'tag_prefix' => ''], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        // Two first-party CLIs, no driver needed: bin/installer
                        // creates the schema + writes ampache.cfg.php (the
                        // panel DB is empty, so -f drop+recreate is a no-op
                        // round-trip; -u/-p omitted on purpose — CREATE USER
                        // needs global privileges the panel DB user lacks),
                        // then bin/cli mints the admin (level 100).
                        'cli' => [
                            ['script' => 'bin/installer', 'args' => ['install', '-U', '{db_user}', '-P', '{db_pass}', '-H', '{db_host}', '-o', '3306', '-d', '{db_name}', '-w', '{webroot}', '-f']],
                            ['script' => 'bin/cli', 'args' => ['admin:addUser', '{user}', '-p', '{pass}', '-e', '{email}', '-l', '100']],
                        ],
                    ],
                    'webroot' => 'public',
                    'admin' => ['path' => ''],
                    'post_install' => 'Installed automatically with the admin account you entered — log in at the site URL, then add your media folders under Admin → Catalog.',
                    'update_check' => ['type' => 'github', 'repo' => 'ampache/ampache'],
                ],
            ],
            [
                'slug' => 'koel', 'name' => 'Koel', 'category' => 'Media',
                'version' => '9.12.0', 'featured' => 0, 'sort' => 52,
                'description' => 'Personal music streaming server with a slick Spotify-like UI.',
                'website' => 'https://koel.dev', 'license' => 'MIT', 'vendor' => 'Koel',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.3', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl']],
                    'database' => ['required' => true, 'prefix' => ''],
                    'download' => ['github' => ['repo' => 'koel/koel', 'asset' => 'koel-{tag}.zip', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        'files' => ['.env' => "APP_NAME=Koel\nAPP_ENV=production\nAPP_DEBUG=false\nAPP_KEY={app_key}\nAPP_URL={url}\nTRUSTED_HOSTS={domain}\nDB_CONNECTION=mysql\nDB_HOST={db_host}\nDB_PORT=3306\nDB_DATABASE={db_name}\nDB_USERNAME={db_user}\nDB_PASSWORD={db_pass}\nMEDIA_PATH=\nMAIL_MAILER=log\nCACHE_DRIVER=file\nSESSION_DRIVER=file\nQUEUE_CONNECTION=sync\nFFMPEG_PATH=/tmp/no-ffmpeg\nTRANSCODE_FLAC=false\n",
                            // One-shot admin re-credentialer (koel:init seeds a
                            // fixed default admin and its password command is
                            // interactive-only): argv carries the secrets (no
                            // shell/quote layer — JSON argv), then it deletes
                            // itself. CLI-only, never linked anywhere.
                            'panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 4, '');
    if (($__e = getenv('CWH_SETUP_PASS')) !== false && $__e !== '') { $args[3] = $__e; }
$name = trim($args[1]);
$email = $args[2];
$pass = $args[3];
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
    fwrite(STDERR, "usage: panel-setup.php <name> <email> <password>\n");
    exit(1);
}
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$target = App\Models\User::where('email', $email)->first();
if ($target === null) {
    $target = App\Models\User::where('email', App\Models\User::FIRST_ADMIN_EMAIL)->firstOrFail();
}
$target->name = $name;
$target->email = $email;
$target->password = Illuminate\Support\Facades\Hash::make($pass);
$target->save();
echo "admin ready\n";
@unlink(__FILE__);
PANELSETUP,
                        ],
                        'cli' => [
                            ['script' => 'artisan', 'args' => ['koel:init', '--no-assets', '--no-interaction', '--no-scheduler']],
                            ['script' => 'panel-setup.php', 'args' => ['{user}', '{email}', '{pass}']],
                            // Bake the config: Koel resolves ffmpeg via
                            // shell_exec() at boot and the PHP masters
                            // disable it — the cached config skips the call.
                            ['script' => 'artisan', 'args' => ['config:cache']],
                        ],
                        'cron' => [
                            ['schedule' => '* * * * *', 'command' => '{phpbin} {dest}/artisan schedule:run'],
                        ],
                        // Koel's REST API uses PUT/PATCH/DELETE, which CRS
                        // method enforcement (911100) blocks by default.
                        'waf' => ['exclude' => [911100]],
                    ],
                    'webroot' => 'public',
                    'admin' => ['path' => ''],
                    'post_install' => 'Installed automatically with the admin account you entered — log in at the site URL. The Laravel scheduler runs every minute via cron.',
                    'update_check' => ['type' => 'github', 'repo' => 'koel/koel'],
                ],
            ],
            [
                'slug' => 'orangehrm', 'name' => 'OrangeHRM', 'category' => 'Utilities',
                'version' => '5.9', 'featured' => 0, 'sort' => 53,
                'description' => 'Human resource management — employees, leave, recruitment.',
                'website' => 'https://www.orangehrm.com', 'license' => 'GPL-2.0', 'vendor' => 'OrangeHRM',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.1', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl']],
                    'database' => ['required' => true, 'prefix' => ''],
                    'download' => ['github' => ['repo' => 'orangehrm/orangehrm', 'asset' => 'orangehrm-{version}.zip', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        // The modern console installer is interactive-only,
                        // so this drives the deprecated (but working)
                        // cli_install.php in existing-database mode — the
                        // panel DB is empty, no privileged user needed. The
                        // YAML is emitted by a driver (Symfony Yaml::dump —
                        // quote-safe for any password; static text would
                        // corrupt on : # " and friends), then the stock
                        // installer runs as step two. CLI-only.
                        'files' => ['panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 9, '');
    if (($__e = getenv('CWH_SETUP_PASS')) !== false && $__e !== '') { $args[4] = $__e; }
    if (($__e = getenv('CWH_SETUP_DB_PASS')) !== false && $__e !== '') { $args[8] = $__e; }
$title = trim($args[1]);
$email = trim($args[2]);
$user = trim($args[3]);
$pass = $args[4] ?? '';
$dbhost = trim($args[5]);
$dbname = trim($args[6]);
$dbuser = trim($args[7]);
$dbpass = $args[8] ?? '';
if ($title === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $user) || strlen($pass) < 8
    || $dbhost === '' || $dbname === '' || $dbuser === '' || $dbpass === ''
) {
    fwrite(STDERR, "usage: panel-setup.php <title> <email> <user> <pass> <dbhost> <dbname> <dbuser> <dbpass>\n");
    exit(1);
}
chdir(__DIR__);
require __DIR__ . '/src/vendor/autoload.php';
$conf = [
    'database' => [
        'hostName' => $dbhost,
        'hostPort' => 3306,
        'databaseName' => $dbname,
        'privilegedDatabaseUser' => $dbuser,
        'privilegedDatabasePassword' => $dbpass,
        'useSameDbUserForOrangeHRM' => 'y',
        'isExistingDatabase' => 'y',
        'enableDataEncryption' => 'n',
    ],
    'organization' => ['name' => mb_substr($title, 0, 200), 'country' => 'US'],
    'admin' => [
        'adminUserName' => $user,
        'adminPassword' => $pass,
        'adminEmployeeFirstName' => 'Admin',
        'adminEmployeeLastName' => 'Admin',
        'workEmail' => $email,
        'contactNumber' => null,
        'registrationConsent' => true,
    ],
    'license' => ['agree' => 'y'],
];
file_put_contents(
    __DIR__ . '/installer/cli_install_config.yaml',
    \Symfony\Component\Yaml\Yaml::dump($conf, 4)
);
echo "config written\n";
PANELSETUP,
                        ],
                        'cli' => [
                            ['script' => 'panel-setup.php', 'args' => ['{title}', '{email}', '{user}', '{pass}', '{db_host}', '{db_name}', '{db_user}', '{db_pass}']],
                            ['script' => 'installer/cli_install.php', 'args' => []],
                        ],
                        'cleanup' => ['panel-setup.php', 'installer/cli_install_config.yaml'],
                    ],
                    'admin' => ['path' => 'auth/login'],
                    'post_install' => 'Installed automatically with the admin account you entered — log in at /auth/login.',
                    'update_check' => ['type' => 'github', 'repo' => 'orangehrm/orangehrm'],
                ],
            ],
            [
                'slug' => 'linkstack', 'name' => 'LinkStack', 'category' => 'Utilities',
                'version' => '4.8.6', 'featured' => 0, 'sort' => 54,
                'description' => 'Self-hosted Linktree alternative — one page with all your links.',
                'website' => 'https://linkstack.org', 'license' => 'MIT', 'vendor' => 'LinkStack',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.1', 'extensions' => ['mysqli', 'curl', 'mbstring', 'zip', 'json']],
                    'database' => ['required' => true, 'prefix' => ''],
                    'download' => ['github' => ['repo' => 'LinkStackOrg/LinkStack', 'asset' => 'linkstack.zip', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        'files' => ['.env' => "APP_NAME=LinkStack\nAPP_ENV=production\nAPP_DEBUG=false\nAPP_KEY={app_key}\nAPP_URL={url}\nDB_CONNECTION=mysql\nDB_HOST={db_host}\nDB_PORT=3306\nDB_DATABASE={db_name}\nDB_USERNAME={db_user}\nDB_PASSWORD={db_pass}\nCACHE_DRIVER=file\nQUEUE_CONNECTION=sync\nSESSION_DRIVER=file\nSESSION_LIFETIME=120\nREGISTER_AUTH=auth\n"],
                        'cli' => [
                            ['script' => 'artisan', 'args' => ['migrate', '--force']],
                            ['script' => 'artisan', 'args' => ['db:seed', '--class=AdminSeeder', '--force']],
                        ],
                        // The seeder plants a fixed admin/admin@admin.com
                        // account: re-credential it to the wizard values
                        // (Laravel bcrypt verifies).
                        'sql_post' => [
                            ['table' => 'users', 'set' => ['name' => '{user}', 'email' => '{email}', 'password' => '{pass_hash}'], 'where' => ['email' => 'admin@admin.com']],
                        ],
                    ],
                    'admin' => ['path' => 'login'],
                    'post_install' => 'Installed automatically with the admin account you entered — log in at /login.',
                    'update_check' => ['type' => 'github', 'repo' => 'LinkStackOrg/LinkStack'],
                ],
            ],
            [
                'slug' => 'livehelperchat', 'name' => 'Live Helper Chat', 'category' => 'Utilities',
                'version' => '4.94v', 'featured' => 0, 'sort' => 55,
                'description' => 'Live chat, co-browsing and helpdesk for your website visitors.',
                'website' => 'https://livehelperchat.com', 'license' => 'Apache-2.0', 'vendor' => 'Live Helper Chat',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.1', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'json', 'openssl']],
                    'database' => ['required' => true, 'prefix' => ''],
                    // The asset nests the app under lhc_web/ (Dockerfile +
                    // siblings beside it) — without strip_dir every install
                    // lands a level deep and serves nothing.
                    'download' => ['github' => ['repo' => 'LiveHelperChat/livehelperchat', 'asset' => '{tag}-with-dependencies.zip', 'tag_prefix' => ''], 'sha256' => '', 'format' => 'zip', 'strip_root' => true, 'strip_dir' => 'lhc_web'],
                    'config' => [],
                    'setup' => [
                        // Upstream's cli/install-cli.php takes an INI file,
                        // but its main() exits 1 even on success and its
                        // relative requires need the app root as CWD (which
                        // the agent never provides) — so the driver calls
                        // the Install class steps directly with clean exit
                        // codes. CLI-only. (Admin passwords cap at 40 chars
                        // upstream; longer wizard passwords fail loudly here
                        // instead of silently.)
                        'files' => [
                            'cli/panel-settings.ini' => <<<'LHINI'
[db]
host = {db_host}
user = {db_user}
password = {db_pass}
database = {db_name}
port = 3306

[admin]
AdminUsername = {user}
AdminPassword = {pass}
AdminEmail = {email}
AdminName = Admin
AdminSurname = Admin
Domain = {domain}
DefaultDepartament = Support
ForceVirtualHost = 0
Extensions =
ApacheUserGroupName = apache
ApacheUserName = apache
TimeZone = UTC
WebhooksEnabled = 0
WebhooksWorker = http
LHINI,
                            'panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 2, '');
$ini = trim($args[1] ?? '');
if ($ini === '' || !is_file($ini)) {
    fwrite(STDERR, "usage: panel-setup.php <settings.ini>\n");
    exit(1);
}
chdir(__DIR__);
require_once "lib/core/lhcore/password.php";
require_once "ezcomponents/Base/src/base.php";
require_once 'cli/lib/install.php';
ezcBase::addClassRepository('./', './lib/autoloads');
spl_autoload_register(array('ezcBase', 'autoload'), true, false);
ezcBaseInit::setCallback('ezcInitDatabaseInstance', 'erLhcoreClassLazyDatabaseConfiguration');
$install = new Install($ini);
foreach (['step1', 'step2', 'step3', 'step4'] as $i => $step) {
    $r = $install->$step();
    if (is_array($r)) {
        fwrite(STDERR, 'step' . ($i + 1) . ': ' . implode('; ', $r) . "\n");
        exit(1);
    }
    echo 'step' . ($i + 1) . " ok\n";
}
echo "installed\n";
PANELSETUP,
                        ],
                        'cli' => [
                            ['script' => 'panel-setup.php', 'args' => ['{dest}/cli/panel-settings.ini']],
                        ],
                        'cleanup' => ['cli/panel-settings.ini', 'panel-setup.php'],
                    ],
                    'admin' => ['path' => 'site_admin/'],
                    'post_install' => 'Installed automatically with the admin account you entered — log in under Site administration.',
                    'update_check' => ['type' => 'github', 'repo' => 'LiveHelperChat/livehelperchat'],
                ],
            ],
            [
                'slug' => 'ilias', 'name' => 'ILIAS', 'category' => 'Education',
                'version' => '11.4', 'featured' => 0, 'sort' => 56,
                'description' => 'Learning management system for universities and enterprises.',
                'website' => 'https://www.ilias.de', 'license' => 'GPL-3.0', 'vendor' => 'ILIAS',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.3', 'php_max' => '8.3', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'imagick']],
                    'database' => ['required' => true, 'prefix' => ''],
                    'download' => ['github' => ['repo' => 'ILIAS-eLearning/ILIAS', 'asset' => 'ILIAS-{version}.zip', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        // ILIAS refuses config files inside the install tree,
                        // so the driver writes it to $HOME (outside the
                        // docroot), runs the setup, and deletes it in a
                        // finally — secrets never persist anywhere. Secrets
                        // ride argv (JSON, quote-safe).
                        'files' => ['panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 11, '');
    if (($__e = getenv('CWH_SETUP_DB_PASS')) !== false && $__e !== '') { $args[5] = $__e; }
$dir = trim($args[1], '/');
$dbhost = trim($args[2]);
$dbname = trim($args[3]);
$dbuser = trim($args[4]);
$dbpass = $args[5];
$home = rtrim($args[6], '/');
$url = rtrim(trim($args[7]), '/');
$email = trim($args[8]);
$user = trim($args[9]);
$title = trim($args[10]);
if ($dir === '' || preg_match('#[^A-Za-z0-9._-]#', $dir)
    || $dbhost === '' || $dbname === '' || $dbuser === '' || $dbpass === ''
    || $home === '' || $url === ''
    || !filter_var($email, FILTER_VALIDATE_EMAIL) || $user === '' || $title === ''
) {
    fwrite(STDERR, "usage: panel-setup.php <dir> <dbhost> <dbname> <dbuser> <dbpass> <home> <url> <email> <user> <title>\n");
    exit(1);
}
$configPath = $home . '/ilias-setup-' . $dir . '.json';
$conf = array(
    'common' => array('client_id' => $dir),
    'database' => array(
        'host' => $dbhost,
        'port' => 3306,
        'database' => $dbname,
        'user' => $dbuser,
        'password' => $dbpass,
        'create_database' => false,
    ),
    'filesystem' => array('data_dir' => $home . '/iliasdata-' . $dir),
    'http' => array('path' => $url . '/'),
    'systemfolder' => array('contact' => array(
        'firstname' => $user,
        'lastname' => 'Admin',
        'email' => $email,
    )),
);
chdir(__DIR__);
file_put_contents($configPath, json_encode($conf, JSON_PRETTY_PRINT));
chmod($configPath, 0600);
// ILIAS ships wide varchar(4000) tables that overflow the row-size limit
// under utf8mb4 — upstream mandates a utf8 database.
try {
    $pdo = new PDO(
        'mysql:host=' . $dbhost . ';port=3306;charset=utf8mb4',
        $dbuser,
        $dbpass,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
    $pdo->exec('ALTER DATABASE `' . str_replace('`', '', $dbname) . '` CHARACTER SET utf8 COLLATE utf8_general_ci');
} catch (Throwable $e) {
    fwrite(STDERR, 'charset setup failed: ' . $e->getMessage() . "\n");
    @unlink($configPath);
    exit(1);
}
$code = 1;
try {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/cli/setup.php')
        . ' install -y --no-interaction ' . escapeshellarg($configPath) . ' 2>&1';
    exec($cmd, $out, $code);
    echo implode("\n", $out) . "\n";
} finally {
    @unlink($configPath);
}
exit($code === 0 ? 0 : 1);
PANELSETUP,
                        ],
                        'cli' => [
                            ['script' => 'panel-setup.php', 'args' => ['{dir}', '{db_host}', '{db_name}', '{db_user}', '{db_pass}', '{home}', '{url}', '{email}', '{user}', '{title}']],
                        ],
                        'cron' => [
                            ['schedule' => '* * * * *', 'command' => '{phpbin} {dest}/cli/cron.php'],
                        ],
                        'datadirs' => ['iliasdata-{dir}'],
                        'cleanup' => ['panel-setup.php'],
                    ],
                    'webroot' => 'public',
                    'admin' => ['path' => ''],
                    'post_install' => 'Installed automatically — log in as root with password homer, then set a new password. Background jobs run every minute via cron.',
                    'update_check' => ['type' => 'github', 'repo' => 'ILIAS-eLearning/ILIAS'],
                ],
            ],
            [
                'slug' => 'omeka-s', 'name' => 'Omeka S', 'category' => 'Education',
                'version' => '4.2.1', 'featured' => 0, 'sort' => 57,
                'description' => 'Publish digital collections and exhibits for libraries and museums.',
                'website' => 'https://omeka.org/s/', 'license' => 'GPL-3.0', 'vendor' => 'Omeka',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.1', 'extensions' => ['pdo_mysql', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'fileinfo']],
                    'database' => ['required' => true, 'prefix' => ''],
                    'download' => ['github' => ['repo' => 'omeka/omeka-s', 'asset' => 'omeka-s-{version}.zip', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        // No CLI installer ships: the driver boots Laminas
                        // headless and calls the stock Installation Installer
                        // service (the same one the web InstallController
                        // uses) — schema + vocabularies + templates + first
                        // admin + settings. database.ini is static
                        // setup.files text (only panel-generated hex secrets
                        // ride it); the wizard password rides argv.
                        // CLI-only. database.ini stays (runtime reads it).
                        'files' => ['config/database.ini' => <<<'PANELCONFIG'
user     = "{db_user}"
password = "{db_pass}"
dbname   = "{db_name}"
host     = "{db_host}"
PANELCONFIG,
                            'panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 5, '');
    if (($__e = getenv('CWH_SETUP_PASS')) !== false && $__e !== '') { $args[4] = $__e; }
$title = trim($args[1]);
$email = trim($args[2]);
$user = trim($args[3]);
$pass = $args[4] ?? '';
if ($title === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $user) || strlen($pass) < 8
) {
    fwrite(STDERR, "usage: panel-setup.php <title> <email> <user> <pass>\n");
    exit(1);
}
// The agent runs setup scripts without cd-ing into the tree.
chdir(__DIR__);
require __DIR__ . '/bootstrap.php';
$app = Omeka\Mvc\Application::init(require __DIR__ . '/application/config/application.config.php');
$installer = $app->getServiceManager()->get('Omeka\Installer');
$installer->registerVars('Omeka\Installation\Task\CreateFirstUserTask', [
    'name' => $user,
    'email' => $email,
    'password-confirm' => ['password' => $pass],
]);
$installer->registerVars('Omeka\Installation\Task\AddDefaultSettingsTask', [
    'administrator_email' => $email,
    'installation_title' => $title,
    'time_zone' => 'UTC',
    'locale' => 'en_US',
]);
if (!$installer->install()) {
    foreach ((array) $installer->getErrors() as $e) {
        fwrite(STDERR, (is_string($e) ? $e : print_r($e, true)) . "\n");
    }
    exit(1);
}
echo "omeka setup complete\n";
PANELSETUP,
                        ],
                        'cli' => [
                            ['script' => 'panel-setup.php', 'args' => ['{title}', '{email}', '{user}', '{pass}']],
                        ],
                        'cleanup' => ['panel-setup.php'],
                    ],
                    'admin' => ['path' => 'admin'],
                    'post_install' => 'Installed automatically with the admin account you entered — log in at /admin/.',
                    'update_check' => ['type' => 'github', 'repo' => 'omeka/omeka-s'],
                ],
            ],
            [
                'slug' => 'homer', 'name' => 'Homer', 'category' => 'Portals',
                'version' => '26.08.3', 'featured' => 0, 'sort' => 59,
                'description' => 'A dead-simple static homepage for all your services and links.',
                'website' => 'https://github.com/bastienwirtz/homer', 'license' => 'Apache-2.0', 'vendor' => 'Homer',
                'manifest' => [
                    'type' => 'php',
                    'requirements' => ['php_min' => '7.4', 'extensions' => []],
                    'database' => ['required' => false],
                    'download' => ['github' => ['repo' => 'bastienwirtz/homer', 'asset' => 'homer.zip', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => ['files' => ['assets/config.yml' => 'homer']],
                    'admin' => ['path' => ''],
                    'post_install' => 'Ready with a starter dashboard — edit assets/config.yml in the File Manager to add your services.',
                    'update_check' => ['type' => 'github', 'repo' => 'bastienwirtz/homer'],
                ],
            ],
            [
                'slug' => 'providence', 'name' => 'CollectiveAccess', 'category' => 'Education',
                'version' => '2.0.11', 'featured' => 0, 'sort' => 60,
                'description' => 'Catalogue museum, archive and library collections professionally.',
                'website' => 'https://collectiveaccess.org', 'license' => 'GPL-3.0', 'vendor' => 'CollectiveAccess',
                'manifest' => [
                    'type' => 'php-mysql',
                    'requirements' => ['php_min' => '8.1', 'extensions' => ['pdo_mysql', 'mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'fileinfo', 'intl']],
                    'database' => ['required' => true, 'prefix' => ''],
                    'download' => ['github' => ['repo' => 'collectiveaccess/providence', 'asset' => 'providence-{version}.zip', 'tag_prefix' => ''], 'sha256' => '', 'format' => 'zip', 'strip_root' => true],
                    'config' => [],
                    'setup' => [
                        // No usable web flow on panel PHP (and the CLI needs
                        // no root when the panel DB already exists): the
                        // driver runs stock caUtils install (default profile,
                        // 38s class — well under the agent cap), then renames
                        // the seeded administrator to the wizard username and
                        // sets its password through the ca_users model.
                        // setup.php is built from setup.php-dist (only
                        // panel-generated hex secrets + paths ride it — user
                        // input never lands in static text); the wizard
                        // password rides argv. Background tasks must use
                        // socket mode (masters disable proc_open, which the
                        // default process mode fatals on at every request
                        // close) via app/conf/local/app.conf; the task queue
                        // additionally runs minutely via setup.cron.
                        // CLI-only; install/ removed by cleanup.
                        'files' => ['app/conf/local/app.conf' => <<<'PANELCONFIG'
background_process_mode = socket
PANELCONFIG,
                            'panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 10, '');
    if (($__e = getenv('CWH_SETUP_PASS')) !== false && $__e !== '') { $args[4] = $__e; }
    if (($__e = getenv('CWH_SETUP_DB_PASS')) !== false && $__e !== '') { $args[8] = $__e; }
$title = trim($args[1]);
$email = trim($args[2]);
$user = trim($args[3]);
$pass = $args[4] ?? '';
$dbhost = trim($args[5]);
$dbname = trim($args[6]);
$dbuser = trim($args[7]);
$dbpass = $args[8] ?? '';
$url = rtrim(trim($args[9]), '/');
if ($title === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $user) || strlen($pass) < 8
    || $dbhost === '' || $dbname === '' || $dbuser === '' || $dbpass === ''
    || !preg_match('#^https?://#', $url)
) {
    fwrite(STDERR, "usage: panel-setup.php <title> <email> <user> <pass> <dbhost> <dbname> <dbuser> <dbpass> <url>\n");
    exit(1);
}
// The agent runs setup scripts without cd-ing into the tree.
chdir(__DIR__);
// 1. setup.php from the shipped -dist (assert every needle: a renamed
// upstream key must fail loudly here, not as a mystery later).
$dist = @file_get_contents(__DIR__ . '/setup.php-dist');
if ($dist === false) {
    fwrite(STDERR, "setup.php-dist missing\n");
    exit(1);
}
$esc = static function ($v) {
    return str_replace(['\\', "'", '$'], ['\\\\', "\\'", '\\$'], (string) $v);
};
$subs = [
    "define(\"__CA_DB_HOST__\", 'localhost');" => "define(\"__CA_DB_HOST__\", '" . $esc($dbhost) . "');",
    "define(\"__CA_DB_USER__\", 'my_database_user');" => "define(\"__CA_DB_USER__\", '" . $esc($dbuser) . "');",
    "define(\"__CA_DB_PASSWORD__\", 'my_database_password');" => "define(\"__CA_DB_PASSWORD__\", '" . $esc($dbpass) . "');",
    "define(\"__CA_DB_DATABASE__\", 'name_of_my_database');" => "define(\"__CA_DB_DATABASE__\", '" . $esc($dbname) . "');",
    'define("__CA_APP_DISPLAY_NAME__", "My First CollectiveAccess System");' => 'define("__CA_APP_DISPLAY_NAME__", "' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $title) . '");',
    "define(\"__CA_ADMIN_EMAIL__\", 'info@put-your-domain-here.com');" => "define(\"__CA_ADMIN_EMAIL__\", '" . $esc($email) . "');",
];
foreach ($subs as $needle => $replacement) {
    if (strpos($dist, $needle) === false) {
        fwrite(STDERR, 'setup.php-dist shape changed (' . substr($needle, 0, 40) . ")\n");
        exit(1);
    }
    $dist = str_replace($needle, $replacement, $dist);
}
$dist .= "\nif (!defined(\"__CA_LOCAL_CONFIG_DIRECTORY__\")) {\n\tdefine(\"__CA_LOCAL_CONFIG_DIRECTORY__\", __DIR__ . \"/app/conf/local\");\n}\n";
if (file_put_contents(__DIR__ . '/setup.php', $dist) === false) {
    fwrite(STDERR, "could not write setup.php\n");
    exit(1);
}
// 2. Stock CLI install (default profile). DB/user pre-exist (panel), so no
// root path is needed. Secrets ride argv (no shell — proc_open array form).
$cmd = [PHP_BINARY, '-d', 'memory_limit=1024M', 'support/bin/caUtils', 'install', '--profile-name=default', '--admin-email=' . $email];
$desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$env = ['COLLECTIVEACCESS_HOME' => __DIR__, 'PATH' => '/usr/bin:/bin'];
$proc = proc_open($cmd, $desc, $pipes, __DIR__, $env);
if (!is_resource($proc)) {
    fwrite(STDERR, "could not start installer\n");
    exit(1);
}
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($proc);
if ($out !== '') {
    echo $out;
}
if ($err !== '') {
    fwrite(STDERR, $err);
}
if ($code !== 0) {
    fwrite(STDERR, 'installer failed (exit ' . $code . ")\n");
    exit(1);
}
// 3. Rename the seeded administrator to the wizard username + set its
// password through the model (proper hashing).
require __DIR__ . '/setup.php';
require_once(__CA_APP_DIR__ . '/helpers/configurationHelpers.php');
$t = new ca_users();
if (!$t->load(['user_name' => 'administrator'])) {
    fwrite(STDERR, "seeded administrator missing\n");
    exit(1);
}
$t->set('user_name', $user);
$t->set('password', $pass);
$t->update();
if ($t->numErrors()) {
    fwrite(STDERR, join('; ', $t->getErrors()) . "\n");
    exit(1);
}
echo "providence setup complete\n";
PANELSETUP,
                        ],
                        'cli' => [
                            ['script' => 'panel-setup.php', 'args' => ['{title}', '{email}', '{user}', '{pass}', '{db_host}', '{db_name}', '{db_user}', '{db_pass}', '{url}']],
                        ],
                        'cron' => [
                            ['schedule' => '* * * * *', 'command' => '{phpbin} -f {dest}/support/bin/caUtils process-task-queue'],
                        ],
                        'cleanup' => ['panel-setup.php', 'install'],
                    ],
                    'admin' => ['path' => ''],
                    'post_install' => 'Installed automatically with the admin account you entered — log in from the front page.',
                    'update_check' => ['type' => 'github', 'repo' => 'collectiveaccess/providence'],
                ],
            ],
            [
                'slug' => 'adminer', 'name' => 'Adminer', 'category' => 'Developer Tools',
                'version' => '6.1.0', 'featured' => 0, 'sort' => 61,
                'description' => 'Database management in a single PHP file — fast phpMyAdmin alternative.',
                'website' => 'https://www.adminer.org', 'license' => 'Apache-2.0', 'vendor' => 'Adminer',
                'manifest' => [
                    'type' => 'php',
                    'requirements' => ['php_min' => '7.4', 'extensions' => ['mysqli']],
                    'database' => ['required' => false],
                    'download' => ['github' => ['repo' => 'vrana/adminer', 'asset' => 'adminer-{version}-mysql-en.php', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'file'],
                    'file' => ['name' => 'adminer.php'],
                    'config' => [],
                    'admin' => ['path' => 'adminer.php'],
                    'post_install' => 'No setup — open adminer.php and log in with a MySQL user from Databases (server 127.0.0.1).',
                    'update_check' => ['type' => 'github', 'repo' => 'vrana/adminer'],
                ],
            ],
            [
                'slug' => 'radicale', 'name' => 'Radicale', 'category' => 'Utilities',
                'version' => 'latest', 'featured' => 0, 'sort' => 62,
                'description' => 'CalDAV/CardDAV calendar and contacts server for your phones and apps.',
                'website' => 'https://radicale.org', 'license' => 'GPL-3.0', 'vendor' => 'Radicale',
                'manifest' => [
                    'type' => 'python-pip',
                    'runtime' => ['version' => '3.11'],
                    'pip' => ['package' => 'radicale', 'script' => 'radicale', 'args' => ['--config', 'config', '--hosts', '{HOST}:{PORT}', '--storage-filesystem-folder', 'collections']],
                    'app' => ['seed_files' => [
                        // Radicale defaults its collection root to the
                        // unwritable /var/lib/radicale — point it at an
                        // account-owned dir (the seed proves the dir).
                        'collections/README.txt' => "CalDAV/CardDAV collections live here.\n",
                        // Unattended auth: htpasswd with the wizard account.
                        // sha256 ($5$) needs no extra Python deps (bcrypt
                        // does); {pass_sha256} is a sha256-crypt hash.
                        'config' => <<<'RADICALECONF'
[auth]
type = htpasswd
htpasswd_filename = users
htpasswd_encryption = sha256
RADICALECONF
                        ,
                        'users' => "{user}:{pass_sha256}\n",
                    ]],
                    'setup' => ['requires_creds' => true],
                    'admin' => ['path' => ''],
                    'post_install' => 'Installed automatically with the login you entered — point your calendar app at the site URL (use it as the account).',
                    'update_check' => ['type' => 'pypi', 'package' => 'radicale'],
                ],
            ],
            [
                'slug' => 'notebook', 'name' => 'Jupyter Notebook', 'category' => 'Developer Tools',
                'version' => 'latest', 'featured' => 0, 'sort' => 63,
                'description' => 'Interactive notebooks for Python data science and experiments.',
                'website' => 'https://jupyter.org', 'license' => 'BSD-3-Clause', 'vendor' => 'Jupyter',
                'manifest' => [
                    'type' => 'python-pip',
                    'runtime' => ['version' => '3.11'],
                    'pip' => ['package' => 'notebook', 'script' => 'jupyter-notebook', 'args' => ['--ip={HOST}', '--port={PORT}', '--no-browser']],
                    'app' => [
                        'env' => ['JUPYTER_CONFIG_DIR' => '.'],
                        'seed_files' => [
                            // Unattended auth: Jupyter reads its token from the
                            // server config, so seeding the wizard password as
                            // the token beats reading an auto-generated one from
                            // the logs. Args (--ip/--port) still win over config.
                            'jupyter_server_config.py' => "c.ServerApp.ip = '{HOST}'\nc.ServerApp.port = {PORT}\nc.ServerApp.open_browser = False\nc.ServerApp.token = '{pass}'\nc.ServerApp.password = ''\nc.IdentityProvider.token = '{pass}'\n",
                        ],
                    ],
                    'setup' => ['requires_creds' => true],
                    'admin' => ['path' => ''],
                    'post_install' => 'Installed automatically — log in with the password you entered (it is the access token).',
                    'update_check' => ['type' => 'pypi', 'package' => 'notebook'],
                ],
            ],
            [
                'slug' => 'mkdocs', 'name' => 'MkDocs', 'category' => 'Documentation',
                'version' => 'latest', 'featured' => 0, 'sort' => 64,
                'description' => 'Project documentation sites from Markdown files.',
                'website' => 'https://www.mkdocs.org', 'license' => 'BSD-2-Clause', 'vendor' => 'MkDocs',
                'manifest' => [
                    'type' => 'python-pip',
                    'runtime' => ['version' => '3.11'],
                    'pip' => ['package' => 'mkdocs', 'script' => 'mkdocs', 'args' => ['serve', '--dev-addr', '{HOST}:{PORT}']],
                    'app' => ['seed_files' => [
                        'mkdocs.yml' => "site_name: My Docs\nnav:\n  - Home: index.md\n",
                        'docs/index.md' => "# Welcome\n\nEdit `docs/` in the File Manager to write your documentation.\n",
                    ]],
                    'admin' => ['path' => ''],
                    'post_install' => 'A starter site is live — edit Markdown files under docs/ in the File Manager and the app restarts itself.',
                    'update_check' => ['type' => 'pypi', 'package' => 'mkdocs'],
                ],
            ],
            [
                'slug' => 'pypiserver', 'name' => 'PyPI Server', 'category' => 'Developer Tools',
                'version' => 'latest', 'featured' => 0, 'sort' => 65,
                'description' => 'Minimal private Python package index for your own wheels.',
                'website' => 'https://pypiserver.readthedocs.io', 'license' => 'MIT', 'vendor' => 'pypiserver',
                'manifest' => [
                    'type' => 'python-pip',
                    'runtime' => ['version' => '3.11'],
                    'pip' => ['package' => 'pypiserver', 'script' => 'pypi-server', 'args' => ['-p', '{PORT}', 'packages']],
                    'app' => ['seed_files' => [
                        'packages/README.txt' => "Upload your .whl / .tar.gz packages here, then:\npip install --index-url <site-url>/simple/ <package>\n",
                    ]],
                    'admin' => ['path' => ''],
                    'post_install' => 'Upload wheels into packages/ via the File Manager, then pip-install from <site-url>/simple/.',
                    'update_check' => ['type' => 'pypi', 'package' => 'pypiserver'],
                ],
            ],
            [
                'slug' => 'wsgidav', 'name' => 'WsgiDAV', 'category' => 'File Sharing',
                'version' => 'latest', 'featured' => 0, 'sort' => 69,
                'description' => 'WebDAV file server — mount your files as a network drive.',
                'website' => 'https://wsgidav.readthedocs.io', 'license' => 'MIT', 'vendor' => 'WsgiDAV',
                'manifest' => [
                    'type' => 'python-pip',
                    'runtime' => ['version' => '3.11'],
                    'pip' => ['package' => 'wsgidav', 'script' => 'wsgidav', 'args' => ['--host={HOST}', '--port={PORT}', '--root=share', '--server', 'wsgiref']],
                    'app' => ['seed_files' => [
                        'share/README.txt' => "Files placed here are shared over WebDAV.\n",
                    ]],
                    'admin' => ['path' => ''],
                    'post_install' => 'Connect any WebDAV client to the site URL. Add --auth=user:pass style args only via a custom app.',
                    'update_check' => ['type' => 'pypi', 'package' => 'wsgidav'],
                ],
            ],
            [
                'slug' => 'http-server', 'name' => 'HTTP Server', 'category' => 'Developer Tools',
                'version' => 'latest', 'featured' => 0, 'sort' => 70,
                'description' => 'Zero-config static file server for any folder.',
                'website' => 'https://www.npmjs.com/package/http-server', 'license' => 'MIT', 'vendor' => 'http-server',
                'manifest' => [
                    'type' => 'node-npm',
                    'runtime' => ['version' => '22'],
                    'npm' => ['package' => 'http-server', 'bin' => 'node_modules/http-server/bin/http-server', 'args' => ['-a', '{HOST}', '-p', '{PORT}', 'www']],
                    'app' => ['seed_files' => [
                        'www/index.html' => "<!doctype html><html><head><meta charset=\"utf-8\"><title>My site</title></head><body><h1>Hello</h1><p>Edit <code>www/</code> in the File Manager.</p></body></html>\n",
                    ]],
                    'admin' => ['path' => ''],
                    'post_install' => 'Serves the www/ folder — edit or upload files there and changes are live instantly.',
                    'update_check' => ['type' => 'npm', 'package' => 'http-server'],
                ],
            ],
            [
                'slug' => 'serve', 'name' => 'Serve', 'category' => 'Developer Tools',
                'version' => 'latest', 'featured' => 0, 'sort' => 71,
                'description' => 'Static file server with clean URLs and SPA fallback.',
                'website' => 'https://www.npmjs.com/package/serve', 'license' => 'MIT', 'vendor' => 'Vercel',
                'manifest' => [
                    'type' => 'node-npm',
                    'runtime' => ['version' => '22'],
                    'npm' => ['package' => 'serve', 'bin' => 'node_modules/serve/build/main.js', 'args' => ['-l', 'tcp://{HOST}:{PORT}', 'www']],
                    'app' => ['seed_files' => [
                        'www/index.html' => "<!doctype html><html><head><meta charset=\"utf-8\"><title>My site</title></head><body><h1>Hello</h1><p>Edit <code>www/</code> in the File Manager.</p></body></html>\n",
                    ]],
                    'admin' => ['path' => ''],
                    'post_install' => 'Serves the www/ folder with clean URLs — ideal for static sites and SPAs.',
                    'update_check' => ['type' => 'npm', 'package' => 'serve'],
                ],
            ],
            [
                'slug' => 'live-server', 'name' => 'Live Server', 'category' => 'Developer Tools',
                'version' => 'latest', 'featured' => 0, 'sort' => 72,
                'description' => 'Static server with live reload while you edit.',
                'website' => 'https://www.npmjs.com/package/live-server', 'license' => 'MIT', 'vendor' => 'live-server',
                'manifest' => [
                    'type' => 'node-npm',
                    'runtime' => ['version' => '22'],
                    'npm' => ['package' => 'live-server', 'bin' => 'node_modules/live-server/live-server.js', 'args' => ['--host={HOST}', '--port={PORT}', 'www']],
                    'app' => ['seed_files' => [
                        'www/index.html' => "<!doctype html><html><head><meta charset=\"utf-8\"><title>My site</title></head><body><h1>Hello</h1><p>Edit <code>www/</code> — the page reloads itself.</p></body></html>\n",
                    ]],
                    'admin' => ['path' => ''],
                    'post_install' => 'Serves www/ with live reload — keep the tab open while editing files.',
                    'update_check' => ['type' => 'npm', 'package' => 'live-server'],
                ],
            ],
            [
                'slug' => 'json-server', 'name' => 'JSON Server', 'category' => 'Developer Tools',
                'version' => 'latest', 'featured' => 0, 'sort' => 73,
                'description' => 'Instant REST API from a JSON file — prototyping and mock backends.',
                'website' => 'https://www.npmjs.com/package/json-server', 'license' => 'MIT', 'vendor' => 'JSON Server',
                'manifest' => [
                    'type' => 'node-npm',
                    'runtime' => ['version' => '22'],
                    'npm' => ['package' => 'json-server', 'bin' => 'node_modules/json-server/lib/bin.js', 'args' => ['db.json', '--port', '{PORT}', '--host', '{HOST}']],
                    'app' => ['seed_files' => [
                        'db.json' => "{\n  \"posts\": [\n    { \"id\": \"1\", \"title\": \"Hello API\", \"body\": \"Edit db.json to shape your mock API.\" }\n  ]\n}\n",
                    ]],
                    'admin' => ['path' => '/posts'],
                    'post_install' => 'Your API is live at /posts — edit db.json in the File Manager to add resources.',
                    'update_check' => ['type' => 'npm', 'package' => 'json-server'],
                ],
            ],
            [
                'slug' => 'docsify', 'name' => 'Docsify', 'category' => 'Documentation',
                'version' => 'latest', 'featured' => 0, 'sort' => 74,
                'description' => 'Documentation site generator — no build step, just Markdown.',
                'website' => 'https://docsify.js.org', 'license' => 'MIT', 'vendor' => 'Docsify',
                'manifest' => [
                    'type' => 'node-npm',
                    'runtime' => ['version' => '22'],
                    'npm' => ['package' => 'docsify-cli', 'bin' => 'node_modules/docsify-cli/bin/docsify', 'args' => ['serve', '.', '--host', '{HOST}', '--port', '{PORT}']],
                    'app' => ['seed_files' => [
                        'index.html' => "<!DOCTYPE html>\n<html><head><meta charset=\"UTF-8\"><link rel=\"stylesheet\" href=\"//cdn.jsdelivr.net/npm/docsify@5/lib/themes/vue.css\"></head><body><div id=\"app\"></div><script>window.\$docsify={name:'My Docs',loadSidebar:false}</script><script src=\"//cdn.jsdelivr.net/npm/docsify@5/lib/docsify.min.js\"></script></body></html>\n",
                        'README.md' => "# Welcome\n\nWrite your docs here in Markdown.\n",
                    ]],
                    'admin' => ['path' => ''],
                    'post_install' => 'Docs are live — edit README.md (and add pages) in the File Manager.',
                    'update_check' => ['type' => 'npm', 'package' => 'docsify-cli'],
                ],
            ],
            [
                'slug' => 'node-red', 'name' => 'Node-RED', 'category' => 'Automation',
                'version' => 'latest', 'featured' => 0, 'sort' => 75,
                'description' => 'Flow-based automation and IoT wiring with a visual editor.',
                'website' => 'https://nodered.org', 'license' => 'Apache-2.0', 'vendor' => 'Node-RED',
                'manifest' => [
                    'type' => 'node-npm',
                    'runtime' => ['version' => '22'],
                    'npm' => ['package' => 'node-red', 'bin' => 'node_modules/node-red/red.js', 'args' => ['--port', '{PORT}']],
                    'app' => [],
                    'admin' => ['path' => ''],
                    'post_install' => 'Open the editor, wire your first flow and deploy. Flows persist in ~/.node-red.',
                    'update_check' => ['type' => 'npm', 'package' => 'node-red'],
                ],
            ],
            [
                'slug' => 'verdaccio', 'name' => 'Verdaccio', 'category' => 'Developer Tools',
                'version' => 'latest', 'featured' => 0, 'sort' => 76,
                'description' => 'Private npm registry proxy with local package publishing.',
                'website' => 'https://verdaccio.org', 'license' => 'MIT', 'vendor' => 'Verdaccio',
                'manifest' => [
                    'type' => 'node-npm',
                    'runtime' => ['version' => '26'],
                    'npm' => ['package' => 'verdaccio', 'bin' => 'node_modules/verdaccio/bin/verdaccio', 'args' => ['--listen', '{HOST}:{PORT}']],
                    'app' => [
                        'seed_files' => [
                            // Keep storage + htpasswd inside the app work dir
                            // (verdaccio's built-in default would use
                            // ~/.config/verdaccio + ~/.local/share/verdaccio,
                            // outside the account's cleanup scope).
                            'config.yaml' => <<<'VERDACCIONCONF'
storage: ./storage
auth:
  htpasswd:
    file: ./htpasswd
    max_users: 1000
uplinks:
  npmjs:
    url: https://registry.npmjs.org/
packages:
  '@*/*':
    access: $all
    publish: $authenticated
    unpublish: $authenticated
    proxy: npmjs
  '**':
    access: $all
    publish: $authenticated
    unpublish: $authenticated
    proxy: npmjs
log:
  type: stdout
  format: pretty
  level: warn
VERDACCIONCONF
                            ,
                            // Unattended first user: verdaccio's htpasswd
                            // registry API creates the account (bcrypt) on
                            // POST-less PUT; npm adduser/publish then work.
                            'panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_slice($argv ?? [], 1);
if (($__e = getenv('CWH_SETUP_PASS')) !== false && $__e !== '') { $args[2] = $__e; }
if (count($args) < 3) {
    fwrite(STDERR, "verdaccio setup: bad arguments\n");
    exit(1);
}
$port = (string) $args[0];
$user = (string) $args[1];
$pass = (string) $args[2];
$email = isset($args[3]) ? (string) $args[3] : '';
if ($user === '' || $pass === '') {
    fwrite(STDERR, "verdaccio setup: missing credentials\n");
    exit(1);
}
$url = 'http://{HOST}:' . $port . '/-/user/org.couchdb.user:' . rawurlencode($user);
$body = json_encode(['name' => $user, 'password' => $pass, 'email' => $email]);
$ctx = stream_context_create(['http' => [
    'method' => 'PUT',
    'header' => "Content-Type: application/json\r\n",
    'content' => $body,
    'timeout' => 30,
    'ignore_errors' => true,
]]);
$last = '';
for ($try = 0; $try < 6; $try++) {
    unset($http_response_header);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $code = (int) $m[1];
    }
    $text = (string) $resp;
    if ($code === 200 || $code === 201 || stripos($text, '"token"') !== false) {
        echo "verdaccio setup complete\n";
        exit(0);
    }
    if ($code === 409 || stripos($text, 'already exists') !== false) {
        echo "verdaccio user already exists\n";
        exit(0);
    }
    $last = 'HTTP ' . $code . ' ' . mb_substr($text, 0, 200);
    sleep(10);
}
fwrite(STDERR, 'verdaccio setup failed: ' . $last . "\n");
exit(1);
PANELSETUP
                        ],
                    ],
                    'setup' => [
                        'cli' => [[
                            'script' => 'panel-setup.php',
                            'args' => ['{PORT}', '{user}', '{pass}', '{email}'],
                        ]],
                        'cleanup' => ['panel-setup.php'],
                    ],
                    'admin' => ['path' => ''],
                    'post_install' => 'Installed automatically with the account you entered — npm adduser --registry <site-url> to publish private packages.',
                    'update_check' => ['type' => 'npm', 'package' => 'verdaccio'],
                ],
            ],
            [
                'slug' => 'express', 'name' => 'Express.js Starter', 'category' => 'Frameworks',
                'version' => 'latest', 'featured' => 0, 'sort' => 77,
                'description' => 'Minimal Express web app skeleton — build your own API or site.',
                'website' => 'https://expressjs.com', 'license' => 'MIT', 'vendor' => 'Express',
                'manifest' => [
                    'type' => 'node-npm',
                    'runtime' => ['version' => '22'],
                    'npm' => ['package' => 'express', 'bin' => 'app.js', 'args' => []],
                    'app' => ['seed_files' => [
                        'app.js' => "const express = require('express');\nconst app = express();\nconst port = process.env.PORT || 3000;\napp.get('/', (req, res) => res.send('<h1>Hello from Express</h1><p>Edit app.js to build your app.</p>'));\napp.listen(port, '{HOST}', () => console.log('listening on ' + port));\n",
                    ]],
                    'admin' => ['path' => ''],
                    'post_install' => 'A working Express app is running — edit app.js in the File Manager, then restart the app.',
                    'update_check' => ['type' => 'npm', 'package' => 'express'],
                ],
            ],
            [
                'slug' => 'fastify', 'name' => 'Fastify Starter', 'category' => 'Frameworks',
                'version' => 'latest', 'featured' => 0, 'sort' => 78,
                'description' => 'Fast, low-overhead Node.js API skeleton.',
                'website' => 'https://fastify.dev', 'license' => 'MIT', 'vendor' => 'Fastify',
                'manifest' => [
                    'type' => 'node-npm',
                    'runtime' => ['version' => '22'],
                    'npm' => ['package' => 'fastify', 'bin' => 'app.js', 'args' => []],
                    'app' => ['seed_files' => [
                        'app.js' => "const fastify = require('fastify')({ logger: true });\nfastify.get('/', async () => ({ hello: 'fastify — edit app.js to build your API' }));\nfastify.listen({ port: process.env.PORT || 3000, host: '{HOST}' });\n",
                    ]],
                    'admin' => ['path' => ''],
                    'post_install' => 'A working Fastify API is running — edit app.js, then restart the app.',
                    'update_check' => ['type' => 'npm', 'package' => 'fastify'],
                ],
            ],
            [
                'slug' => 'koa', 'name' => 'Koa Starter', 'category' => 'Frameworks',
                'version' => 'latest', 'featured' => 0, 'sort' => 79,
                'description' => 'Elegant middleware-based Node.js app skeleton.',
                'website' => 'https://koajs.com', 'license' => 'MIT', 'vendor' => 'Koa',
                'manifest' => [
                    'type' => 'node-npm',
                    'runtime' => ['version' => '22'],
                    'npm' => ['package' => 'koa', 'bin' => 'app.js', 'args' => []],
                    'app' => ['seed_files' => [
                        'app.js' => "const Koa = require('koa');\nconst app = new Koa();\napp.use(async ctx => { ctx.body = '<h1>Hello Koa</h1><p>Edit app.js to build your app.</p>'; });\napp.listen(process.env.PORT || 3000, '{HOST}');\n",
                    ]],
                    'admin' => ['path' => ''],
                    'post_install' => 'A working Koa app is running — edit app.js, then restart the app.',
                    'update_check' => ['type' => 'npm', 'package' => 'koa'],
                ],
            ],
            [
                'slug' => 'hapi', 'name' => 'hapi Starter', 'category' => 'Frameworks',
                'version' => 'latest', 'featured' => 0, 'sort' => 80,
                'description' => 'Robust configuration-centric Node.js app skeleton.',
                'website' => 'https://hapi.dev', 'license' => 'BSD-3-Clause', 'vendor' => 'hapi',
                'manifest' => [
                    'type' => 'node-npm',
                    'runtime' => ['version' => '22'],
                    'npm' => ['package' => '@hapi/hapi', 'bin' => 'app.js', 'args' => []],
                    'app' => ['seed_files' => [
                        'app.js' => "const Hapi = require('@hapi/hapi');\nconst init = async () => {\n  const server = Hapi.server({ port: process.env.PORT || 3000, host: '{HOST}' });\n  server.route({ method: 'GET', path: '/', handler: () => '<h1>Hello hapi</h1><p>Edit app.js.</p>' });\n  await server.start();\n  console.log(server.info.uri);\n};\ninit();\n",
                    ]],
                    'admin' => ['path' => ''],
                    'post_install' => 'A working hapi app is running — edit app.js, then restart the app.',
                    'update_check' => ['type' => 'npm', 'package' => '@hapi/hapi'],
                ],
            ],
            [
                'slug' => 'restify', 'name' => 'Restify Starter', 'category' => 'Frameworks',
                'version' => 'latest', 'featured' => 0, 'sort' => 81,
                'description' => 'API-first Node.js service skeleton with built-in DTrace support.',
                'website' => 'https://restify.com', 'license' => 'MIT', 'vendor' => 'Restify',
                'manifest' => [
                    'type' => 'node-npm',
                    'runtime' => ['version' => '22'],
                    'npm' => ['package' => 'restify', 'bin' => 'app.js', 'args' => []],
                    'app' => ['seed_files' => [
                        'app.js' => "const restify = require('restify');\nconst server = restify.createServer();\nserver.get('/', (req, res, next) => { res.send('Hello Restify — edit app.js'); next(); });\nserver.listen(process.env.PORT || 3000, '{HOST}', () => console.log('listening'));\n",
                    ]],
                    'admin' => ['path' => ''],
                    'post_install' => 'A working Restify service is running — edit app.js, then restart the app.',
                    'update_check' => ['type' => 'npm', 'package' => 'restify'],
                ],
            ],
            [
                'slug' => 'vite', 'name' => 'Vite Starter', 'category' => 'Developer Tools',
                'version' => 'latest', 'featured' => 0, 'sort' => 82,
                'description' => 'Next-generation frontend dev server with instant hot reload.',
                'website' => 'https://vite.dev', 'license' => 'MIT', 'vendor' => 'Vite',
                'manifest' => [
                    'type' => 'node-npm',
                    'runtime' => ['version' => '22'],
                    'npm' => ['package' => 'vite', 'bin' => 'node_modules/vite/bin/vite.js', 'args' => ['--host', '{HOST}', '--port', '{PORT}']],
                    'app' => ['seed_files' => [
                        'index.html' => "<!doctype html>\n<html><head><meta charset=\"utf-8\"><title>Vite app</title></head><body><div id=\"app\"><h1>Hello Vite</h1></div><script type=\"module\">console.log('edit index.html or add src/main.js')</script></body></html>\n",
                        // Behind the panel reverse proxy the Host header is
                        // the public domain, which Vite's host check 403s —
                        // allow it (the front door is the public face).
                        'vite.config.js' => "export default { server: { allowedHosts: true } };\n",
                    ]],
                    'admin' => ['path' => ''],
                    'post_install' => 'Vite serves index.html — add your frontend files and run `npm run build` output via a static server for production.',
                    'update_check' => ['type' => 'npm', 'package' => 'vite'],
                ],
            ],
            [
                'slug' => 'local-web-server', 'name' => 'Local Web Server', 'category' => 'Developer Tools',
                'version' => 'latest', 'featured' => 0, 'sort' => 83,
                'description' => 'Feature-rich static server — rewrites, SPA fallback, directory views.',
                'website' => 'https://www.npmjs.com/package/local-web-server', 'license' => 'MIT', 'vendor' => '75lb',
                'manifest' => [
                    'type' => 'node-npm',
                    'runtime' => ['version' => '22'],
                    'npm' => ['package' => 'local-web-server', 'bin' => 'node_modules/local-web-server/bin/cli.js', 'args' => ['--port', '{PORT}', '--hostname', '{HOST}', '--directory', 'www']],
                    'app' => ['seed_files' => [
                        'www/index.html' => "<!doctype html><html><head><meta charset=\"utf-8\"><title>My site</title></head><body><h1>Hello</h1><p>Edit <code>www/</code> in the File Manager.</p></body></html>\n",
                    ]],
                    'admin' => ['path' => ''],
                    'post_install' => 'Serves the www/ folder — uploads and edits there go live instantly.',
                    'update_check' => ['type' => 'npm', 'package' => 'local-web-server'],
                ],
            ],
            [
                'slug' => 'gitea', 'name' => 'Gitea', 'category' => 'Developer Tools',
                'version' => '1.27.3', 'featured' => 0, 'sort' => 84,
                'description' => 'Self-hosted Git service — repos, issues, pull requests, CI.',
                'website' => 'https://gitea.com', 'license' => 'MIT', 'vendor' => 'Gitea',
                'manifest' => [
                    'type' => 'binary',
                    'download' => ['github' => ['repo' => 'go-gitea/gitea', 'asset' => 'gitea-{version}-linux-amd64', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'file'],
                    'app' => [
                        'binary' => 'gitea',
                        'args' => ['web', '--port', '{PORT}'],
                        'seed_files' => [
                            // The unit auto-discovers custom/conf/app.ini
                            // relative to the workdir; an explicit config
                            // beats the browser installer (which cannot run
                            // headless). SQLite migrates on first boot.
                            'custom/conf/app.ini' => "APP_NAME = Gitea: Git with a cup of tea\nRUN_MODE = prod\n[server]\nPROTOCOL = http\nDOMAIN = {domain}\nHTTP_ADDR = {HOST}\nHTTP_PORT = {PORT}\nROOT_URL = {url}/\nDISABLE_SSH = true\n[database]\nDB_TYPE = sqlite3\nPATH = data/gitea.db\n[security]\nINSTALL_LOCK = true\n[service]\nDISABLE_REGISTRATION = true\n",
                            // Admin creation runs post-boot (setup.cli on app
                            // installs): JSON argv keeps any password intact.
                            'panel-setup.php' => <<<'PANELSETUP'
<?php
// Gitea unattended admin: the unit already booted + migrated the
// database (setup.cli runs post-start for app installs), so the stock
// `admin user create` CLI mints the wizard admin directly. Retries
// boundedly (migration can lag the settle on slow disks); an existing
// admin is success (idempotent reinstalls). On failure the install
// lock is dropped so the browser installer (not a locked-out login)
// greets the owner.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$in = json_decode($argv[1] ?? 'null', true);
if (!is_array($in)) {
    fwrite(STDERR, "gitea setup: bad arguments\n");
    exit(1);
}
foreach (['user', 'pass', 'email'] as $k) {
    if (!isset($in[$k]) || !is_string($in[$k]) || $in[$k] === '' || strlen($in[$k]) > 254) {
        fwrite(STDERR, "gitea setup: missing {$k}\n");
        exit(1);
    }
    if (str_contains($in[$k], "\n") || str_contains($in[$k], "\0")) {
        fwrite(STDERR, "gitea setup: bad {$k}\n");
        exit(1);
    }
}
chdir(__DIR__);
$base = ['./gitea', 'admin', 'user', 'create', '--admin', '--username', $in['user'], '--password', $in['pass'], '--email', $in['email']];
$last = '';
for ($try = 0; $try < 6; $try++) {
    $des = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
    $p = @proc_open($base, $des, $pipes);
    if (is_resource($p)) {
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);
        $text = (string) $out . "\n" . (string) $err;
        if ($code === 0) {
            echo "gitea setup complete\n";
            exit(0);
        }
        if (stripos($text, 'already exists') !== false) {
            echo "gitea admin already exists\n";
            exit(0);
        }
        $last = trim($text) !== '' ? trim($text) : ('exit ' . $code);
    }
    sleep(10);
}
$ini = __DIR__ . '/custom/conf/app.ini';
$c = @file_get_contents($ini);
if ($c !== false) {
    @file_put_contents($ini, str_replace('INSTALL_LOCK = true', 'INSTALL_LOCK = false', $c));
}
fwrite(STDERR, 'gitea setup failed: ' . mb_substr($last, -300) . "\n");
exit(1);
PANELSETUP
                        ],
                    ],
                    'setup' => [
                        'cli' => [[
                            'script' => 'panel-setup.php',
                            'args' => ['{"user":"{user}","pass":"{pass}","email":"{email}"}'],
                        ]],
                        'cleanup' => ['panel-setup.php'],
                    ],
                    'admin' => ['path' => ''],
                    'post_install' => 'Installed automatically with the admin account you entered — sign in on the site.',
                    'update_check' => ['type' => 'github', 'repo' => 'go-gitea/gitea'],
                ],
            ],
            [
                'slug' => 'forgejo', 'name' => 'Forgejo', 'category' => 'Developer Tools',
                'version' => '16.0.4', 'featured' => 0, 'sort' => 85,
                'description' => 'Community-driven Git forge, soft fork of Gitea.',
                'website' => 'https://forgejo.org', 'license' => 'MIT', 'vendor' => 'Forgejo',
                'manifest' => [
                    'type' => 'binary',
                    'download' => ['codeberg' => ['repo' => 'forgejo/forgejo', 'asset' => 'forgejo-{version}-linux-amd64', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'file'],
                    'app' => [
                        'binary' => 'forgejo',
                        'args' => ['web', '--port', '{PORT}'],
                        'seed_files' => [
                            'custom/conf/app.ini' => "APP_NAME = Forgejo: Beyond coding. We forge.\nRUN_MODE = prod\n[server]\nPROTOCOL = http\nDOMAIN = {domain}\nHTTP_ADDR = {HOST}\nHTTP_PORT = {PORT}\nROOT_URL = {url}/\nDISABLE_SSH = true\n[database]\nDB_TYPE = sqlite3\nPATH = data/forgejo.db\n[security]\nINSTALL_LOCK = true\n[service]\nDISABLE_REGISTRATION = true\n",
                            'panel-setup.php' => <<<'PANELSETUP'
<?php
// Forgejo unattended admin: same shape as the Gitea driver (soft fork,
// identical CLI). The unit already booted + migrated the database.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$in = json_decode($argv[1] ?? 'null', true);
if (($__e = getenv('CWH_SETUP_PASS')) !== false && $__e !== '') { $in['pass'] = $__e; }
if (($__e = getenv('CWH_SETUP_DB_PASS')) !== false && $__e !== '') { $in['db_pass'] = $__e; }
if (!is_array($in)) {
    fwrite(STDERR, "forgejo setup: bad arguments\n");
    exit(1);
}
foreach (['user', 'pass', 'email'] as $k) {
    if (!isset($in[$k]) || !is_string($in[$k]) || $in[$k] === '' || strlen($in[$k]) > 254) {
        fwrite(STDERR, "forgejo setup: missing {$k}\n");
        exit(1);
    }
    if (str_contains($in[$k], "\n") || str_contains($in[$k], "\0")) {
        fwrite(STDERR, "forgejo setup: bad {$k}\n");
        exit(1);
    }
}
chdir(__DIR__);
$base = ['./forgejo', 'admin', 'user', 'create', '--admin', '--username', $in['user'], '--password', $in['pass'], '--email', $in['email']];
$last = '';
for ($try = 0; $try < 6; $try++) {
    $des = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
    $p = @proc_open($base, $des, $pipes);
    if (is_resource($p)) {
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);
        $text = (string) $out . "\n" . (string) $err;
        if ($code === 0) {
            echo "forgejo setup complete\n";
            exit(0);
        }
        if (stripos($text, 'already exists') !== false) {
            echo "forgejo admin already exists\n";
            exit(0);
        }
        $last = trim($text) !== '' ? trim($text) : ('exit ' . $code);
    }
    sleep(10);
}
$ini = __DIR__ . '/custom/conf/app.ini';
$c = @file_get_contents($ini);
if ($c !== false) {
    @file_put_contents($ini, str_replace('INSTALL_LOCK = true', 'INSTALL_LOCK = false', $c));
}
fwrite(STDERR, 'forgejo setup failed: ' . mb_substr($last, -300) . "\n");
exit(1);
PANELSETUP
                        ],
                    ],
                    'setup' => [
                        'cli' => [[
                            'script' => 'panel-setup.php',
                            'args' => ['{"user":"{user}","pass":"{pass}","email":"{email}"}'],
                        ]],
                        'cleanup' => ['panel-setup.php'],
                    ],
                    'admin' => ['path' => ''],
                    'post_install' => 'Installed automatically with the admin account you entered — sign in on the site.',
                    'update_check' => ['type' => 'codeberg', 'repo' => 'forgejo/forgejo'],
                ],
            ],
            [
                'slug' => 'caddy-fileserver', 'name' => 'Caddy File Server', 'category' => 'File Sharing',
                'version' => '2.11.4', 'featured' => 0, 'sort' => 86,
                'description' => 'Fast static file server with directory browsing, powered by Caddy.',
                'website' => 'https://caddyserver.com', 'license' => 'Apache-2.0', 'vendor' => 'Caddy',
                'manifest' => [
                    'type' => 'binary',
                    'download' => ['github' => ['repo' => 'caddyserver/caddy', 'asset' => 'caddy_{version}_linux_amd64.tar.gz', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'tar', 'strip_root' => false],
                    'app' => [
                        'binary' => 'caddy',
                        'args' => ['file-server', '--listen', '{HOST}:{PORT}', '--root', 'www'],
                        'seed_files' => [
                            'www/index.html' => "<!doctype html><html><head><meta charset=\"utf-8\"><title>Files</title></head><body><h1>Shared files</h1><p>Upload into <code>www/</code> to share.</p></body></html>\n",
                        ],
                    ],
                    'admin' => ['path' => ''],
                    'post_install' => 'Serves the www/ folder with browsing — upload files there to share them.',
                    'update_check' => ['type' => 'github', 'repo' => 'caddyserver/caddy'],
                ],
            ],
            [
                'slug' => 'dufs', 'name' => 'Dufs', 'category' => 'File Sharing',
                'version' => '0.46.0', 'featured' => 0, 'sort' => 87,
                'description' => 'Distinctive file server — browse, upload, search and WebDAV.',
                'website' => 'https://github.com/sigoden/dufs', 'license' => 'MIT', 'vendor' => 'Dufs',
                'manifest' => [
                    'type' => 'binary',
                    'download' => ['github' => ['repo' => 'sigoden/dufs', 'asset' => 'dufs-{tag}-x86_64-unknown-linux-musl.tar.gz', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'tar', 'strip_root' => false],
                    'app' => [
                        'binary' => 'dufs',
                        'args' => ['-b', '{HOST}', '-p', '{PORT}', '--render-index', '--allow-upload', 'www'],
                        'seed_files' => [
                            'www/index.html' => "<!doctype html><html><head><meta charset=\"utf-8\"><title>Files</title></head><body><h1>Shared files</h1></body></html>\n",
                        ],
                    ],
                    'admin' => ['path' => ''],
                    'post_install' => 'Serves www/ with uploads enabled — drop files in via the browser or the File Manager.',
                    'update_check' => ['type' => 'github', 'repo' => 'sigoden/dufs'],
                ],
            ],
            [
                'slug' => 'miniserve', 'name' => 'Miniserve', 'category' => 'File Sharing',
                'version' => '0.35.0', 'featured' => 0, 'sort' => 88,
                'description' => 'Tiny self-contained file server with upload and archive download.',
                'website' => 'https://github.com/svenstaro/miniserve', 'license' => 'MIT', 'vendor' => 'Miniserve',
                'manifest' => [
                    'type' => 'binary',
                    'download' => ['github' => ['repo' => 'svenstaro/miniserve', 'asset' => 'miniserve-{version}-x86_64-unknown-linux-musl', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'file'],
                    'app' => [
                        'binary' => 'miniserve',
                        'args' => ['-p', '{PORT}', '-i', '{HOST}', '--index', 'index.html', 'www'],
                        'seed_files' => [
                            'www/index.html' => "<!doctype html><html><head><meta charset=\"utf-8\"><title>Files</title></head><body><h1>Shared files</h1></body></html>\n",
                        ],
                    ],
                    'admin' => ['path' => ''],
                    'post_install' => 'Serves the www/ folder — upload files there to share them.',
                    'update_check' => ['type' => 'github', 'repo' => 'svenstaro/miniserve'],
                ],
            ],
            [
                'slug' => 'navidrome', 'name' => 'Navidrome', 'category' => 'Media',
                'version' => '0.64.0', 'featured' => 0, 'sort' => 90,
                'description' => 'Personal music streamer — Subsonic-compatible, works with phone apps.',
                'website' => 'https://www.navidrome.org', 'license' => 'GPL-3.0', 'vendor' => 'Navidrome',
                'manifest' => [
                    'type' => 'binary',
                    'download' => ['github' => ['repo' => 'navidrome/navidrome', 'asset' => 'navidrome_{version}_linux_amd64.tar.gz', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'tar', 'strip_root' => false],
                    'app' => [
                        'binary' => 'navidrome',
                        'args' => ['-a', '{HOST}', '-p', '{PORT}'],
                        'seed_files' => [
                            'music/README.txt' => "Upload your music here (mp3/flac/ogg), then run a scan from Settings.\n",
                            // Unattended admin: Navidrome's `user create` CLI
                            // prompts on a TTY (no --password flag), but the
                            // HTTP API creates the first admin while the user
                            // table is empty.
                            'panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_slice($argv ?? [], 1);
if (($__e = getenv('CWH_SETUP_PASS')) !== false && $__e !== '') { $args[2] = $__e; }
if (count($args) < 3) {
    fwrite(STDERR, "navidrome setup: bad arguments\n");
    exit(1);
}
[$port, $user, $pass] = $args;
foreach (['user' => $user, 'pass' => $pass] as $k => $v) {
    if ($v === '' || str_contains($v, "\n") || str_contains($v, "\0")) {
        fwrite(STDERR, "navidrome setup: bad {$k}\n");
        exit(1);
    }
}
$url = 'http://{HOST}:' . $port . '/auth/createAdmin';
$body = json_encode(['username' => $user, 'password' => $pass]);
$ctx = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/json\r\n",
    'content' => $body,
    'timeout' => 15,
    'ignore_errors' => true,
]]);
$last = '';
for ($try = 0; $try < 6; $try++) {
    unset($http_response_header);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $code = (int) $m[1];
    }
    $text = (string) $resp;
    if ($code === 200 && stripos($text, '"token"') !== false) {
        echo "navidrome setup complete\n";
        exit(0);
    }
    if ($code === 403 || stripos($text, 'Cannot create another first admin') !== false) {
        echo "navidrome admin already exists\n";
        exit(0);
    }
    $last = 'HTTP ' . $code . ' ' . mb_substr($text, 0, 200);
    sleep(10);
}
fwrite(STDERR, 'navidrome setup failed: ' . $last . "\n");
exit(1);
PANELSETUP
                        ],
                    ],
                    'setup' => [
                        'cli' => [[
                            'script' => 'panel-setup.php',
                            'args' => ['{PORT}', '{user}', '{pass}'],
                        ]],
                        'cleanup' => ['panel-setup.php'],
                    ],
                    'admin' => ['path' => ''],
                    'post_install' => 'Installed automatically with the admin account you entered — upload music into music/, then scan from Settings.',
                    'update_check' => ['type' => 'github', 'repo' => 'navidrome/navidrome'],
                ],
            ],
            [
                'slug' => 'gokapi', 'name' => 'Gokapi', 'category' => 'File Sharing',
                'version' => '2.2.4', 'featured' => 0, 'sort' => 91,
                'description' => 'Share files with expiring links — lightweight WeTransfer alternative.',
                'website' => 'https://gokapi.de', 'license' => 'MIT', 'vendor' => 'Gokapi',
                'manifest' => [
                    'type' => 'binary',
                    'download' => ['github' => ['repo' => 'Forceu/Gokapi', 'asset' => 'gokapi-{version}_linux-amd64.zip', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'zip', 'strip_root' => false],
                    'app' => [
                        'binary' => 'gokapi',
                        'args' => [],
                        'env' => ['GOKAPI_PORT' => '{PORT}', 'GOKAPI_DATA_DIR' => 'data'],
                        'seed_files' => [
                            // Unattended setup: with no config.json gokapi boots
                            // its /setup wizard on the port; the wizard's POST
                            // (/setup/setupResult — a JSON array of {name,value})
                            // writes config.json + the DB + the admin, then the
                            // process continues into the normal server.
                            'panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_slice($argv ?? [], 1);
if (($__e = getenv('CWH_SETUP_PASS')) !== false && $__e !== '') { $args[2] = $__e; }
if (count($args) < 3) {
    fwrite(STDERR, "gokapi setup: bad arguments\n");
    exit(1);
}
$port = (string) $args[0];
$user = (string) $args[1];
$pass = (string) $args[2];
$title = isset($args[3]) && $args[3] !== '' ? (string) $args[3] : 'Gokapi';
if ($user === '' || $pass === '') {
    fwrite(STDERR, "gokapi setup: missing credentials\n");
    exit(1);
}
$fields = [
    'dbtype_sel' => '0',
    'sqlite_location' => 'data/gokapi.sqlite',
    'auth_username' => $user,
    'auth_pw' => $pass,
    'port' => $port,
    'public_name' => $title,
    'url' => '',
    'url_redirection' => '',
    'localhost_sel' => '1',
    'ssl_sel' => '0',
    'logip_sel' => '0',
    'redis_location' => '',
    'redis_prefix' => '0',
    'redis_user' => '',
    'redis_password' => '',
    'redis_ssl_sel' => '0',
    'oauth_provider' => '0',
    'oauth_id' => '0',
    'oauth_secret' => '0',
    'oauth_recheck_interval' => '0',
    'oauth_admin_user' => '',
    'oauth_only_registered_users' => '0',
    'oauth_restrict_groups' => '0',
    'oauth_allowed_groups' => '0',
    'oauth_scope_groups' => '0',
    'auth_header_admin' => '0',
    'auth_headerkey' => '0',
    'auth_header_only_registered_users' => '0',
    'showfilename_sel' => '0',
    'authentication_sel' => '0',
    'storage_sel_image' => '0',
    'encrypt_sel' => '0',
    'enc_pw' => '',
    'storage_sel' => '0',
];
$list = [];
foreach ($fields as $k => $v) {
    $list[] = ['name' => $k, 'value' => $v];
}
$url = 'http://{HOST}:' . $port . '/setup/setupResult';
$ctx = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/json\r\n",
    'content' => json_encode($list),
    'timeout' => 30,
    'ignore_errors' => true,
]]);
$last = '';
for ($try = 0; $try < 6; $try++) {
    unset($http_response_header);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $code = (int) $m[1];
    }
    $text = (string) $resp;
    if (stripos($text, '"OK"') !== false) {
        echo "gokapi setup complete\n";
        exit(0);
    }
    $last = 'HTTP ' . $code . ' ' . mb_substr($text, 0, 200);
    sleep(10);
}
fwrite(STDERR, 'gokapi setup failed: ' . $last . "\n");
exit(1);
PANELSETUP
                        ],
                    ],
                    'setup' => [
                        'cli' => [[
                            'script' => 'panel-setup.php',
                            'args' => ['{PORT}', '{user}', '{pass}', '{title}'],
                        ]],
                        'cleanup' => ['panel-setup.php'],
                    ],
                    'admin' => ['path' => '/admin'],
                    'post_install' => 'Installed automatically with the admin account you entered — log in at /admin.',
                    'update_check' => ['type' => 'github', 'repo' => 'Forceu/Gokapi'],
                ],
            ],
            [
                'slug' => 'mdbook', 'name' => 'mdBook', 'category' => 'Documentation',
                'version' => '0.5.4', 'featured' => 0, 'sort' => 93,
                'description' => 'Create books from Markdown — the Rust book toolchain.',
                'website' => 'https://rust-lang.github.io/mdBook/', 'license' => 'MPL-2.0', 'vendor' => 'mdBook',
                'manifest' => [
                    'type' => 'binary',
                    'download' => ['github' => ['repo' => 'rust-lang/mdBook', 'asset' => 'mdbook-{tag}-x86_64-unknown-linux-gnu.tar.gz', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'tar', 'strip_root' => false],
                    'app' => [
                        'binary' => 'mdbook',
                        'args' => ['serve', '--hostname', '{HOST}', '--port', '{PORT}'],
                        'seed_files' => [
                            'book.toml' => "[book]\ntitle = \"My Book\"\n",
                            'src/SUMMARY.md' => "# Summary\n\n- [Chapter 1](chapter_1.md)\n",
                            'src/chapter_1.md' => "# Chapter 1\n\nWrite your book in Markdown under `src/`.\n",
                        ],
                    ],
                    'admin' => ['path' => ''],
                    'post_install' => 'A starter book is live — edit src/*.md in the File Manager; pages rebuild on refresh.',
                    'update_check' => ['type' => 'github', 'repo' => 'rust-lang/mdBook'],
                ],
            ],
            [
                'slug' => 'hugo', 'name' => 'Hugo', 'category' => 'Documentation',
                'version' => '0.166.0', 'featured' => 0, 'sort' => 94,
                'description' => 'Blazing-fast static site generator with live preview server.',
                'website' => 'https://gohugo.io', 'license' => 'Apache-2.0', 'vendor' => 'Hugo',
                'manifest' => [
                    'type' => 'binary',
                    'download' => ['github' => ['repo' => 'gohugoio/hugo', 'asset' => 'hugo_{version}_Linux-64bit.tar.gz', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'tar', 'strip_root' => false],
                    'app' => [
                        'binary' => 'hugo',
                        'args' => ['server', '--bind', '{HOST}', '--port', '{PORT}'],
                        'seed_files' => [
                            'hugo.toml' => "baseURL = '/'\ntitle = 'My Site'\n",
                            'content/_index.md' => "---\ntitle: Home\n---\n\n# Hello Hugo\n\nEdit `content/` and `layouts/` to build your site.\n",
                            'layouts/index.html' => "<!doctype html>\n<html><head><meta charset=\"utf-8\"><title>{{ .Site.Title }}</title></head><body>{{ .Content }}</body></html>\n",
                        ],
                    ],
                    'admin' => ['path' => ''],
                    'post_install' => 'A starter site is live — edit content/ and layouts/, then `hugo` build output can be served statically.',
                    'update_check' => ['type' => 'github', 'repo' => 'gohugoio/hugo'],
                ],
            ],
            [
                'slug' => 'statping-ng', 'name' => 'Statping-ng', 'category' => 'Monitoring',
                'version' => '0.93.0', 'featured' => 0, 'sort' => 96,
                'description' => 'Status page and monitor for websites, APIs and services.',
                'website' => 'https://github.com/statping-ng/statping-ng', 'license' => 'GPL-3.0', 'vendor' => 'Statping-ng',
                'manifest' => [
                    'type' => 'binary',
                    'download' => ['github' => ['repo' => 'statping-ng/statping-ng', 'asset' => 'statping-linux-amd64.tar.gz', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'tar', 'strip_root' => false],
                    'app' => [
                        'binary' => 'statping',
                        'args' => ['-s', '{HOST}', '-p', '{PORT}'],
                        'seed_files' => [
                            // Unattended setup: statping boots into setup mode
                            // (303 → /setup) until /api/setup is posted once;
                            // that writes config.yml + the SQLite DB and mints
                            // the admin from the posted username/password.
                            'panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_slice($argv ?? [], 1);
if (($__e = getenv('CWH_SETUP_PASS')) !== false && $__e !== '') { $args[2] = $__e; }
if (count($args) < 3) {
    fwrite(STDERR, "statping setup: bad arguments\n");
    exit(1);
}
$port = (string) $args[0];
$user = (string) $args[1];
$pass = (string) $args[2];
$email = isset($args[3]) ? (string) $args[3] : '';
if ($user === '' || $pass === '') {
    fwrite(STDERR, "statping setup: missing credentials\n");
    exit(1);
}
foreach (['user' => $user, 'pass' => $pass, 'email' => $email] as $k => $v) {
    if (str_contains($v, "\n") || str_contains($v, "\0")) {
        fwrite(STDERR, "statping setup: bad {$k}\n");
        exit(1);
    }
}
$url = 'http://{HOST}:' . $port . '/api/setup';
$form = http_build_query([
    'db_connection' => 'sqlite',
    'db_database' => 'statping.db',
    'project' => 'Status',
    'description' => '',
    'domain' => '',
    'language' => 'en',
    'newsletter' => 'false',
    'send_reports' => 'false',
    'username' => $user,
    'password' => $pass,
    'confirm_password' => $pass,
    'email' => $email,
]);
$ctx = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
    'content' => $form,
    'timeout' => 30,
    'ignore_errors' => true,
]]);
$last = '';
for ($try = 0; $try < 6; $try++) {
    unset($http_response_header);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $code = (int) $m[1];
    }
    $text = (string) $resp;
    if ($code === 200 && stripos($text, '"success"') !== false) {
        echo "statping setup complete\n";
        exit(0);
    }
    if (stripos($text, 'already been setup') !== false) {
        echo "statping already setup\n";
        exit(0);
    }
    $last = 'HTTP ' . $code . ' ' . mb_substr($text, 0, 200);
    sleep(10);
}
fwrite(STDERR, 'statping setup failed: ' . $last . "\n");
exit(1);
PANELSETUP
                        ],
                    ],
                    'setup' => [
                        'cli' => [[
                            'script' => 'panel-setup.php',
                            'args' => ['{PORT}', '{user}', '{pass}', '{email}'],
                        ]],
                        'cleanup' => ['panel-setup.php'],
                    ],
                    'admin' => ['path' => ''],
                    'post_install' => 'Installed automatically with the admin account you entered — then add your first service to monitor.',
                    'update_check' => ['type' => 'github', 'repo' => 'statping-ng/statping-ng'],
                ],
            ],
            [
                'slug' => 'victoriametrics', 'name' => 'VictoriaMetrics', 'category' => 'Monitoring',
                'version' => '1.152.0', 'featured' => 0, 'sort' => 97,
                'description' => 'Fast time-series database and monitoring backend (Prometheus-compatible).',
                'website' => 'https://victoriametrics.com', 'license' => 'Apache-2.0', 'vendor' => 'VictoriaMetrics',
                'manifest' => [
                    'type' => 'binary',
                    'download' => ['github' => ['repo' => 'VictoriaMetrics/VictoriaMetrics', 'asset' => 'victoria-metrics-linux-amd64-{tag}.tar.gz', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'tar', 'strip_root' => false],
                    'app' => ['binary' => 'victoria-metrics-prod', 'args' => ['-storageDataPath=data', '-httpListenAddr={HOST}:{PORT}']],
                    'admin' => ['path' => '/vmui'],
                    'post_install' => 'Push Prometheus metrics at /api/v1/import/prometheus and explore them in the built-in vmui.',
                    'update_check' => ['type' => 'github', 'repo' => 'VictoriaMetrics/VictoriaMetrics'],
                ],
            ],
            [
                'slug' => 'yarr', 'name' => 'Yarr', 'category' => 'Media',
                'version' => '2.9', 'featured' => 0, 'sort' => 98,
                'description' => 'Minimal RSS reader — follow feeds without algorithms.',
                'website' => 'https://github.com/nkanaev/yarr', 'license' => 'MIT', 'vendor' => 'Yarr',
                'manifest' => [
                    'type' => 'binary',
                    'download' => ['github' => ['repo' => 'nkanaev/yarr', 'asset' => 'yarr_linux_amd64.zip', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'zip', 'strip_root' => false],
                    'app' => ['binary' => 'yarr', 'args' => ['-addr', '{HOST}:{PORT}']],
                    'admin' => ['path' => ''],
                    'post_install' => 'No login needed by default — add feeds and read. Set -auth via a custom app if exposed publicly.',
                    'update_check' => ['type' => 'github', 'repo' => 'nkanaev/yarr'],
                ],
            ],
            [
                'slug' => 'opengist', 'name' => 'Opengist', 'category' => 'File Sharing',
                'version' => '1.15.2', 'featured' => 0, 'sort' => 99,
                'description' => 'Self-hosted pastebin powered by Git — snippets with history and sharing.',
                'website' => 'https://opengist.live', 'license' => 'AGPL-3.0', 'vendor' => 'Opengist',
                'manifest' => [
                    'type' => 'binary',
                    'download' => ['github' => ['repo' => 'thomiceli/opengist', 'asset' => 'opengist{version}-linux-amd64.tar.gz', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'tar', 'strip_root' => true],
                    'app' => [
                        'binary' => 'opengist',
                        // --config is a GLOBAL option: it must precede the
                        // start command (the binary ignores ./config.yml and
                        // binds its default port otherwise).
                        'args' => ['--config', 'config.yml', 'start'],
                        'seed_files' => [
                            'config.yml' => "log-level: warn\nlog-output: stdout\nopengist-home: data\nhttp.host: {HOST}\nhttp.port: {PORT}\nexternal-url: \"\"\n",
                            // Unattended admin: opengist ships `admin
                            // create-user` ("useful to seed an admin account
                            // at install time"). Runs post-start (setup.cli on
                            // app installs) so the SQLite DB exists; the
                            // password rides stdin (never argv/ps).
                            'panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_slice($argv ?? [], 1);
if (($__e = getenv('CWH_SETUP_PASS')) !== false && $__e !== '') { $args[2] = $__e; }
if (count($args) < 4) {
    fwrite(STDERR, "opengist setup: bad arguments\n");
    exit(1);
}
[$port, $user, $pass, $email] = $args;
foreach (['user' => $user, 'pass' => $pass, 'email' => $email] as $k => $v) {
    if ($v === '' || str_contains($v, "\n") || str_contains($v, "\0")) {
        fwrite(STDERR, "opengist setup: bad {$k}\n");
        exit(1);
    }
}
chdir(__DIR__);
$last = '';
for ($try = 0; $try < 6; $try++) {
    $des = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
    $p = @proc_open(['./opengist', '--config', 'config.yml', 'admin', 'create-user', '-u', $user, '--email', $email, '--admin', '--password-stdin'], $des, $pipes);
    if (is_resource($p)) {
        fwrite($pipes[0], $pass);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);
        $text = (string) $out . "\n" . (string) $err;
        if ($code === 0) {
            echo "opengist setup complete\n";
            exit(0);
        }
        if (stripos($text, 'already exist') !== false) {
            echo "opengist admin already exists\n";
            exit(0);
        }
        $last = trim($text) !== '' ? trim($text) : ('exit ' . $code);
    }
    sleep(10);
}
fwrite(STDERR, 'opengist setup failed: ' . mb_substr($last, -300) . "\n");
exit(1);
PANELSETUP
                        ],
                    ],
                    'setup' => [
                        'cli' => [[
                            'script' => 'panel-setup.php',
                            'args' => ['{PORT}', '{user}', '{pass}', '{email}'],
                        ]],
                        'cleanup' => ['panel-setup.php'],
                    ],
                    'admin' => ['path' => ''],
                    'post_install' => 'Installed automatically with the admin account you entered — log in on the site. Needs the git binary on the host.',
                    'update_check' => ['type' => 'github', 'repo' => 'thomiceli/opengist'],
                ],
            ],

        [
            'slug' => 'freshrss',
            'name' => 'FreshRSS',
            'category' => 'Media',
            'version' => '1.30.1',
            'featured' => 0,
            'sort' => 100,
            'description' => 'Self-hosted RSS aggregator with full-text, mobile UI, OPML import/export.',
            'website' => 'https://freshrss.org',
            'license' => 'AGPL-3.0',
            'vendor' => 'FreshRSS',
            'manifest' => [
                'type' => 'php-mysql',
                'requirements' => [
                    'php_min' => '8.1',
                    'extensions' => ['pdo_mysql','curl','dom','fileinfo','intl','mbstring','openssl','simplexml','zip','gmp'],
                ],
                'database' => [
                    'required' => true,
                    'prefix' => '',
                ],
                'download' => [
                    'url' => 'https://github.com/FreshRSS/FreshRSS/archive/refs/tags/{version}.tar.gz',
                    'sha256' => '',
                    'format' => 'tar',
                    'strip_root' => true,
                ],
                'config' => [],
                'setup' => [
                    'files' => [
                        'panel-setup.php' => <<<'PANELSETUP'
<?php

/**
 * FreshRSS unattended installer driver (runs as the account user via the
 * agent). Discrete argv (no shell, no JSON quoting — the panel interpolates
 * each placeholder into its own argument):
 *   1 db_host  2 db_name  3 db_user  4 db_pass  5 user  6 pass  7 base_url
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$args = array_slice($argv ?? [], 1);
if (count($args) < 6) {
    fwrite(STDERR, "freshrss setup: bad arguments\n");
    exit(1);
}
$in = [
    'db_host' => (string) $args[0],
    'db_name' => (string) $args[1],
    'db_user' => (string) $args[2],
    'db_pass' => (string) $args[3],
    'user' => (string) $args[4],
    'pass' => (string) $args[5],
    'url' => isset($args[6]) ? (string) $args[6] : '',
];
if (($__e = getenv('CWH_SETUP_PASS')) !== false && $__e !== '') { $in['pass'] = $__e; }
if (($__e = getenv('CWH_SETUP_DB_PASS')) !== false && $__e !== '') { $in['db_pass'] = $__e; }

foreach (['db_host', 'db_name', 'db_user', 'db_pass', 'user', 'pass'] as $k) {
    if ($in[$k] === '') {
        fwrite(STDERR, "freshrss setup: missing {$k}\n");
        exit(1);
    }
    if (str_contains($in[$k], "\n") || str_contains($in[$k], "\0")) {
        fwrite(STDERR, "freshrss setup: invalid {$k}\n");
        exit(1);
    }
}

if (function_exists('posix_getuid') && posix_getuid() === 0) {
    fwrite(STDERR, "freshrss setup: refusing to run as root\n");
    exit(1);
}

$marker = __DIR__ . '/data/applied_migrations.txt';
if (is_file($marker)) {
    echo "freshrss setup: already installed, skipping\n";
    exit(0);
}

$baseUrl = 'http://localhost';
if (isset($in['url']) && is_string($in['url']) && $in['url'] !== '') {
    $baseUrl = $in['url'];
}

$rc1 = 1;
passthru(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/cli/do-install.php')
    . ' --db-type mysql'
    . ' --db-host ' . escapeshellarg($in['db_host'])
    . ' --db-user ' . escapeshellarg($in['db_user'])
    . ' --db-password ' . escapeshellarg($in['db_pass'])
    . ' --db-base ' . escapeshellarg($in['db_name'])
    . ' --base-url ' . escapeshellarg($baseUrl)
    . ' --default-user ' . escapeshellarg($in['user'])
    . ' --auth-type form'
    . ' --environment production',
    $rc1
);
if ($rc1 !== 0 && !is_file($marker)) {
    fwrite(STDERR, "freshrss setup: installer failed (exit {$rc1})\n");
    exit(1);
}

$rc2 = 1;
passthru(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/cli/create-user.php')
    . ' --user ' . escapeshellarg($in['user'])
    . ' --password ' . escapeshellarg($in['pass']),
    $rc2
);
if ($rc2 !== 0) {
    fwrite(STDERR, "freshrss setup: create-user failed (exit {$rc2})\n");
    exit(1);
}

echo "freshrss setup complete\n";
exit(0);
PANELSETUP
                    ],
                    'cli' => [[
                        'script' => 'panel-setup.php',
                        'args' => ['{db_host}', '{db_name}', '{db_user}', '{db_pass}', '{user}', '{pass}', '{url}'],
                    ]],
                    'cleanup' => ['panel-setup.php'],
                ],
                'webroot' => 'p',
                'admin' => ['path' => ''],
                'update_check' => ['type' => 'github', 'repo' => 'FreshRSS/FreshRSS'],
            ],
        ],
        ];
    }
}

#!/usr/bin/env php
<?php
/**
 * M-7: database least privilege via SQL SECURITY DEFINER procedures.
 *
 * MariaDB requires a grantor to *hold* the privileges it grants, so a panel
 * that hands out `GRANT ALL ON <tenantdb>.*` must itself hold global data
 * privileges — i.e. it can read every tenant database. To break that, all
 * database management (create/drop db, create/drop user, grant, password,
 * per-user limits) runs in `cwhpanel_admin` procedures that execute as
 * DEFINER root. The panel account then only needs EXECUTE on that schema + its
 * own DB + a small set of global administrative privileges — no data access to
 * tenant schemas.
 *
 * Run as root:  php scripts/install-db-admin-procedures.php
 * Idempotent (CREATE OR REPLACE + scoped GRANT/REVOKE).
 */

require __DIR__ . '/_bootstrap.php';

use App\Foundation\Application;

$app = new Application(BASE_PATH);
$app->bootstrap();

$cfg = config('database');
// Prefer the root unix socket (the panel account may already be scoped on a
// re-run, and root is needed to CREATE PROCEDURE ... DEFINER root). Fall back
// to the configured panel credentials.
$pdo = null;
$socket = '/var/lib/mysql/mysql.sock';
foreach ([[$socket, 'root', ''], null] as $try) {
    try {
        if ($try !== null) {
            $pdo = new PDO("mysql:unix_socket={$try[0]};charset=utf8mb4", $try[1], $try[2], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } else {
            $pdo = new PDO("mysql:host={$cfg['host']};port={$cfg['port']};charset={$cfg['charset']}", (string) $cfg['username'], (string) $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }
        break;
    } catch (\Throwable $e) {
        $pdo = null;
    }
}
if ($pdo === null) {
    fwrite(STDERR, "cannot connect to MariaDB (root socket or panel credentials)\n");
    exit(1);
}

$A = 'cwhpanel_admin';

$procs = [];

$procs['cwh_db_create'] = "CREATE OR REPLACE DEFINER='root'@'localhost' PROCEDURE $A.cwh_db_create(IN p_name VARCHAR(64))
SQL SECURITY DEFINER
BEGIN
  IF p_name IS NULL OR p_name NOT REGEXP '^[a-z0-9_]{1,64}\$' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid database name';
  END IF;
  SET @s = CONCAT('CREATE DATABASE IF NOT EXISTS `', p_name, '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
  PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
END";

$procs['cwh_db_drop'] = "CREATE OR REPLACE DEFINER='root'@'localhost' PROCEDURE $A.cwh_db_drop(IN p_name VARCHAR(64))
SQL SECURITY DEFINER
BEGIN
  IF p_name IS NULL OR p_name NOT REGEXP '^[a-z0-9_]{1,64}\$' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid database name';
  END IF;
  SET @s = CONCAT('DROP DATABASE IF EXISTS `', p_name, '`');
  PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
END";

$procs['cwh_user_create'] = "CREATE OR REPLACE DEFINER='root'@'localhost' PROCEDURE $A.cwh_user_create(IN p_user VARCHAR(64), IN p_host VARCHAR(255), IN p_password VARCHAR(255))
SQL SECURITY DEFINER
BEGIN
  IF p_user IS NULL OR p_user NOT REGEXP '^[a-z0-9_]{1,64}\$'
     OR p_host IS NULL OR p_host NOT REGEXP '^[a-z0-9.%_-]{1,255}\$' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid user or host';
  END IF;
  SET @s = CONCAT('CREATE USER IF NOT EXISTS `', p_user, '`@`', p_host, '` IDENTIFIED BY ', QUOTE(p_password));
  PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
END";

$procs['cwh_user_drop'] = "CREATE OR REPLACE DEFINER='root'@'localhost' PROCEDURE $A.cwh_user_drop(IN p_user VARCHAR(64), IN p_host VARCHAR(255))
SQL SECURITY DEFINER
BEGIN
  IF p_user IS NULL OR p_user NOT REGEXP '^[a-z0-9_]{1,64}\$'
     OR p_host IS NULL OR p_host NOT REGEXP '^[a-z0-9.%_-]{1,255}\$' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid user or host';
  END IF;
  SET @s = CONCAT('DROP USER IF EXISTS `', p_user, '`@`', p_host, '`');
  PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
END";

$procs['cwh_user_grant'] = "CREATE OR REPLACE DEFINER='root'@'localhost' PROCEDURE $A.cwh_user_grant(IN p_user VARCHAR(64), IN p_host VARCHAR(255), IN p_db VARCHAR(64))
SQL SECURITY DEFINER
BEGIN
  IF p_user IS NULL OR p_user NOT REGEXP '^[a-z0-9_]{1,64}\$'
     OR p_host IS NULL OR p_host NOT REGEXP '^[a-z0-9.%_-]{1,255}\$'
     OR p_db IS NULL OR p_db NOT REGEXP '^[a-z0-9_]{1,64}\$' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid grant';
  END IF;
  SET @s = CONCAT('GRANT ALL PRIVILEGES ON `', p_db, '`.* TO `', p_user, '`@`', p_host, '`');
  PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
END";

$procs['cwh_user_write_access'] = "CREATE OR REPLACE DEFINER='root'@'localhost' PROCEDURE $A.cwh_user_write_access(IN p_user VARCHAR(64), IN p_host VARCHAR(255), IN p_db VARCHAR(64), IN p_allow TINYINT)
SQL SECURITY DEFINER
BEGIN
  IF p_user IS NULL OR p_user NOT REGEXP '^[a-z0-9_]{1,64}\$'
     OR p_host IS NULL OR p_host NOT REGEXP '^[a-z0-9.%_-]{1,255}\$'
     OR p_db IS NULL OR p_db NOT REGEXP '^[a-z0-9_]{1,64}\$' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid write-access request';
  END IF;
  IF p_allow = 1 THEN
    SET @s = CONCAT('GRANT INSERT,UPDATE,DELETE,CREATE,DROP,ALTER,INDEX ON `', p_db, '`.* TO `', p_user, '`@`', p_host, '`');
  ELSE
    SET @s = CONCAT('REVOKE INSERT,UPDATE,DELETE,CREATE,DROP,ALTER,INDEX ON `', p_db, '`.* FROM `', p_user, '`@`', p_host, '`');
  END IF;
  PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  FLUSH PRIVILEGES;
END";

$procs['cwh_user_password'] = "CREATE OR REPLACE DEFINER='root'@'localhost' PROCEDURE $A.cwh_user_password(IN p_user VARCHAR(64), IN p_host VARCHAR(255), IN p_password VARCHAR(255))
SQL SECURITY DEFINER
BEGIN
  IF p_user IS NULL OR p_user NOT REGEXP '^[a-z0-9_]{1,64}\$'
     OR p_host IS NULL OR p_host NOT REGEXP '^[a-z0-9.%_-]{1,255}\$' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid user or host';
  END IF;
  SET @s = CONCAT('ALTER USER `', p_user, '`@`', p_host, '` IDENTIFIED BY ', QUOTE(p_password));
  PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
END";

$procs['cwh_user_limits'] = "CREATE OR REPLACE DEFINER='root'@'localhost' PROCEDURE $A.cwh_user_limits(IN p_user VARCHAR(64), IN p_host VARCHAR(255), IN p_conns INT, IN p_queries INT, IN p_updates INT)
SQL SECURITY DEFINER
BEGIN
  IF p_user IS NULL OR p_user NOT REGEXP '^[a-z0-9_]{1,64}\$'
     OR p_host IS NULL OR p_host NOT REGEXP '^[a-z0-9.%_-]{1,255}\$' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid user or host';
  END IF;
  SET @s = CONCAT('ALTER USER `', p_user, '`@`', p_host, '` WITH MAX_USER_CONNECTIONS ', GREATEST(0, p_conns),
                  ' MAX_QUERIES_PER_HOUR ', GREATEST(0, p_queries), ' MAX_UPDATES_PER_HOUR ', GREATEST(0, p_updates));
  PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
END";

$procs['cwh_kill_query'] = "CREATE OR REPLACE DEFINER='root'@'localhost' PROCEDURE $A.cwh_kill_query(IN p_id BIGINT)
SQL SECURITY DEFINER
BEGIN
  IF p_id IS NULL OR p_id <= 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid thread id';
  END IF;
  SET @s = CONCAT('KILL QUERY ', p_id);
  PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
END";

$procs['cwh_user_hosts'] = "CREATE OR REPLACE DEFINER='root'@'localhost' PROCEDURE $A.cwh_user_hosts(IN p_user VARCHAR(64))
SQL SECURITY DEFINER
BEGIN
  IF p_user IS NULL OR p_user NOT REGEXP '^[a-z0-9_]{1,64}\$' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid user';
  END IF;
  SELECT Host FROM mysql.user WHERE User = p_user;
END";

$procs['cwh_db_size'] = "CREATE OR REPLACE DEFINER='root'@'localhost' PROCEDURE $A.cwh_db_size(IN p_db VARCHAR(64))
SQL SECURITY DEFINER
BEGIN
  IF p_db IS NULL OR p_db NOT REGEXP '^[a-z0-9_]{1,64}\$' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid database name';
  END IF;
  SELECT COALESCE(SUM(data_length + index_length), 0) AS bytes
  FROM information_schema.TABLES WHERE table_schema = p_db;
END";

$procs['cwh_db_sizes'] = "CREATE OR REPLACE DEFINER='root'@'localhost' PROCEDURE $A.cwh_db_sizes()
SQL SECURITY DEFINER
BEGIN
  SELECT TABLE_SCHEMA AS db_name, COALESCE(SUM(data_length + index_length), 0) AS bytes
  FROM information_schema.TABLES GROUP BY TABLE_SCHEMA;
END";

$pdo->exec("CREATE DATABASE IF NOT EXISTS `$A` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
foreach ($procs as $name => $sql) {
    $pdo->exec($sql);
    echo "procedure {$name} installed\n";
}

$user = (string) $cfg['username'];
$tryExec = function (string $sql) use ($pdo): void {
    try { $pdo->exec($sql); } catch (\Throwable $e) { /* grant not present — fine */ }
};
foreach (["'{$user}'@'localhost'", "'{$user}'@'127.0.0.1'"] as $acct) {
    // Revokes first (idempotent; missing grants are tolerated), then the
    // scoped grants — so a re-run always converges without a broken window.
    $tryExec("REVOKE ALL PRIVILEGES, GRANT OPTION FROM {$acct}");
    $tryExec("REVOKE ALL PRIVILEGES ON `cwhpanel`.* FROM {$acct}");
    $tryExec("REVOKE ALL PRIVILEGES ON `$A`.* FROM {$acct}");
    $pdo->exec("GRANT SHOW DATABASES, PROCESS, RELOAD ON *.* TO {$acct}");
    $pdo->exec("GRANT ALL PRIVILEGES ON `cwhpanel`.* TO {$acct}");
    $pdo->exec("GRANT EXECUTE ON `$A`.* TO {$acct}");
    echo "scoped {$acct}\n";
}
$pdo->exec('FLUSH PRIVILEGES');

foreach (["'{$user}'@'localhost'", "'{$user}'@'127.0.0.1'"] as $acct) {
    echo "\nGrants for {$acct}:\n";
    foreach ($pdo->query("SHOW GRANTS FOR {$acct}")->fetchAll(PDO::FETCH_COLUMN) as $g) {
        echo "  {$g}\n";
    }
}
echo "\nDone. Panel DB account scoped; DB management runs via DEFINER procedures.\n";

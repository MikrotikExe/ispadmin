<?php
/**
 * Copy an existing SQLite database into MySQL/MariaDB (needed for PPPoE via RADIUS,
 * because FreeRADIUS reads the same MySQL database).
 *
 * The SQLite file is only READ. The MySQL tables are created/upgraded the same way the
 * app does it on startup, then the app tables are filled with the SQLite rows (same IDs).
 * Refuses to run if MySQL already holds customers, unless --force is given.
 *
 * Usage (Docker, after `docker compose up -d` with the radius profile and DB_* in .env):
 *   preview: sudo docker exec -u www-data -it mt-ispadmin php /var/www/html/migrate_sqlite_to_mysql.php
 *   copy:    sudo docker exec -u www-data -it mt-ispadmin php /var/www/html/migrate_sqlite_to_mysql.php --apply
 *   other file: ... migrate_sqlite_to_mysql.php --apply --sqlite=/data/other.sqlite
 */
require_once __DIR__ . '/lib/mikrotik.php';

$APPLY = in_array('--apply', $argv, true);
$FORCE = in_array('--force', $argv, true);
$cfg = require __DIR__ . '/config.php';
$src = $cfg['db']['sqlite_path'];
foreach ($argv as $a) {
    if (strpos($a, '--sqlite=') === 0) $src = substr($a, 9);
}

if ($cfg['db']['driver'] !== 'mysql') {
    fwrite(STDERR, "DB_DRIVER is not 'mysql'. Set DB_DRIVER=mysql and the DB_* variables first.\n");
    exit(1);
}
if (!is_file($src)) {
    fwrite(STDERR, "SQLite file not found: $src\n");
    exit(1);
}

$sq = new PDO('sqlite:' . $src, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$my = db();   // vytvori/doplni MySQL tabulky rovnako ako appka

echo $APPLY ? "=== COPY (--apply) ===\n" : "=== PREVIEW (nothing is written, run with --apply) ===\n";
echo "from SQLite: $src\n";
echo "to MySQL:    {$cfg['db']['mysql']['host']}:{$cfg['db']['mysql']['port']}/{$cfg['db']['mysql']['dbname']}\n\n";

$existing = (int)$my->query('SELECT COUNT(*) FROM customers')->fetchColumn();
if ($existing > 0 && !$FORCE) {
    fwrite(STDERR, "MySQL already contains $existing customers. Refusing to overwrite (use --force to replace the app tables).\n");
    exit(1);
}

$tables = ['users', 'routers', 'programs', 'networks', 'customers', 'change_log', 'settings'];
$have = [];
foreach ($sq->query("SELECT name FROM sqlite_master WHERE type='table'") as $r) $have[] = $r['name'];

$plan = [];
foreach ($tables as $t) {
    if (!in_array($t, $have, true)) { echo sprintf("  %-12s missing in SQLite, skipped\n", $t); continue; }
    $myCols = [];
    foreach ($my->query("SHOW COLUMNS FROM `$t`") as $c) {
        $myCols[$c['Field']] = ['null' => $c['Null'] === 'YES', 'type' => strtolower($c['Type'])];
    }
    $sqCols = array_column($sq->query("PRAGMA table_info(`$t`)")->fetchAll(), 'name');
    $cols = array_values(array_intersect($sqCols, array_keys($myCols)));
    $lost = array_diff($sqCols, $cols);
    $n = (int)$sq->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    echo sprintf("  %-12s %6d rows%s\n", $t, $n, $lost ? '  (columns not in MySQL, dropped: ' . implode(', ', $lost) . ')' : '');
    $plan[$t] = [$cols, $myCols];
}

if (!$APPLY) {
    echo "\nNothing was written. Re-run with --apply.\n";
    exit(0);
}

$my->exec('SET FOREIGN_KEY_CHECKS = 0');
$my->beginTransaction();
try {
    foreach ($plan as $t => [$cols, $myCols]) {
        $my->exec("DELETE FROM `$t`");   // seed riadky (admin, ukazkove programy) nahradia data zo SQLite
        $q = implode(',', array_map(fn($c) => "`$c`", $cols));
        $ph = implode(',', array_fill(0, count($cols), '?'));
        $ins = $my->prepare("INSERT INTO `$t` ($q) VALUES ($ph)");
        foreach ($sq->query("SELECT $q FROM `$t`") as $row) {
            $vals = [];
            foreach ($cols as $c) {
                $v = $row[$c];
                $isText = preg_match('/char|text/', $myCols[$c]['type']);
                if ($v === '' && !$isText && $myCols[$c]['null']) $v = null;   // '' -> NULL pre INT/DATETIME
                if ($v === null && !$myCols[$c]['null']) $v = $isText ? '' : 0;
                $vals[] = $v;
            }
            $ins->execute($vals);
        }
    }
    $my->commit();
} catch (Throwable $e) {
    $my->rollBack();
    $my->exec('SET FOREIGN_KEY_CHECKS = 1');
    fwrite(STDERR, "FAILED, nothing was changed in MySQL: " . $e->getMessage() . "\n");
    exit(1);
}
$my->exec('SET FOREIGN_KEY_CHECKS = 1');

// FreeRADIUS klienti (tabulka nas) pre routery, ktore uz maju zapnuty RADIUS
foreach ($my->query('SELECT * FROM routers WHERE pppoe_radius = 1')->fetchAll() as $r) {
    radius_nas_sync((int)$r['id'], $r);
}

echo "\nDONE. Log in with your existing accounts. The SQLite file was not modified.\n";

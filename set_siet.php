<?php
/**
 * Bulk-sets the "Network / type" field on customers.
 * Does not touch their plan, speed or anything on the MikroTik.
 *
 *   docker exec -u www-data -it mt-ispadmin php /var/www/html/set_siet.php "North Side"
 *   docker exec -u www-data -it mt-ispadmin php /var/www/html/set_siet.php "North Side" --router="Example Town"
 *
 *   --router=NAME   only customers on that router
 *   --from=VALUE    only customers whose current value matches
 */
require_once __DIR__ . '/lib/db.php';

$val = $argv[1] ?? '';
if ($val === '') { fwrite(STDERR, "Pouzitie: php set_siet.php \"Nazov siete\" [--router=...] [--from=...]\n"); exit(1); }
$routerName = null;
$fromVal = null;
foreach ($argv as $a) {
    if (preg_match('/^--router=(.*)$/', $a, $m)) $routerName = trim($m[1], "\"'");
    if (preg_match('/^--from=(.*)$/', $a, $m)) $fromVal = trim($m[1], "\"'");
}
$pdo = db();

$sql = 'UPDATE customers SET siet = ? WHERE deleted_at IS NULL';
$params = [$val];
if ($fromVal !== null) {
    $sql .= ' AND siet = ?';
    $params[] = $fromVal;
}
if ($routerName !== null) {
    // najprv presna zhoda, inak ciastocna (LIKE) - aby stacila aj cast nazvu routera
    $rids = [];
    $st = $pdo->prepare('SELECT id, name FROM routers WHERE name = ?');
    $st->execute([$routerName]);
    foreach ($st as $r) $rids[$r['id']] = $r['name'];
    if (!$rids) {
        $st = $pdo->prepare('SELECT id, name FROM routers WHERE name LIKE ?');
        $st->execute(['%' . $routerName . '%']);
        foreach ($st as $r) $rids[$r['id']] = $r['name'];
    }
    if (!$rids) { fwrite(STDERR, "Žiadny router podľa '$routerName'. Dostupné: " . implode(', ', array_column($pdo->query('SELECT name FROM routers')->fetchAll(), 'name')) . "\n"); exit(1); }
    echo "Routery: " . implode(', ', $rids) . "\n";
    $in = implode(',', array_fill(0, count($rids), '?'));
    $sql .= ' AND router_id IN (' . $in . ')';
    $params = array_merge($params, array_keys($rids));
}
$st = $pdo->prepare($sql);
$st->execute($params);
echo "Nastavené Sieť = '$val'" . ($routerName !== null ? " pre vybraný router" : " (všetci)") . " — " . $st->rowCount() . " zákazníkov.\n";
echo "Programy ani rýchlosti sa nezmenili, MikroTik sa nedotklo.\n";

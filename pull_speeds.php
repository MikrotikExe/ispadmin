<?php
/**
 * Doplní reálnu rýchlosť (Up/Down) zákazníkom z MikroTiku — prečíta max-limit
 * Simple Queue podľa target IP. Z MikroTiku len ČÍTA (žiadny zápis na zariadenie).
 *
 *   náhľad:        sudo docker exec -it mt-ispadmin php /var/www/html/pull_speeds.php
 *   doplniť:       sudo docker exec -it mt-ispadmin php /var/www/html/pull_speeds.php --apply
 *   len 1 router:  ... pull_speeds.php --apply --router=2
 *   prepísať aj vyplnené: ... pull_speeds.php --apply --force
 */
require_once __DIR__ . '/lib/mikrotik.php';

$APPLY = in_array('--apply', $argv, true);
$FORCE = in_array('--force', $argv, true);
$ROUTER = 0;
foreach ($argv as $a) {
    if (preg_match('/^--router=(\d+)$/', $a, $m)) $ROUTER = (int)$m[1];
}
$pdo = db();

function toKbit(string $tok): int
{
    $tok = trim($tok);
    if (preg_match('/^([0-9.]+)\s*([kKMG]?)$/', $tok, $m)) {
        $n = (float)$m[1];
        $s = strtoupper($m[2]);
        if ($s === 'M')      $mbps = $n;
        elseif ($s === 'K')  $mbps = $n / 1000;
        elseif ($s === 'G')  $mbps = $n * 1000;
        else                 $mbps = $n / 1000000; // holé bps
        return (int)round($mbps * 1024);
    }
    return 0;
}
$mb = fn(int $k) => $k > 0 ? rtrim(rtrim(sprintf('%.2f', $k / 1024), '0'), '.') : '0';

echo $APPLY ? "=== DOPLNENIE (--apply) ===\n" : "=== NÁHĽAD (bez zápisu) ===\n";

// routery, ktore riesime
$rsql = 'SELECT * FROM routers WHERE active = 1' . ($ROUTER ? ' AND id = ' . $ROUTER : '') . ' ORDER BY name';
$routers = $pdo->query($rsql)->fetchAll();

$totFill = 0; $totSkip = 0; $totMiss = 0;

foreach ($routers as $router) {
    // zakaznici tohto routera, ktorym treba doplnit
    $cond = 'router_id = ? AND deleted_at IS NULL AND ip <> ""';
    if (!$FORCE) $cond .= ' AND (real_ul_kbit = 0 OR real_dl_kbit = 0)';
    $cst = $pdo->prepare("SELECT id, ip, priezvisko, firma, real_ul_kbit, real_dl_kbit FROM customers WHERE $cond");
    $cst->execute([$router['id']]);
    $custs = $cst->fetchAll();
    if (!$custs) continue;

    echo "\nRouter '{$router['name']}' (#{$router['id']}): na doplnenie " . count($custs) . " zákazníkov\n";

    [$api, $err] = mt_connect($router);
    if (!$api) {
        echo "  SPOJENIE ZLYHALO: $err — preskakujem\n";
        $totMiss += count($custs);
        continue;
    }
    // nacitaj vsetky fronty raz, sprav mapu target-IP -> max-limit
    $res = $api->comm('/queue/simple/print');
    $map = [];
    foreach ($res['items'] ?? [] as $it) {
        $tgt = $it['target'] ?? '';
        $ml  = $it['max-limit'] ?? '';
        if ($tgt === '' || $ml === '') continue;
        foreach (explode(',', $tgt) as $t) {
            $ip = explode('/', trim($t))[0];
            if ($ip !== '') $map[$ip] = $ml;
        }
    }
    $api->disconnect();

    foreach ($custs as $c) {
        $ml = $map[$c['ip']] ?? null;
        $who = $c['firma'] ?: $c['priezvisko'] ?: $c['ip'];
        if (!$ml || strpos($ml, '/') === false) {
            echo "  — bez fronty na MT: {$c['ip']} ($who)\n";
            $totMiss++;
            continue;
        }
        [$ulT, $dlT] = explode('/', $ml, 2);
        $ul = toKbit($ulT);
        $dl = toKbit($dlT);
        if ($ul <= 0 || $dl <= 0) { $totSkip++; continue; }
        echo "  ✓ {$c['ip']} ($who): {$mb($ul)}/{$mb($dl)} Mbps  [MT: $ml]\n";
        if ($APPLY) {
            $pdo->prepare('UPDATE customers SET real_ul_kbit = ?, real_dl_kbit = ?, updated_at = ?, updated_by = ? WHERE id = ?')
                ->execute([$ul, $dl, date('Y-m-d H:i:s'), 'pull-speeds', $c['id']]);
            log_change((int)$c['id'], '', 'pull-speeds', "Doplnená reálna rýchlosť z MikroTiku: {$mb($ul)}/{$mb($dl)} Mbps");
        }
        $totFill++;
    }
}

echo "\n--- SÚHRN ---\n";
echo "  doplnených:        $totFill\n";
echo "  bez fronty na MT:  $totMiss\n";
echo "  preskočených (0):  $totSkip\n";
echo $APPLY ? "HOTOVO.\n" : "Nič sa nezapísalo. Spusti s --apply.\n";

<?php
/**
 * Import a router, its networks and customers from a JSON file.
 * For the file format see example_data.json.
 *
 * Usage:
 *   preview:  php import_json.php data.json
 *   import:   php import_json.php data.json --apply
 *
 * Inside the Docker container:
 *   docker exec -it mt-ispadmin php /var/www/html/import_json.php /var/www/html/data.json --apply
 *
 * Safe to re-run: existing customers (same IP, PPPoE login or Circuit ID on that router)
 * are skipped. Nothing is written to the MikroTik - only to the app's own database.
 */
require_once __DIR__ . '/lib/db.php';

$args = array_slice($argv, 1);
$APPLY = in_array('--apply', $args, true);
$jsonPath = null;
foreach ($args as $a) { if ($a !== '--apply') { $jsonPath = $a; break; } }

if (!$jsonPath) { fwrite(STDERR, "Usage: php import_json.php <file.json> [--apply]\n"); exit(1); }
if (!is_file($jsonPath)) { fwrite(STDERR, "File not found: $jsonPath\n"); exit(1); }
$data = json_decode(file_get_contents($jsonPath), true);
if (!$data) { fwrite(STDERR, "Could not parse the JSON file.\n"); exit(1); }

$pdo = db();
echo ($APPLY ? "=== IMPORT (--apply) ===\n" : "=== PREVIEW (nothing is written, run with --apply) ===\n");

// --- router ---
$rcfg = $data['router'];
$routerId = (int)($pdo->query("SELECT id FROM routers WHERE name = " . $pdo->quote($rcfg['name']))->fetchColumn() ?: 0);
if (!$routerId) {
    echo "Router '{$rcfg['name']}' - WILL BE CREATED (host {$rcfg['host']}, dhcp {$rcfg['dhcp_server']}). Add the API username/password in the UI.\n";
    if ($APPLY) {
        $pdo->prepare('INSERT INTO routers (name,host,api_port,use_ssl,api_user,api_pass,dhcp_server,parent_queue,siet,manage_arp,arp_interface,active)
                       VALUES (?,?,8728,0,?,?,?,?,?,?,?,1)')
            ->execute([$rcfg['name'], $rcfg['host'], '', '', $rcfg['dhcp_server'], $rcfg['parent_queue'], $rcfg['siet'], (int)($rcfg['manage_arp'] ?? 0), $rcfg['arp_interface'] ?? '']);
        $routerId = (int)$pdo->lastInsertId();
    }
} else {
    echo "Router '{$rcfg['name']}' - already exists (#$routerId).\n";
}

// --- siete ---
$netMap = []; $netNew = 0;
foreach ($data['networks'] as $n) {
    $existing = $routerId ? (int)($pdo->query(
        "SELECT id FROM networks WHERE router_id = $routerId AND name = " . $pdo->quote($n['name']))->fetchColumn() ?: 0) : 0;
    if ($existing) { $netMap[$n['name']] = $existing; continue; }
    $netNew++;
    if ($APPLY && $routerId) {
        $pdo->prepare('INSERT INTO networks (router_id,name,subnet,parent_queue,active) VALUES (?,?,?,?,1)')
            ->execute([$routerId, $n['name'], $n['subnet'], $n['parent_queue']]);
        $netMap[$n['name']] = (int)$pdo->lastInsertId();
    }
}
echo "Networks: " . count($data['networks']) . " (new: $netNew)\n";

// --- programy: nazov -> id ---
$progMap = [];
foreach ($pdo->query('SELECT id, name FROM programs') as $p) { $progMap[$p['name']] = (int)$p['id']; }

// --- zakaznici ---
$ins = 0; $skipDb = 0; $skipDup = 0; $seenIp = [];
$byStatus = ['pripojeny'=>0,'neplatic'=>0,'ukoncena'=>0,'docasne'=>0];
$now = date('Y-m-d H:i:s');

foreach ($data['customers'] as $c) {
    $ip = $c['ip'];
    $circuitId = trim((string)($c['circuit_id'] ?? ''));
    $isPppoe = ($c['conn_type'] ?? 'dhcp') === 'pppoe';
    // kluc pre rozlisenie: PPPoE login > circuit ID > IP
    if ($isPppoe)      { $key = 'ppp:' . $c['pppoe_user']; }
    elseif ($circuitId !== '') { $key = 'cid:' . $circuitId; }
    else               { $key = 'ip:' . $ip; }
    // duplicita v ramci tejto davky
    if (isset($seenIp[$key])) { $skipDup++; continue; }
    // uz v DB na tomto routeri
    if ($routerId) {
        if ($isPppoe) {
            $dup = $pdo->prepare('SELECT id FROM customers WHERE router_id = ? AND pppoe_user = ? AND deleted_at IS NULL LIMIT 1');
            $dup->execute([$routerId, $c['pppoe_user']]);
        } elseif ($circuitId !== '') {
            $dup = $pdo->prepare('SELECT id FROM customers WHERE router_id = ? AND circuit_id = ? AND circuit_id <> "" AND deleted_at IS NULL LIMIT 1');
            $dup->execute([$routerId, $circuitId]);
        } else {
            $dup = $pdo->prepare('SELECT id FROM customers WHERE router_id = ? AND ip = ? AND ip <> "" AND deleted_at IS NULL LIMIT 1');
            $dup->execute([$routerId, $ip]);
        }
        if ($dup->fetch()) { $skipDb++; $seenIp[$key] = true; continue; }
    }
    $seenIp[$key] = true;

    $byStatus[$c['status']] = ($byStatus[$c['status']] ?? 0) + 1;
    $progId = $progMap[$c['program']] ?? null;
    $netId  = ($c['network'] !== '' && isset($netMap[$c['network']])) ? $netMap[$c['network']] : null;

    if ($APPLY && $routerId) {
        $row = [
            'contract_no' => '',
            'status'      => $c['status'],
            'meno'        => $c['meno'],
            'priezvisko'  => $c['priezvisko'],
            'firma'       => $c['firma'],
            'ulica'       => $c['ulica'],
            'cislo_domu'  => $c['cislo_domu'] ?? '',
            'mesto'       => $c['mesto'] ?? '',
            'telefon'     => $c['telefon'],
            'router_id'   => $routerId,
            'network_id'  => $netId,
            'siet'        => $c['siet'],
            'ip'          => $ip,
            'mac'         => $c['mac'],
            'conn_type'   => $c['conn_type'] ?? 'dhcp',
            'pppoe_user'  => $c['pppoe_user'] ?? '',
            'pppoe_pass'  => $c['pppoe_pass'] ?? '',
            'pppoe_profile'=> $c['pppoe_profile'] ?? '',
            'circuit_id'  => $c['circuit_id'] ?? '',
            'program_id'  => $progId,
            'real_ul_kbit'=> (int)$c['real_ul'],
            'real_dl_kbit'=> (int)$c['real_dl'],
            'zariadenie'  => 'Router',
            'poznamka'    => $c['poznamka'],
            'updated_at'  => $now,
            'updated_by'  => 'import-json',
        ];
        $cols = implode(',', array_keys($row));
        $ph = implode(',', array_map(fn($k) => ":$k", array_keys($row)));
        $pdo->prepare("INSERT INTO customers ($cols) VALUES ($ph)")->execute($row);
        $newId = (int)$pdo->lastInsertId();
        $who = $row['firma'] !== '' ? $row['firma'] : trim($row['priezvisko'] . ' ' . $row['meno']);
        log_change($newId, '', 'import-json', "Imported from JSON ({$rcfg['name']}) — " . ($who ?: $ip));
    }
    $ins++;
}

echo "\nCustomers:\n";
echo "  to import:                $ins\n";
echo "  skipped (already in DB):  $skipDb\n";
echo "  skipped (duplicate in file): $skipDup\n";
echo "  by status: " . json_encode($byStatus, JSON_UNESCAPED_UNICODE) . "\n";
echo $APPLY ? "\nDONE. Check the Home and Networks pages.\n" : "\nNothing was written. Re-run with --apply.\n";

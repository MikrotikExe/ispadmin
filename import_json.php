<?php
/**
 * Import routera, sietí a zákazníkov z JSON súboru do appky.
 * Formát JSON viď example_data.json.
 *
 * Spustenie:
 *   náhľad:  php import_json.php data.json
 *   import:  php import_json.php data.json --apply
 *
 * V Docker kontajneri:
 *   docker exec -it mt-ispadmin php /var/www/html/import_json.php /var/www/html/data.json --apply
 *
 * Bezpečné: existujúcich zákazníkov (rovnaká IP / PPPoE login na routeri) preskočí,
 * dá sa spustiť opakovane. Nemení nič na MikroTiku — zapisuje len do DB appky.
 */
require_once __DIR__ . '/lib/db.php';

$args = array_slice($argv, 1);
$APPLY = in_array('--apply', $args, true);
$jsonPath = null;
foreach ($args as $a) { if ($a !== '--apply') { $jsonPath = $a; break; } }

if (!$jsonPath) { fwrite(STDERR, "Použitie: php import_json.php <subor.json> [--apply]\n"); exit(1); }
if (!is_file($jsonPath)) { fwrite(STDERR, "Súbor nenájdený: $jsonPath\n"); exit(1); }
$data = json_decode(file_get_contents($jsonPath), true);
if (!$data) { fwrite(STDERR, "Nečitateľný JSON.\n"); exit(1); }

$pdo = db();
echo ($APPLY ? "=== IMPORT (--apply) ===\n" : "=== NÁHĽAD (bez zápisu, spusti s --apply) ===\n");

// --- router ---
$rcfg = $data['router'];
$routerId = (int)($pdo->query("SELECT id FROM routers WHERE name = " . $pdo->quote($rcfg['name']))->fetchColumn() ?: 0);
if (!$routerId) {
    echo "Router '{$rcfg['name']}' — VYTVORÍ SA (host {$rcfg['host']}, dhcp {$rcfg['dhcp_server']}). API user/heslo doplníš v UI.\n";
    if ($APPLY) {
        $pdo->prepare('INSERT INTO routers (name,host,api_port,use_ssl,api_user,api_pass,dhcp_server,parent_queue,siet,manage_arp,arp_interface,active)
                       VALUES (?,?,8728,0,?,?,?,?,?,?,?,1)')
            ->execute([$rcfg['name'], $rcfg['host'], '', '', $rcfg['dhcp_server'], $rcfg['parent_queue'], $rcfg['siet'], (int)($rcfg['manage_arp'] ?? 0), $rcfg['arp_interface'] ?? '']);
        $routerId = (int)$pdo->lastInsertId();
    }
} else {
    echo "Router '{$rcfg['name']}' — už existuje (#$routerId).\n";
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
echo "Siete: " . count($data['networks']) . " (nových: $netNew)\n";

// --- programy: nazov -> id ---
$progMap = [];
foreach ($pdo->query('SELECT id, name FROM programs') as $p) { $progMap[$p['name']] = (int)$p['id']; }

// --- zakaznici ---
$ins = 0; $skipDb = 0; $skipDup = 0; $seenIp = [];
$byStatus = ['pripojeny'=>0,'neplatic'=>0,'ukoncena'=>0,'docasne'=>0];
$now = date('Y-m-d H:i:s');

foreach ($data['customers'] as $c) {
    $ip = $c['ip'];
    $cid = trim((string)($c['circuit_id'] ?? ''));
    $isPppoe = ($c['conn_type'] ?? 'dhcp') === 'pppoe';
    // kluc pre rozlisenie: PPPoE login > circuit ID > IP
    if ($isPppoe)      { $key = 'ppp:' . $c['pppoe_user']; }
    elseif ($cid !== ''){ $key = 'cid:' . $cid; }
    else               { $key = 'ip:' . $ip; }
    // duplicita v ramci tejto davky
    if (isset($seenIp[$key])) { $skipDup++; continue; }
    // uz v DB na tomto routeri
    if ($routerId) {
        if ($isPppoe) {
            $dup = $pdo->prepare('SELECT id FROM customers WHERE router_id = ? AND pppoe_user = ? AND deleted_at IS NULL LIMIT 1');
            $dup->execute([$routerId, $c['pppoe_user']]);
        } elseif ($cid !== '') {
            $dup = $pdo->prepare('SELECT id FROM customers WHERE router_id = ? AND circuit_id = ? AND circuit_id <> "" AND deleted_at IS NULL LIMIT 1');
            $dup->execute([$routerId, $cid]);
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
        $cid = (int)$pdo->lastInsertId();
        $who = $row['firma'] !== '' ? $row['firma'] : trim($row['priezvisko'] . ' ' . $row['meno']);
        log_change($cid, '', 'import-json', "Imported from JSON ({$rcfg['name']}) — " . ($who ?: $ip));
    }
    $ins++;
}

echo "\nZákazníci:\n";
echo "  na import:            $ins\n";
echo "  preskočené (už v DB): $skipDb\n";
echo "  preskočené (dupl. IP v dávke): $skipDup\n";
echo "  podľa stavu: " . json_encode($byStatus, JSON_UNESCAPED_UNICODE) . "\n";
echo $APPLY ? "\nHOTOVO. Skontroluj Domov + Siete.\n" : "\nNič sa nezapísalo. Spusti s --apply.\n";

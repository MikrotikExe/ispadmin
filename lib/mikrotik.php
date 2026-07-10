<?php
require_once __DIR__ . '/RouterosApi.php';
require_once __DIR__ . '/db.php';

/** kbit (ulozene ako Mbps*1024) -> MikroTik max-limit string: cele Mbps ako "15M", inak "Nk". */
function mt_rate(int $kbit): string
{
    if ($kbit <= 0) return '0';
    $mbps = $kbit / 1024;
    if ($mbps >= 1 && abs($mbps - round($mbps)) < 0.0001) {
        return (string)((int)round($mbps)) . 'M';
    }
    // necele Mbps -> posli v kbit (1000-based ekvivalent rychlosti)
    return (string)max(1, (int)round($mbps * 1000)) . 'k';
}

/** Prevedie slovensku/ceskou diakritiku na ASCII - komentare na MikroTiku nech nie su domrvene. */
function mt_ascii(string $s): string
{
    $map = [
        'á'=>'a','ä'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','í'=>'i','ĺ'=>'l','ľ'=>'l','ň'=>'n',
        'ó'=>'o','ô'=>'o','ö'=>'o','ŕ'=>'r','ř'=>'r','š'=>'s','ť'=>'t','ú'=>'u','ů'=>'u','ü'=>'u',
        'ý'=>'y','ž'=>'z',
        'Á'=>'A','Ä'=>'A','Č'=>'C','Ď'=>'D','É'=>'E','Ě'=>'E','Í'=>'I','Ĺ'=>'L','Ľ'=>'L','Ň'=>'N',
        'Ó'=>'O','Ô'=>'O','Ö'=>'O','Ŕ'=>'R','Ř'=>'R','Š'=>'S','Ť'=>'T','Ú'=>'U','Ů'=>'U','Ü'=>'U',
        'Ý'=>'Y','Ž'=>'Z',
    ];
    $s = strtr($s, $map);
    // odstran pripadne zvysne ne-ASCII znaky
    return preg_replace('/[^\x20-\x7E]/', '', $s);
}

function mt_connect(array $router): array
{
    $api = new RouterosApi();
    $api->port = (int)$router['api_port'];
    $api->ssl  = (bool)$router['use_ssl'];
    if (!$api->connect($router['host'], $router['api_user'], $router['api_pass'])) {
        return [null, $api->error ?: 'spojenie zlyhalo'];
    }
    return [$api, ''];
}

function mt_test_router(array $router): array
{
    [$api, $err] = mt_connect($router);
    if (!$api) {
        return ['ok' => false, 'msg' => $err];
    }
    $r = $api->comm('/system/identity/print');
    $api->disconnect();
    $name = $r['items'][0]['name'] ?? '?';
    return ['ok' => true, 'msg' => 'OK - identity: ' . $name];
}

/** Najde prvy .id v polozkach, ktore maju dany atribut == hodnota (filter v PHP). */
function mt_find_id(array $items, string $attr, string $value): ?string
{
    foreach ($items as $it) {
        if (isset($it[$attr]) && $it[$attr] === $value && isset($it['.id'])) {
            return $it['.id'];
        }
    }
    return $items[0]['.id'] ?? null;
}

/**
 * Aplikuje zakaznika na jeho router.
 * pripojeny  -> lease + queue (ak ma program rychlost), zmaze z block listu
 * docasne/neplatic -> lease ostava, queue disabled, IP do block listu
 * ukoncena   -> zmaze lease + queue + block list
 */
function mt_apply_customer(int $customerId): array
{
    $cfg = require __DIR__ . '/../config.php';
    $pdo = db();

    $c = $pdo->query('SELECT * FROM customers WHERE id = ' . (int)$customerId)->fetch();
    if (!$c) {
        return ['ok' => false, 'msg' => 'zakaznik neexistuje'];
    }
    if (empty($c['router_id'])) {
        return ['ok' => false, 'msg' => 'zákazník nemá priradenú MikroTik sieť'];
    }
    $router = $pdo->query('SELECT * FROM routers WHERE id = ' . (int)$c['router_id'])->fetch();
    if (!$router) {
        return ['ok' => false, 'msg' => 'MikroTik sieť neexistuje'];
    }

    $program = null;
    if (!empty($c['program_id'])) {
        $program = $pdo->query('SELECT * FROM programs WHERE id = ' . (int)$c['program_id'])->fetch() ?: null;
    }

    // nadradena fronta (parent): primarne zo siete zakaznika, inak z routera
    $network = null;
    if (!empty($c['network_id'])) {
        $network = $pdo->query('SELECT * FROM networks WHERE id = ' . (int)$c['network_id'])->fetch() ?: null;
    }
    $parentQueue = '';
    if ($network) {
        $parentQueue = trim($network['parent_queue'] ?? '') !== ''
            ? trim($network['parent_queue'])
            : trim($network['name'] ?? '');
    }
    if ($parentQueue === '') {
        $parentQueue = trim($router['parent_queue'] ?? '');
    }

    // realna rychlost (override) - ma prednost pred programom (kbit)
    $realUl = (int)($c['real_ul_kbit'] ?? 0);
    $realDl = (int)($c['real_dl_kbit'] ?? 0);
    $useReal = ($realUl > 0 && $realDl > 0);

    $ip  = trim($c['ip']);
    $mac = strtoupper(trim($c['mac']));
    if ($ip === '' && ($c['conn_type'] ?? 'dhcp') !== 'pppoe') {
        return ['ok' => false, 'msg' => 'chýba IP'];
    }

    [$api, $err] = mt_connect($router);
    if (!$api) {
        return ['ok' => false, 'msg' => 'sieť ' . $router['name'] . ': ' . $err];
    }

    $name    = $c['contract_no'] !== '' ? $c['contract_no'] : ('cust-' . $c['id']);
    $comment = trim($c['meno'] . ' ' . $c['priezvisko']);
    if ($c['firma'] !== '') {
        $comment = $c['firma'];
    }
    $comment = ($name . ' ' . $comment);
    $comment = mt_ascii($comment);
    $blockList = $cfg['block_address_list'];
    $blockLists = $cfg['block_lists'] ?? ['docasne' => 'docasne_odpojeni', 'neplatic' => 'neplatici', 'ukoncena' => 'vypovede'];
    $blockLimit = $cfg['block_limit'] ?? '1k/1k';
    $status = $c['status'];
    $log = [];

    try {
        // --- PPPoE pripojenie: spravuje sa /ppp secret (login/heslo/profil), nie lease/queue/ARP ---
        if (($c['conn_type'] ?? 'dhcp') === 'pppoe') {
            $user = trim((string)($c['pppoe_user'] ?? ''));
            if ($user === '') {
                $api->disconnect();
                return ['ok' => false, 'msg' => $router['name'] . ': PPPoE bez loginu'];
            }
            $sec = $api->comm('/ppp/secret/print', ['?name' => $user]);
            $secId = $sec['items'][0]['.id'] ?? null;
            if ($status === 'ukoncena') {
                if ($secId) {
                    $api->comm('/ppp/secret/remove', ['.id' => $secId]);
                    $log[] = 'PPP secret zmazaný';
                }
            } else {
                $args = ['service' => 'pppoe', 'comment' => $comment,
                         'disabled' => in_array($status, ['docasne', 'neplatic'], true) ? 'yes' : 'no'];
                $pass = (string)($c['pppoe_pass'] ?? '');
                if ($pass !== '') $args['password'] = $pass;
                $prof = trim((string)($c['pppoe_profile'] ?? ''));
                if ($prof !== '') $args['profile'] = $prof;
                if ($secId) {
                    $api->comm('/ppp/secret/set', ['.id' => $secId] + $args);
                    $log[] = $args['disabled'] === 'yes' ? 'PPP secret zablokovaný' : 'PPP secret aktualizovaný';
                } else {
                    $r = $api->comm('/ppp/secret/add', ['name' => $user] + $args);
                    if ($r['status'] === 'error') {
                        throw new RuntimeException('PPP secret: ' . $r['message']);
                    }
                    $log[] = 'PPP secret pridaný';
                }
            }
            $api->disconnect();
            return ['ok' => true, 'msg' => $router['name'] . ': ' . implode(', ', $log)];
        }

        // --- DHCP lease (len ak mame MAC; staticke IP bez DHCP preskocime) ---
        if ($mac !== '') {
            $leases = $api->comm('/ip/dhcp-server/lease/print', ['?mac-address' => $mac]);
            $leaseId = $leases['items'][0]['.id'] ?? null;
            if (!$leaseId) {
                // prevezmi existujuci lease podla IP (aj ked ma iny zapis MAC)
                $byIp = $api->comm('/ip/dhcp-server/lease/print', ['?address' => $ip]);
                $leaseId = $byIp['items'][0]['.id'] ?? null;
            }

            if ($status === 'ukoncena') {
                if ($leaseId) {
                    $api->comm('/ip/dhcp-server/lease/remove', ['.id' => $leaseId]);
                    $log[] = 'lease zmazany';
                }
            } else {
                $args = ['address' => $ip, 'mac-address' => $mac, 'comment' => $comment];
                if (trim($router['dhcp_server']) !== '') {
                    $args['server'] = trim($router['dhcp_server']);
                }
                if ($leaseId) {
                    $api->comm('/ip/dhcp-server/lease/set', ['.id' => $leaseId] + $args);
                    $log[] = 'lease aktualizovany';
                } else {
                    $r = $api->comm('/ip/dhcp-server/lease/add', $args);
                    if ($r['status'] === 'error') {
                        throw new RuntimeException('lease: ' . $r['message']);
                    }
                    $log[] = 'lease pridany';
                }
            }
        } else {
            $log[] = 'bez MAC (lease neriešený)';
        }

        // --- Simple queue (realna rychlost ma prednost pred programom) ---
        $progHas = $program && (int)$program['dl_user'] > 0 && (int)$program['ul_user'] > 0;
        $hasSpeed = $useReal || $progHas;
        $queues = $api->comm('/queue/simple/print', ['?name' => $name]);
        $queueId = $queues['items'][0]['.id'] ?? null;
        $queueByIp = false;
        if (!$queueId) {
            // prevezmi existujucu frontu podla target IP (zachova povodny "ludsky" nazov)
            $qt = $api->comm('/queue/simple/print', ['?target' => $ip . '/32']);
            $queueId = $qt['items'][0]['.id'] ?? null;
            if ($queueId) {
                $queueByIp = true;
            }
        }

        if ($status === 'ukoncena') {
            // koniec - frontu zmaz uplne
            if ($queueId) {
                $api->comm('/queue/simple/remove', ['.id' => $queueId]);
                $log[] = 'queue zmazana';
            }
        } elseif (!$hasSpeed) {
            // bez programu aj realnej rychlosti - frontu nevieme nastavit
            if ($queueId) {
                $api->comm('/queue/simple/remove', ['.id' => $queueId]);
                $log[] = 'queue zmazana (bez rýchlosti)';
            }
        } else {
            // pripojeny aj docasne/neplatic: fronta normalna rychlost (blok rieši firewall address-list)
            if ($useReal) {
                $agg = 1;
                $ul  = $realUl;
                $dl  = $realDl;
                $label = $program ? $program['name'] . ' / reálne' : 'reálne';
            } else {
                $agg = max(1, (int)$program['aggregation']);
                $dl  = (int)$program['dl_user'];
                $ul  = (int)$program['ul_user'];
                $label = $program['name'];
            }
            // max-limit = upload/download (15M, 30M ...)
            $maxLimit = mt_rate($ul) . '/' . mt_rate($dl);

            $qargs = [
                'target'    => $ip . '/32',
                'max-limit' => $maxLimit,
                'comment'   => mt_ascii($comment . ' [' . $label . ']'),
                'disabled'  => 'no',
            ];
            if ($parentQueue !== '') {
                $qargs['parent'] = $parentQueue;
            }
            // garantovany podiel len ak je agregacia > 1 (limit-at = max / agregacia)
            if ($agg > 1) {
                $qargs['limit-at'] = mt_rate((int)floor($ul / $agg)) . '/' . mt_rate((int)floor($dl / $agg));
            }
            if ($queueId) {
                $api->comm('/queue/simple/set', ['.id' => $queueId] + $qargs);
                $log[] = 'queue aktualizovaná'
                       . ($queueByIp ? ' (prevzatá podľa IP, názov zachovaný)' : '');
            } else {
                $r = $api->comm('/queue/simple/add', ['name' => $name] + $qargs);
                if ($r['status'] === 'error') {
                    throw new RuntimeException('queue: ' . $r['message']);
                }
                $log[] = 'queue pridana';
            }
        }

        // --- Statický ARP (pre reply-only siete) ---
        if ((int)($router['manage_arp'] ?? 0) === 1 && $mac !== '') {
            $iface = (string)($router['arp_interface'] ?? '');
            if ($iface !== '') {
                $ar = $api->comm('/ip/arp/print', ['?address' => $ip, '?interface' => $iface]);
                $arpId = $ar['items'][0]['.id'] ?? null;
                if ($status === 'ukoncena') {
                    if ($arpId) {
                        $api->comm('/ip/arp/remove', ['.id' => $arpId]);
                        $log[] = 'ARP zmazaný';
                    }
                } elseif ($arpId) {
                    $api->comm('/ip/arp/set', ['.id' => $arpId, 'mac-address' => $mac]);
                    $log[] = 'ARP aktualizovaný';
                } else {
                    $r = $api->comm('/ip/arp/add', ['address' => $ip, 'mac-address' => $mac, 'interface' => $iface]);
                    if ($r['status'] === 'error') {
                        throw new RuntimeException('ARP: ' . $r['message']);
                    }
                    $log[] = 'ARP pridaný';
                }
            }
        }

        // --- Firewall address-listy podla stavu (kazdy stav ma vlastny list) ---
        // IP patri do listu zodpovedajuceho stavu; z ostatnych spravovanych listov sa odoberie.
        $managed = array_values(array_unique($blockLists));   // napr. docasne_odpojeni, neplatici, vypovede
        $targetList = $blockLists[$status] ?? null;           // pripojeny -> null (nikde)
        foreach ($managed as $ln) {
            $cur = $api->comm('/ip/firewall/address-list/print', ['?list' => $ln, '?address' => $ip]);
            $existId = $cur['items'][0]['.id'] ?? null;
            if ($ln === $targetList) {
                if (!$existId) {
                    $api->comm('/ip/firewall/address-list/add', [
                        'list' => $ln, 'address' => $ip, 'comment' => $comment,
                    ]);
                    $log[] = 'pridany do ' . $ln;
                }
            } else {
                if ($existId) {
                    $api->comm('/ip/firewall/address-list/remove', ['.id' => $existId]);
                    $log[] = 'odobraty z ' . $ln;
                }
            }
        }

        $api->disconnect();
        return ['ok' => true, 'msg' => $router['name'] . ': ' . implode(', ', $log)];
    } catch (Throwable $e) {
        $api->disconnect();
        return ['ok' => false, 'msg' => $router['name'] . ': ' . $e->getMessage()];
    }
}

/**
 * Precita viacero firewall address-listov z routera v jednom spojeni. Read-only.
 * $lists = pole nazvov listov.
 * Vrati ['ok'=>bool,'msg'=>?, 'lists'=>[ name => ['items'=>[['id','address','comment']], 'rule'=>bool] ]]
 */
function mt_blocklists_read(array $router, array $lists): array
{
    [$api, $err] = mt_connect($router);
    if (!$api) {
        return ['ok' => false, 'msg' => $err, 'lists' => []];
    }
    $out = [];
    foreach ($lists as $ln) {
        $items = [];
        $res = $api->comm('/ip/firewall/address-list/print', ['?list' => $ln]);
        foreach ($res['items'] ?? [] as $it) {
            $items[] = [
                'id'      => $it['.id'] ?? '',
                'address' => $it['address'] ?? '',
                'comment' => $it['comment'] ?? '',
            ];
        }
        $out[$ln] = ['items' => $items, 'rule' => false];
    }
    // detekcia drop/reject pravidiel pre jednotlive listy (jeden print)
    $fl = $api->comm('/ip/firewall/filter/print');
    foreach ($fl['items'] ?? [] as $r) {
        $act = $r['action'] ?? '';
        if (!in_array($act, ['drop', 'reject'], true)) continue;
        $src = $r['src-address-list'] ?? '';
        $dst = $r['dst-address-list'] ?? '';
        foreach ($lists as $ln) {
            if ($src === $ln || $dst === $ln) {
                $out[$ln]['rule'] = true;
            }
        }
    }
    $api->disconnect();
    return ['ok' => true, 'msg' => '', 'lists' => $out];
}

/** Odoberie jednu IP z address-listu (uvolni). */
function mt_blocklist_remove(array $router, string $list, string $address): array
{
    [$api, $err] = mt_connect($router);
    if (!$api) {
        return ['ok' => false, 'msg' => $err];
    }
    $res = $api->comm('/ip/firewall/address-list/print', ['?list' => $list, '?address' => $address]);
    $removed = 0;
    foreach ($res['items'] ?? [] as $it) {
        if (isset($it['.id'])) {
            $api->comm('/ip/firewall/address-list/remove', ['.id' => $it['.id']]);
            $removed++;
        }
    }
    $api->disconnect();
    return ['ok' => true, 'msg' => "uvoľnené ($removed)"];
}

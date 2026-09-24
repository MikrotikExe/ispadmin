<?php
/**
 * PPPoE cez RADIUS.
 *
 * Architektura: RADIUS server je FreeRADIUS 3.x (kontajner "freeradius") s rlm_sql nad
 * tou istou MySQL databazou. ISPadmin NIE JE RADIUS server - len spravuje riadky
 * v standardnych tabulkach (radcheck / radreply / nas) a cita radacct.
 *
 * Jedine, co tu PHP po sieti posiela, su:
 *   - CoA-Request / Disconnect-Request (RFC 5176) na MikroTik (UDP 3799) pri zmene stavu/programu,
 *   - Access-Request pre tlacidlo "RADIUS test" (overi, ze FreeRADIUS zije a cita DB).
 */
require_once __DIR__ . '/db.php';

const RAD_ACCESS_REQUEST   = 1;
const RAD_ACCESS_ACCEPT    = 2;
const RAD_ACCESS_REJECT    = 3;
const RAD_DISCONNECT_REQ   = 40;
const RAD_DISCONNECT_ACK   = 41;
const RAD_DISCONNECT_NAK   = 42;
const RAD_COA_REQ          = 43;
const RAD_COA_ACK          = 44;
const RAD_COA_NAK          = 45;

const RAD_ATTR_USER_NAME        = 1;
const RAD_ATTR_USER_PASSWORD    = 2;
const RAD_ATTR_NAS_IP           = 4;
const RAD_ATTR_FRAMED_IP        = 8;
const RAD_ATTR_FILTER_ID        = 11;
const RAD_ATTR_REPLY_MESSAGE    = 18;
const RAD_ATTR_VSA              = 26;
const RAD_ATTR_ACCT_SESSION_ID  = 44;
const RAD_ATTR_ERROR_CAUSE      = 101;
const RAD_ATTR_MSG_AUTH         = 80;

const RAD_VENDOR_MIKROTIK       = 14988;
const RAD_MT_GROUP              = 3;
const RAD_MT_RATE_LIMIT         = 8;
const RAD_MT_ADDRESS_LIST       = 19;

/** Konfiguracia RADIUS (s predvolenymi hodnotami, aby starsi config.php nepadal). */
function radius_cfg(): array
{
    $cfg = require __DIR__ . '/../config.php';
    $r = $cfg['radius'] ?? [];
    $r += [
        'enabled' => false, 'auth_mode' => 'restrict', 'restrict_rate' => '', 'restrict_filter_id' => '',
        'password_storage' => 'cleartext', 'rate_limit_template' => '{ul}/{dl}', 'framed_pool' => '',
        'accounting' => true, 'interim_interval' => 300, 'address' => '', 'server' => '127.0.0.1', 'auth_port' => 1812,
        'local_secret' => '', 'coa' => [],
    ];
    $r['coa'] += ['status_method' => 'disconnect', 'port' => 3799, 'timeout' => 2, 'source_ip' => ''];
    return $r;
}

/** RADIUS je v appke k dispozicii (zapnuty v configu a DB je MySQL). */
function radius_available(): bool
{
    return !empty(radius_cfg()['enabled']) && db_driver() === 'mysql';
}

/** Pouziva tento router PPPoE cez RADIUS (namiesto /ppp secret)? */
function radius_router_on(?array $router): bool
{
    return $router !== null && radius_available() && (int)($router['pppoe_radius'] ?? 0) === 1;
}

/** Adresa NAS (odkial MikroTik posiela RADIUS a kam ide CoA). */
function radius_nas_ip(array $router): string
{
    $ip = trim((string)($router['radius_nas_ip'] ?? ''));
    return $ip !== '' ? $ip : trim((string)$router['host']);
}

/** Nahodny retazec z bezpecnej abecedy (bez znakov, ktore CPE/MikroTik zvyknu kazit). */
function radius_random(int $len, string $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'): string
{
    $out = '';
    $n = strlen($alphabet);
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, $n - 1)];
    }
    return $out;
}

/** NT hash (MD4 z UTF-16LE) pre NT-Password - MS-CHAPv2 a PAP. */
function radius_nt_hash(string $pw): string
{
    $u16 = function_exists('mb_convert_encoding')
        ? mb_convert_encoding($pw, 'UTF-16LE', 'UTF-8')
        : iconv('UTF-8', 'UTF-16LE', $pw);
    return '0x' . strtoupper(hash('md4', (string)$u16));
}

/**
 * Mikrotik-Rate-Limit pre zakaznika. Rovnaka logika ako Simple Queue v mt_apply_customer:
 * realna rychlost ma prednost pred programom; rx/tx z pohladu routera = upload/download zakaznika.
 * Vrati '' ak nema rychlost (router potom pouzije rate-limit z PPP profilu).
 */
function radius_rate_limit(array $c, ?array $program, string $template): string
{
    $realUl = (int)($c['real_ul_kbit'] ?? 0);
    $realDl = (int)($c['real_dl_kbit'] ?? 0);
    if ($realUl > 0 && $realDl > 0) {
        $ul = $realUl; $dl = $realDl; $agg = 1;
    } elseif ($program && (int)$program['dl_user'] > 0 && (int)$program['ul_user'] > 0) {
        $ul = (int)$program['ul_user']; $dl = (int)$program['dl_user'];
        $agg = max(1, (int)$program['aggregation']);
    } else {
        return '';
    }
    return strtr($template, [
        '{ul}'    => mt_rate($ul),
        '{dl}'    => mt_rate($dl),
        '{ul_at}' => mt_rate((int)floor($ul / $agg)),
        '{dl_at}' => mt_rate((int)floor($dl / $agg)),
    ]);
}

/**
 * Pozadovany stav RADIUS riadkov pre PPPoE zakaznika.
 * Vrati ['state' => accept|restrict|reject, 'check' => [[attr, op, value]], 'reply' => [...]]
 */
function radius_desired_rows(array $c, ?array $program): array
{
    $rc = radius_cfg();
    $app = require __DIR__ . '/../config.php';
    $blockLists = $app['block_lists'] ?? [];
    $status = (string)$c['status'];

    if ($status === 'ukoncena'
        || (in_array($status, ['docasne', 'neplatic'], true) && $rc['auth_mode'] === 'reject')) {
        return ['state' => 'reject', 'check' => [['Auth-Type', ':=', 'Reject']], 'reply' => []];
    }

    $pw = (string)$c['pppoe_pass'];
    $check = $rc['password_storage'] === 'nt'
        ? [['NT-Password', ':=', radius_nt_hash($pw)]]
        : [['Cleartext-Password', ':=', $pw]];

    $reply = [];
    $ip = trim((string)$c['ip']);
    if ($ip !== '') {
        $reply[] = ['Framed-IP-Address', ':=', $ip];
    } elseif (trim($rc['framed_pool']) !== '') {
        $reply[] = ['Framed-Pool', ':=', trim($rc['framed_pool'])];
    }
    $prof = trim((string)($c['pppoe_profile'] ?? ''));
    if ($prof !== '') {
        $reply[] = ['Mikrotik-Group', ':=', $prof];
    }

    $state = 'accept';
    $rate = radius_rate_limit($c, $program, (string)$rc['rate_limit_template']);
    if (in_array($status, ['docasne', 'neplatic'], true)) {
        $state = 'restrict';
        if (trim($rc['restrict_rate']) !== '') {
            $rate = trim($rc['restrict_rate']);
        }
        $list = $blockLists[$status] ?? '';
        if ($list !== '') {
            $reply[] = ['Mikrotik-Address-List', ':=', $list];
        }
        if (trim($rc['restrict_filter_id']) !== '') {
            $reply[] = ['Filter-Id', ':=', trim($rc['restrict_filter_id'])];
        }
    }
    if ($rate !== '') {
        $reply[] = ['Mikrotik-Rate-Limit', ':=', $rate];
    }
    if (!empty($rc['accounting']) && (int)$rc['interim_interval'] > 0) {
        $reply[] = ['Acct-Interim-Interval', ':=', (string)(int)$rc['interim_interval']];
    }
    return ['state' => $state, 'check' => $check, 'reply' => $reply];
}

/** Aktualne riadky pouzivatela v radcheck/radreply. */
function radius_read_rows(string $username): array
{
    $out = ['check' => [], 'reply' => []];
    foreach (['check' => 'radcheck', 'reply' => 'radreply'] as $k => $tbl) {
        $st = db()->prepare("SELECT attribute, op, value FROM $tbl WHERE username = ? ORDER BY id");
        $st->execute([$username]);
        foreach ($st->fetchAll() as $r) {
            $out[$k][] = [$r['attribute'], $r['op'], $r['value']];
        }
    }
    return $out;
}

/** Prepise riadky pouzivatela (v transakcii - FreeRADIUS nikdy nevidi polovicny stav). */
function radius_write_rows(string $username, array $rows): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach (['check' => 'radcheck', 'reply' => 'radreply'] as $k => $tbl) {
            $pdo->prepare("DELETE FROM $tbl WHERE username = ?")->execute([$username]);
            $ins = $pdo->prepare("INSERT INTO $tbl (username, attribute, op, value) VALUES (?,?,?,?)");
            foreach ($rows[$k] as [$a, $op, $v]) {
                $ins->execute([$username, $a, $op, $v]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Zmaze vsetky RADIUS riadky pouzivatela. Vrati true, ak nieco zmazal. */
function radius_delete_user(string $username): bool
{
    if ($username === '' || !radius_available()) return false;
    $n = 0;
    foreach (['radcheck', 'radreply', 'radusergroup'] as $tbl) {
        $st = db()->prepare("DELETE FROM $tbl WHERE username = ?");
        $st->execute([$username]);
        $n += $st->rowCount();
    }
    return $n > 0;
}

/**
 * Pouziva login iny (nezmazany) PPPoE zakaznik na routeri s RADIUS?
 * RADIUS login je globalny (radcheck nema router), preto musi byt unikatny.
 */
function radius_username_taken(string $username, int $exceptCustomerId): bool
{
    if ($username === '') return false;
    $st = db()->prepare("SELECT c.id FROM customers c JOIN routers r ON r.id = c.router_id
        WHERE c.pppoe_user = ? AND c.id <> ? AND c.conn_type = 'pppoe' AND c.deleted_at IS NULL
          AND r.pppoe_radius = 1 LIMIT 1");
    $st->execute([$username, $exceptCustomerId]);
    return (bool)$st->fetchColumn();
}

/** Hodnota atributu z riadkov (prvy vyskyt). */
function radius_attr(array $rows, string $attr): ?string
{
    foreach ($rows as [$a, , $v]) {
        if ($a === $attr) return $v;
    }
    return null;
}

/** Otvorene relacie pouzivatela (z radacct). */
function radius_open_sessions(string $username): array
{
    if ($username === '') return [];
    $st = db()->prepare('SELECT acctsessionid, nasipaddress, framedipaddress, acctstarttime
        FROM radacct WHERE username = ? AND acctstoptime IS NULL ORDER BY acctstarttime DESC LIMIT 5');
    $st->execute([$username]);
    return $st->fetchAll();
}

/** Posledne relacie pre prehlad v UI. */
function radius_sessions(string $username, int $limit = 20): array
{
    if ($username === '' || !radius_available()) return [];
    try {
        $st = db()->prepare('SELECT acctstarttime, acctstoptime, acctupdatetime, acctsessiontime, nasipaddress,
                framedipaddress, callingstationid, acctinputoctets, acctoutputoctets, acctterminatecause
            FROM radacct WHERE username = ? ORDER BY acctstarttime DESC, radacctid DESC LIMIT ' . max(1, $limit));
        $st->execute([$username]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Export hook: PPPoE relacie (radacct), ktore sa prekryvaju s obdobim [$from, $to),
 * doplnene o cislo zmluvy zakaznika. Zaklad pre buduci reporting (data retention) -
 * format a rozsah poli sa doplnia, az bude specifikacia. Vracia generator riadkov.
 */
function radius_sessions_export(string $from, string $to): Generator
{
    $st = db()->prepare("SELECT a.radacctid, a.username, c.id AS customer_id, c.contract_no,
            a.nasipaddress, a.framedipaddress, a.callingstationid, a.acctsessionid,
            a.acctstarttime, a.acctstoptime, a.acctsessiontime,
            a.acctinputoctets, a.acctoutputoctets, a.acctterminatecause
        FROM radacct a
        LEFT JOIN customers c ON c.pppoe_user = a.username AND c.conn_type = 'pppoe' AND c.deleted_at IS NULL
        WHERE a.acctstarttime < ? AND (a.acctstoptime IS NULL OR a.acctstoptime >= ?)
        ORDER BY a.acctstarttime, a.radacctid");
    $st->execute([$to, $from]);
    while ($row = $st->fetch()) {
        yield $row;
    }
}

/** Posledne pokusy o prihlasenie (radpostauth) - pomoc pri "neprihlasi sa". */
function radius_postauth(string $username, int $limit = 5): array
{
    if ($username === '' || !radius_available()) return [];
    try {
        $st = db()->prepare('SELECT reply, authdate FROM radpostauth WHERE username = ? ORDER BY id DESC LIMIT ' . max(1, $limit));
        $st->execute([$username]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Shared secret NAS-u podla nasname (z tabulky nas). */
function radius_nas_secret(string $nasIp): ?string
{
    $st = db()->prepare('SELECT secret FROM nas WHERE nasname = ? LIMIT 1');
    $st->execute([$nasIp]);
    $s = $st->fetchColumn();
    return $s === false ? null : (string)$s;
}

/**
 * Zosynchronizuje riadok v tabulke nas pre router. FreeRADIUS nacitava klientov
 * dynamicky (docker/freeradius/sites/dynamic-clients), takze netreba restart.
 * Riadky appky sa poznaju podla description = 'ispadmin:router:<id>'.
 */
function radius_nas_sync(int $routerId, array $router): void
{
    if (!radius_available()) return;
    $pdo = db();
    $tag = 'ispadmin:router:' . $routerId;
    $pdo->prepare('DELETE FROM nas WHERE description = ?')->execute([$tag]);
    if ((int)($router['pppoe_radius'] ?? 0) !== 1 || trim((string)($router['radius_secret'] ?? '')) === '') {
        return;
    }
    $nasIp = radius_nas_ip($router);
    // poistka: ina (rucne pridana) polozka s rovnakou IP by FreeRADIUS mylila
    $pdo->prepare('DELETE FROM nas WHERE nasname = ?')->execute([$nasIp]);
    $pdo->prepare('INSERT INTO nas (nasname, shortname, type, ports, secret, description) VALUES (?,?,?,?,?,?)')
        ->execute([$nasIp, substr(mt_ascii((string)$router['name']), 0, 32), 'other', (int)radius_cfg()['coa']['port'],
                   (string)$router['radius_secret'], $tag]);
}

/** Odstrani nas riadok routera (pri zmazani routera). */
function radius_nas_remove(int $routerId): void
{
    if (!radius_available()) return;
    try {
        db()->prepare('DELETE FROM nas WHERE description = ?')->execute(['ispadmin:router:' . $routerId]);
    } catch (Throwable $e) { /* ticho */ }
}

/* ---------------------------------------------------------------------------
 * RADIUS paket (klient). Len to, co appka potrebuje: Access-Request (PAP)
 * a CoA/Disconnect-Request. Vsetko s Message-Authenticator (BlastRADIUS).
 * ------------------------------------------------------------------------- */

/** Zakoduje jeden atribut. $value moze byt ['vsa', vendor, type, value]. */
function radius_attr_encode(int $type, $value): string
{
    if (is_array($value)) {
        [, $vendor, $vtype, $vval] = $value;
        $sub = chr($vtype) . chr(strlen($vval) + 2) . $vval;
        $data = pack('N', $vendor) . $sub;
        return chr(RAD_ATTR_VSA) . chr(strlen($data) + 2) . $data;
    }
    if (strlen($value) > 253) {
        throw new RuntimeException('RADIUS attribute too long');
    }
    return chr($type) . chr(strlen($value) + 2) . $value;
}

/** IPv4 -> 4 bajty. */
function radius_ip(string $ip): string
{
    $b = @inet_pton($ip);
    if ($b === false || strlen($b) !== 4) {
        throw new RuntimeException('invalid IPv4: ' . $ip);
    }
    return $b;
}

/** Zasifruje User-Password (RFC 2865 5.2). */
function radius_encrypt_password(string $pw, string $secret, string $auth): string
{
    $pad = strlen($pw) === 0 ? 16 : (int)(ceil(strlen($pw) / 16) * 16);
    $pw = str_pad($pw, min(128, $pad), "\0");
    $out = '';
    $prev = $auth;
    for ($i = 0; $i < strlen($pw); $i += 16) {
        $b = md5($secret . $prev, true);
        $c = substr($pw, $i, 16) ^ $b;
        $out .= $c;
        $prev = $c;
    }
    return $out;
}

/**
 * Posle RADIUS poziadavku a pocka na odpoved.
 * $attrs = zoznam [type, value]. Vrati ['ok' => bool, 'code' => int|null, 'attrs' => [[type, raw]], 'error' => string]
 */
function radius_send(string $host, int $port, string $secret, int $code, array $attrs, int $timeout = 2, string $sourceIp = ''): array
{
    $id = random_int(0, 255);
    $isAccess = ($code === RAD_ACCESS_REQUEST);
    $reqAuth = $isAccess ? random_bytes(16) : str_repeat("\0", 16);

    $body = '';
    foreach ($attrs as [$t, $v]) {
        if ($t === RAD_ATTR_USER_PASSWORD) {
            $v = radius_encrypt_password($v, $secret, $reqAuth);
        }
        $body .= radius_attr_encode($t, $v);
    }
    // Message-Authenticator: HMAC-MD5 cez cely paket s MA = 16 nul
    // (pri CoA/Disconnect sa pocita s Request Authenticatorom = 16 nul, RFC 5176 3.3)
    $maOffset = strlen($body) + 2;
    $body .= chr(RAD_ATTR_MSG_AUTH) . chr(18) . str_repeat("\0", 16);
    $len = 20 + strlen($body);
    $hdr = chr($code) . chr($id) . pack('n', $len);
    $ma = hash_hmac('md5', $hdr . $reqAuth . $body, $secret, true);
    $body = substr($body, 0, $maOffset) . $ma . substr($body, $maOffset + 16);
    if (!$isAccess) {
        $reqAuth = md5($hdr . str_repeat("\0", 16) . $body . $secret, true);
    }
    $packet = $hdr . $reqAuth . $body;

    // Nespojeny UDP socket: odpoved sa prijme aj z inej adresy, nez kam isla poziadavka.
    // NAS za NAT (napr. brana maskuje verejne IP inej podsiete) odpoveda z adresy brany;
    // spojeny socket by takuto odpoved zahodil a CoA by koncilo timeoutom. Pravost odpovede
    // aj tak overuje Response Authenticator (MD5 so secretom) nizsie.
    $dst = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
    if (!filter_var($dst, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return ['ok' => false, 'code' => null, 'attrs' => [], 'error' => 'cannot resolve ' . $host];
    }
    $errno = 0; $errstr = '';
    $sock = @stream_socket_server('udp://' . ($sourceIp !== '' ? $sourceIp : '0.0.0.0') . ':0', $errno, $errstr, STREAM_SERVER_BIND);
    if (!$sock) {
        return ['ok' => false, 'code' => null, 'attrs' => [], 'error' => $errstr ?: 'socket error'];
    }

    $resp = '';
    $from = '';
    for ($try = 0; $try < 2 && $resp === ''; $try++) {
        @stream_socket_sendto($sock, $packet, 0, $dst . ':' . $port);
        $deadline = microtime(true) + $timeout;
        while (($left = $deadline - microtime(true)) > 0) {
            $r = [$sock]; $w = null; $e = null;
            if (!@stream_select($r, $w, $e, (int)$left, (int)(($left - (int)$left) * 1e6))) {
                break;               // timeout -> dalsi pokus
            }
            $peer = '';
            $d = @stream_socket_recvfrom($sock, 4096, 0, $peer);
            if (is_string($d) && strlen($d) >= 20 && ord($d[1]) === $id) {
                $resp = $d;
                $from = $peer;
                break;
            }
            // cudzi/oneskoreny paket - ignoruj a cakaj dalej
        }
    }
    fclose($sock);
    if ($resp === '') {
        return ['ok' => false, 'code' => null, 'attrs' => [], 'error' => 'timeout'];
    }

    $rlen = unpack('n', substr($resp, 2, 2))[1];
    if ($rlen < 20 || $rlen > strlen($resp)) {
        return ['ok' => false, 'code' => null, 'attrs' => [], 'error' => 'malformed reply'];
    }
    $resp = substr($resp, 0, $rlen);
    $check = md5(substr($resp, 0, 4) . $reqAuth . substr($resp, 20) . $secret, true);
    if (!hash_equals($check, substr($resp, 4, 16))) {
        return ['ok' => false, 'code' => ord($resp[0]), 'attrs' => [], 'error' => 'bad response authenticator (wrong secret?)'];
    }
    $out = [];
    $p = 20;
    while ($p + 2 <= $rlen) {
        $t = ord($resp[$p]);
        $l = ord($resp[$p + 1]);
        if ($l < 2) break;
        $out[] = [$t, substr($resp, $p + 2, $l - 2)];
        $p += $l;
    }
    $via = ($from !== '' && explode(':', $from)[0] !== $dst) ? explode(':', $from)[0] : '';
    return ['ok' => true, 'code' => ord($resp[0]), 'attrs' => $out, 'error' => '', 'from' => $via];
}

/** Popis odpovede na CoA/Disconnect vratane Error-Cause. */
function radius_reply_text(array $res): string
{
    if (!$res['ok']) return $res['error'];
    $names = [RAD_COA_ACK => 'CoA-ACK', RAD_COA_NAK => 'CoA-NAK', RAD_DISCONNECT_ACK => 'Disconnect-ACK',
              RAD_DISCONNECT_NAK => 'Disconnect-NAK', RAD_ACCESS_ACCEPT => 'Access-Accept', RAD_ACCESS_REJECT => 'Access-Reject'];
    $s = $names[$res['code']] ?? ('code ' . $res['code']);
    if (!empty($res['from'])) {
        $s .= ' via ' . $res['from'];
    }
    foreach ($res['attrs'] as [$t, $v]) {
        if ($t === RAD_ATTR_ERROR_CAUSE && strlen($v) === 4) {
            $s .= ' (Error-Cause ' . unpack('N', $v)[1] . ')';
        }
    }
    return $s;
}

/** Identifikacia relacie pre CoA/PoD (MikroTik paruje podla User-Name + Acct-Session-Id). */
function radius_session_attrs(string $username, array $sess): array
{
    $a = [[RAD_ATTR_USER_NAME, $username]];
    if (($sess['acctsessionid'] ?? '') !== '') {
        $a[] = [RAD_ATTR_ACCT_SESSION_ID, (string)$sess['acctsessionid']];
    }
    if (($sess['framedipaddress'] ?? '') !== '') {
        try { $a[] = [RAD_ATTR_FRAMED_IP, radius_ip((string)$sess['framedipaddress'])]; } catch (Throwable $e) { /* bez IP */ }
    }
    return $a;
}

/**
 * Kam poslat CoA/PoD pre relaciu a s akym secretom. Vrati [host, secret] alebo [null, chyba].
 *
 * NAS-IP-Address v radacct nemusi byt adresa, na ktoru sa da CoA poslat - napr. MikroTik za NAT
 * posiela vlastnu (privatnu) adresu a RADIUS chodi zo zdielanej verejnej. Preto sa CoA posiela
 * na API host routera (ten je z ISPadmin dosiahnutelny vzdy) so secretom routera:
 *   1) router, ktoreho host alebo NAS IP = nasipaddress relacie,
 *   2) inak router zakaznika ($router),
 *   3) inak nasipaddress + secret z tabulky nas.
 */
function radius_coa_target(array $sess, ?array $router): array
{
    $nas = trim((string)($sess['nasipaddress'] ?? ''));
    if ($nas !== '') {
        $st = db()->prepare('SELECT * FROM routers WHERE pppoe_radius = 1 AND (host = ? OR radius_nas_ip = ?) LIMIT 1');
        $st->execute([$nas, $nas]);
        $hit = $st->fetch();
        if ($hit) {
            $router = $hit;
        }
    }
    if ($router && trim((string)($router['radius_secret'] ?? '')) !== '') {
        return [trim((string)$router['host']), (string)$router['radius_secret']];
    }
    $secret = $nas !== '' ? radius_nas_secret($nas) : null;
    if ($secret === null) {
        return [null, 'NAS ' . $nas . ' not in nas table'];
    }
    return [$nas, $secret];
}

/** Disconnect-Request (PoD) pre jednu relaciu. */
function radius_disconnect(string $username, array $sess, ?array $router = null): array
{
    $rc = radius_cfg();
    [$host, $secret] = radius_coa_target($sess, $router);
    if ($host === null) {
        return ['ok' => false, 'code' => null, 'attrs' => [], 'error' => $secret];
    }
    return radius_send($host, (int)$rc['coa']['port'], $secret, RAD_DISCONNECT_REQ,
        radius_session_attrs($username, $sess), (int)$rc['coa']['timeout'], (string)$rc['coa']['source_ip']);
}

/** CoA-Request: znovu aplikuje Mikrotik-Rate-Limit / Address-List / Filter-Id na relacii. */
function radius_coa(string $username, array $sess, array $reply, ?array $router = null): array
{
    $rc = radius_cfg();
    [$host, $secret] = radius_coa_target($sess, $router);
    if ($host === null) {
        return ['ok' => false, 'code' => null, 'attrs' => [], 'error' => $secret];
    }
    $attrs = radius_session_attrs($username, $sess);
    $map = ['Mikrotik-Rate-Limit' => RAD_MT_RATE_LIMIT, 'Mikrotik-Address-List' => RAD_MT_ADDRESS_LIST];
    foreach ($reply as $k => $v) {
        if (isset($map[$k])) {
            $attrs[] = [RAD_ATTR_VSA, ['vsa', RAD_VENDOR_MIKROTIK, $map[$k], $v]];
        } elseif ($k === 'Filter-Id') {
            $attrs[] = [RAD_ATTR_FILTER_ID, $v];
        }
    }
    return radius_send($host, (int)$rc['coa']['port'], $secret, RAD_COA_REQ,
        $attrs, (int)$rc['coa']['timeout'], (string)$rc['coa']['source_ip']);
}

/**
 * Hlavna funkcia: zapise RADIUS riadky PPPoE zakaznika a zmenu premietne do bezaicej relacie.
 * $oldUser = predosly PPPoE login (ak sa zmenil), aby sa stare riadky zmazali a relacia odpojila.
 * Vrati ['state' => ..., 'log' => [kluce prekladu alebo [format, arg...]]]
 */
function radius_apply_customer(array $c, ?array $program, ?string $oldUser = null, ?array $router = null): array
{
    $rc = radius_cfg();
    $user = trim((string)$c['pppoe_user']);
    $log = [];

    $old = radius_read_rows($user);
    $new = radius_desired_rows($c, $program);
    radius_write_rows($user, $new);
    $log[] = ['accept' => 'RADIUS: povolený', 'restrict' => 'RADIUS: obmedzený', 'reject' => 'RADIUS: zamietnutý'][$new['state']];

    // premenovany login: stare riadky prec, jeho relacia sa odpoji
    if ($oldUser !== null && $oldUser !== '' && $oldUser !== $user) {
        radius_delete_user($oldUser);
        foreach (radius_open_sessions($oldUser) as $s) {
            $r = radius_disconnect($oldUser, $s, $router);
            $log[] = $r['ok'] && $r['code'] === RAD_DISCONNECT_ACK
                ? 'relácia odpojená (PoD)' : ['PoD zlyhalo: %s', radius_reply_text($r)];
        }
    }

    // --- zivá relacia ---
    $sessions = radius_open_sessions($user);
    if (!$sessions) {
        return ['state' => $new['state'], 'log' => $log];
    }
    $pick = static function (array $rows): array {
        $o = [];
        foreach ($rows as [$a, , $v]) { $o[$a] = $v; }
        return $o;
    };
    $oldR = $pick($old['reply']);
    $newR = $pick($new['reply']);
    $wasReject = radius_attr($old['check'], 'Auth-Type') === 'Reject';
    // co sa neda zmenit bez noveho prihlasenia (IP, profil, pool) alebo zmena stavu
    $ignore = ['Mikrotik-Rate-Limit' => 1, 'Acct-Interim-Interval' => 1];
    $structural = array_diff_key($oldR, $ignore) != array_diff_key($newR, $ignore);
    $rateChanged = ($oldR['Mikrotik-Rate-Limit'] ?? '') !== ($newR['Mikrotik-Rate-Limit'] ?? '');

    foreach ($sessions as $s) {
        if ($new['state'] === 'reject' || $wasReject) {
            $r = radius_disconnect($user, $s, $router);
            $log[] = $r['ok'] && $r['code'] === RAD_DISCONNECT_ACK ? 'relácia odpojená (PoD)' : ['PoD zlyhalo: %s', radius_reply_text($r)];
        } elseif ($structural) {
            $lists = ['Mikrotik-Address-List' => 1, 'Filter-Id' => 1];
            $onlyLists = array_diff_key($oldR, $ignore + $lists) == array_diff_key($newR, $ignore + $lists);
            // CoA vie zoznam/filter nastavit, ale nie spolahlivo odobrat -> navrat do "pripojeny" ide cez PoD
            $removes = (isset($oldR['Mikrotik-Address-List']) && !isset($newR['Mikrotik-Address-List']))
                    || (isset($oldR['Filter-Id']) && !isset($newR['Filter-Id']));
            if ($rc['coa']['status_method'] === 'coa' && $onlyLists && !$removes) {
                $send = array_intersect_key($newR, ['Mikrotik-Rate-Limit' => 1] + $lists);
                $r = radius_coa($user, $s, $send, $router);
                $log[] = $r['ok'] && $r['code'] === RAD_COA_ACK ? 'zmena naživo (CoA)' : ['CoA zlyhalo: %s', radius_reply_text($r)];
            } else {
                $r = radius_disconnect($user, $s, $router);
                $log[] = $r['ok'] && $r['code'] === RAD_DISCONNECT_ACK ? 'relácia odpojená (PoD)' : ['PoD zlyhalo: %s', radius_reply_text($r)];
            }
        } elseif ($rateChanged && isset($newR['Mikrotik-Rate-Limit'])) {
            $r = radius_coa($user, $s, ['Mikrotik-Rate-Limit' => $newR['Mikrotik-Rate-Limit']], $router);
            $log[] = $r['ok'] && $r['code'] === RAD_COA_ACK ? 'zmena naživo (CoA)' : ['CoA zlyhalo: %s', radius_reply_text($r)];
        } elseif ($rateChanged) {
            // rychlost zrusena uplne -> CoA ju nevie "odobrat", treba nove prihlasenie
            $r = radius_disconnect($user, $s, $router);
            $log[] = $r['ok'] && $r['code'] === RAD_DISCONNECT_ACK ? 'relácia odpojená (PoD)' : ['PoD zlyhalo: %s', radius_reply_text($r)];
        }
    }
    return ['state' => $new['state'], 'log' => $log];
}

/** Odpoji vsetky relacie a zmaze riadky pouzivatela (zmazany zakaznik / zmena na DHCP). */
function radius_remove_customer(string $username, ?array $router = null): array
{
    $log = [];
    if ($username === '' || !radius_available()) return $log;
    try {
        if (radius_delete_user($username)) {
            $log[] = 'RADIUS záznamy zmazané';
        }
        foreach (radius_open_sessions($username) as $s) {
            $r = radius_disconnect($username, $s, $router);
            $log[] = $r['ok'] && $r['code'] === RAD_DISCONNECT_ACK ? 'relácia odpojená (PoD)' : ['PoD zlyhalo: %s', radius_reply_text($r)];
        }
    } catch (Throwable $e) {
        $log[] = ['RADIUS chyba: %s', $e->getMessage()];
    }
    return $log;
}

/**
 * Zakaznik bol natrvalo zmazany: odstran jeho RADIUS riadky - ale len ak login
 * medzicasom nepouziva iny zakaznik (inak by sme mu zmazali pristup).
 */
function radius_forget_customer(array $c): array
{
    $u = trim((string)($c['pppoe_user'] ?? ''));
    if ($u === '' || !radius_available() || radius_username_taken($u, (int)$c['id'])) {
        return [];
    }
    $router = null;
    if (!empty($c['router_id'])) {
        $router = db()->query('SELECT * FROM routers WHERE id = ' . (int)$c['router_id'])->fetch() ?: null;
    }
    return radius_remove_customer($u, $router);
}

/**
 * RADIUS test routera: (1) FreeRADIUS odpoveda a cita DB, (2) router je v tabulke nas,
 * (3) cez API: na MikroTiku je /radius pre ppp, incoming (CoA) a /ppp aaa use-radius.
 * Vrati ['ok' => bool, 'lines' => [[ok(bool), text]]]
 */
function radius_test_router(array $router): array
{
    $rc = radius_cfg();
    $lines = [];

    // 1) FreeRADIUS - neexistujuci pouzivatel musi dostat Access-Reject (= server zije a cita DB)
    if ($rc['local_secret'] === '') {
        $lines[] = [false, t('RADIUS_LOCAL_SECRET nie je nastavený — test servera preskočený.')];
    } else {
        $probe = 'ispadmin-probe-' . radius_random(8);
        $res = radius_send((string)$rc['server'], (int)$rc['auth_port'], (string)$rc['local_secret'], RAD_ACCESS_REQUEST, [
            [RAD_ATTR_USER_NAME, $probe],
            [RAD_ATTR_USER_PASSWORD, radius_random(12)],
            [RAD_ATTR_NAS_IP, radius_ip('127.0.0.1')],
        ], 3);
        $ok = $res['ok'] && $res['code'] === RAD_ACCESS_REJECT;
        $lines[] = [$ok, t('FreeRADIUS %s:%d: %s', $rc['server'], (int)$rc['auth_port'], radius_reply_text($res))];
        if ($ok) {
            $st = db()->prepare('SELECT COUNT(*) FROM radpostauth WHERE username = ?');
            $st->execute([$probe]);
            $logged = (int)$st->fetchColumn() > 0;
            $lines[] = [$logged, $logged ? t('FreeRADIUS zapisuje do databázy (radpostauth).') : t('FreeRADIUS nezapísal pokus do radpostauth — skontroluj modul sql.')];
            db()->prepare('DELETE FROM radpostauth WHERE username = ?')->execute([$probe]);
        }
    }

    // 2) nas tabulka
    $nasIp = radius_nas_ip($router);
    $secret = radius_nas_secret($nasIp);
    $lines[] = [$secret !== null, $secret !== null
        ? t('NAS %s je v tabuľke nas.', $nasIp)
        : t('NAS %s chýba v tabuľke nas — ulož router so zapnutým RADIUS.', $nasIp)];

    // 3) strana MikroTiku cez API (len citanie)
    [$api, $err] = mt_connect($router);
    if (!$api) {
        $lines[] = [false, t('API: %s', $err)];
    } else {
        $rad = $api->comm('/radius/print');
        $found = null;
        foreach ($rad['items'] ?? [] as $it) {
            if (strpos((string)($it['service'] ?? ''), 'ppp') !== false && ($it['disabled'] ?? 'false') !== 'true') {
                $found = $it;
                break;
            }
        }
        if (!$found) {
            $lines[] = [false, t('MikroTik: chýba /radius so service=ppp.')];
        } else {
            $lines[] = [true, t('MikroTik: /radius ppp → %s', (string)($found['address'] ?? '?'))];
            if (isset($found['secret']) && $secret !== null && $found['secret'] !== $secret) {
                $lines[] = [false, t('MikroTik: secret v /radius sa nezhoduje s ISPadmin.')];
            }
        }
        $inc = $api->comm('/radius/incoming/print');
        $acc = (string)($inc['items'][0]['accept'] ?? '');
        $lines[] = [$acc === 'true', $acc === 'true' ? t('MikroTik: /radius incoming accept=yes (CoA).') : t('MikroTik: /radius incoming accept=no — CoA/odpojenie nebude fungovať.')];
        $aaa = $api->comm('/ppp/aaa/print');
        $use = (string)($aaa['items'][0]['use-radius'] ?? '');
        $lines[] = [$use === 'true', $use === 'true' ? t('MikroTik: /ppp aaa use-radius=yes.') : t('MikroTik: /ppp aaa use-radius=no.')];
        $api->disconnect();
    }

    $allOk = true;
    foreach ($lines as [$ok]) { $allOk = $allOk && $ok; }
    return ['ok' => $allOk, 'lines' => $lines];
}

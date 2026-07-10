<?php
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/mikrotik.php';
require_login();
$pdo = db();
$user = current_user();

// zoznam sietí sa skladá z existujúcich hodnôt v DB (zákazníci + routre)
$siete = [];
foreach ($pdo->query("SELECT DISTINCT siet FROM customers WHERE siet <> '' UNION SELECT DISTINCT siet FROM routers WHERE siet <> ''") as $r) {
    if (!in_array($r['siet'], $siete, true)) $siete[] = $r['siet'];
}
sort($siete);
$zariadenia = ['Router', 'Bridge', 'AP'];
// kbit -> Mbps pre zobrazenie v inpute (bez zbytocnych nul)
$mb = static fn(int $k) => $k > 0 ? rtrim(rtrim(sprintf('%.3f', $k / 1024), '0'), '.') : '';
$statuses = [
    'pripojeny' => 'Pripojený',
    'docasne'   => 'Dočasne odpojený',
    'neplatic'  => 'Odpojený neplatič',
    'ukoncena'  => 'Ukončená zmluva',
];

// ---------- POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        flash('err', t('Neplatná relácia.'));
        header('Location: index.php');
        exit;
    }
    $action = $_POST['action'] ?? 'save';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'delete' && $id) {
        $cust = $pdo->query('SELECT * FROM customers WHERE id = ' . $id)->fetch();
        if (!$cust) {
            flash('err', t('Zákazník neexistuje.'));
            header('Location: index.php');
            exit;
        }
        $hadRouter = !empty($cust['router_id']);

        if (current_level() >= 2) {
            // admin / administrator -> zmazanie natvrdo hned
            $res = ['ok' => true];
            if ($hadRouter) {
                $pdo->prepare('UPDATE customers SET status = ? WHERE id = ?')->execute(['ukoncena', $id]);
                $res = mt_apply_customer($id);
            }
            $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
            log_change($id, (string)$cust['contract_no'], (string)$user, 'zmazany');
            if ($hadRouter && !$res['ok']) {
                flash('err', t('Zákazník zmazaný, ale na MikroTiku sa nepodarilo zrušiť: %s', $res['msg']));
            } else {
                flash('ok', t('Zákazník zmazaný.'));
            }
        } else {
            // user -> presun do kosa (30 dni), sluzba na MikroTiku sa zrusi
            $pdo->prepare('UPDATE customers SET prev_status = status, status = ?, deleted_at = ?, deleted_by = ? WHERE id = ?')
                ->execute(['ukoncena', date('Y-m-d H:i:s'), (string)$user, $id]);
            if ($hadRouter) {
                mt_apply_customer($id); // odstrani lease/queue (status je teraz ukoncena)
            }
            log_change($id, (string)$cust['contract_no'], (string)$user, 'do koša');
            flash('ok', t('Zákazník presunutý do koša. Automaticky sa zmaže o 30 dní, dovtedy ho admin môže obnoviť.'));
        }
        header('Location: index.php');
        exit;
    }

    // save (insert/update)
    $f = static fn(string $k) => trim($_POST[$k] ?? '');
    $data = [
        'contract_no' => $f('contract_no'),
        'status'      => array_key_exists($f('status'), $statuses) ? $f('status') : 'pripojeny',
        'meno'        => $f('meno'),
        'priezvisko'  => $f('priezvisko'),
        'firma'       => $f('firma'),
        'ulica'       => $f('ulica'),
        'cislo_domu'  => $f('cislo_domu'),
        'vchod'       => $f('vchod'),
        'poschodie'   => $f('poschodie'),
        'mesto'       => $f('mesto'),
        'telefon'     => $f('telefon'),
        'mail'        => $f('mail'),
        'router_id'   => (int)$f('router_id') ?: null,
        'network_id'  => (int)$f('network_id') ?: null,
        'siet'        => $f('siet'),
        'ip'          => $f('ip'),
        'mac'         => strtoupper($f('mac')),
        'conn_type'   => $f('conn_type') === 'pppoe' ? 'pppoe' : 'dhcp',
        'pppoe_user'  => $f('pppoe_user'),
        'pppoe_pass'  => $f('pppoe_pass'),
        'pppoe_profile' => $f('pppoe_profile'),
        'program_id'  => (int)$f('program_id') ?: null,
        'real_ul_kbit'=> (float)$f('real_ul') > 0 ? (int)round((float)$f('real_ul') * 1024) : 0,
        'real_dl_kbit'=> (float)$f('real_dl') > 0 ? (int)round((float)$f('real_dl') * 1024) : 0,
        'zariadenie'  => $f('zariadenie'),
        'poznamka'    => $f('poznamka'),
        'updated_at'  => date('Y-m-d H:i:s'),
        'updated_by'  => (string)$user,
    ];

    $isNew = ($id === 0);

    // poistka: rovnaka IP na tom istom MikroTiku nesmie patrit dvom zakaznikom
    if (!empty($data['router_id']) && $data['ip'] !== '') {
        $dup = $pdo->prepare(
            'SELECT id, contract_no, meno, priezvisko, firma FROM customers
             WHERE router_id = ? AND ip = ? AND id <> ? AND deleted_at IS NULL LIMIT 1'
        );
        $dup->execute([$data['router_id'], $data['ip'], $id]);
        if ($other = $dup->fetch()) {
            $kto = $other['firma'] !== '' ? $other['firma']
                 : trim($other['priezvisko'] . ' ' . $other['meno']);
            $kto = $kto !== '' ? $kto : t('zákazník #%d', $other['id']);
            $rname = $pdo->query('SELECT name FROM routers WHERE id = ' . (int)$data['router_id'])->fetchColumn();
            flash('err', t('IP %s je na MikroTiku „%s“ už obsadená zákazníkom: %s. Uloženie zamietnuté — zvoľ inú IP alebo iný MikroTik.', $data['ip'], $rname, $kto));
            header('Location: customer.php' . ($id ? '?id=' . $id : ''));
            exit;
        }
    }

    if ($id) {
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($data)));
        $st = $pdo->prepare("UPDATE customers SET $set WHERE id = :id");
        $st->execute($data + ['id' => $id]);
    } else {
        $cols = implode(', ', array_keys($data));
        $ph = implode(', ', array_map(fn($k) => ":$k", array_keys($data)));
        $st = $pdo->prepare("INSERT INTO customers ($cols) VALUES ($ph)");
        $st->execute($data);
        $id = (int)$pdo->lastInsertId();
    }

    // aplikuj na MikroTik (ak je priradena siet) a zapis detail do historie
    $stavLbl = $statuses[$data['status']] ?? $data['status'];
    $pref = ($isNew ? 'Pridaný' : 'Zmena') . ' · ' . $stavLbl;
    if (empty($data['router_id'])) {
        log_change($id, $data['contract_no'], (string)$user, $pref . ' (bez MikroTiku)');
        flash('info', t('Uložené. (Bez priradenej MikroTik siete sa na zariadenie nič neaplikovalo.)'));
    } else {
        $res = mt_apply_customer($id);
        if ($res['ok']) {
            log_change($id, $data['contract_no'], (string)$user, $pref . ' — ' . $res['msg']);
            flash('ok', t('Uložené a aplikované na MikroTik: %s', $res['msg']));
        } else {
            log_change($id, $data['contract_no'], (string)$user, $pref . ' — MikroTik ZLYHALO: ' . $res['msg']);
            flash('err', t('Uložené, ale aplikácia na MikroTik zlyhala: %s', $res['msg']));
        }
    }
    header('Location: customer.php?id=' . $id);
    exit;
}

// ---------- GET ----------
$id = (int)($_GET['id'] ?? 0);
$c = [
    'contract_no' => '', 'status' => 'pripojeny', 'meno' => '', 'priezvisko' => '', 'firma' => '',
    'ulica' => '', 'cislo_domu' => '', 'vchod' => '', 'poschodie' => '', 'mesto' => '',
    'telefon' => '', 'mail' => '', 'router_id' => '', 'network_id' => '', 'siet' => '', 'ip' => '', 'mac' => '',
    'conn_type' => 'dhcp', 'pppoe_user' => '', 'pppoe_pass' => '', 'pppoe_profile' => '',
    'program_id' => '', 'real_ul_kbit' => 0, 'real_dl_kbit' => 0, 'zariadenie' => 'Router', 'poznamka' => '',
];
if ($id) {
    $row = $pdo->query('SELECT * FROM customers WHERE id = ' . $id)->fetch();
    if ($row) {
        $c = $row;
    } else {
        $id = 0;
    }
}

$routers  = $pdo->query('SELECT id, name FROM routers WHERE active = 1 ORDER BY name')->fetchAll();
$programs = $pdo->query('SELECT id, name, ul_user, dl_user FROM programs WHERE active = 1 ORDER BY id')->fetchAll();
$networks = $pdo->query('SELECT id, router_id, name, subnet FROM networks WHERE active = 1 ORDER BY router_id, name')->fetchAll();

$historia = [];
if ($id) {
    $hs = $pdo->prepare('SELECT who, action, created_at FROM change_log WHERE customer_id = ? ORDER BY id DESC LIMIT 50');
    $hs->execute([$id]);
    $historia = $hs->fetchAll();
}

layout_header('customer.php');
render_flash();

function sel($a, $b): string { return (string)$a === (string)$b ? ' selected' : ''; }
?>
<form method="post" action="customer.php">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="id" value="<?= (int)$id ?>">

  <fieldset class="panel">
    <legend><?= t('Stav pripojenia') ?></legend>
    <div class="statusrow">
      <?php foreach ($statuses as $key => $lbl): ?>
        <label class="statuscard s-<?= $key ?>">
          <input type="radio" name="status" value="<?= $key ?>"<?= $c['status'] === $key ? ' checked' : '' ?>>
          <span><?= t($lbl) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
  </fieldset>

  <fieldset class="panel">
    <legend><?= t('Osoba / Firma') ?></legend>
    <div class="grid">
      <div class="cell olive" style="max-width:260px">
        <label><?= t('Číslo zmluvy') ?></label>
        <input type="text" name="contract_no" value="<?= h($c['contract_no']) ?>">
      </div>
    </div>
    <div class="grid g4">
      <div class="cell"><label><?= t('Meno') ?></label><input name="meno" value="<?= h($c['meno']) ?>"></div>
      <div class="cell"><label><?= t('Priezvisko') ?></label><input name="priezvisko" value="<?= h($c['priezvisko']) ?>"></div>
      <div class="cell"><label><?= t('Firma') ?></label><input name="firma" value="<?= h($c['firma']) ?>"></div>
      <div class="cell"><label><?= t('Ulica') ?></label><input name="ulica" value="<?= h($c['ulica']) ?>"></div>
    </div>
    <div class="grid g4">
      <div class="cell"><label><?= t('Číslo domu') ?></label><input name="cislo_domu" value="<?= h($c['cislo_domu']) ?>"></div>
      <div class="cell"><label><?= t('Vchod') ?></label><input name="vchod" value="<?= h($c['vchod']) ?>"></div>
      <div class="cell"><label><?= t('Poschodie') ?></label><input name="poschodie" value="<?= h($c['poschodie']) ?>"></div>
      <div class="cell"><label><?= t('Mesto') ?></label><input name="mesto" value="<?= h($c['mesto']) ?>"></div>
    </div>
    <div class="grid g2">
      <div class="cell"><label><?= t('Telefón') ?></label><input name="telefon" value="<?= h($c['telefon']) ?>"></div>
      <div class="cell"><label><?= t('Mail') ?></label><input name="mail" value="<?= h($c['mail']) ?>"></div>
    </div>
  </fieldset>

  <fieldset class="panel">
    <legend><?= t('Technické údaje') ?></legend>
    <div class="grid g4">
      <div class="cell">
        <label><?= t('Typ pripojenia') ?></label>
        <select name="conn_type" id="connType" onchange="toggleConn()">
          <option value="dhcp"<?= sel('dhcp', $c['conn_type'] ?? 'dhcp') ?>><?= t('DHCP / statická (IP + MAC)') ?></option>
          <option value="pppoe"<?= sel('pppoe', $c['conn_type'] ?? 'dhcp') ?>><?= t('PPPoE (login + heslo)') ?></option>
        </select>
      </div>
      <div class="cell pppoe-only">
        <label><?= t('PPPoE login') ?></label>
        <input name="pppoe_user" value="<?= h($c['pppoe_user'] ?? '') ?>" placeholder="<?= h(t('napr. novak')) ?>">
      </div>
      <div class="cell pppoe-only">
        <label><?= t('PPPoE heslo') ?></label>
        <input name="pppoe_pass" value="<?= h($c['pppoe_pass'] ?? '') ?>" placeholder="<?= h(t('heslo')) ?>">
      </div>
      <div class="cell pppoe-only">
        <label><?= t('PPPoE profil') ?> <span class="muted"><?= t('(rýchlosť)') ?></span></label>
        <input name="pppoe_profile" value="<?= h($c['pppoe_profile'] ?? '') ?>" placeholder="<?= h(t('napr. 80Mbps')) ?>">
      </div>
    </div>
    <div class="grid g4">
      <div class="cell">
        <label><?= t('MikroTik (router)') ?></label>
        <select name="router_id" id="routerSel" onchange="filterNets()">
          <option value=""><?= t('— vyber —') ?></option>
          <?php foreach ($routers as $r): ?>
            <option value="<?= (int)$r['id'] ?>"<?= sel($r['id'], $c['router_id']) ?>><?= h($r['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="cell">
        <label><?= t('Sieť / skupina (nadradená fronta)') ?></label>
        <select name="network_id" id="netSel" onchange="setIpPrefix()">
          <option value="" data-router="" data-prefix=""><?= t('— žiadna —') ?></option>
          <?php foreach ($networks as $n): ?>
            <?php $pfx = preg_match('/^(\d+\.\d+\.\d+)\./', (string)$n['subnet'], $m) ? $m[1] . '.' : ''; ?>
            <option value="<?= (int)$n['id'] ?>" data-router="<?= (int)$n['router_id'] ?>" data-prefix="<?= h($pfx) ?>"<?= sel($n['id'], $c['network_id']) ?>><?= h($n['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="cell"><label>IP</label><input name="ip" value="<?= h($c['ip']) ?>" placeholder="172.16.0.0"></div>
      <div class="cell"><label>MAC</label><input name="mac" value="<?= h($c['mac']) ?>" placeholder="AA:BB:CC:DD:EE:FF"></div>
    </div>
    <div class="grid g4">
      <div class="cell">
        <label><?= t('Program') ?></label>
        <select name="program_id" id="progSel" onchange="fillSpeed()">
          <option value=""><?= t('— vyber —') ?></option>
          <?php foreach ($programs as $p): ?>
            <option value="<?= (int)$p['id'] ?>" data-up="<?= (int)$p['ul_user'] ?>" data-down="<?= (int)$p['dl_user'] ?>"<?= sel($p['id'], $c['program_id']) ?>><?= h($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="cell"><label><?= t('Reálna rýchlosť Up (Mbps)') ?></label><input name="real_ul" value="<?= $mb((int)($c['real_ul_kbit'] ?? 0)) ?>" placeholder="<?= h(t('napr. 5')) ?>"></div>
      <div class="cell"><label><?= t('Reálna rýchlosť Down (Mbps)') ?></label><input name="real_dl" value="<?= $mb((int)($c['real_dl_kbit'] ?? 0)) ?>" placeholder="<?= h(t('napr. 20')) ?>"></div>
      <div class="cell">
        <label><?= t('Zariadenie') ?></label>
        <select name="zariadenie">
          <?php foreach ($zariadenia as $z): ?>
            <option value="<?= h($z) ?>"<?= sel($z, $c['zariadenie']) ?>><?= h(t($z)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="grid g2">
      <div class="cell">
        <label><?= t('Sieť / Typ (obec)') ?></label>
        <select name="siet">
          <option value="">—</option>
          <?php $sopts = $siete; if (($c['siet'] ?? '') !== '' && !in_array($c['siet'], $sopts, true)) $sopts[] = $c['siet']; ?>
          <?php foreach ($sopts as $s): ?>
            <option value="<?= h($s) ?>"<?= sel($s, $c['siet']) ?>><?= h($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="cell"><label><?= t('Poznámka') ?></label><input name="poznamka" value="<?= h($c['poznamka']) ?>"></div>
    </div>
    <p class="hint"><?= t('Po výbere programu sa Reálna rýchlosť Up/Down prepíše na rýchlosť balíka — môžeš ju potom ručne doladiť. Reálna rýchlosť má prednosť pred programom (program je len „rola"); prázdne polia → použije sa rýchlosť programu. Nadradená fronta sa berie zo siete, inak z routera.') ?></p>
  </fieldset>

  <div class="btns">
    <button class="btn" type="submit"><?= t('Uložiť a aplikovať') ?></button>
    <a class="btn gray" href="index.php"><?= t('Návrat') ?></a>
    <?php if ($id): ?>
      <button class="btn red" type="submit" name="action" value="delete"
              onclick="return confirm('<?= h(t('Zmazať zákazníka a zrušiť na routeri?')) ?>')"><?= t('Zmazať') ?></button>
    <?php endif; ?>
  </div>
</form>
<div class="applywarn">
  <div class="applywarn-head">⚠ <?= t('Pozor — „Uložiť a aplikovať“ sa naživo zapíše do MikroTiku') ?></div>
  <p><?= t('Po uložení appka rovno zasiahne do vybraného MikroTiku podľa zvoleného stavu a programu:') ?></p>
  <ul>
    <li><?= t('<b>Pripojený</b> — vytvorí/zapne DHCP lease (IP + MAC) a Simple Queue s rýchlosťou programu, vyradí IP z blokovacieho zoznamu.') ?></li>
    <li><?= t('<b>Dočasne odpojený / Odpojený neplatič</b> — fronta ostane na normálnej rýchlosti, IP sa pridá do firewall address-listu podľa stavu (dočasne → docasne_odpojeni, neplatič → neplatici). Blok zabezpečí drop pravidlo na MikroTiku. Prehľad a uvoľnenie v sekcii Blokovaní.') ?></li>
    <li><?= t('<b>Ukončená zmluva</b> — DHCP lease aj Simple Queue z MikroTiku <b>odstráni</b> a IP pridá do address-listu výpovedí (vypovede), takže ostane blokovaná aj bez fronty.') ?></li>
  </ul>
  <p><?= t('Zmena programu prepíše rýchlosť (max-limit) na fronte. Zmena IP/MAC sa premietne do lease aj fronty. Ak má router zapnuté „Spravovať ARP", appka pri Pripojený/Dočasne/Neplatič pridá aj statický ARP záznam (IP+MAC) a pri Ukončenej ho zmaže — pre reply-only siete je to nutné, inak zákazník nepôjde (treba vyplnené MAC).') ?></p>
  <p class="applywarn-imp"><?= t('Existujúce fronty appka prevezme podľa target IP (aj keď majú na MikroTiku „ľudský“ názov) — neprepíše názov a nevytvorí duplikát, len upraví rýchlosť/stav. To isté platí pre DHCP lease (podľa MAC, inak podľa IP). Čo sa stalo, nájdeš nižšie v Histórii.') ?></p>
</div>
<?php if ($id): ?>
<div class="panel-title"><?= t('História') ?></div>
<div class="tablewrap">
<table class="compact hist">
  <tr><th><?= t('Dátum') ?></th><th><?= t('Kto') ?></th><th><?= t('Udalosť') ?></th></tr>
  <?php if (!$historia): ?>
    <tr><td colspan="3" class="muted"><?= t('Zatiaľ žiadne záznamy.') ?></td></tr>
  <?php endif; ?>
  <?php foreach ($historia as $h): ?>
  <tr>
    <td class="nowrap"><?= h($h['created_at']) ?></td>
    <td><?= h($h['who']) ?></td>
    <td class="hist-act"><?= h($h['action']) ?></td>
  </tr>
  <?php endforeach; ?>
</table>
</div>
<?php endif; ?>
<script>
function filterNets(){
  var rs=document.getElementById('routerSel'), ns=document.getElementById('netSel');
  if(!rs||!ns) return;
  var rid=rs.value, cur=ns.value, ok=false;
  for(var i=0;i<ns.options.length;i++){
    var o=ns.options[i], dr=o.getAttribute('data-router')||'';
    var show=(dr===''||dr===rid);
    o.hidden=!show; o.disabled=!show;
    if(show && o.value===cur) ok=true;
  }
  if(!ok) ns.value='';
}
// po vybere programu prepise realnu rychlost Up/Down na rychlost balika (kbit -> Mbps)
function fillSpeed(){
  var ps=document.getElementById('progSel');
  if(!ps) return;
  var o=ps.options[ps.selectedIndex];
  if(!o || !o.value) return;
  var up=parseInt(o.getAttribute('data-up')||'0',10), dn=parseInt(o.getAttribute('data-down')||'0',10);
  var fu=document.querySelector('input[name=real_ul]'), fd=document.querySelector('input[name=real_dl]');
  var mb=function(k){ if(k<=0) return ''; var v=k/1024; return (Math.round(v*1000)/1000).toString(); };
  if(fu) fu.value=mb(up);
  if(fd) fd.value=mb(dn);
}
// po vybere siete/skupiny predvyplni IP prefix (prve 3 oktety), posledny oktet zachova
function setIpPrefix(){
  var ns=document.getElementById('netSel');
  if(!ns) return;
  var o=ns.options[ns.selectedIndex];
  if(!o) return;
  var pfx=o.getAttribute('data-prefix')||'';
  if(!pfx) return;
  var ip=document.querySelector('input[name=ip]');
  if(!ip) return;
  var cur=(ip.value||'').trim();
  var last='';
  var parts=cur.split('.');
  if(parts.length===4 && parts[3]!=='') last=parts[3];
  ip.value=pfx+last;
}
// prepinanie PPPoE poli podla typu pripojenia
function toggleConn(){
  var t=document.getElementById('connType');
  var pppoe=(t && t.value==='pppoe');
  var els=document.querySelectorAll('.pppoe-only');
  for(var i=0;i<els.length;i++) els[i].style.display = pppoe ? '' : 'none';
}
toggleConn();
filterNets();
</script>
<?php layout_footer();

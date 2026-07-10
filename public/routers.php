<?php
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/mikrotik.php';
require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { flash('err', t('Neplatná relácia.')); header('Location: routers.php'); exit; }
    $action = $_POST['action'] ?? 'save';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'delete' && $id) {
        $pdo->prepare('DELETE FROM routers WHERE id = ?')->execute([$id]);
        flash('ok', t('Router zmazaný.'));
        header('Location: routers.php');
        exit;
    }
    if ($action === 'test' && $id) {
        $r = $pdo->query('SELECT * FROM routers WHERE id = ' . $id)->fetch();
        $res = $r ? mt_test_router($r) : ['ok' => false, 'msg' => t('router neexistuje')];
        flash($res['ok'] ? 'ok' : 'err', t('Test %s: %s', $r['name'] ?? '', $res['msg']));
        header('Location: routers.php');
        exit;
    }

    $d = [
        'name'        => trim($_POST['name'] ?? ''),
        'host'        => trim($_POST['host'] ?? ''),
        'api_port'    => (int)($_POST['api_port'] ?? 8728),
        'use_ssl'     => ((int)($_POST['use_ssl'] ?? 0) === 1) ? 1 : 0,
        'api_user'    => trim($_POST['api_user'] ?? ''),
        'api_pass'    => (string)($_POST['api_pass'] ?? ''),
        'dhcp_server' => trim($_POST['dhcp_server'] ?? ''),
        'parent_queue'=> trim($_POST['parent_queue'] ?? ''),
        'manage_arp'  => ((int)($_POST['manage_arp'] ?? 0) === 1) ? 1 : 0,
        'arp_interface' => trim($_POST['arp_interface'] ?? ''),
        'siet'        => trim($_POST['siet'] ?? ''),
        'ulica'       => trim($_POST['ulica'] ?? ''),
        'cislo_domu'  => trim($_POST['cislo_domu'] ?? ''),
        'mesto'       => trim($_POST['mesto'] ?? ''),
        'vchod'       => trim($_POST['vchod'] ?? ''),
        'poschodie'   => trim($_POST['poschodie'] ?? ''),
        'active'      => isset($_POST['active']) ? 1 : 0,
    ];
    // ak je heslo prazdne pri edite, ponechaj povodne
    if ($id && $d['api_pass'] === '') {
        $old = $pdo->query('SELECT api_pass FROM routers WHERE id = ' . $id)->fetchColumn();
        $d['api_pass'] = (string)$old;
    }
    if ($id) {
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($d)));
        $pdo->prepare("UPDATE routers SET $set WHERE id = :id")->execute($d + ['id' => $id]);
    } else {
        $cols = implode(', ', array_keys($d));
        $ph = implode(', ', array_map(fn($k) => ":$k", array_keys($d)));
        $pdo->prepare("INSERT INTO routers ($cols) VALUES ($ph)")->execute($d);
    }
    flash('ok', t('Router uložený.'));
    header('Location: routers.php');
    exit;
}

$editId = (int)($_GET['edit'] ?? 0);
$e = ['id' => 0, 'name' => '', 'host' => '', 'api_port' => 8728, 'use_ssl' => 0,
      'api_user' => '', 'api_pass' => '', 'dhcp_server' => '', 'parent_queue' => '', 'siet' => '',
      'manage_arp' => 0, 'arp_interface' => '',
      'ulica' => '', 'cislo_domu' => '', 'mesto' => '', 'vchod' => '', 'poschodie' => '', 'active' => 1];
if ($editId) {
    $row = $pdo->query('SELECT * FROM routers WHERE id = ' . $editId)->fetch();
    if ($row) $e = $row;
}
$list = $pdo->query('SELECT * FROM routers ORDER BY name')->fetchAll();

layout_header('routers.php');
render_flash();
?>
<fieldset class="panel">
  <legend><?= $e['id'] ? t('Upraviť router #%d', (int)$e['id']) : t('Nový router') ?></legend>
  <form method="post" action="routers.php">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
    <input type="hidden" name="action" value="save">
    <div class="grid g3">
      <div class="cell"><label><?= t('Názov (site)') ?></label><input name="name" value="<?= h($e['name']) ?>" placeholder="<?= h(t('Partizánske')) ?>"></div>
      <div class="cell"><label><?= t('Host / IP') ?></label><input name="host" value="<?= h($e['host']) ?>" placeholder="10.0.0.1"></div>
      <div class="cell"><label><?= t('API port') ?></label><input type="number" name="api_port" value="<?= (int)$e['api_port'] ?>"></div>
    </div>
    <div class="grid g4">
      <div class="cell"><label><?= t('API user') ?></label><input name="api_user" value="<?= h($e['api_user']) ?>"></div>
      <div class="cell"><label><?= t('API heslo') ?></label><input type="password" name="api_pass" placeholder="<?= $e['id'] ? h(t('(nemeniť)')) : '' ?>"></div>
      <div class="cell"><label><?= t('DHCP server') ?></label><input name="dhcp_server" value="<?= h($e['dhcp_server']) ?>" placeholder="<?= h(t('(voliteľné)')) ?>"></div>
      <div class="cell"><label><?= t('SSL (8729)') ?></label>
        <select name="use_ssl"><option value="0"<?= $e['use_ssl'] ? '' : ' selected' ?>><?= t('nie') ?></option>
        <option value="1"<?= $e['use_ssl'] ? ' selected' : '' ?>><?= t('áno') ?></option></select>
      </div>
    </div>
    <div class="grid g2">
      <div class="cell"><label><?= t('Sieť / typ siete') ?> <span class="muted"><?= t('(pre zákazníkov tohto MikroTiku)') ?></span></label>
        <input name="siet" value="<?= h($e['siet']) ?>" placeholder="<?= h(t('napr. názov obce')) ?>">
      </div>
      <div class="cell"><label><?= t('Nadradená fronta / parent') ?> <span class="muted"><?= t('(voliteľné)') ?></span></label>
        <input name="parent_queue" value="<?= h($e['parent_queue']) ?>" placeholder="<?= h(t('napr. Klienti')) ?>">
      </div>
    </div>
    <div class="grid g3">
      <div class="cell">
        <label><?= t('Spravovať ARP') ?> <span class="muted"><?= t('(reply-only sieť)') ?></span></label>
        <select name="manage_arp">
          <option value="0"<?= $e['manage_arp'] ? '' : ' selected' ?>><?= t('nie') ?></option>
          <option value="1"<?= $e['manage_arp'] ? ' selected' : '' ?>><?= t('áno') ?></option>
        </select>
      </div>
      <div class="cell" style="grid-column:span 2">
        <label><?= t('ARP interface') ?> <span class="muted"><?= t('(presný názov, napr. "ether1 - Switch")') ?></span></label>
        <input name="arp_interface" value="<?= h($e['arp_interface']) ?>" placeholder="ether1 - Switch">
      </div>
    </div>
    <div class="grid g3">
      <div class="cell"><label><?= t('Ulica') ?></label><input name="ulica" value="<?= h($e['ulica']) ?>"></div>
      <div class="cell"><label><?= t('Číslo domu') ?></label><input name="cislo_domu" value="<?= h($e['cislo_domu']) ?>"></div>
      <div class="cell"><label><?= t('Mesto') ?></label><input name="mesto" value="<?= h($e['mesto']) ?>"></div>
    </div>
    <div class="grid g2">
      <div class="cell"><label><?= t('Vchod') ?> <span class="muted"><?= t('(voliteľné)') ?></span></label><input name="vchod" value="<?= h($e['vchod']) ?>"></div>
      <div class="cell"><label><?= t('Poschodie') ?> <span class="muted"><?= t('(voliteľné)') ?></span></label><input name="poschodie" value="<?= h($e['poschodie']) ?>"></div>
    </div>
    <div class="btns">
      <label class="muted"><input type="checkbox" name="active" <?= $e['active'] ? 'checked' : '' ?>> <?= t('aktívny') ?></label>
      <button class="btn" type="submit"><?= t('Uložiť') ?></button>
      <?php if ($e['id']): ?><a class="btn gray" href="routers.php"><?= t('Nový') ?></a><?php endif; ?>
    </div>
  </form>
</fieldset>

<div class="panel-title"><?= t('Routery') ?></div>
<table>
  <tr><th>#</th><th><?= t('Názov') ?></th><th><?= t('Sieť') ?></th><th><?= t('Adresa') ?></th><th><?= t('Host') ?></th><th><?= t('Port') ?></th><th>SSL</th><th><?= t('User') ?></th><th><?= t('DHCP server') ?></th><th><?= t('Aktívny') ?></th><th><?= t('Akcie') ?></th></tr>
  <?php if (!$list): ?><tr><td colspan="11" class="muted"><?= t('Zatiaľ žiadne routery — pridaj prvý hore.') ?></td></tr><?php endif; ?>
  <?php foreach ($list as $i => $r): ?>
  <?php
    $adr = trim(($r['ulica'] ?? '') . ' ' . ($r['cislo_domu'] ?? ''));
    $extra = trim(($r['vchod'] ?? '') . (!empty($r['poschodie']) ? '/' . $r['poschodie'] : ''));
    if ($extra !== '') $adr .= ', ' . $extra;
    if (!empty($r['mesto'])) $adr = trim($adr) !== '' ? $adr . ', ' . $r['mesto'] : $r['mesto'];
  ?>
  <tr<?= $r['active'] ? '' : ' style="opacity:.5"' ?>>
    <td><?= $i + 1 ?></td>
    <td><?= h($r['name']) ?></td>
    <td><?= h($r['siet']) ?: '<span class="muted">—</span>' ?></td>
    <td><?= h(trim($adr, ', ')) ?: '<span class="muted">—</span>' ?></td>
    <td><?= h($r['host']) ?></td>
    <td><?= (int)$r['api_port'] ?></td>
    <td><?= $r['use_ssl'] ? t('áno') : t('nie') ?></td>
    <td><?= h($r['api_user']) ?></td>
    <td><?= h($r['dhcp_server']) ?></td>
    <td><?= $r['active'] ? t('áno') : t('nie') ?></td>
    <td>
      <a class="btn sm" href="routers.php?edit=<?= (int)$r['id'] ?>"><?= t('Zmena') ?></a>
      <form method="post" style="display:inline">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="test">
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <button class="btn sm gray" type="submit"><?= t('Test') ?></button>
      </form>
      <form method="post" style="display:inline" onsubmit="return confirm('<?= h(t('Zmazať router?')) ?>')">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <button class="btn sm red" type="submit"><?= t('Zmazať') ?></button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
<p class="muted"><?= t('Na routeri musí byť povolené API:') ?> <code>/ip service enable api</code> <?= t('(port 8728), prípadne api-ssl (8729).') ?></p>
<?php layout_footer();

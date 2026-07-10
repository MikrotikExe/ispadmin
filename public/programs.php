<?php
require_once __DIR__ . '/../lib/layout.php';
require_login();
$pdo = db();
$user = current_user();

/** kbit -> Mbps ako pekny retazec (2048 -> "2", 512 -> "0.5") */
function mbps($kbit): string
{
    $m = (float)$kbit / 1024;
    $s = rtrim(rtrim(number_format($m, 3, '.', ''), '0'), '.');
    return $s === '' ? '0' : $s;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { flash('err', t('Neplatná relácia.')); header('Location: programs.php'); exit; }
    $id = (int)($_POST['id'] ?? 0);
    $toKbit = static fn($v) => (int)round(((float)str_replace(',', '.', (string)$v)) * 1024);
    $d = [
        'name'        => trim($_POST['name'] ?? ''),
        'aggregation' => max(1, (int)($_POST['aggregation'] ?? 1)),
        'dl_group'    => $toKbit($_POST['dl_group'] ?? 0),
        'ul_group'    => $toKbit($_POST['ul_group'] ?? 0),
        'dl_user'     => $toKbit($_POST['dl_user'] ?? 0),
        'ul_user'     => $toKbit($_POST['ul_user'] ?? 0),
        'active'      => isset($_POST['active']) ? 1 : 0,
        'updated_at'  => date('Y-m-d H:i:s'),
        'updated_by'  => (string)$user,
    ];
    if ($id) {
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($d)));
        $pdo->prepare("UPDATE programs SET $set WHERE id = :id")->execute($d + ['id' => $id]);
    } else {
        $cols = implode(', ', array_keys($d));
        $ph = implode(', ', array_map(fn($k) => ":$k", array_keys($d)));
        $pdo->prepare("INSERT INTO programs ($cols) VALUES ($ph)")->execute($d);
    }
    flash('ok', t('Program uložený.'));
    header('Location: programs.php');
    exit;
}

$editId = (int)($_GET['edit'] ?? 0);
$e = ['id' => 0, 'name' => '', 'aggregation' => 1, 'dl_group' => 0, 'ul_group' => 0,
      'dl_user' => 0, 'ul_user' => 0, 'active' => 1];
if ($editId) {
    $row = $pdo->query('SELECT * FROM programs WHERE id = ' . $editId)->fetch();
    if ($row) $e = $row;
}
$list = $pdo->query('SELECT * FROM programs ORDER BY id')->fetchAll();

layout_header('programs.php');
render_flash();
?>
<fieldset class="panel">
  <legend><?= $e['id'] ? t('Upraviť program #%d', (int)$e['id']) : t('Nový program') ?></legend>
  <form method="post" action="programs.php">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
    <div class="grid g3">
      <div class="cell"><label><?= t('Program') ?></label><input name="name" value="<?= h($e['name']) ?>"></div>
      <div class="cell"><label><?= t('Agregácia') ?></label><input type="number" name="aggregation" value="<?= (int)$e['aggregation'] ?>"></div>
      <div class="cell"><label><?= t('Aktívny') ?></label>
        <select name="active"><option value="1"<?= $e['active'] ? ' selected' : '' ?>><?= t('áno') ?></option>
        <option value="0"<?= $e['active'] ? '' : ' selected' ?>><?= t('nie') ?></option></select>
      </div>
    </div>
    <div class="grid g4">
      <div class="cell"><label><?= t('Download Skupina (Mbps)') ?></label><input type="number" step="0.1" min="0" name="dl_group" value="<?= mbps($e['dl_group']) ?>"></div>
      <div class="cell"><label><?= t('Upload Skupina (Mbps)') ?></label><input type="number" step="0.1" min="0" name="ul_group" value="<?= mbps($e['ul_group']) ?>"></div>
      <div class="cell"><label><?= t('Download Užívateľ (Mbps)') ?></label><input type="number" step="0.1" min="0" name="dl_user" value="<?= mbps($e['dl_user']) ?>"></div>
      <div class="cell"><label><?= t('Upload Užívateľ (Mbps)') ?></label><input type="number" step="0.1" min="0" name="ul_user" value="<?= mbps($e['ul_user']) ?>"></div>
    </div>
    <div class="btns">
      <button class="btn" type="submit"><?= t('Uložiť') ?></button>
      <?php if ($e['id']): ?><a class="btn gray" href="programs.php"><?= t('Nový') ?></a><?php endif; ?>
    </div>
  </form>
</fieldset>

<div class="panel-title"><?= t('Zoznam programov') ?></div>
<table>
  <tr><th>#</th><th><?= t('Program') ?></th><th><?= t('Agregácia') ?></th><th><?= t('Download Skupina (Mbps)') ?></th><th><?= t('Upload Skupina (Mbps)') ?></th>
      <th><?= t('Download Užívateľ (Mbps)') ?></th><th><?= t('Upload Užívateľ (Mbps)') ?></th><th><?= t('Zmenil') ?></th><th><?= t('Zmena') ?></th></tr>
  <?php foreach ($list as $i => $p): ?>
  <tr<?= $p['active'] ? '' : ' style="opacity:.5"' ?>>
    <td><?= $i + 1 ?></td>
    <td><?= h($p['name']) ?></td>
    <td><?= $p['dl_user'] ? (int)$p['aggregation'] : '' ?></td>
    <td><?= $p['dl_group'] ? mbps($p['dl_group']) : '' ?></td>
    <td><?= $p['ul_group'] ? mbps($p['ul_group']) : '' ?></td>
    <td><?= $p['dl_user'] ? mbps($p['dl_user']) : '' ?></td>
    <td><?= $p['ul_user'] ? mbps($p['ul_user']) : '' ?></td>
    <td><?= h($p['updated_by']) ?><br><span class="muted"><?= h($p['updated_at']) ?></span></td>
    <td><a class="btn sm" href="programs.php?edit=<?= (int)$p['id'] ?>"><?= t('Zmena') ?></a></td>
  </tr>
  <?php endforeach; ?>
</table>
<p class="muted"><?= t('Program bez rýchlosti (DL/UL Užívateľ = 0, napr. IP TV / GPON) nevytvára frontu — len DHCP lease.') ?></p>
<?php layout_footer();

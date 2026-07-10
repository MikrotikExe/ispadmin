<?php
require_once __DIR__ . '/../lib/layout.php';
require_login();
$pdo = db();

if (!function_exists('sel')) {
    function sel($a, $b): string { return (string)$a === (string)$b ? ' selected' : ''; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { flash('err', t('Neplatná relácia.')); header('Location: networks.php'); exit; }
    $action = $_POST['action'] ?? 'save';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'delete' && $id) {
        $pdo->prepare('DELETE FROM networks WHERE id = ?')->execute([$id]);
        flash('ok', t('Sieť zmazaná.'));
        header('Location: networks.php');
        exit;
    }

    $d = [
        'router_id'    => (int)($_POST['router_id'] ?? 0) ?: null,
        'name'         => trim($_POST['name'] ?? ''),
        'subnet'       => trim($_POST['subnet'] ?? ''),
        'parent_queue' => trim($_POST['parent_queue'] ?? ''),
        'note'         => trim($_POST['note'] ?? ''),
        'active'       => isset($_POST['active']) ? 1 : 0,
    ];
    if ($d['name'] === '' || !$d['router_id']) {
        flash('err', t('Vyplň router aj názov siete.'));
        header('Location: networks.php');
        exit;
    }
    if ($id) {
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($d)));
        $pdo->prepare("UPDATE networks SET $set WHERE id = :id")->execute($d + ['id' => $id]);
    } else {
        $cols = implode(', ', array_keys($d));
        $ph = implode(', ', array_map(fn($k) => ":$k", array_keys($d)));
        $pdo->prepare("INSERT INTO networks ($cols) VALUES ($ph)")->execute($d);
    }
    flash('ok', t('Sieť uložená.'));
    header('Location: networks.php');
    exit;
}

$editId = (int)($_GET['edit'] ?? 0);
$e = ['id' => 0, 'router_id' => '', 'name' => '', 'subnet' => '', 'parent_queue' => '', 'note' => '', 'active' => 1];
if ($editId) {
    $row = $pdo->query('SELECT * FROM networks WHERE id = ' . $editId)->fetch();
    if ($row) $e = $row;
}
$routers = $pdo->query('SELECT id, name FROM routers ORDER BY name')->fetchAll();
$list = $pdo->query(
    'SELECT n.*, r.name AS router_name,
            (SELECT COUNT(*) FROM customers c WHERE c.network_id = n.id AND c.deleted_at IS NULL) AS cnt
     FROM networks n LEFT JOIN routers r ON r.id = n.router_id
     ORDER BY r.name, n.name'
)->fetchAll();

layout_header('networks.php');
render_flash();
?>
<fieldset class="panel">
  <legend><?= $e['id'] ? t('Upraviť sieť #%d', (int)$e['id']) : t('Nová sieť / skupina') ?></legend>
  <form method="post" action="networks.php">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
    <input type="hidden" name="action" value="save">
    <div class="grid g3">
      <div class="cell">
        <label><?= t('MikroTik (router)') ?></label>
        <select name="router_id">
          <option value=""><?= t('— vyber —') ?></option>
          <?php foreach ($routers as $r): ?>
            <option value="<?= (int)$r['id'] ?>"<?= sel($r['id'], $e['router_id']) ?>><?= h($r['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="cell"><label><?= t('Názov siete') ?></label><input name="name" value="<?= h($e['name']) ?>" placeholder="<?= h(t('napr. Klienti sever')) ?>"></div>
      <div class="cell"><label><?= t('Podsieť') ?> <span class="muted"><?= t('(voliteľné)') ?></span></label><input name="subnet" value="<?= h($e['subnet']) ?>" placeholder="172.16.5.0/24"></div>
    </div>
    <div class="grid g2">
      <div class="cell"><label><?= t('Nadradená fronta na MikroTiku') ?></label>
        <input name="parent_queue" value="<?= h($e['parent_queue']) ?>" placeholder="<?= h(t('(prázdne = rovnaké ako názov)')) ?>">
      </div>
      <div class="cell"><label><?= t('Poznámka') ?></label><input name="note" value="<?= h($e['note']) ?>"></div>
    </div>
    <div class="btns">
      <label class="muted"><input type="checkbox" name="active" <?= $e['active'] ? 'checked' : '' ?>> <?= t('aktívna') ?></label>
      <button class="btn" type="submit"><?= t('Uložiť') ?></button>
      <?php if ($e['id']): ?><a class="btn gray" href="networks.php"><?= t('Nová') ?></a><?php endif; ?>
    </div>
  </form>
</fieldset>

<div class="panel-title"><?= t('Siete / skupiny') ?></div>
<div class="tablewrap">
<table class="compact">
  <tr><th>#</th><th>MikroTik</th><th><?= t('Názov') ?></th><th><?= t('Podsieť') ?></th><th><?= t('Nadradená fronta') ?></th><th><?= t('Zákazníkov') ?></th><th><?= t('Aktívna') ?></th><th><?= t('Akcie') ?></th></tr>
  <?php if (!$list): ?><tr><td colspan="8" class="muted"><?= t('Zatiaľ žiadne siete.') ?></td></tr><?php endif; ?>
  <?php foreach ($list as $i => $n): ?>
  <tr<?= $n['active'] ? '' : ' style="opacity:.5"' ?>>
    <td><?= $i + 1 ?></td>
    <td><?= h($n['router_name']) ?: '<span class="muted">—</span>' ?></td>
    <td><?= h($n['name']) ?></td>
    <td class="nowrap"><?= h($n['subnet']) ?: '<span class="muted">—</span>' ?></td>
    <td><?= h($n['parent_queue'] !== '' ? $n['parent_queue'] : $n['name']) ?></td>
    <td><?= (int)$n['cnt'] ?></td>
    <td><?= $n['active'] ? t('áno') : t('nie') ?></td>
    <td class="nowrap">
      <a class="btn sm" href="networks.php?edit=<?= (int)$n['id'] ?>"><?= t('Zmena') ?></a>
      <form method="post" style="display:inline" onsubmit="return confirm('<?= h(t('Zmazať sieť? (zákazníci ostanú, len sa im zruší priradenie)')) ?>')">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
        <button class="btn sm red" type="submit"><?= t('Zmazať') ?></button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
</div>
<p class="muted"><?= t('Sieť = agregačná (nadradená) fronta na konkrétnom MikroTiku. Zákazník v karte vyberie sieť a appka mu nastaví túto frontu ako parent.') ?></p>
<?php layout_footer();

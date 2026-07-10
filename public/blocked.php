<?php
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/mikrotik.php';
require_admin();
$pdo = db();
$cfg = require __DIR__ . '/../config.php';
$lists = $cfg['block_lists'];
$managed = array_values(array_unique($lists));
$listLabel = ['docasne' => t('Dočasne odpojení'), 'neplatic' => t('Neplatiči'), 'ukoncena' => t('Výpovede')];
$labelByName = [];
foreach ($lists as $st => $ln) { $labelByName[$ln] = $listLabel[$st] ?? $ln; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { flash('err', t('Neplatná relácia.')); header('Location: blocked.php'); exit; }
    $rid = (int)($_POST['router_id'] ?? 0);
    $addr = trim($_POST['address'] ?? '');
    $ln = trim($_POST['list'] ?? '');
    $router = $pdo->query('SELECT * FROM routers WHERE id = ' . $rid)->fetch();
    if ($router && $addr !== '' && in_array($ln, $managed, true)) {
        $r = mt_blocklist_remove($router, $ln, $addr);
        flash($r['ok'] ? 'ok' : 'err', $r['ok'] ? t('IP %s uvoľnená z %s na %s.', $addr, $ln, $router['name']) : t('Chyba: %s', $r['msg']));
    }
    header('Location: blocked.php');
    exit;
}

$routers = $pdo->query('SELECT * FROM routers WHERE active = 1 ORDER BY name')->fetchAll();

$cmap = [];
foreach ($pdo->query('SELECT ip, meno, priezvisko, firma, status, deleted_at FROM customers WHERE ip <> ""') as $c) {
    $cmap[$c['ip']] = $c;
}

layout_header('blocked.php');
render_flash();
?>
<div class="panel-title"><?= t('Blokovaní zákazníci') ?></div>
<p class="muted"><?= t('Číta sa priamo z MikroTikov. Každý stav má vlastný address-list:') ?>
  <b><?= t('dočasne') ?></b> → <?= h($lists['docasne']) ?>, <b><?= t('neplatič') ?></b> → <?= h($lists['neplatic']) ?>, <b><?= t('výpoveď') ?></b> → <?= h($lists['ukoncena']) ?>.
  <?= t('„Uvoľniť" odoberie IP z firewallu (nemení záznam zákazníka) — hodí sa aj na osirené IP po zmazaní.') ?></p>

<?php foreach ($routers as $router): ?>
  <?php $res = mt_blocklists_read($router, $managed); ?>
  <fieldset class="panel">
    <legend><?= h($router['name']) ?>
      <?php if (!$res['ok']): ?><span class="stav-badge s-neplatic" style="margin-left:8px"><?= t('nedostupný') ?></span><?php endif; ?>
    </legend>

    <?php if (!$res['ok']): ?>
      <p class="muted"><?= t('Spojenie zlyhalo: %s — skontroluj host/API údaje v Routery.', h($res['msg'])) ?></p>
    <?php else: ?>
      <?php foreach ($managed as $ln): $blk = $res['lists'][$ln]; ?>
        <div class="bl-head">
          <b><?= h($labelByName[$ln] ?? $ln) ?></b>
          <span class="muted">(<?= h($ln) ?>)</span>
          <?php if ($blk['rule']): ?>
            <span class="stav-badge s-pripojeny"><?= t('drop pravidlo OK') ?></span>
          <?php else: ?>
            <span class="stav-badge s-docasne"><?= t('chýba drop pravidlo') ?></span>
          <?php endif; ?>
          <span class="muted"><?= count($blk['items']) ?> IP</span>
        </div>
        <?php if (!$blk['rule']): ?>
          <pre class="codebox">/ip firewall filter add chain=forward src-address-list=<?= h($ln) ?> action=drop comment="block <?= h($ln) ?>"</pre>
        <?php endif; ?>
        <?php if ($blk['items']): ?>
          <div class="tablewrap">
          <table class="compact">
            <tr><th>#</th><th>IP</th><th><?= t('Zákazník') ?></th><th><?= t('Stav') ?></th><th><?= t('Komentár (MT)') ?></th><th><?= t('Akcia') ?></th></tr>
            <?php foreach ($blk['items'] as $i => $it): ?>
              <?php
                $cust = $cmap[$it['address']] ?? null;
                if ($cust) {
                    $who = $cust['firma'] !== '' ? $cust['firma'] : trim($cust['priezvisko'] . ' ' . $cust['meno']);
                    $who = $who !== '' ? h($who) : t('(bez mena)');
                    $note = $cust['deleted_at'] ? t('zmazaný (osirené)') : $cust['status'];
                } else {
                    $who = '<span class="muted">' . h(t('neznáma IP')) . '</span>';
                    $note = t('bez záznamu');
                }
              ?>
              <tr>
                <td><?= $i + 1 ?></td>
                <td class="nowrap"><?= h($it['address']) ?></td>
                <td><?= $who ?></td>
                <td class="nowrap"><?= h($note) ?></td>
                <td><?= h($it['comment']) ?: '<span class="muted">—</span>' ?></td>
                <td class="nowrap">
                  <form method="post" style="display:inline" onsubmit="return confirm('<?= h(t('Uvoľniť %s z %s na %s?', $it['address'], $ln, $router['name'])) ?>')">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="router_id" value="<?= (int)$router['id'] ?>">
                    <input type="hidden" name="list" value="<?= h($ln) ?>">
                    <input type="hidden" name="address" value="<?= h($it['address']) ?>">
                    <button class="btn sm" type="submit"><?= t('Uvoľniť') ?></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </table>
          </div>
        <?php else: ?>
          <p class="muted" style="margin:.2rem 0 1rem"><?= t('Žiadne IP.') ?></p>
        <?php endif; ?>
      <?php endforeach; ?>
    <?php endif; ?>
  </fieldset>
<?php endforeach; ?>

<?php if (!$routers): ?><p class="muted"><?= t('Žiadne aktívne routery.') ?></p><?php endif; ?>
<?php layout_footer();

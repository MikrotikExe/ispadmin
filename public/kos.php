<?php
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/mikrotik.php';
require_admin();
$pdo = db();
$user = current_user();
purge_expired_trash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { flash('err', t('Neplatná relácia.')); header('Location: kos.php'); exit; }
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $cust = $pdo->query('SELECT * FROM customers WHERE id = ' . $id . ' AND deleted_at IS NOT NULL')->fetch();

    if (!$cust) {
        flash('err', t('Záznam v koši neexistuje.'));
    } elseif ($action === 'restore') {
        $prev = $cust['prev_status'] ?: 'pripojeny';
        $pdo->prepare('UPDATE customers SET status = ?, prev_status = NULL, deleted_at = NULL, deleted_by = NULL WHERE id = ?')
            ->execute([$prev, $id]);
        log_change($id, (string)$cust['contract_no'], (string)$user, 'restored');
        $msg = t('Zákazník obnovený.');
        if (!empty($cust['router_id'])) {
            $res = mt_apply_customer($id); // znovu aplikuj podla obnoveneho stavu
            $msg .= ' ' . t('MikroTik: %s', $res['msg']);
        }
        flash('ok', $msg);
    } elseif ($action === 'purge') {
        $res = ['ok' => true];
        if (!empty($cust['router_id'])) {
            $pdo->prepare('UPDATE customers SET status = ? WHERE id = ?')->execute(['ukoncena', $id]);
            $res = mt_apply_customer($id);
        }
        $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
        log_change($id, (string)$cust['contract_no'], (string)$user, 'permanently deleted');
        flash('ok', t('Zákazník trvalo zmazaný.'));
    }
    header('Location: kos.php');
    exit;
}

$rows = $pdo->query(
    'SELECT c.*, p.name program FROM customers c
     LEFT JOIN programs p ON p.id = c.program_id
     WHERE c.deleted_at IS NOT NULL
     ORDER BY c.deleted_at DESC'
)->fetchAll();

layout_header('kos.php');
render_flash();

function dni_do_zmazania(string $deletedAt): int
{
    $del = strtotime($deletedAt);
    if (!$del) return 30;
    $left = 30 - (int)floor((time() - $del) / 86400);
    return max(0, $left);
}
?>
<div class="panel-title"><?= t('Kôš — zmazaní zákazníci (automatické odstránenie po 30 dňoch)') ?></div>
<table>
  <tr><th>#</th><th><?= t('Číslo zmluvy') ?></th><th><?= t('Zákazník') ?></th><th>IP</th><th><?= t('Sieť') ?></th>
      <th><?= t('Zmazal') ?></th><th><?= t('Zostáva') ?></th><th><?= t('Akcie') ?></th></tr>
  <?php if (!$rows): ?>
    <tr><td colspan="8" class="muted"><?= t('Kôš je prázdny.') ?></td></tr>
  <?php endif; ?>
  <?php foreach ($rows as $i => $r): ?>
  <?php
    $meno = $r['firma'] !== '' ? $r['firma'] : trim($r['priezvisko'] . ', ' . $r['meno'], ', ');
    $left = dni_do_zmazania((string)$r['deleted_at']);
  ?>
  <tr>
    <td><?= $i + 1 ?></td>
    <td><?= h($r['contract_no']) ?></td>
    <td><?= h($meno) ?></td>
    <td><?= h($r['ip']) ?></td>
    <td><?= h($r['siet']) ?></td>
    <td><?= h($r['deleted_by']) ?><br><span class="muted"><?= h($r['deleted_at']) ?></span></td>
    <td><span class="pill"><?= t('%d dní', $left) ?></span></td>
    <td>
      <form method="post" style="display:inline">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="restore">
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <button class="btn sm" type="submit"><?= t('Obnoviť') ?></button>
      </form>
      <form method="post" style="display:inline" onsubmit="return confirm('<?= h(t('Trvalo zmazať %s? Toto sa nedá vrátiť.', $r['contract_no'])) ?>')">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="purge">
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <button class="btn sm red" type="submit"><?= t('Zmazať natrvalo') ?></button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
<p class="muted"><?= t('Keď zákazníka zmaže používateľ (rola user), presunie sa sem a po 30 dňoch sa odstráni automaticky. Admin a administrátor mažú natvrdo hneď, a tu vedia obnoviť alebo zmazať natrvalo.') ?></p>
<?php layout_footer();

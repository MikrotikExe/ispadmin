<?php
require_once __DIR__ . '/../lib/layout.php';
require_login();
$pdo = db();
purge_expired_trash();

$q = trim($_GET['q'] ?? '');
$routerF = (int)($_GET['router'] ?? 0);
$limitParam = (string)($_GET['limit'] ?? '20');
$allowed = ['10', '20', '50', '100', '200'];
$isAll = ($limitParam === 'all');
$limit = in_array($limitParam, $allowed, true) ? (int)$limitParam : 20;
if (!$isAll && !in_array($limitParam, $allowed, true)) $limitParam = '20';
$searching = (strlen($q) >= 4);

$where = ['c.deleted_at IS NULL'];
$params = [];

if ($searching) {
    $cols = ['contract_no', 'meno', 'priezvisko', 'firma', 'ip', 'mac', 'ulica', 'mesto', 'telefon', 'poznamka'];
    if (db_driver() === 'mysql') {
        $like = '%' . $q . '%';
        $or = implode(' OR ', array_map(fn($c) => "c.$c LIKE ?", $cols));
    } else {
        $like = '%' . noacc($q) . '%';
        $or = implode(' OR ', array_map(fn($c) => "noacc(c.$c) LIKE ?", $cols));
    }
    $where[] = '(' . $or . ')';
    $params = array_merge($params, array_fill(0, count($cols), $like));
}
if ($routerF) {
    $where[] = 'c.router_id = ?';
    $params[] = $routerF;
}
$whereSql = implode(' AND ', $where);

// celkovy pocet (bez limitu)
$cst = $pdo->prepare('SELECT COUNT(*) FROM customers c WHERE ' . $whereSql);
$cst->execute($params);
$total = (int)$cst->fetchColumn();

// zoznam (s poslednou udalostou z change_log pre stlpec Zmenil/Zmena)
$sql = 'SELECT c.id cid, c.contract_no, c.meno, c.priezvisko, c.firma, c.ulica,
               c.cislo_domu, c.vchod, c.poschodie, c.mesto, c.telefon, c.ip, c.mac, c.siet, c.status,
               COALESCE(cl.who, c.updated_by) who, COALESCE(cl.created_at, c.updated_at) created_at,
               p.name program, r.name router_name
        FROM customers c
        LEFT JOIN programs p ON p.id = c.program_id
        LEFT JOIN routers r ON r.id = c.router_id
        LEFT JOIN (SELECT customer_id, MAX(id) AS max_id FROM change_log GROUP BY customer_id) last
             ON last.customer_id = c.id
        LEFT JOIN change_log cl ON cl.id = last.max_id
        WHERE ' . $whereSql . '
        ORDER BY c.updated_at DESC' . ($isAll ? '' : ' LIMIT ' . $limit);
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$routersAll = $pdo->query('SELECT id, name FROM routers WHERE active = 1 ORDER BY name')->fetchAll();

layout_header('index.php');
render_flash();

function adresa(array $r): string
{
    $a = trim($r['ulica'] . ' ' . $r['cislo_domu']);
    $extra = trim($r['vchod'] . ($r['poschodie'] !== '' ? '/' . $r['poschodie'] : ''));
    return $a . ($extra !== '' ? ', ' . $extra : '');
}
function zakaznik(array $r): string
{
    if ($r['firma'] !== '') return $r['firma'];
    return trim($r['priezvisko'] . ', ' . $r['meno'], ', ');
}
function stav_badge(string $s): string
{
    $labels = ['pripojeny' => t('Pripojený'), 'docasne' => t('Dočasne odpojený'),
               'neplatic' => t('Odpojený neplatič'), 'ukoncena' => t('Ukončená zmluva')];
    $short  = ['pripojeny' => t('Pripojený'), 'docasne' => t('Dočasne'),
               'neplatic' => t('Neplatič'), 'ukoncena' => t('Ukončená')];
    $k = array_key_exists($s, $labels) ? $s : 'pripojeny';
    return '<span class="stav-badge s-' . $k . '" title="' . h($labels[$k]) . '">' . h($short[$k]) . '</span>';
}
function dt_short(?string $dt): string
{
    if (!$dt) return '';
    $t = strtotime($dt);
    return $t ? date('d.m.Y H:i', $t) : (string)$dt;
}
?>
<form method="get" action="index.php" class="filterbar">
  <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= h($q) ?>"><?php endif; ?>
  <label>MikroTik:
    <select name="router" onchange="this.form.submit()">
      <option value="0"><?= t('— všetky —') ?></option>
      <?php foreach ($routersAll as $r): ?>
        <option value="<?= (int)$r['id'] ?>"<?= $routerF === (int)$r['id'] ? ' selected' : '' ?>><?= h($r['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= t('Zobraziť:') ?>
    <select name="limit" onchange="this.form.submit()">
      <?php foreach (['10', '20', '50', '100', '200', 'all'] as $opt): ?>
        <option value="<?= $opt ?>"<?= $limitParam === $opt ? ' selected' : '' ?>><?= $opt === 'all' ? t('všetko') : $opt ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php if ($q !== '' || $routerF): ?><a class="btn sm gray" href="index.php"><?= t('Zrušiť filter') ?></a><?php endif; ?>
  <span class="count"><?= t('zobrazených <b>%d</b> z <b>%d</b>', count($rows), $total) ?></span>
</form>
<div class="panel-title"><?= $searching ? t('Výsledky hľadania: %s', h($q)) : t('Zákazníci') ?></div>
<div class="tablewrap">
<table class="compact">
  <tr>
    <th>#</th><th><?= t('Stav') ?></th><th><?= t('Číslo zmluvy') ?></th><th><?= t('Zákazník') ?></th><th><?= t('Adresa') ?></th><th><?= t('Mesto') ?></th>
    <th><?= t('Telefón') ?></th><th>IP</th><th>MAC</th><th><?= t('Sieť') ?></th><th>MikroTik</th><th><?= t('Program') ?></th><th><?= t('Zmenil') ?></th><th><?= t('Zmena') ?></th>
  </tr>
  <?php if (!$rows): ?>
    <tr><td colspan="14" class="muted"><?= $searching ? t('Nič sa nenašlo.') : t('Zatiaľ žiadne zmeny.') ?></td></tr>
  <?php endif; ?>
  <?php foreach ($rows as $i => $r): ?>
  <tr class="row-<?= h($r['status']) ?>">
    <td><?= $i + 1 ?></td>
    <td><?= stav_badge((string)$r['status']) ?></td>
    <td><?= h($r['contract_no']) ?></td>
    <td><?= h(zakaznik($r)) ?></td>
    <td><?= h(adresa($r)) ?></td>
    <td><?= h($r['mesto']) ?></td>
    <td class="tel"><?= $r['telefon'] !== '' ? h($r['telefon']) : '<span class="muted">—</span>' ?></td>
    <td class="ipcol"><?= h($r['ip']) ?></td>
    <td class="mono"><?= h($r['mac']) ?></td>
    <td><?= h($r['siet']) ?></td>
    <td><?= $r['router_name'] ? h($r['router_name']) : '<span class="muted">—</span>' ?></td>
    <td><?= h($r['program']) ?></td>
    <td><?= h($r['who']) ?> <span class="muted"><?= h(dt_short($r['created_at'])) ?></span></td>
    <td class="actions">
      <a class="btn sm" href="customer.php?id=<?= (int)$r['cid'] ?>"><?= t('Zmena') ?></a>
      <form method="post" action="customer.php" style="display:inline"
            onsubmit="return confirm('<?= h(t('Zmazať zákazníka a zrušiť na routeri?')) ?>')">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$r['cid'] ?>">
        <button class="btn sm red" type="submit"><?= t('Zmazať') ?></button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
</div>
<?php layout_footer();

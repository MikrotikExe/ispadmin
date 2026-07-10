<?php
require_once __DIR__ . '/../lib/layout.php';
require_admin();
$pdo = db();
$me = current_user();
$myLevel = current_level();

$ROLE_LABELS = ['user' => 'user', 'admin' => 'admin', 'administrator' => 'administrator'];

function count_role(PDO $pdo, string $role): int
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role = ?');
    $s->execute([$role]);
    return (int)$s->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { flash('err', t('Neplatná relácia.')); header('Location: pouzivatelia.php'); exit; }
    $action = $_POST['action'] ?? '';

    // pomocna: nacitaj cielovy ucet
    $loadTarget = function (int $id) use ($pdo): ?array {
        $r = $pdo->query('SELECT * FROM users WHERE id = ' . $id)->fetch();
        return $r ?: null;
    };

    if ($action === 'add') {
        $username = trim($_POST['username'] ?? '');
        $pass     = (string)($_POST['password'] ?? '');
        $role     = $_POST['role'] ?? 'user';
        if (!isset($ROLE_LABELS[$role]) || role_level($role) > $myLevel) {
            flash('err', t('Nemôžeš prideliť rolu vyššiu než máš ty.'));
        } elseif ($username === '' || !preg_match('/^[a-zA-Z0-9._-]{3,32}$/', $username)) {
            flash('err', t('Meno: 3–32 znakov, len písmená/čísla/._-'));
        } elseif (strlen($pass) < 6) {
            flash('err', t('Heslo musí mať aspoň 6 znakov.'));
        } else {
            $ex = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
            $ex->execute([$username]);
            if ($ex->fetchColumn()) {
                flash('err', t('Používateľ s týmto menom už existuje.'));
            } else {
                $ins = $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?,?,?)');
                $ins->execute([$username, password_hash($pass, PASSWORD_DEFAULT), $role]);
                flash('ok', t('Používateľ %s (%s) vytvorený.', $username, $role));
            }
        }
    } elseif ($action === 'reset') {
        $id = (int)($_POST['id'] ?? 0);
        $pass = (string)($_POST['password'] ?? '');
        $t = $loadTarget($id);
        if (!$t) {
            flash('err', t('Používateľ neexistuje.'));
        } elseif (role_level($t['role']) > $myLevel) {
            flash('err', t('Nemôžeš meniť heslo účtu s vyššou rolou.'));
        } elseif (strlen($pass) < 6) {
            flash('err', t('Heslo musí mať aspoň 6 znakov.'));
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
            flash('ok', t('Heslo zmenené.'));
        }
    } elseif ($action === 'role') {
        $id = (int)($_POST['id'] ?? 0);
        $role = $_POST['role'] ?? 'user';
        $t = $loadTarget($id);
        if (!$t) {
            flash('err', t('Používateľ neexistuje.'));
        } elseif ($t['username'] === $me) {
            flash('err', t('Vlastnú rolu si meniť nemôžeš.'));
        } elseif (role_level($t['role']) > $myLevel || role_level($role) > $myLevel) {
            flash('err', t('Na túto zmenu nemáš oprávnenie.'));
        } elseif (!isset($ROLE_LABELS[$role])) {
            flash('err', t('Neplatná rola.'));
        } elseif ($t['role'] === 'administrator' && $role !== 'administrator' && count_role($pdo, 'administrator') <= 1) {
            flash('err', t('Nemôžeš odobrať poslednú rolu administrator.'));
        } else {
            $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
            flash('ok', t('Rola zmenená na %s.', $role));
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $t = $loadTarget($id);
        if (!$t) {
            flash('err', t('Používateľ neexistuje.'));
        } elseif ($t['username'] === $me) {
            flash('err', t('Nemôžeš zmazať sám seba.'));
        } elseif (role_level($t['role']) > $myLevel) {
            flash('err', t('Nemôžeš zmazať účet s vyššou rolou.'));
        } elseif ($t['role'] === 'administrator' && count_role($pdo, 'administrator') <= 1) {
            flash('err', t('Nemôžeš zmazať posledného administrátora.'));
        } else {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            flash('ok', t('Používateľ %s zmazaný.', $t['username']));
        }
    }
    header('Location: pouzivatelia.php');
    exit;
}

$users = $pdo->query("SELECT id, username, role, last_login, last_login_ip FROM users ORDER BY CASE role WHEN 'administrator' THEN 0 WHEN 'admin' THEN 1 WHEN 'user' THEN 2 ELSE 3 END, username")->fetchAll();

// role, ktore moze sucasny pouzivatel pridelovat (max do svojej urovne)
$assignable = array_filter(array_keys($ROLE_LABELS), fn($r) => role_level($r) <= $myLevel);

layout_header('pouzivatelia.php');
render_flash();
?>
<fieldset class="panel" style="max-width:560px">
  <legend><?= t('Nový používateľ') ?></legend>
  <form method="post" action="pouzivatelia.php">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="add">
    <div class="grid g3">
      <div class="cell"><label><?= t('Meno') ?></label><input name="username" placeholder="<?= h(t('napr. technik1')) ?>"></div>
      <div class="cell"><label><?= t('Heslo (min. 6)') ?></label>
        <input type="password" name="password" id="np_pass">
        <button type="button" class="btn sm gray" style="margin-top:6px;align-self:flex-start" onclick="fillPwd('np_pass')"><?= t('Generovať heslo') ?></button>
      </div>
      <div class="cell"><label><?= t('Rola') ?></label>
        <select name="role" class="role-select">
          <?php foreach ($assignable as $r): ?>
            <option value="<?= h($r) ?>"<?= $r === 'user' ? ' selected' : '' ?>><?= h(t($ROLE_LABELS[$r])) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="btns"><button class="btn" type="submit"><?= t('Vytvoriť účet') ?></button></div>
  </form>
</fieldset>

<div class="panel-title"><?= t('Používatelia') ?></div>
<table>
  <tr><th>#</th><th><?= t('Meno') ?></th><th><?= t('Rola') ?></th><th><?= t('Posledné prihlásenie') ?></th><th><?= t('Reset hesla') ?></th><th><?= t('Akcie') ?></th></tr>
  <?php foreach ($users as $i => $u): ?>
  <?php
    $canManage = role_level($u['role']) <= $myLevel && $u['username'] !== $me;
  ?>
  <tr>
    <td><?= $i + 1 ?></td>
    <td><?= h($u['username']) ?><?= $u['username'] === $me ? ' <span class="muted">' . t('(ty)') . '</span>' : '' ?></td>
    <td>
      <?php if (!$canManage): ?>
        <span class="role-badge r-<?= h($u['role']) ?>"><?= h(t($u['role'])) ?></span>
      <?php else: ?>
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="role">
          <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
          <select name="role" class="role-select r-<?= h($u['role']) ?>" onchange="this.form.submit()">
            <?php foreach ($assignable as $r): ?>
              <option value="<?= h($r) ?>"<?= $u['role'] === $r ? ' selected' : '' ?>><?= h(t($ROLE_LABELS[$r])) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      <?php endif; ?>
    </td>
    <td>
      <?php if (!empty($u['last_login'])): ?>
        <?= h(date('d.m.Y H:i', strtotime($u['last_login']))) ?>
        <?php if (!empty($u['last_login_ip'])): ?>
          <br><span class="muted" style="font-size:.85em"><?= h($u['last_login_ip']) ?></span>
        <?php endif; ?>
      <?php else: ?>
        <span class="muted"><?= t('nikdy') ?></span>
      <?php endif; ?>
    </td>
    <td>
      <?php if ($canManage): ?>
      <form method="post" style="display:flex;gap:6px;flex-wrap:wrap">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="reset">
        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
        <input class="inp" type="password" name="password" id="rp_<?= (int)$u['id'] ?>" placeholder="<?= h(t('nové heslo')) ?>" style="max-width:150px">
        <button type="button" class="btn sm gray" onclick="fillPwd('rp_<?= (int)$u['id'] ?>')"><?= t('Gen') ?></button>
        <button class="btn sm gray" type="submit"><?= t('Reset') ?></button>
      </form>
      <?php else: ?><span class="muted">—</span><?php endif; ?>
    </td>
    <td>
      <?php if ($canManage): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('<?= h(t('Zmazať používateľa %s?', $u['username'])) ?>')">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
        <button class="btn sm red" type="submit"><?= t('Zmazať') ?></button>
      </form>
      <?php else: ?><span class="muted">—</span><?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
<p class="muted"><?= t('Úrovne: administrator &gt; admin &gt; user. Spravovať (zmazať, zmeniť heslo, zmeniť rolu) môžeš len účty s rovnakou alebo nižšou rolou. Admin sa teda nedotkne administrátora. Heslo si každý mení sám v sekcii Účet.') ?></p>
<?php layout_footer();

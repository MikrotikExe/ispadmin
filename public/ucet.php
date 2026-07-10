<?php
require_once __DIR__ . '/../lib/layout.php';
require_login();
$pdo = db();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        flash('err', t('Neplatná relácia.'));
        header('Location: ucet.php');
        exit;
    }
    $cur  = (string)($_POST['current'] ?? '');
    $new1 = (string)($_POST['new1'] ?? '');
    $new2 = (string)($_POST['new2'] ?? '');

    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE username = ?');
    $stmt->execute([$user]);
    $hash = $stmt->fetchColumn();

    if (!$hash || !password_verify($cur, $hash)) {
        flash('err', t('Súčasné heslo nesedí.'));
    } elseif (strlen($new1) < 6) {
        flash('err', t('Nové heslo musí mať aspoň 6 znakov.'));
    } elseif ($new1 !== $new2) {
        flash('err', t('Nové heslá sa nezhodujú.'));
    } else {
        $upd = $pdo->prepare('UPDATE users SET password_hash = ? WHERE username = ?');
        $upd->execute([password_hash($new1, PASSWORD_DEFAULT), $user]);
        flash('ok', t('Heslo bolo zmenené.'));
    }
    header('Location: ucet.php');
    exit;
}

layout_header('ucet.php');
render_flash();
?>
<fieldset class="panel" style="max-width:460px">
  <legend><?= h(t('Zmena hesla – %s', $user)) ?></legend>
  <form method="post" action="ucet.php">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <div class="grid">
      <div class="cell"><label><?= t('Súčasné heslo') ?></label><input type="password" name="current" autocomplete="current-password"></div>
      <div class="cell"><label><?= t('Nové heslo (min. 6 znakov)') ?></label><input type="password" name="new1" id="u_new1" autocomplete="new-password"></div>
      <div class="cell"><label><?= t('Nové heslo znova') ?></label><input type="password" name="new2" id="u_new2" autocomplete="new-password"></div>
      <div><button type="button" class="btn sm gray" onclick="fillPwd('u_new1','u_new2')"><?= t('Generovať heslo') ?></button></div>
    </div>
    <div class="btns">
      <button class="btn" type="submit"><?= t('Zmeniť heslo') ?></button>
      <a class="btn gray" href="index.php"><?= t('Návrat') ?></a>
    </div>
  </form>
</fieldset>
<?php layout_footer();

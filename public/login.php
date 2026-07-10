<?php
require_once __DIR__ . '/../lib/auth.php';
$cfg = require __DIR__ . '/../config.php';
geo_guard();

if (current_user() !== null) {
    header('Location: index.php');
    exit;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $err = t('Neplatná relácia, skús znova.');
    } elseif (attempt_login($_POST['username'] ?? '', $_POST['password'] ?? '')) {
        header('Location: index.php');
        exit;
    } else {
        $err = t('Nesprávne meno alebo heslo.');
    }
}
?><!DOCTYPE html>
<html lang="<?= h(lang_current()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(t('Prihlásenie')) ?></title>
<script>(function(){try{var t=localStorage.getItem('theme');if(!t){t=window.matchMedia&&matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light';}document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="login-box">
  <h1><?= h(t('Prihlásenie')) ?></h1>
  <div class="body">
    <div class="login-logo"><span class="b1"><?= h($cfg['brand_pre']) ?></span><span class="b2"><?= h($cfg['brand_post']) ?></span></div>
    <div class="muted" style="text-align:center;margin-bottom:12px"><?= h($cfg['tagline']) ?></div>
    <?php if ($err): ?><div class="flash err"><?= h($err) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="text" name="username" placeholder="<?= h(t('meno')) ?>" autofocus>
      <input type="password" name="password" placeholder="<?= h(t('heslo')) ?>">
      <button class="btn" type="submit" style="width:100%"><?= h(t('Prihlásiť')) ?></button>
    </form>
    <div class="login-lang" style="margin-top:14px;text-align:center"><?php lang_selector(); ?></div>
  </div>
</div>
</body>
</html>

<?php
require_once __DIR__ . '/auth.php';

function layout_header(string $active = ''): void
{
    $cfg = require __DIR__ . '/../config.php';
    $user = current_user();
    $nav = [
        'index.php'     => t('Domov'),
        'customer.php'  => t('Pridať zákazníka'),
        'programs.php'  => t('Programy'),
        'routers.php'   => t('Routery'),
        'networks.php'  => t('Siete'),
        'ucet.php'      => t('Účet'),
    ];
    if (is_admin()) {
        $nav['blocked.php'] = t('Blokovaní');
        $nav['kos.php'] = t('Kôš');
        $nav['pouzivatelia.php'] = t('Používatelia');
        $nav['zaloha.php'] = t('Záloha');
    }
    ?><!DOCTYPE html>
<html lang="<?= h(lang_current()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($cfg['app_name']) ?></title>
<script>(function(){try{var t=localStorage.getItem('theme');if(!t){t=window.matchMedia&&matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light';}document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="wrap<?= $active !== 'index.php' ? ' page-form' : '' ?>">
  <header class="brand">
    <div class="brand-left">
      <a href="index.php" class="logo-link">
        <div class="logo"><span class="b1"><?= h($cfg['brand_pre']) ?></span><span class="b2"><?= h($cfg['brand_post']) ?></span></div>
        <div class="sub"><?= h($cfg['tagline']) ?></div>
      </a>
    </div>
    <?php if ($user): ?>
    <div class="brand-right">
    <nav class="topnav">
      <?php foreach ($nav as $href => $label): ?>
        <a href="<?= $href ?>" class="<?= $active === $href ? 'on' : '' ?>"><?= $label ?></a>
      <?php endforeach; ?>
      <span class="who"><?= h($user) ?> · <a href="logout.php"><?= h(t('Odhlásiť')) ?></a></span>
    </nav>
    <?php lang_selector('lang-select sm'); ?>
    <button type="button" class="theme-toggle" onclick="toggleTheme()" title="<?= h(t('Svetlý / tmavý režim')) ?>" aria-label="<?= h(t('Prepnúť tému')) ?>">☾</button>
    </div>
    <?php endif; ?>
  </header>
  <?php if ($user): ?>
  <form class="search" method="get" action="index.php">
    <input type="text" name="q" placeholder="<?= h(t('Hľadať: zmluva · meno · IP · MAC  (min. 4 znaky)')) ?>" value="<?= h($_GET['q'] ?? '') ?>">
    <button class="btn sm" type="submit"><?= h(t('Hľadať')) ?></button>
  </form>
  <?php endif; ?>
  <main>
<?php
}

function layout_footer(): void
{
    ?>
  </main>
  <footer class="sitefoot">
    <span>© <?= date('Y') ?> ISPadmin</span>
    <span class="foot-sep">·</span>
    <span><?= h(t('Autor webu:')) ?> <a href="https://jurajchudy.sk" target="_blank" rel="noopener">Juraj Chudý</a></span>
  </footer>
</div>
<script>
function toggleTheme(){var h=document.documentElement;var t=h.getAttribute('data-theme')==='dark'?'light':'dark';h.setAttribute('data-theme',t);try{localStorage.setItem('theme',t);}catch(e){}themeIcon();}
function themeIcon(){var b=document.querySelector('.theme-toggle');if(b)b.textContent=document.documentElement.getAttribute('data-theme')==='dark'?'☀':'☾';}
themeIcon();
function _rnd(n){try{var a=new Uint32Array(1);crypto.getRandomValues(a);return a[0]%n;}catch(e){return Math.floor(Math.random()*n);}}
function genPwd(len){len=len||14;var L="abcdefghijkmnpqrstuvwxyz",U="ABCDEFGHJKLMNPQRSTUVWXYZ",D="23456789",S="!@#$%-_";var all=L+U+D+S;var p=[L[_rnd(L.length)],U[_rnd(U.length)],D[_rnd(D.length)],S[_rnd(S.length)]];while(p.length<len)p.push(all[_rnd(all.length)]);for(var i=p.length-1;i>0;i--){var j=_rnd(i+1);var t=p[i];p[i]=p[j];p[j]=t;}return p.join('');}
function fillPwd(){var v=genPwd(14);for(var i=0;i<arguments.length;i++){var el=document.getElementById(arguments[i]);if(el){el.type='text';el.value=v;}}}
</script>
</body>
</html>
<?php
}

function flash(string $type, string $msg): void
{
    boot_session();
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function render_flash(): void
{
    boot_session();
    if (empty($_SESSION['flash'])) {
        return;
    }
    foreach ($_SESSION['flash'] as $f) {
        echo '<div class="flash ' . h($f['type']) . '">' . h($f['msg']) . '</div>';
    }
    $_SESSION['flash'] = [];
}

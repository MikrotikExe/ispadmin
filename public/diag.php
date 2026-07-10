<?php
require_once __DIR__ . '/../lib/layout.php';
require_admin();

$cfg = require __DIR__ . '/../config.php';
$geo = $cfg['geo'] ?? [];
$ip = client_ip();
$country = strtoupper(trim($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''));

$isPriv = ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE));
$inAllow = !empty($geo['allow_ips']) && in_array($ip, $geo['allow_ips'], true);

// CIDR zoznam
$cidrFile = $geo['cidr_file'] ?? '';
$cidrCount = 0; $cidrMatch = null; $cidrLoaded = false;
if ($cidrFile !== '' && is_file($cidrFile) && filesize($cidrFile) > 0) {
    $cidrLoaded = true;
    $cidrs = [];
    foreach (file($cidrFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        $l = trim($l); if ($l !== '' && $l[0] !== '#') $cidrs[] = $l;
    }
    $cidrCount = count($cidrs);
    if (!$isPriv && $cidrCount) $cidrMatch = ip_in_cidr_list($ip, $cidrs);
}

$blocked = false;
if (empty($geo['enforce'])) {
    $verdict = t('Geo-ochrana je VYPNUTÁ → každý prejde.');
} elseif ($isPriv) {
    $verdict = t('LAN/privátna IP → vždy povolené.');
} elseif ($inAllow) {
    $verdict = t('IP je v allowliste → vždy povolené.');
} elseif ($cidrLoaded && $cidrCount) {
    if ($cidrMatch) {
        $verdict = t('IP patrí do povolených rozsahov (CIDR zoznam) → prejde.');
    } else {
        $verdict = t('IP NIE je v povolených rozsahoch → toto sa zablokuje (403).');
        $blocked = true;
    }
} elseif ($country !== '' && $country !== 'XX' && $country !== 'T1') {
    if (in_array($country, $geo['countries'] ?? [], true)) {
        $verdict = t('Krajina %s je povolená (cez Cloudflare) → prejde.', $country);
    } else {
        $verdict = t('Krajina %s nie je povolená → blok (403).', $country);
        $blocked = true;
    }
} else {
    $verdict = t('Krajina/rozsahy sa NEZISTILI (chýba CIDR zoznam aj Cloudflare hlavička) → appka pustí (fail-open). TOTO je dôvod, prečo to neblokuje. Spusti update_geoip.php.');
}

$rows = [
    t('client_ip() (vyhodnotená)') => $ip ?: '—',
    t('CIDR zoznam (súbor)') => $cidrLoaded ? t('%s — %d rozsahov', $cidrFile, $cidrCount) : t('%s — CHÝBA / prázdny', $cidrFile),
    t('IP v CIDR zozname?') => $cidrLoaded ? ($isPriv ? 'n/a (LAN)' : ($cidrMatch ? t('ÁNO') : t('NIE'))) : 'n/a',
    'CF-IPCountry (Cloudflare)' => $country !== '' ? $country : t('— (chýba; nejdeš cez Cloudflare)'),
    'CF-Connecting-IP' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? t('— (chýba)'),
    'X-Forwarded-For'  => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? t('— (chýba)'),
    'X-Real-IP'        => $_SERVER['HTTP_X_REAL_IP'] ?? t('— (chýba)'),
    t('REMOTE_ADDR (priame spojenie)') => $_SERVER['REMOTE_ADDR'] ?? '—',
];

layout_header('');
?>
<fieldset class="panel">
  <legend><?= t('Diagnostika prístupu (geo / IP)') ?></legend>

  <p class="hint">
    <?= t('Geo-ochrana:') ?> <strong><?= empty($geo['enforce']) ? t('vypnutá') : t('zapnutá') ?></strong>
    &nbsp;|&nbsp; <?= t('povolené krajiny:') ?> <strong><?= h(implode(', ', $geo['countries'] ?? [])) ?: '—' ?></strong>
    &nbsp;|&nbsp; allow IPs: <strong><?= h(implode(', ', $geo['allow_ips'] ?? [])) ?: '—' ?></strong>
  </p>

  <div class="flash <?= $blocked ? 'error' : 'info' ?>" style="margin:12px 0">
    <?= h($verdict) ?>
  </div>

  <table>
    <tr><th><?= t('Hlavička / hodnota') ?></th><th><?= t('Čo server vidí') ?></th></tr>
    <?php foreach ($rows as $k => $v): ?>
      <tr><td><?= h($k) ?></td><td><code><?= h((string)$v) ?></code></td></tr>
    <?php endforeach; ?>
  </table>

  <p class="hint" style="margin-top:14px">
    <?= t('Ak je „CF-IPCountry" prázdne, Cloudflare neposiela krajinu alebo ju Nginx Proxy Manager neprepúšťa.') ?>
    <?= t('Riešenie: v Cloudflare zapni pridávanie krajiny do hlavičiek a over, že doména ide cez Cloudflare proxy (oranžový mráčik).') ?>
  </p>
</fieldset>
<?php layout_footer();

<?php
require_once __DIR__ . '/../lib/layout.php';
require_admin();
$user = current_user();

$envTz = trim((string)getenv('ISPADMIN_TZ'));
$envLocked = ($envTz !== '' && tz_is_valid($envTz));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        flash('err', t('Neplatná relácia.'));
        header('Location: settings.php');
        exit;
    }
    $tz = trim((string)($_POST['timezone'] ?? ''));
    if ($envLocked) {
        flash('err', t('Zónu určuje premenná prostredia, ručná zmena sa neuplatní.'));
    } elseif ($tz === '') {
        // prazdna volba = vrat sa na autodetekciu zo servera
        setting_set('timezone', '', (string)$user);
        flash('ok', t('Časová zóna sa opäť preberá zo servera.'));
    } elseif (!tz_is_valid($tz)) {
        flash('err', t('Neplatná časová zóna.'));
    } else {
        setting_set('timezone', $tz, (string)$user);
        flash('ok', t('Časová zóna uložená: %s', $tz));
    }
    header('Location: settings.php');
    exit;
}

$cur      = tz_resolve();
$serverTz = tz_detect_server();
$dbTz     = (string)setting_get('timezone', '');
$zones    = DateTimeZone::listIdentifiers();
$cfg      = require __DIR__ . '/../config.php';

$sourceLabel = [
    'env'     => t('premenná prostredia ISPADMIN_TZ'),
    'db'      => t('ručné nastavenie nižšie'),
    'server'  => t('automaticky zo servera'),
    'default' => t('predvolená (UTC)'),
][$cur['source']] ?? $cur['source'];

layout_header('settings.php');
render_flash();
?>
<fieldset class="panel">
  <legend><?= t('Časová zóna') ?></legend>

  <div class="grid g3">
    <div class="cell">
      <label><?= t('Aktuálne používaná') ?></label>
      <input value="<?= h($cur['tz']) ?>" disabled>
    </div>
    <div class="cell">
      <label><?= t('Zdroj nastavenia') ?></label>
      <input value="<?= h($sourceLabel) ?>" disabled>
    </div>
    <div class="cell">
      <label><?= t('Miestny čas teraz') ?></label>
      <input value="<?= h(date('Y-m-d H:i:s (T, P)')) ?>" disabled>
    </div>
  </div>

  <?php if ($envLocked): ?>
    <div class="flash err" style="margin-top:12px">
      <?= t('Zónu určuje premenná prostredia ISPADMIN_TZ (%s). Kým je nastavená, voľba nižšie sa neuplatní — zmeň ju v docker-compose.yml alebo v prostredí servera.', h($envTz)) ?>
    </div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <div class="grid g2" style="margin-top:12px">
      <div class="cell">
        <label><?= t('Ručné nastavenie') ?></label>
        <select name="timezone"<?= $envLocked ? ' disabled' : '' ?>>
          <option value="">
            <?= t('— automaticky zo servera —') ?><?= $serverTz ? '  ' . h($serverTz) : '' ?>
          </option>
          <?php foreach ($zones as $z): ?>
            <option value="<?= h($z) ?>"<?= $z === $dbTz ? ' selected' : '' ?>><?= h($z) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="cell">
        <label>&nbsp;</label>
        <button class="btn" type="submit"<?= $envLocked ? ' disabled' : '' ?>><?= t('Uložiť') ?></button>
      </div>
    </div>
    <p class="hint"><?= t('Ovplyvňuje všetky časy v aplikácii — históriu zmien, prihlásenia aj názvy záloh. Ukladá sa do databázy, nie do konfiguračného súboru, takže prežije aktualizáciu.') ?></p>
  </form>
</fieldset>

<div class="panel-title"><?= t('O aplikácii') ?></div>
<table>
  <tr><th><?= t('Verzia') ?></th><td><?= h($cfg['version']) ?></td></tr>
  <tr><th><?= t('Databáza') ?></th><td><?= h(db()->getAttribute(PDO::ATTR_DRIVER_NAME)) ?></td></tr>
  <tr><th>PHP</th><td><?= h(PHP_VERSION) ?></td></tr>
  <tr><th><?= t('Zóna servera') ?></th><td><?= h($serverTz ?: t('nezistená')) ?></td></tr>
</table>
<?php layout_footer(); ?>

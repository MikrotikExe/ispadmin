<?php
require_once __DIR__ . '/../lib/layout.php';
require_admin();

$cfg = require __DIR__ . '/../config.php';
$driver = $cfg['db']['driver'] ?? 'sqlite';
$sqlitePath = $cfg['db']['sqlite_path'] ?? '';

/** Vytvori konzistentny snimok DB do docasneho suboru, vrati cestu alebo null. */
function make_backup_snapshot(string $sqlitePath): ?string
{
    $tmp = sys_get_temp_dir() . '/ispadmin-backup-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($tmp);
    try {
        db()->exec("VACUUM INTO '" . str_replace("'", "''", $tmp) . "'");
    } catch (Throwable $e) { /* skusi sa kopia nizsie */ }
    if (!is_file($tmp)) @copy($sqlitePath, $tmp);
    return is_file($tmp) ? $tmp : null;
}

/** Nahra subor na FTP/FTPS. Pouzije curl, inak ftp rozsirenie. */
function upload_to_ftp(string $local, string $host, int $port, string $user, string $pass, string $remote, bool $tls): array
{
    $fname = 'ispadmin-' . date('Ymd-His') . '.sqlite';
    $remote = trim($remote);
    if ($remote === '') {
        $remote = $fname;
    } elseif (substr($remote, -1) === '/') {
        $remote .= $fname;                          // priecinok -> dopln nazov
    } elseif (!preg_match('/\.[A-Za-z0-9]{1,5}$/', basename($remote))) {
        $remote = rtrim($remote, '/') . '/' . $fname; // vyzera ako priecinok
    }
    $remotePath = '/' . ltrim($remote, '/');
    $port = $port > 0 ? $port : 21;

    if (function_exists('curl_init')) {
        $fp = fopen($local, 'rb');
        if (!$fp) return ['ok' => false, 'msg' => t('Nedá sa otvoriť dočasná záloha.')];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => ($tls ? 'ftps' : 'ftp') . '://' . $host . ':' . $port . $remotePath,
            CURLOPT_USERPWD        => $user . ':' . $pass,
            CURLOPT_UPLOAD         => true,
            CURLOPT_INFILE         => $fp,
            CURLOPT_INFILESIZE     => filesize($local),
            CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_USE_SSL        => $tls ? CURLUSESSL_ALL : CURLUSESSL_NONE,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FTP_USE_EPSV   => true,
        ]);
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        return $ok ? ['ok' => true, 'msg' => $remotePath] : ['ok' => false, 'msg' => $err ?: t('FTP prenos zlyhal')];
    }

    if (function_exists('ftp_connect')) {
        $conn = ($tls && function_exists('ftp_ssl_connect')) ? @ftp_ssl_connect($host, $port, 15) : @ftp_connect($host, $port, 15);
        if (!$conn) return ['ok' => false, 'msg' => t('Nepripojil sa na FTP server.')];
        if (!@ftp_login($conn, $user, $pass)) { ftp_close($conn); return ['ok' => false, 'msg' => t('Zlé meno alebo heslo.')]; }
        @ftp_pasv($conn, true);
        $dir = dirname($remotePath);
        if ($dir && $dir !== '/' && $dir !== '.') {
            $p = '';
            foreach (explode('/', trim($dir, '/')) as $d) { $p .= '/' . $d; @ftp_mkdir($conn, $p); }
        }
        $ok = @ftp_put($conn, $remotePath, $local, FTP_BINARY);
        ftp_close($conn);
        return $ok ? ['ok' => true, 'msg' => $remotePath] : ['ok' => false, 'msg' => t('Nahratie na FTP zlyhalo (cesta/práva?).')];
    }

    return ['ok' => false, 'msg' => t('Server nemá curl ani ftp rozšírenie.')];
}

// --- Záloha na FTP ---
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'ftp') {
    if (!csrf_check()) { flash('error', t('Neplatný token, skús znova.')); header('Location: zaloha.php'); exit; }
    if ($driver !== 'sqlite') { flash('error', t('FTP záloha je podporovaná len pre SQLite.')); header('Location: zaloha.php'); exit; }
    $host = trim($_POST['ftp_host'] ?? '');
    $port = (int)($_POST['ftp_port'] ?? 21);
    $user = trim($_POST['ftp_user'] ?? '');
    $pass = (string)($_POST['ftp_pass'] ?? '');
    $path = (string)($_POST['ftp_path'] ?? '');
    $tls  = ($_POST['ftp_tls'] ?? '') === '1';
    if ($host === '' || $user === '') { flash('error', t('Vyplň aspoň host/IP a meno.')); header('Location: zaloha.php'); exit; }
    $snap = make_backup_snapshot($sqlitePath);
    if (!$snap) { flash('error', t('Snímok databázy sa nepodarilo vytvoriť.')); header('Location: zaloha.php'); exit; }
    $res = upload_to_ftp($snap, $host, $port, $user, $pass, $path, $tls);
    @unlink($snap);
    if ($res['ok']) flash('info', t('Záloha nahratá na FTP: %s', $res['msg']));
    else flash('error', t('FTP záloha zlyhala: %s', $res['msg']));
    header('Location: zaloha.php'); exit;
}

// --- Obnova zo súboru (prepíše databázu) ---
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'restore') {
    if (!csrf_check()) {
        flash('error', t('Neplatný token, skús znova.'));
        header('Location: zaloha.php'); exit;
    }
    if ($driver !== 'sqlite') {
        flash('error', t('Obnova odtiaľto je podporovaná len pre SQLite.'));
        header('Location: zaloha.php'); exit;
    }
    $f = $_FILES['backup'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        flash('error', t('Súbor sa nenahral. Vyber platný .sqlite súbor.'));
        header('Location: zaloha.php'); exit;
    }
    // 1) overenie, že je to SQLite súbor (magic hlavička)
    $magic = file_get_contents($f['tmp_name'], false, null, 0, 16);
    if ($magic !== "SQLite format 3\000") {
        flash('error', t('Nahratý súbor nie je platná SQLite databáza.'));
        header('Location: zaloha.php'); exit;
    }
    // 2) overenie, že obsahuje očakávané tabuľky
    try {
        $test = new PDO('sqlite:' . $f['tmp_name']);
        $test->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $need = ['users', 'customers', 'routers'];
        $have = [];
        foreach ($test->query("SELECT name FROM sqlite_master WHERE type='table'") as $r) $have[] = $r['name'];
        $missing = array_diff($need, $have);
        $test = null;
        if ($missing) {
            flash('error', t('Záloha nevyzerá ako databáza tejto aplikácie (chýba: %s).', implode(', ', $missing)));
            header('Location: zaloha.php'); exit;
        }
    } catch (Throwable $e) {
        flash('error', t('Súbor sa nepodarilo prečítať ako databázu.'));
        header('Location: zaloha.php'); exit;
    }
    // 3) bezpečnostná záloha aktuálnej DB pred prepísaním
    if (is_file($sqlitePath)) {
        @copy($sqlitePath, $sqlitePath . '.pred-obnovou-' . date('Ymd-His') . '.bak');
    }
    // 4) prepísanie databázy
    if (!@copy($f['tmp_name'], $sqlitePath)) {
        flash('error', t('Databázu sa nepodarilo prepísať (práva na súbor?).'));
        header('Location: zaloha.php'); exit;
    }
    flash('info', t('Databáza bola obnovená zo zálohy. Pôvodná sa odložila ako .bak vedľa databázy.'));
    header('Location: zaloha.php'); exit;
}

// --- Stiahnutie zálohy (musí prebehnúť pred akýmkoľvek výstupom layoutu) ---
if (isset($_GET['download'])) {
    if ($driver !== 'sqlite') {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo t('Automatické sťahovanie zálohy je podporované len pre SQLite. Pre MySQL použi mysqldump na serveri.');
        exit;
    }
    if (!is_file($sqlitePath)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo t('Databáza sa nenašla.');
        exit;
    }

    // Konzistentný snapshot cez VACUUM INTO (cieľ nesmie existovať)
    $tmp = sys_get_temp_dir() . '/ispadmin-backup-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($tmp);
    $usedVacuum = false;
    try {
        db()->exec("VACUUM INTO '" . str_replace("'", "''", $tmp) . "'");
        $usedVacuum = is_file($tmp);
    } catch (Throwable $e) {
        $usedVacuum = false;
    }
    // Fallback: obyčajná kópia (ak by VACUUM INTO nebol dostupný)
    if (!$usedVacuum) {
        @copy($sqlitePath, $tmp);
    }
    if (!is_file($tmp)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo t('Zálohu sa nepodarilo vytvoriť.');
        exit;
    }

    $fname = 'ispadmin-' . date('Ymd-His') . '.sqlite';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

// --- Info pre stránku ---
$exists = ($driver === 'sqlite' && is_file($sqlitePath));
$size = $exists ? filesize($sqlitePath) : 0;
$mtime = $exists ? date('d.m.Y H:i:s', filemtime($sqlitePath)) : '—';
$human = function (int $b): string {
    if ($b >= 1048576) return round($b / 1048576, 1) . ' MB';
    if ($b >= 1024) return round($b / 1024, 1) . ' kB';
    return $b . ' B';
};

layout_header('zaloha.php');
?>
<fieldset class="panel">
  <legend><?= t('Ručná záloha databázy') ?></legend>

  <?php if ($driver === 'sqlite'): ?>
    <p><?= t('Stiahne sa kompletná kópia databázy (zákazníci, routery, siete, používatelia, história zmien) ako jeden súbor <code>.sqlite</code>. Súbor si odlož na bezpečné miesto.') ?></p>
    <p class="hint">
      <?= t('Veľkosť databázy:') ?> <strong><?= h($human($size)) ?></strong><br>
      <?= t('Posledná zmena:') ?> <strong><?= h($mtime) ?></strong>
    </p>
    <p style="margin-top:14px">
      <a class="btn" href="zaloha.php?download=1"><?= t('Stiahnuť zálohu') ?></a>
    </p>
    <p class="hint" style="margin-top:14px">
      <?= t('Tip: záloha je konzistentný snímok, dá sa robiť aj počas behu. Obnova = nahradiť súbor databázy touto kópiou (keď appka nebeží) a reštartovať kontajner.') ?>
    </p>
  <?php else: ?>
    <p><?= t('Databáza beží na MySQL. Automatické sťahovanie odtiaľto nie je podporované – zálohu sprav cez <code>mysqldump</code> na serveri.') ?></p>
  <?php endif; ?>
</fieldset>

<?php if ($driver === 'sqlite'): ?>
<fieldset class="panel">
  <legend><?= t('Záloha na FTP server') ?></legend>
  <p><?= t('Nahrá aktuálny snímok databázy priamo na FTP server. Súbor sa pomenuje automaticky (<code>ispadmin-RRRRMMDD-HHMMSS.sqlite</code>).') ?></p>
  <form method="post" style="margin-top:10px">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="ftp">
    <div class="grid g4">
      <div class="cell">
        <label><?= t('Host / IP') ?></label>
        <input class="inp" name="ftp_host" placeholder="<?= h(t('napr. backup.server.sk')) ?>" required>
      </div>
      <div class="cell">
        <label><?= t('Port') ?></label>
        <input class="inp" name="ftp_port" value="21" placeholder="21">
      </div>
      <div class="cell">
        <label><?= t('Používateľ') ?></label>
        <input class="inp" name="ftp_user" autocomplete="off" required>
      </div>
      <div class="cell">
        <label><?= t('Heslo') ?></label>
        <input class="inp" type="password" name="ftp_pass" autocomplete="new-password">
      </div>
    </div>
    <div class="grid g4">
      <div class="cell" style="grid-column:span 2">
        <label><?= t('Cesta na serveri') ?> <span class="muted"><?= t('(priečinok alebo súbor)') ?></span></label>
        <input class="inp" name="ftp_path" placeholder="<?= h(t('napr. /zalohy/ispadmin/')) ?>">
      </div>
      <div class="cell" style="grid-column:span 2">
        <label><?= t('Zabezpečenie') ?></label>
        <select name="ftp_tls" class="inp">
          <option value="0"><?= t('FTP (bez šifrovania)') ?></option>
          <option value="1"><?= t('FTPS (TLS)') ?></option>
        </select>
      </div>
    </div>
    <p style="margin-top:14px">
      <button class="btn" type="submit"><?= t('Zálohovať na FTP') ?></button>
    </p>
    <p class="hint" style="margin-top:6px"><?= t('Ak cesta končí lomkou alebo je priečinok, názov súboru sa doplní automaticky. Heslo sa nikam neukladá – použije sa len na tento prenos.') ?></p>
  </form>
</fieldset>

<fieldset class="panel">
  <legend><?= t('Obnoviť zo zálohy') ?></legend>
  <p><?= t('Nahraj predtým stiahnutý súbor <code>.sqlite</code>. Týmto sa <strong>prepíše celá aktuálna databáza</strong> – všetci zákazníci, routery aj používatelia sa nahradia obsahom zálohy.') ?></p>
  <p class="hint"><?= t('Pre istotu sa aktuálna databáza pred prepísaním odloží ako <code>.bak</code> súbor vedľa nej. Po obnove sa môže zmeniť aj zoznam prihlásení/hesiel – prihlás sa podľa údajov zo zálohy.') ?></p>
  <form method="post" enctype="multipart/form-data"
        onsubmit="return confirm('<?= h(t('Naozaj prepísať aktuálnu databázu obsahom tejto zálohy? Túto akciu nemožno vrátiť (okrem .bak súboru).')) ?>');"
        style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:10px">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="restore">
    <span class="filepick">
      <label class="pickbtn" for="bkfile"><?= t('Vybrať súbor') ?></label>
      <input type="file" id="bkfile" name="backup" accept=".sqlite,application/octet-stream" required
             onchange="var s=this.parentNode.querySelector('.fname');s.textContent=this.files.length?this.files[0].name:'<?= h(t('Nevybraný žiadny súbor')) ?>';s.classList.toggle('set',this.files.length>0)">
      <span class="fname"><?= t('Nevybraný žiadny súbor') ?></span>
    </span>
    <button class="btn red" type="submit"><?= t('Obnoviť databázu') ?></button>
  </form>
</fieldset>
<?php endif; ?>
<?php layout_footer();

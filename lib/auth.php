<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lang.php';

function boot_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        $cfg = require __DIR__ . '/../config.php';
        session_name($cfg['session_name']);
        session_start();
    }
}

function current_user(): ?string
{
    boot_session();
    return $_SESSION['user'] ?? null;
}

/**
 * Vypise peknu 403 blokovaciu stranku a ukonci skript.
 */
function geo_block_page(string $ip): void
{
    $cfg = require __DIR__ . '/../config.php';
    $brandPre  = htmlspecialchars($cfg['brand_pre'] ?? 'moon', ENT_QUOTES);
    $brandPost = htmlspecialchars($cfg['brand_post'] ?? 'site', ENT_QUOTES);
    $ipEsc = htmlspecialchars($ip, ENT_QUOTES);
    $lng      = lang_current();
    $tTitle   = h(t('Prístup zamietnutý'));
    $tSub     = h(t('Prístup z vašej polohy nie je povolený'));
    $tBody    = h(t('Z tejto IP adresy nemáte povolený prístup k tejto aplikácii.'));
    $tFoot    = h(t('Chyba 403 · prístup obmedzený geograficky'));

    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="{$lng}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$tTitle}</title>
<style>
  *{box-sizing:border-box}
  body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
    background:linear-gradient(160deg,#eef3fb 0%,#dbe6f7 100%);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:#2b3a4a;padding:24px}
  .card{background:#fff;max-width:520px;width:100%;border-radius:18px;
    box-shadow:0 18px 50px rgba(31,58,99,.18);padding:40px 36px;text-align:center;border:1px solid #d8e0ea}
  .logo{font-weight:800;font-size:20px;letter-spacing:.3px;margin-bottom:22px}
  .logo .a{color:#2f6cb0}.logo .b{color:#1f3a63}
  .icon{width:84px;height:84px;margin:0 auto 18px}
  h1{font-size:22px;margin:0 0 6px;color:#1f3a63}
  .sub{font-size:15px;color:#76828f;margin:0 0 20px}
  p{font-size:15px;line-height:1.55;margin:0 0 14px}
  .ip{display:inline-block;background:#f4f2ec;border:1px solid #ecdfc6;color:#9a6a12;
    padding:5px 12px;border-radius:8px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:14px}
  .divider{height:1px;background:#e6ecf4;margin:22px 0}
  .contact{font-size:14px;color:#4a5a6a}
  .contact a{color:#2f6cb0;text-decoration:none;font-weight:600}
  .foot{margin-top:18px;font-size:12px;color:#9aa6b2}
</style>
</head>
<body>
  <div class="card">
    <div class="logo"><span class="a">{$brandPre}</span><span class="b">{$brandPost}</span></div>
    <svg class="icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
      <path d="M12 3.2c.62 0 1.19.33 1.5.87l8.2 14.2a1.73 1.73 0 0 1-1.5 2.6H3.8a1.73 1.73 0 0 1-1.5-2.6l8.2-14.2c.31-.54.88-.87 1.5-.87Z"
            fill="#fdeccd" stroke="#e8821e" stroke-width="1.4" stroke-linejoin="round"/>
      <path d="M12 9.2v4.6" stroke="#cf7115" stroke-width="1.8" stroke-linecap="round"/>
      <circle cx="12" cy="16.7" r="1.15" fill="#cf7115"/>
    </svg>
    <h1>{$tTitle}</h1>
    <div class="sub">{$tSub}</div>
    <p>{$tBody}</p>
    <p><span class="ip">{$ipEsc}</span></p>
    <div class="foot">{$tFoot}</div>
  </div>
</body>
</html>
HTML;
    exit;
}

/**
 * Over, ci IP patri do niektoreho CIDR rozsahu zo zoznamu (IPv4 aj IPv6).
 */
function ip_in_cidr(string $ip, string $cidr): bool
{
    $cidr = trim($cidr);
    if ($cidr === '') return false;
    if (strpos($cidr, '/') === false) $cidr .= (strpos($cidr, ':') !== false ? '/128' : '/32');
    [$net, $bits] = explode('/', $cidr, 2);
    $bits = (int)$bits;
    $ipB = @inet_pton($ip);
    $netB = @inet_pton($net);
    if ($ipB === false || $netB === false || strlen($ipB) !== strlen($netB)) return false;
    $bytes = intdiv($bits, 8);
    $rem = $bits % 8;
    if ($bytes > 0 && substr($ipB, 0, $bytes) !== substr($netB, 0, $bytes)) return false;
    if ($rem === 0) return true;
    $mask = chr(0xff << (8 - $rem) & 0xff);
    return (ord($ipB[$bytes]) & ord($mask)) === (ord($netB[$bytes]) & ord($mask));
}

function ip_in_cidr_list(string $ip, array $cidrs): bool
{
    foreach ($cidrs as $c) {
        if (ip_in_cidr($ip, $c)) return true;
    }
    return false;
}

/**
 * Geo-ochrana. Dva sposoby (skusaju sa v poradi):
 *   1) Zoznam CIDR rozsahov povolenych krajin v subore (geo.cidr_file) – funguje BEZ Cloudflare.
 *      Zoznam stiahne skript update_geoip.php (server ma internet).
 *   2) Cloudflare hlavicka CF-IPCountry – len ak prevadzka ide cez Cloudflare.
 * Lokalne/privatne IP a allow_ips su vzdy povolene. Ak sa krajina neda zistit, pristup sa povoli.
 */
function geo_guard(): void
{
    if (PHP_SAPI === 'cli') return;
    $cfg = require __DIR__ . '/../config.php';
    $geo = $cfg['geo'] ?? null;
    if (!$geo || empty($geo['enforce'])) return;

    $ip = client_ip();
    // lokalne / privatne / reserved IP nikdy neblokuj
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return;
    }
    // explicitne povolene IP
    if (!empty($geo['allow_ips']) && in_array($ip, $geo['allow_ips'], true)) return;

    // 1) CIDR zoznam (bez Cloudflare)
    $cidrFile = $geo['cidr_file'] ?? '';
    if ($cidrFile !== '' && is_file($cidrFile) && filesize($cidrFile) > 0) {
        $lines = file($cidrFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $cidrs = [];
        foreach ($lines as $l) { $l = trim($l); if ($l !== '' && $l[0] !== '#') $cidrs[] = $l; }
        if ($cidrs) {
            $ipv6 = strpos($ip, ':') !== false;
            $listHasV6 = false;
            foreach ($cidrs as $c) { if (strpos($c, ':') !== false) { $listHasV6 = true; break; } }
            // ak je klient IPv6 a v zozname nie su IPv6 rozsahy, nevieme overit -> pusti (nezamykaj)
            if ($ipv6 && !$listHasV6) return;
            if (ip_in_cidr_list($ip, $cidrs)) return;       // IP patri do povolenej krajiny
            // nepatri -> blok
            geo_block_page($ip);
        }
    }

    // 2) Cloudflare hlavicka (ak je k dispozicii)
    $country = strtoupper(trim($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''));
    if ($country === '' || $country === 'XX' || $country === 'T1') return;  // nezistitelne -> pusti
    if (!in_array($country, $geo['countries'], true)) {
        geo_block_page($ip);
    }
}

function require_login(): void
{
    geo_guard();
    if (current_user() === null) {
        header('Location: login.php');
        exit;
    }
}

/** Vrati rolu prihlaseneho pouzivatela ('administrator' | 'admin' | 'user') alebo null. */
function current_role(): ?string
{
    $u = current_user();
    if ($u === null) {
        return null;
    }
    static $cache = [];
    if (!array_key_exists($u, $cache)) {
        $stmt = db()->prepare('SELECT role FROM users WHERE username = ?');
        $stmt->execute([$u]);
        $cache[$u] = $stmt->fetchColumn() ?: 'user';
    }
    return $cache[$u];
}

/** Cislo urovne role (vyssie = vacsie prava). */
function role_level(?string $role): int
{
    return ['user' => 1, 'admin' => 2, 'administrator' => 3][$role] ?? 1;
}

function current_level(): int
{
    return role_level(current_role());
}

/** admin aj administrator (uroven >= 2) maju pristup k sprave. */
function is_admin(): bool
{
    return current_level() >= 2;
}

/** najvyssia rola. */
function is_superadmin(): bool
{
    return current_level() >= 3;
}

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        if (function_exists('flash')) {
            flash('err', t('Na túto sekciu nemáš oprávnenie.'));
        }
        header('Location: index.php');
        exit;
    }
}

function client_ip(): string
{
    // za reverse proxy (Cloudflare / Nginx Proxy Manager) je realna IP v hlavickach
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);   // prvy z retazca XFF
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function attempt_login(string $username, string $password): bool
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $u = $stmt->fetch();
    if ($u && password_verify($password, $u['password_hash'])) {
        boot_session();
        session_regenerate_id(true);
        $_SESSION['user'] = $u['username'];
        try {
            db()->prepare('UPDATE users SET last_login = ?, last_login_ip = ? WHERE id = ?')
                ->execute([date('Y-m-d H:i:s'), client_ip(), $u['id']]);
        } catch (Throwable $e) { /* ticho */ }
        return true;
    }
    return false;
}

function logout(): void
{
    boot_session();
    $_SESSION = [];
    session_destroy();
}

/** HTML escape skratka. */
function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** CSRF token. */
function csrf_token(): string
{
    boot_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): bool
{
    boot_session();
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}

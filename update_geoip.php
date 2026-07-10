<?php
/**
 * Stiahne zoznam IP rozsahov (CIDR) povolených krajín a uloží ich do geo.cidr_file.
 * Zdroj: ipdeny.com (agregované zóny, IPv4 + IPv6). Server musí mať prístup na internet.
 *
 * Spustenie (v kontajneri):
 *   docker exec -it mt-ispadmin php /var/www/html/update_geoip.php
 *   docker exec -it mt-ispadmin php /var/www/html/update_geoip.php sk,cz
 *
 * Odporúčanie: pridať do cronu na serveri (napr. raz týždenne), zoznamy sa občas menia.
 */
require_once __DIR__ . '/lib/db.php';
$cfg = require __DIR__ . '/config.php';

$countries = [];
if (!empty($argv[1])) {
    $countries = array_filter(array_map('trim', explode(',', strtolower($argv[1]))));
} else {
    $countries = array_map('strtolower', $cfg['geo']['countries'] ?? ['sk']);
}
if (!$countries) $countries = ['sk'];

$file = $cfg['geo']['cidr_file'] ?? '';
if ($file === '') { fwrite(STDERR, "Nie je nastavený geo.cidr_file.\n"); exit(1); }

$urls = [];
foreach ($countries as $c) {
    $urls[] = "https://www.ipdeny.com/ipblocks/data/aggregated/{$c}-aggregated.zone";          // IPv4
    $urls[] = "https://www.ipdeny.com/ipv6/ipaddresses/aggregated/{$c}-aggregated.zone";        // IPv6
}

function fetch_url(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_USERAGENT      => 'mt-ispadmin geoip updater',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code >= 200 && $code < 300) ? $body : null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 60]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body !== false ? $body : null;
}

$all = [];
$okCount = 0;
foreach ($urls as $url) {
    $body = fetch_url($url);
    if ($body === null) { echo "  preskočené (nedostupné): $url\n"; continue; }
    $n = 0;
    foreach (preg_split('/\R/', $body) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        // jednoduchá validácia CIDR
        if (preg_match('#^[0-9a-fA-F:.]+/\d{1,3}$#', $line)) { $all[$line] = true; $n++; }
    }
    echo "  načítané $n rozsahov z $url\n";
    if ($n > 0) $okCount++;
}

if (!$all || $okCount === 0) {
    fwrite(STDERR, "Nestiahli sa žiadne rozsahy – súbor sa NEPREPÍSAL (aby si sa nezamkol).\n");
    exit(1);
}

$header = "# CIDR zoznam povolených krajín: " . implode(',', $countries) . "\n"
        . "# vygenerované: " . date('Y-m-d H:i:s') . " | spolu " . count($all) . " rozsahov\n";
$dir = dirname($file);
if (!is_dir($dir)) @mkdir($dir, 0775, true);
$tmp = $file . '.tmp';
file_put_contents($tmp, $header . implode("\n", array_keys($all)) . "\n");
rename($tmp, $file);

echo "Hotovo: " . count($all) . " rozsahov uložených do $file\n";
echo "Krajiny: " . implode(', ', $countries) . "\n";

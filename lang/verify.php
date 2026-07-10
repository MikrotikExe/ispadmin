<?php
// Overenie prekladoveho suboru: php _verify.php <kod>
$code = $argv[1] ?? '';
$dict = require __DIR__ . "/$code.php";
$keys = array_filter(explode("\n", file_get_contents(__DIR__ . '/keys.txt')), 'strlen');
$err = 0;
foreach ($keys as $k) {
    if (!array_key_exists($k, $dict)) { echo "CHYBA kluc: $k\n"; $err++; continue; }
    $v = $dict[$k];
    foreach (['%s','%d'] as $ph) {
        if (substr_count($k, $ph) !== substr_count($v, $ph)) { echo "PLACEHOLDER $ph nesedi: $k\n"; $err++; }
    }
    foreach (['<b>','</b>','<code>','</code>','<strong>','</strong>','<br>','<li>'] as $tag) {
        if (substr_count($k, $tag) !== substr_count($v, $tag)) { echo "TAG $tag nesedi: $k\n"; $err++; }
    }
}
$extra = array_diff(array_keys($dict), $keys);
foreach ($extra as $e) { echo "NAVYSE kluc: $e\n"; }
echo $err === 0 && !$extra ? "OK ($code: " . count($dict) . " klucov)\n" : "CHYB: $err, navyse: " . count($extra) . "\n";
exit($err || $extra ? 1 : 0);

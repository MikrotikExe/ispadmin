<?php
/**
 * Export PPPoE sessions (RADIUS accounting, table radacct) for a period — the hook for
 * data-retention reporting. Writes CSV (default) or JSON lines to stdout.
 *
 *   sudo docker exec -u www-data mt-ispadmin php /var/www/html/export_sessions.php \
 *        --from=2026-09-01 --to=2026-10-01 > sessions-2026-09.csv
 *   ... --format=jsonl
 *
 * A session is included when it overlaps [from, to). Bytes: in = from the customer
 * (upload), out = to the customer (download), as reported by the MikroTik.
 */
require_once __DIR__ . '/lib/radius.php';

$opt = ['from' => date('Y-m-01'), 'to' => date('Y-m-d', strtotime('tomorrow')), 'format' => 'csv'];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--(from|to|format)=(.+)$/', $a, $m)) $opt[$m[1]] = $m[2];
}
if (!radius_available()) {
    fwrite(STDERR, "RADIUS is not enabled (ISPADMIN_RADIUS=1 and the MySQL driver are required).\n");
    exit(1);
}
foreach (['from', 'to'] as $k) {
    if (strtotime($opt[$k]) === false) { fwrite(STDERR, "Invalid --$k date.\n"); exit(1); }
    $opt[$k] = date('Y-m-d H:i:s', strtotime($opt[$k]));
}

$out = fopen('php://stdout', 'w');
$n = 0;
foreach (radius_sessions_export($opt['from'], $opt['to']) as $row) {
    if ($opt['format'] === 'jsonl') {
        fwrite($out, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n");
    } else {
        if ($n === 0) fputcsv($out, array_keys($row), ',', '"', '');
        fputcsv($out, array_values($row), ',', '"', '');
    }
    $n++;
}
fwrite(STDERR, "$n sessions ({$opt['from']} .. {$opt['to']})\n");

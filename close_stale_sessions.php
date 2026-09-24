<?php
/**
 * Close PPPoE sessions that are still "online" in RADIUS accounting (radacct) but whose NAS
 * stopped reporting them — e.g. the router crashed or rebooted without sending Accounting-Stop.
 * Without this they stay online forever: wrong session list and export, and CoA/disconnect
 * attempts on sessions that no longer exist.
 *
 * A session is stale when it got no interim update (or start) for longer than
 * 3 x Acct-Interim-Interval (default 3 x 300 s = 15 min). It is closed at its last update,
 * with acctterminatecause = 'Stale-Session'.
 *
 *   preview:  sudo docker exec -u www-data mt-ispadmin php /var/www/html/close_stale_sessions.php
 *   close:    sudo docker exec -u www-data mt-ispadmin php /var/www/html/close_stale_sessions.php --apply
 *   custom:   ... close_stale_sessions.php --apply --minutes=30
 *
 * Requires interim updates on the NAS (/ppp aaa interim-update, or Acct-Interim-Interval from
 * ISPadmin). Without them every session longer than the limit would look stale.
 */
require_once __DIR__ . '/lib/radius.php';

$APPLY = in_array('--apply', $argv, true);
$minutes = 0;
foreach ($argv as $a) {
    if (preg_match('/^--minutes=(\d+)$/', $a, $m)) $minutes = (int)$m[1];
}
if (!radius_available()) {
    fwrite(STDERR, "RADIUS is not enabled (ISPADMIN_RADIUS=1 and the MySQL driver are required).\n");
    exit(1);
}
$interim = (int)radius_cfg()['interim_interval'];
if ($minutes <= 0) {
    if ($interim <= 0) {
        fwrite(STDERR, "Interim updates are off (ISPADMIN_RADIUS_INTERIM=0); pass --minutes=N explicitly.\n");
        exit(1);
    }
    $minutes = (int)ceil(3 * $interim / 60);
}
$limit = $minutes * 60;

$pdo = db();
// casy su v casovej zone databazy (FreeRADIUS ich zapisuje cez FROM_UNIXTIME) -> porovnavat s NOW() v DB
$st = $pdo->prepare("SELECT radacctid, username, nasipaddress, framedipaddress, acctstarttime,
        COALESCE(acctupdatetime, acctstarttime) AS lastseen
    FROM radacct
    WHERE acctstoptime IS NULL
      AND COALESCE(acctupdatetime, acctstarttime) < NOW() - INTERVAL ? SECOND
    ORDER BY lastseen");
$st->execute([$limit]);
$rows = $st->fetchAll();

echo ($APPLY ? "=== CLOSE (--apply) ===\n" : "=== PREVIEW (nothing is written, run with --apply) ===\n");
echo "stale = no update for more than $minutes min\n";
foreach ($rows as $r) {
    echo sprintf("  #%d %-20s %-15s %-15s start %s  last %s\n", $r['radacctid'], $r['username'],
        $r['nasipaddress'], $r['framedipaddress'], $r['acctstarttime'], $r['lastseen']);
}
echo count($rows) . " stale session(s)\n";

if ($APPLY && $rows) {
    $up = $pdo->prepare("UPDATE radacct
        SET acctstoptime = COALESCE(acctupdatetime, acctstarttime),
            acctsessiontime = GREATEST(0, TIMESTAMPDIFF(SECOND, acctstarttime, COALESCE(acctupdatetime, acctstarttime))),
            acctterminatecause = 'Stale-Session'
        WHERE radacctid = ? AND acctstoptime IS NULL");
    $n = 0;
    foreach ($rows as $r) {
        $up->execute([(int)$r['radacctid']]);
        $n += $up->rowCount();
    }
    echo "closed: $n\n";
}

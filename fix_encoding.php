<?php
/**
 * Jednorazová oprava: dekóduje RouterOS escapy diakritiky (\E1, \8A ...) v CP1250
 * v už naimportovaných záznamoch. Spustenie:
 *   náhľad: sudo docker exec -it mt-ispadmin php /var/www/html/fix_encoding.php
 *   oprava: sudo docker exec -it mt-ispadmin php /var/www/html/fix_encoding.php --apply
 * Je idempotentný (druhé spustenie už nič nezmení).
 */
require_once __DIR__ . '/lib/db.php';

$APPLY = in_array('--apply', $argv, true);
$pdo = db();

function ros_unescape(?string $s): string
{
    $s = (string)$s;
    if (strpos($s, '\\') === false) return $s;
    return preg_replace_callback('/\\\\([0-9A-Fa-f]{2})/', function ($m) {
        $u = iconv('CP1250', 'UTF-8//TRANSLIT', chr(hexdec($m[1])));
        return $u !== false ? $u : $m[0];
    }, $s);
}

echo $APPLY ? "=== OPRAVA (--apply) ===\n" : "=== NÁHĽAD (bez zápisu) ===\n";

$targets = [
    'networks'  => ['name', 'parent_queue', 'note'],
    'customers' => ['meno', 'priezvisko', 'firma', 'ulica', 'mesto', 'poznamka', 'siet'],
    'routers'   => ['name', 'parent_queue', 'siet'],
];

$totChanged = 0;
foreach ($targets as $table => $cols) {
    $rows = $pdo->query("SELECT * FROM $table")->fetchAll();
    $changedRows = 0;
    foreach ($rows as $row) {
        $set = [];
        foreach ($cols as $col) {
            if (!array_key_exists($col, $row)) continue;
            $new = ros_unescape($row[$col]);
            if ($new !== (string)$row[$col]) {
                $set[$col] = $new;
            }
        }
        if ($set) {
            $changedRows++;
            $sample = reset($set);
            if ($changedRows <= 6) {
                echo "  $table #{$row['id']}: " . array_key_first($set) . " -> $sample\n";
            }
            if ($APPLY) {
                $assign = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($set)));
                $pdo->prepare("UPDATE $table SET $assign WHERE id = :id")
                    ->execute($set + ['id' => $row['id']]);
            }
        }
    }
    echo ucfirst($table) . ": zmenených riadkov $changedRows\n";
    $totChanged += $changedRows;
}

echo "\nSpolu riadkov na zmenu: $totChanged\n";
echo $APPLY ? "HOTOVO.\n" : "Nič sa nezapísalo. Spusti s --apply.\n";

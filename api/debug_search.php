<?php
session_start();
if (!isset($_SESSION["user_id"])) { echo "not logged in"; exit; }
require __DIR__ . "/../config/db.php";
try { $pdo->exec("SET SESSION MAX_EXECUTION_TIME=60000"); } catch (PDOException $e) {}
$mobile10 = "9789242628";
$mobile91 = "+91" . $mobile10;
$cols = "id, mobile_no, alternative_no, name";
$subQueries = [
    ["mobile_no = :mobile",        ["mobile"   => $mobile10]],
    ["alternative_no = :mobile",   ["mobile"   => $mobile10]],
    ["mobile_no = :mobile91",      ["mobile91" => $mobile91]],
    ["alternative_no = :mobile91", ["mobile91" => $mobile91]],
];
$seen = []; $allRows = [];
foreach ($subQueries as [$w, $p]) {
    try {
        $s = $pdo->prepare("SELECT $cols FROM customers_tamil_nadu WHERE $w LIMIT 50");
        $s->execute($p);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        echo count($rows) . " rows for: $w\n";
        foreach ($rows as $r) {
            if (!isset($seen[$r["id"]])) { $seen[$r["id"]] = true; $allRows[] = $r; }
        }
    } catch (PDOException $e) {
        echo "ERROR [$w]: " . $e->getMessage() . "\n";
    }
}
echo "Total unique: " . count($allRows) . "\n";
foreach ($allRows as $r) { echo "  {$r["mobile_no"]} | alt={$r["alternative_no"]} | {$r["name"]}\n"; }

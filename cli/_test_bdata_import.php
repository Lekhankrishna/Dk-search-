<?php
// One-off: streams advbdata_import.bdata (a MyISAM table already loaded into
// this MySQL server) directly into crm_db.customers_karnataka via SQL, no CSV
// involved. Adds to the existing Karnataka rows — does not deduplicate.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

ini_set('memory_limit', '512M');
set_time_limit(0);

require __DIR__ . '/../config/db.php'; // gives $pdo (buffered) for writes
require __DIR__ . '/../includes/import_helpers.php';

$targetTable = 'customers_karnataka';
$batchSize   = 2000;
$skipRows    = 0;
foreach ($argv as $a) {
    if (str_starts_with($a, '--skip-rows=')) {
        $skipRows = max(0, (int) substr($a, strlen('--skip-rows=')));
    }
}

// Separate unbuffered connection for the source read — streams all 65M+ rows
// without loading them into PHP memory at once. Writes go through the normal
// buffered $pdo from config/db.php.
$pdoRead = new PDO(
    "mysql:host=$DB_HOST;charset=utf8mb4",
    $DB_USER,
    $DB_PASS,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
    ]
);

$lockFile = __DIR__ . '/.import_active_' . $targetTable;
file_put_contents($lockFile, date('c'));

$skipLogPath = __DIR__ . '/karnataka_bdata_skipped.log';
$skipLog = fopen($skipLogPath, 'a');

const MAX_LEN = [
    'customer_code' => 32, 'name' => 128, 'father_name' => 128, 'gender' => 10,
    'mobile_no' => 15, 'alternative_no' => 15, 'email' => 191,
    'address' => 512, 'permanent_address' => 512, 'pincode' => 6,
    'identity_no' => 64, 'circle' => 64, 'merge_mob' => 15,
];
function cap(string $v, string $col): string {
    $v = @iconv('UTF-8', 'UTF-8//IGNORE', $v) ?: '';
    $max = MAX_LEN[$col] ?? null;
    return $max !== null ? mb_substr($v, 0, $max) : $v;
}
// bdata uses the literal string "Nil" (case-insensitive) for missing values
// instead of NULL/blank.
function cleanNil(?string $v): string {
    $v = trim((string) $v);
    return (strtolower($v) === 'nil') ? '' : $v;
}

$nextCodeRow = $pdo->query(
    "SELECT customer_code FROM `$targetTable` WHERE customer_code REGEXP '^CUST[0-9]+$' ORDER BY CAST(SUBSTRING(customer_code, 5) AS UNSIGNED) DESC LIMIT 1"
)->fetch();
$nextCodeNum = $nextCodeRow ? ((int) substr($nextCodeRow['customer_code'], 4)) + 1 : 1;
fwrite(STDOUT, "Next customer_code starts at CUST" . str_pad((string) $nextCodeNum, 4, '0', STR_PAD_LEFT) . "\n");

$updateClause = implode(', ', array_map(fn($col) => "$col = VALUES($col)", array_diff(CUSTOMER_COLUMNS, ['customer_code'])));
$preparedByRowCount = [];

function getBatchStatement(PDO $pdo, int $rowCount, array &$cache, string $updateClause, string $table): PDOStatement {
    if (isset($cache[$rowCount])) return $cache[$rowCount];
    $columnsSql = '(' . implode(', ', CUSTOMER_COLUMNS) . ')';
    $rowPlaceholder = '(' . implode(', ', array_fill(0, count(CUSTOMER_COLUMNS), '?')) . ')';
    $sql = 'INSERT INTO `' . $table . '` ' . $columnsSql
        . ' VALUES ' . implode(', ', array_fill(0, $rowCount, $rowPlaceholder))
        . ' ON DUPLICATE KEY UPDATE ' . $updateClause;
    $cache[$rowCount] = $pdo->prepare($sql);
    return $cache[$rowCount];
}

function flushBatch(
    array $batch, PDO $pdo, array &$cache, string $updateClause, string $table,
    $skipLog, int &$skippedRows, int &$validRows
): void {
    if (empty($batch)) return;
    $flatValues = [];
    foreach ($batch as $row) {
        foreach (CUSTOMER_COLUMNS as $col) $flatValues[] = $row[$col];
    }
    $stmt = getBatchStatement($pdo, count($batch), $cache, $updateClause, $table);
    try {
        $pdo->beginTransaction();
        $stmt->execute($flatValues);
        $pdo->commit();
        return;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
    $singleStmt = getBatchStatement($pdo, 1, $cache, $updateClause, $table);
    foreach ($batch as $row) {
        $values = [];
        foreach (CUSTOMER_COLUMNS as $col) $values[] = $row[$col];
        try {
            $singleStmt->execute($values);
        } catch (PDOException $e2) {
            $validRows--;
            $skippedRows++;
            fwrite($skipLog, "customer_code {$row['customer_code']}: " . $e2->getMessage() . "\n");
        }
    }
}

$startTime = microtime(true);
$rowNo = 0;
$validRows = 0;
$skippedRows = 0;
$batch = [];

$readStmt = $pdoRead->query(
    'SELECT State, Operator, mobile, CName, DOB, FName, LAdd, PAdd, Altno, Email, Gender FROM advbdata_import.bdata LIMIT 25'
);

if ($skipRows > 0) {
    while ($rowNo < $skipRows && $readStmt->fetch(PDO::FETCH_ASSOC) !== false) {
        $rowNo++;
    }
    fwrite(STDOUT, "Resuming: skipped $rowNo already-processed rows.\n");
}

while (($row = $readStmt->fetch(PDO::FETCH_ASSOC)) !== false) {
    $rowNo++;

    $name   = cleanNil($row['CName']);
    $mobile = cleanNil((string) $row['mobile']);
    if ($name === '' || $mobile === '') {
        $skippedRows++;
        fwrite($skipLog, "Row $rowNo: missing name or mobile.\n");
        continue;
    }

    $customerCode = 'CUST' . str_pad((string) $nextCodeNum, 4, '0', STR_PAD_LEFT);
    $nextCodeNum++;

    $batch[] = [
        'customer_code'      => cap($customerCode, 'customer_code'),
        'name'               => cap($name, 'name'),
        'mobile_no'          => cap($mobile, 'mobile_no'),
        'dob'                => normalizeDob(cleanNil($row['DOB'])),
        'gender'             => cap(normalizeGender(cleanNil($row['Gender'])) ?? '', 'gender'),
        'father_name'        => cap(cleanNil($row['FName']), 'father_name'),
        // PAdd used per explicit instruction; LAdd deliberately dropped entirely.
        'address'            => cap(cleanNil($row['PAdd']), 'address'),
        'permanent_address'  => '',
        'email'              => cap(cleanNil($row['Email']), 'email'),
        'alternative_no'     => cap(cleanNil($row['Altno']), 'alternative_no'),
        'identity_no'        => '',
        'circle'             => cap(cleanNil($row['Operator']), 'circle'),
        'merge_mob'          => '',
        'pincode'            => '',
    ];
    $validRows++;

    if (count($batch) >= $batchSize) {
        flushBatch($batch, $pdo, $preparedByRowCount, $updateClause, $targetTable, $skipLog, $skippedRows, $validRows);
        $batch = [];

        if ($validRows % ($batchSize * 25) === 0) {
            $elapsed = microtime(true) - $startTime;
            $rate = $validRows / max($elapsed, 0.001);
            printf("Processed %d rows in %.1fs (%.0f rows/sec)\n", $validRows, $elapsed, $rate);
        }
    }
}

flushBatch($batch, $pdo, $preparedByRowCount, $updateClause, $targetTable, $skipLog, $skippedRows, $validRows);
fclose($skipLog);

$elapsed = microtime(true) - $startTime;
printf("\nDone. %d rows imported, %d skipped, in %.1fs.\n", $validRows, $skippedRows, $elapsed);
if ($skippedRows > 0) {
    echo "Skipped-row details: $skipLogPath\n";
}

@unlink($lockFile);

<?php
// One-off: streams "D:\Dhanush Data\Kerala data.csv" (raw, headerless, no
// customer_code) into crm_db.customers_kerala_part2, which lives on D: via
// DATA DIRECTORY — customers_kerala itself (21.6M rows, on C:) is left
// untouched, same split used for Karnataka's part2 table.
//
// Source quirks handled here:
//  - The exporter wrote missing-value markers as `"N,"` instead of the
//    correct `"N",` (comma and closing quote swapped). Fixed per-line before
//    parsing.
//  - ~15% of rows have a stray quote mid-address that truncates the field
//    and spills the rest of the address onto the next physical line as a
//    bogus "row". Those spillover lines fail the mobile-number check below
//    and are skipped/logged; the row they belong to keeps its truncated
//    address rather than being dropped entirely.
//  - Only 5 of the 11 source columns are trustworthy (mobile, name, dob,
//    father_name, address). The rest (permanent_address, email, gender,
//    identity_no, alternative_no) are placeholder "N"/"null" in every row
//    sampled during review, so they're left blank rather than guessed at.
//    A trailing record-timestamp column is dropped (no matching field).

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

ini_set('memory_limit', '512M');
set_time_limit(0);

require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/import_helpers.php';

$targetTable = 'customers_kerala_part2';
$codeSourceTable = 'customers_kerala';
$filePath = 'D:/Dhanush Data/Kerala data.csv';
$batchSize = 2000;
$skipLines = 0;
$limitLines = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--skip-lines=')) {
        $skipLines = max(0, (int) substr($a, strlen('--skip-lines=')));
    } elseif (str_starts_with($a, '--limit=')) {
        $limitLines = max(1, (int) substr($a, strlen('--limit=')));
    }
}

$lockFile = __DIR__ . '/.import_active_' . $targetTable;
file_put_contents($lockFile, date('c'));

$skipLogPath = __DIR__ . '/kerala_dhanush_skipped.log';
$skipLog = fopen($skipLogPath, $skipLines > 0 ? 'a' : 'w');

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

// Source dob is either a real datetime, the literal "N" marker, or the
// MySQL zero-date "0000-00-00 00:00:00" placeholder — all non-dates return
// null here rather than a garbage date.
function normalizeDobSafe(string $value): ?string {
    $value = trim($value);
    if ($value === '' || $value === 'N' || str_starts_with($value, '0000-00-00')) {
        return null;
    }
    $ts = strtotime($value);
    return $ts !== false ? date('Y-m-d', $ts) : null;
}

$nextCodeRow = $pdo->query(
    "SELECT customer_code FROM `$codeSourceTable` WHERE customer_code REGEXP '^CUST[0-9]+$' ORDER BY CAST(SUBSTRING(customer_code, 5) AS UNSIGNED) DESC LIMIT 1"
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

$handle = fopen($filePath, 'r');
if ($handle === false) {
    fwrite(STDERR, "Could not open file: $filePath\n");
    exit(1);
}

$startTime = microtime(true);
$lineNo = 0;
$validRows = 0;
$skippedRows = 0;
$batch = [];

if ($skipLines > 0) {
    while ($lineNo < $skipLines && fgets($handle) !== false) {
        $lineNo++;
    }
    fwrite(STDOUT, "Resuming: skipped $lineNo already-processed lines.\n");
}

while (($line = fgets($handle)) !== false) {
    $lineNo++;
    if ($limitLines !== null && ($lineNo - $skipLines) > $limitLines) {
        break;
    }

    // A rare minority of rows use a stray backslash-escaped quote (\") instead
    // of this file's usual conventions — drop the backslash so the field
    // closes normally instead of swallowing the next field.
    $fixed = str_replace('\\"', '"', $line);
    // Fix the swapped comma/quote around the "N" placeholder marker before parsing.
    $fixed = str_replace('N,"', 'N",', $fixed);
    $row = str_getcsv(trim($fixed), ',', '"');

    $mobile = trim((string) ($row[0] ?? ''));
    $name   = trim((string) ($row[1] ?? ''));

    // Real mobile numbers in this source are mostly 10 digits but a genuine
    // minority are 8-9 (data-entry drops), so length isn't the filter — only
    // digit-ness is. This rejects the spillover fragments from a truncated
    // address on the previous line (they start with fragment text like
    // " Manhal" or "Taluka/tehsil", never pure digits), without discarding
    // real short numbers.
    if (!preg_match('/^[0-9]{6,15}$/', $mobile) || $name === '') {
        $skippedRows++;
        fwrite($skipLog, "Line $lineNo: bad mobile/name — " . substr(trim($line), 0, 120) . "\n");
        continue;
    }

    // When dob is the "N" marker, the comma/quote fix above still leaves the
    // next field's parse starting unquoted, which picks up a stray trailing
    // `"` (e.g. father_name comes out as `Raman"`) — strip it here.
    $dobRaw   = trim((string) ($row[2] ?? ''), " \t\n\r\0\x0B\"");
    $fname    = trim((string) ($row[3] ?? ''), " \t\n\r\0\x0B\"");
    $address  = trim((string) ($row[4] ?? ''), " \t\n\r\0\x0B\"");

    $customerCode = 'CUST' . str_pad((string) $nextCodeNum, 4, '0', STR_PAD_LEFT);
    $nextCodeNum++;

    $batch[] = [
        'customer_code'      => cap($customerCode, 'customer_code'),
        'name'               => cap($name, 'name'),
        'mobile_no'          => cap($mobile, 'mobile_no'),
        'dob'                => normalizeDobSafe($dobRaw === 'N' ? '' : $dobRaw),
        'gender'             => '',
        'father_name'        => cap($fname === 'N' ? '' : $fname, 'father_name'),
        'address'            => cap($address, 'address'),
        'permanent_address'  => '',
        'email'              => '',
        'alternative_no'     => '',
        'identity_no'        => '',
        'circle'             => '',
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
            printf("Line %d: %d imported, %d skipped, in %.1fs (%.0f rows/sec)\n", $lineNo, $validRows, $skippedRows, $elapsed, $rate);
        }
    }
}

flushBatch($batch, $pdo, $preparedByRowCount, $updateClause, $targetTable, $skipLog, $skippedRows, $validRows);
fclose($handle);
fclose($skipLog);

$elapsed = microtime(true) - $startTime;
printf("\nDone. %d lines read, %d imported, %d skipped, in %.1fs.\n", $lineNo, $validRows, $skippedRows, $elapsed);
echo "Skipped-row details: $skipLogPath\n";

@unlink($lockFile);

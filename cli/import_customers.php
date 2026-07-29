<?php
// CLI-only bulk importer for large (millions-of-rows) CSV/TSV files.
// Usage: php cli/import_customers.php <path-to-file> <state> [batch_size]
// Streams the file line-by-line and writes in large batches, so memory use
// stays flat regardless of file size — unlike the web upload importer.
//
// NOTE: rows are only matched to an existing customer when the file supplies
// a "customer_code" column. Without one, every run inserts brand-new rows —
// re-running the importer on the same file will create duplicates.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

ini_set('memory_limit', '512M');
set_time_limit(0);

require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/xlsx_reader.php';
require __DIR__ . '/../includes/import_helpers.php';
require __DIR__ . '/../includes/customer_code.php';

$stateTableMap = [
    'Karnataka'      => 'customers_karnataka',
    'Tamil Nadu'     => 'customers_tamil_nadu',
    'Kerala'         => 'customers_kerala',
    'Andhra Pradesh' => 'customers_andhra_pradesh',
];

$args = $argv;
array_shift($args); // drop script name
$columnsArg = null;
$skipLines = 0;
$positional = [];
foreach ($args as $a) {
    if (str_starts_with($a, '--columns=')) {
        $columnsArg = substr($a, strlen('--columns='));
    } elseif (str_starts_with($a, '--skip-lines=')) {
        $skipLines = max(0, (int) substr($a, strlen('--skip-lines=')));
    } else {
        $positional[] = $a;
    }
}

$filePath  = $positional[0] ?? null;
$stateName = $positional[1] ?? null;
$batchSize = isset($positional[2]) ? max(1, (int) $positional[2]) : 2000;

if ($filePath === null || !is_file($filePath) || $stateName === null) {
    fwrite(STDERR, "Usage: php cli/import_customers.php <path-to-csv-or-tsv-file> <state> [batch_size] [--columns=name,father_name,...]\n");
    fwrite(STDERR, "States: " . implode(', ', array_keys($stateTableMap)) . "\n");
    fwrite(STDERR, "--columns= is for headerless files: give the canonical field name for each column, in order, skipping columns to ignore with an empty entry.\n");
    exit(1);
}

$targetTable = $stateTableMap[$stateName] ?? '';
if ($targetTable === '') {
    fwrite(STDERR, "Unknown state: $stateName\nValid states: " . implode(', ', array_keys($stateTableMap)) . "\n");
    exit(1);
}

// Lock marker: tells api/search.php to skip FULLTEXT on this table while we're
// hammering it with inserts (MySQL 5.7's InnoDB FTS engine can crash under that
// combination). Removed only once the import fully completes below.
$lockFile = __DIR__ . '/.import_active_' . $targetTable;
file_put_contents($lockFile, date('c'));

$skipLogPath = $filePath . '.import_skipped.log';
$skipLog = fopen($skipLogPath, 'w');

$handle = fopen($filePath, 'r');
if ($handle === false) {
    fwrite(STDERR, "Could not open file: $filePath\n");
    exit(1);
}

$firstLine = fgets($handle);
rewind($handle);
$delimiter = detectDelimiter($firstLine === false ? '' : $firstLine);

if ($columnsArg !== null) {
    // Headerless file: caller supplied the column order directly; line 1 is data, not a header.
    $fieldByColumn = [];
    foreach (explode(',', $columnsArg) as $i => $col) {
        $col = trim($col);
        if ($col !== '') {
            $fieldByColumn[$i] = $col;
        }
    }
} else {
    $headerRow = fgetcsv($handle, 0, $delimiter);
    if ($headerRow === false) {
        fwrite(STDERR, "File appears to be empty.\n");
        exit(1);
    }
    $fieldByColumn = mapHeaderRow($headerRow);
}

if (!in_array('name', $fieldByColumn, true) || !in_array('mobile_no', $fieldByColumn, true)) {
    fwrite(STDERR, 'The file must have a "Name" column and a "Mobile No" column. Recognized headers: '
        . implode(', ', array_unique(array_keys(FIELD_HEADER_MAP))) . "\n");
    exit(1);
}

// Reserve a block of codes instead of scanning the whole table for the
// current max (see includes/customer_code.php) — that REGEXP scan measured
// 110s+ on an 80M-row table, pure overhead paid before any real work starts.
// This file's own docstring says "millions of rows", so reserve generously;
// codeBlockCeiling tracks when to reserve another block mid-run.
$codeBlockSize    = 2_000_000;
$nextCodeNum      = reserveCustomerCodeBlock($pdo, $targetTable, $codeBlockSize) + 1;
$codeBlockCeiling = $nextCodeNum + $codeBlockSize - 1;

$updateClause = implode(', ', array_map(fn($col) => "$col = VALUES($col)", array_diff(CUSTOMER_COLUMNS, ['customer_code'])));
$preparedByRowCount = [];

// Schema max lengths — dirty source data can exceed these; cap instead of crashing.
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

function getBatchStatement(PDO $pdo, int $rowCount, array &$cache, string $updateClause, string $table): PDOStatement {
    if (isset($cache[$rowCount])) {
        return $cache[$rowCount];
    }
    $columnsSql = '(' . implode(', ', CUSTOMER_COLUMNS) . ')';
    $rowPlaceholder = '(' . implode(', ', array_fill(0, count(CUSTOMER_COLUMNS), '?')) . ')';
    $sql = 'INSERT INTO `' . $table . '` ' . $columnsSql
        . ' VALUES ' . implode(', ', array_fill(0, $rowCount, $rowPlaceholder))
        . ' ON DUPLICATE KEY UPDATE ' . $updateClause;
    $cache[$rowCount] = $pdo->prepare($sql);
    return $cache[$rowCount];
}

$startTime = microtime(true);
$lineNo = $columnsArg !== null ? 0 : 1; // line 1 was a header, unless headerless (--columns)
$validRows = 0;
$skippedRows = 0;
$batch = [];

function flushBatch(
    array $batch, PDO $pdo, array &$cache, string $updateClause, string $table,
    $skipLog, int &$skippedRows, int &$validRows
): void {
    if (empty($batch)) {
        return;
    }
    $flatValues = [];
    foreach ($batch as $row) {
        foreach (CUSTOMER_COLUMNS as $col) {
            $flatValues[] = $row[$col];
        }
    }
    $stmt = getBatchStatement($pdo, count($batch), $cache, $updateClause, $table);
    try {
        $pdo->beginTransaction();
        $stmt->execute($flatValues);
        $pdo->commit();
        return;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
    // Batch failed (one bad row can poison an entire multi-row INSERT) — retry
    // row by row so the rest of the batch still gets imported, and log whichever
    // individual row(s) still fail instead of losing the whole batch.
    $singleStmt = getBatchStatement($pdo, 1, $cache, $updateClause, $table);
    foreach ($batch as $row) {
        $values = [];
        foreach (CUSTOMER_COLUMNS as $col) {
            $values[] = $row[$col];
        }
        try {
            $singleStmt->execute($values);
        } catch (PDOException $e2) {
            $validRows--;
            $skippedRows++;
            fwrite($skipLog, "customer_code {$row['customer_code']}: " . $e2->getMessage() . "\n");
        }
    }
}

if ($skipLines > 0) {
    // Resuming a previous run: fast-forward past already-imported lines without
    // re-inserting them (no DB work here, just discarding already-read rows).
    for ($i = 0; $i < $skipLines && fgetcsv($handle, 0, $delimiter) !== false; $i++) {
        $lineNo++;
    }
    fwrite(STDOUT, "Resuming: skipped $lineNo already-processed lines.\n");
}

while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
    $lineNo++;
    if (count(array_filter($row, fn($c) => trim((string) $c) !== '')) === 0) {
        continue; // fully blank line
    }

    $rec = buildCustomerRecord($row, $fieldByColumn);

    if (($rec['name'] ?? '') === '' || ($rec['mobile_no'] ?? '') === '') {
        $skippedRows++;
        fwrite($skipLog, "Line $lineNo: missing name or mobile number.\n");
        continue;
    }

    $customerCode = $rec['customer_code'] ?? '';
    if ($customerCode === '') {
        if ($nextCodeNum > $codeBlockCeiling) {
            $nextCodeNum      = reserveCustomerCodeBlock($pdo, $targetTable, $codeBlockSize) + 1;
            $codeBlockCeiling = $nextCodeNum + $codeBlockSize - 1;
        }
        $customerCode = 'CUST' . str_pad((string) $nextCodeNum, 4, '0', STR_PAD_LEFT);
        $nextCodeNum++;
    }

    // All customer columns are NOT NULL (default '') except dob, which is nullable.
    // cap() also truncates to the schema's max length and strips invalid UTF-8.
    $batch[] = [
        'customer_code' => cap($customerCode, 'customer_code'),
        'name' => cap($rec['name'], 'name'),
        'mobile_no' => cap($rec['mobile_no'], 'mobile_no'),
        'dob' => normalizeDob($rec['dob'] ?? ''),
        'gender' => cap(normalizeGender($rec['gender'] ?? '') ?? '', 'gender'),
        'father_name' => cap($rec['father_name'] ?? '', 'father_name'),
        'address' => cap($rec['address'] ?? '', 'address'),
        'permanent_address' => cap($rec['permanent_address'] ?? '', 'permanent_address'),
        'email' => cap($rec['email'] ?? '', 'email'),
        'alternative_no' => cap($rec['alternative_no'] ?? '', 'alternative_no'),
        'identity_no' => cap($rec['identity_no'] ?? '', 'identity_no'),
        'circle' => cap($rec['circle'] ?? '', 'circle'),
        'merge_mob' => cap($rec['merge_mob'] ?? '', 'merge_mob'),
        'pincode' => cap($rec['pincode'] ?? '', 'pincode'),
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
fclose($handle);
fclose($skipLog);

$elapsed = microtime(true) - $startTime;
printf("\nDone. %d rows imported, %d skipped, in %.1fs.\n", $validRows, $skippedRows, $elapsed);
if ($skippedRows > 0) {
    echo "Skipped-row details: $skipLogPath\n";
} else {
    @unlink($skipLogPath);
}
@unlink($lockFile); // import finished — FULLTEXT search on this table is safe again

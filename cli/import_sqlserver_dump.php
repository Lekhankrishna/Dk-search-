<?php
// CLI-only importer for SQL Server (T-SQL) script dumps containing
// Tbl_ChndataN tables (UTF-16LE encoded), streamed and mapped into the
// MySQL customers table without ever loading the whole file into memory.
// Usage: php cli/import_sqlserver_dump.php <path-to-sql-file> [--dry-run] [batch_size]
//
// --dry-run scans the file and reports row counts / truncation stats
// without writing to the database — use it first to check the data
// before committing to a real import.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

ini_set('memory_limit', '512M');
set_time_limit(0);

require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/import_helpers.php';
require __DIR__ . '/../includes/xlsx_reader.php'; // excelSerialToDate(), used by normalizeDob()

$args = array_slice($argv, 1);
$dryRun = false;
$positional = [];
foreach ($args as $a) {
    if ($a === '--dry-run') {
        $dryRun = true;
    } else {
        $positional[] = $a;
    }
}

$filePath = $positional[0] ?? null;
$batchSize = isset($positional[1]) ? max(1, (int) $positional[1]) : 2000;

if ($filePath === null || !is_file($filePath)) {
    fwrite(STDERR, "Usage: php cli/import_sqlserver_dump.php <path-to-sql-file> [--dry-run] [batch_size]\n");
    exit(1);
}

const CHN_COLUMN_MAP = [
    'chnmobileno' => 'mobile_no',
    'chnname' => 'name',
    'chndob' => 'dob',
    'chnfathername' => 'father_name',
    'chnaddress' => 'address',
    'chnperaddress' => 'permanent_address',
    'chnemail' => 'email',
    'chngender' => 'gender',
    'chnalternativeno' => 'alternative_no',
    'chnidentity' => 'identity_no',
    'chnmergemob' => 'merge_mob',
    'chnpincode' => 'pincode',
];

// Mirrors the column widths in schema.sql — source data is much wider
// (e.g. identity_no source column is varchar(600)), so truncation is
// expected and tracked below rather than left to fail at INSERT time.
const FIELD_MAX_LENGTHS = [
    'name' => 100, 'mobile_no' => 15, 'father_name' => 100, 'address' => 255,
    'permanent_address' => 255, 'email' => 100, 'alternative_no' => 15,
    'identity_no' => 255, 'circle' => 50, 'merge_mob' => 50, 'pincode' => 10,
];

function utf16LinesGenerator($handle): Generator {
    $chunkSize = 1 << 20;
    $carryBytes = '';
    $carryText = '';
    $first = true;
    while (!feof($handle)) {
        $chunk = fread($handle, $chunkSize);
        if ($chunk === false || $chunk === '') {
            continue;
        }
        $data = $carryBytes . $chunk;
        if (strlen($data) % 2 !== 0) {
            $carryBytes = substr($data, -1);
            $data = substr($data, 0, -1);
        } else {
            $carryBytes = '';
        }
        $text = iconv('UTF-16LE', 'UTF-8//IGNORE', $data);
        if ($first) {
            if (substr($text, 0, 3) === "\xEF\xBB\xBF") {
                $text = substr($text, 3);
            }
            $first = false;
        }
        $text = $carryText . $text;
        $parts = explode("\n", $text);
        $carryText = array_pop($parts);
        foreach ($parts as $line) {
            yield rtrim($line, "\r");
        }
    }
    if ($carryText !== '') {
        yield rtrim($carryText, "\r");
    }
}

// Tokenizes a SQL Server VALUES(...) tuple body, honoring N'...' / '...'
// strings (with '' as an escaped quote) and bare NULL/numeric tokens.
function parseSqlValuesTuple(string $s): array {
    $tokens = [];
    $i = 0;
    $len = strlen($s);
    while ($i < $len) {
        while ($i < $len && ($s[$i] === ' ' || $s[$i] === "\t")) $i++;
        if ($i >= $len) break;
        if ($s[$i] === 'N' && ($s[$i + 1] ?? '') === "'") {
            $i++;
        }
        if ($s[$i] === "'") {
            $i++;
            $val = '';
            while ($i < $len) {
                if ($s[$i] === "'") {
                    if (($s[$i + 1] ?? '') === "'") {
                        $val .= "'";
                        $i += 2;
                        continue;
                    }
                    $i++;
                    break;
                }
                $val .= $s[$i];
                $i++;
            }
            $tokens[] = $val;
        } else {
            $start = $i;
            while ($i < $len && $s[$i] !== ',') $i++;
            $raw = trim(substr($s, $start, $i - $start));
            $tokens[] = (strcasecmp($raw, 'NULL') === 0) ? null : $raw;
        }
        while ($i < $len && ($s[$i] === ' ' || $s[$i] === "\t")) $i++;
        if ($i < $len && $s[$i] === ',') $i++;
    }
    return $tokens;
}

function parseChnDob(?string $v): ?string {
    $v = trim((string) $v);
    if ($v === '') return null;
    $d = DateTime::createFromFormat('d/m/Y', $v);
    if ($d !== false && $d->format('d/m/Y') === $v) {
        return $d->format('Y-m-d');
    }
    return normalizeDob($v);
}

function truncateField(?string $val, string $field, array &$truncCounts): ?string {
    if ($val === null || $val === '') return null;
    $max = FIELD_MAX_LENGTHS[$field] ?? null;
    if ($max !== null && mb_strlen($val) > $max) {
        $truncCounts[$field] = ($truncCounts[$field] ?? 0) + 1;
        return mb_substr($val, 0, $max);
    }
    return $val;
}

function getBatchStatement(PDO $pdo, int $rowCount, array &$cache, string $updateClause): PDOStatement {
    if (isset($cache[$rowCount])) {
        return $cache[$rowCount];
    }
    $columnsSql = '(' . implode(', ', CUSTOMER_COLUMNS) . ')';
    $rowPlaceholder = '(' . implode(', ', array_fill(0, count(CUSTOMER_COLUMNS), '?')) . ')';
    $sql = 'INSERT INTO customers ' . $columnsSql
        . ' VALUES ' . implode(', ', array_fill(0, $rowCount, $rowPlaceholder))
        . ' ON DUPLICATE KEY UPDATE ' . $updateClause;
    $cache[$rowCount] = $pdo->prepare($sql);
    return $cache[$rowCount];
}

function flushBatch(array $batch, PDO $pdo, array &$cache, string $updateClause): void {
    if (empty($batch)) return;
    $flatValues = [];
    foreach ($batch as $row) {
        foreach (CUSTOMER_COLUMNS as $col) {
            $flatValues[] = $row[$col];
        }
    }
    $stmt = getBatchStatement($pdo, count($batch), $cache, $updateClause);
    $pdo->beginTransaction();
    $stmt->execute($flatValues);
    $pdo->commit();
}

$skipLogPath = $filePath . '.import_skipped.log';
$skipLog = fopen($skipLogPath, 'w');

$handle = fopen($filePath, 'rb');
if ($handle === false) {
    fwrite(STDERR, "Could not open file: $filePath\n");
    exit(1);
}

$nextCodeNum = 1;
if (!$dryRun) {
    $nextCodeRow = $pdo->query(
        "SELECT customer_code FROM customers WHERE customer_code REGEXP '^CUST[0-9]+$' ORDER BY CAST(SUBSTRING(customer_code, 5) AS UNSIGNED) DESC LIMIT 1"
    )->fetch();
    $nextCodeNum = $nextCodeRow ? ((int) substr($nextCodeRow['customer_code'], 4)) + 1 : 1;
}

$updateClause = implode(', ', array_map(fn($col) => "$col = VALUES($col)", array_diff(CUSTOMER_COLUMNS, ['customer_code'])));
$preparedByRowCount = [];

$insertLineRegex = '/^INSERT\s+\[dbo\]\.\[([^\]]+)\]\s*\(([^)]*)\)\s*VALUES\s*\((.*)\)\s*$/i';

$inCreateTable = false;
$lineNo = 0;
$validRows = 0;
$skippedRows = 0;
$truncCounts = [];
$tablesSeen = [];
$batch = [];
$startTime = microtime(true);

foreach (utf16LinesGenerator($handle) as $line) {
    $lineNo++;
    $trimmed = trim($line);

    if ($trimmed === '') continue;

    if ($inCreateTable) {
        if ($trimmed === 'GO') $inCreateTable = false;
        continue;
    }
    if (stripos($trimmed, 'CREATE TABLE') === 0) {
        $inCreateTable = true;
        continue;
    }
    if (stripos($trimmed, 'INSERT') !== 0) {
        continue; // GO / SET .../ USE .../ comments / ALTER TABLE / EXEC sp_... etc.
    }

    if (!preg_match($insertLineRegex, $trimmed, $m)) {
        $skippedRows++;
        fwrite($skipLog, "Line $lineNo: could not parse INSERT statement.\n");
        continue;
    }

    [, $tableName, $colListRaw, $valuesRaw] = $m;
    $tablesSeen[$tableName] = ($tablesSeen[$tableName] ?? 0) + 1;

    $colNames = array_map(
        fn($c) => strtolower(trim($c, " \t[]")),
        explode(',', $colListRaw)
    );
    $values = parseSqlValuesTuple($valuesRaw);

    $rec = [];
    foreach ($colNames as $idx => $colName) {
        if (isset(CHN_COLUMN_MAP[$colName])) {
            $rec[CHN_COLUMN_MAP[$colName]] = $values[$idx] ?? null;
        }
    }

    $name = trim((string) ($rec['name'] ?? ''));
    $mobile = trim((string) ($rec['mobile_no'] ?? ''));
    if ($name === '' || $mobile === '') {
        $skippedRows++;
        fwrite($skipLog, "Line $lineNo: missing name or mobile number.\n");
        continue;
    }

    $customerCode = 'CUST' . str_pad((string) $nextCodeNum, 4, '0', STR_PAD_LEFT);
    $nextCodeNum++;

    $row = [
        'customer_code' => $customerCode,
        'name' => truncateField($name, 'name', $truncCounts),
        'mobile_no' => truncateField($mobile, 'mobile_no', $truncCounts),
        'dob' => parseChnDob($rec['dob'] ?? null),
        'gender' => normalizeGender((string) ($rec['gender'] ?? '')),
        'father_name' => truncateField(trim((string) ($rec['father_name'] ?? '')), 'father_name', $truncCounts),
        'address' => truncateField(trim((string) ($rec['address'] ?? '')), 'address', $truncCounts),
        'permanent_address' => truncateField(trim((string) ($rec['permanent_address'] ?? '')), 'permanent_address', $truncCounts),
        'email' => truncateField(trim((string) ($rec['email'] ?? '')), 'email', $truncCounts),
        'alternative_no' => truncateField(trim((string) ($rec['alternative_no'] ?? '')), 'alternative_no', $truncCounts),
        'identity_no' => truncateField(trim((string) ($rec['identity_no'] ?? '')), 'identity_no', $truncCounts),
        'circle' => null,
        'merge_mob' => truncateField(trim((string) ($rec['merge_mob'] ?? '')), 'merge_mob', $truncCounts),
        'pincode' => truncateField(trim((string) ($rec['pincode'] ?? '')), 'pincode', $truncCounts),
    ];

    $validRows++;

    if ($dryRun) {
        if ($validRows % 100000 === 0) {
            printf("Scanned %d rows...\n", $validRows);
        }
        continue;
    }

    $batch[] = $row;
    if (count($batch) >= $batchSize) {
        flushBatch($batch, $pdo, $preparedByRowCount, $updateClause);
        $batch = [];
        if ($validRows % ($batchSize * 25) === 0) {
            $elapsed = microtime(true) - $startTime;
            $rate = $validRows / max($elapsed, 0.001);
            printf("Processed %d rows in %.1fs (%.0f rows/sec)\n", $validRows, $elapsed, $rate);
        }
    }
}

if (!$dryRun) {
    flushBatch($batch, $pdo, $preparedByRowCount, $updateClause);
}
fclose($handle);
fclose($skipLog);

$elapsed = microtime(true) - $startTime;
printf(
    "\n%s %d rows %s, %d skipped, in %.1fs.\n",
    $dryRun ? 'Scanned' : 'Done.',
    $validRows,
    $dryRun ? 'matched' : 'imported',
    $skippedRows,
    $elapsed
);

echo "\nTables encountered:\n";
foreach ($tablesSeen as $t => $c) {
    echo "  $t: $c rows\n";
}

if (!empty($truncCounts)) {
    echo "\nFields truncated to fit column limits (data loss — consider widening these columns before re-running):\n";
    foreach ($truncCounts as $field => $count) {
        echo "  $field: $count rows truncated (max " . FIELD_MAX_LENGTHS[$field] . " chars)\n";
    }
}

if ($skippedRows > 0) {
    echo "\nSkipped-row details: $skipLogPath\n";
} else {
    @unlink($skipLogPath);
}

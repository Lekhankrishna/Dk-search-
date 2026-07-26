<?php
// Recovery pass over cli/kerala_dhanush_skipped.log from import_kerala_dhanush.php.
// A ~183k-row block of the source file (roughly original lines 8.12M-8.30M) uses
// a different column layout than the rest: [N, name, N, father_name, email, N, N,
// mobile, N, N, timestamp] — mobile lives at index 7, not index 0, and email is
// real data here (unlike the main format where it's always a placeholder). Those
// rows got rejected by the primary import's index-0 mobile check; this recovers
// them into the same customers_kerala_part2 table. Everything else in the skip
// log (genuinely too-short/garbled mobile numbers) is left skipped.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

ini_set('memory_limit', '1024M');
set_time_limit(0);

require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/import_helpers.php';

$targetTable = 'customers_kerala_part2';
$skipLogPath = __DIR__ . '/kerala_dhanush_skipped.log';
$stillSkippedPath = __DIR__ . '/kerala_dhanush_still_skipped.log';

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
function cleanField(string $v): string {
    return trim($v, " \t\n\r\0\x0B\"");
}

// Next code must come from the table we're actually inserting into — the main
// import already burned CUST numbers up to here against customers_kerala_part2.
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

function flushBatch(array $batch, PDO $pdo, array &$cache, string $updateClause, string $table): void {
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
        try { $singleStmt->execute($values); } catch (PDOException $e2) { /* dropped */ }
    }
}

// Some skip-log entries contain an embedded raw newline (the same source
// corruption that hits addresses elsewhere can hit the name field here too),
// so a plain fgets()-per-line read would silently truncate those entries.
// Split on the "Line N: ..." prefix instead, which reliably marks the start
// of each entry regardless of what's embedded inside it.
$content = file_get_contents($skipLogPath);
$entries = preg_split('/(?=^Line \d+: bad mobile\/name — )/m', $content, -1, PREG_SPLIT_NO_EMPTY);

$stillSkipped = fopen($stillSkippedPath, 'w');

$batch = [];
$batchSize = 2000;
$recovered = 0;
$stillBad = 0;
$lineNo = 0;
$startTime = microtime(true);

foreach ($entries as $logLine) {
    $lineNo++;
    if (!preg_match('/^Line \d+: bad mobile\/name — (.*)$/s', $logLine, $m)) {
        continue;
    }
    $raw = rtrim($m[1], "\r\n");

    $fixed = str_replace('\\"', '"', $raw);
    $fixed = str_replace('N,"', 'N",', $fixed);
    $row = str_getcsv(trim($fixed), ',', '"');

    // Field positions in this alt layout aren't fixed — a middle field (most
    // often father_name) is sometimes omitted entirely rather than left as an
    // explicit "N" placeholder, which shifts every field after it. name at
    // index 1 has held in every sample checked, but email/mobile are found by
    // content instead of position.
    $name  = cleanField((string) ($row[1] ?? ''));
    $email = '';
    $mobileCandidate = '';
    $fname = '';
    foreach ($row as $idx => $v) {
        if ($idx === 1) continue;
        $cv = cleanField((string) $v);
        if ($email === '' && strpos($cv, '@') !== false) {
            $email = $cv;
        } elseif ($mobileCandidate === '' && preg_match('/^[0-9]{6,15}$/', $cv)) {
            $mobileCandidate = $cv;
        } elseif ($fname === '' && $idx === 3 && $cv !== '' && $cv !== 'N' && strpos($cv, '@') === false && !ctype_digit($cv)) {
            $fname = $cv;
        }
    }

    if ($name === '' || $email === '' || $mobileCandidate === '') {
        fwrite($stillSkipped, $logLine);
        $stillBad++;
        continue;
    }

    $customerCode = 'CUST' . str_pad((string) $nextCodeNum, 4, '0', STR_PAD_LEFT);
    $nextCodeNum++;

    $batch[] = [
        'customer_code'      => cap($customerCode, 'customer_code'),
        'name'               => cap($name, 'name'),
        'mobile_no'          => cap($mobileCandidate, 'mobile_no'),
        'dob'                => null,
        'gender'             => '',
        'father_name'        => cap($fname, 'father_name'),
        'address'            => '',
        'permanent_address'  => '',
        'email'              => cap($email, 'email'),
        'alternative_no'     => '',
        'identity_no'        => '',
        'circle'             => '',
        'merge_mob'          => '',
        'pincode'            => '',
    ];
    $recovered++;

    if (count($batch) >= $batchSize) {
        flushBatch($batch, $pdo, $preparedByRowCount, $updateClause, $targetTable);
        $batch = [];
    }
}

flushBatch($batch, $pdo, $preparedByRowCount, $updateClause, $targetTable);
fclose($stillSkipped);

$elapsed = microtime(true) - $startTime;
printf("Done. %d recovered, %d still unrecoverable, in %.1fs.\n", $recovered, $stillBad, $elapsed);
printf("Remaining unrecoverable rows logged to: %s\n", $stillSkippedPath);

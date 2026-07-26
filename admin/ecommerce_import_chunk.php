<?php
// AJAX backend for the chunked E-Commerce import (admin/ecommerce_import.php).
// A single blocking form POST can't report progress mid-request, so the
// upload happens once ("init" — saves the file server-side, normalized to a
// plain comma CSV with the header stripped, so CSV and XLSX share one
// processing path from here on), then the browser drives repeated "chunk"
// calls that each insert a batch and report back how far along things are.
// The client computes elapsed-time-based ETA from the reported progress.
require __DIR__ . '/../includes/auth.php';
requireAdmin('../login.php');
require_once __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/xlsx_reader.php';
require __DIR__ . '/../includes/import_helpers.php'; // normalizeHeader(), detectDelimiter(), normalizeDob()
require __DIR__ . '/../includes/ecommerce_import_helpers.php';

header('Content-Type: application/json');

const BATCH_SIZE = 500;

function respondError(string $msg): void {
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$tmpDir = sys_get_temp_dir() . '/crm_ecommerce_import';
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0700, true);
}
// Opportunistic cleanup of abandoned sessions (tab closed mid-import, etc.) —
// there's no background job in this app to do it otherwise. Cheap enough to
// run on every request; only touches files older than an hour.
foreach (glob("$tmpDir/*") as $f) {
    if (is_file($f) && filemtime($f) < time() - 3600) {
        @unlink($f);
    }
}

$action = $_POST['action'] ?? '';

if ($action === 'init') {
    if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
        respondError('Please choose a CSV or XLSX file to upload (check it is under the server upload size limit).');
    }
    $tmpPath      = $_FILES['import_file']['tmp_name'];
    $originalName = $_FILES['import_file']['name'];
    $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    $token    = bin2hex(random_bytes(16));
    $dataFile = "$tmpDir/$token.csv";

    try {
        if ($ext === 'csv') {
            $firstLine = file($tmpPath, FILE_IGNORE_NEW_LINES)[0] ?? '';
            $delimiter = detectDelimiter($firstLine);
            $in = fopen($tmpPath, 'r');
            $headerRow = fgetcsv($in, 0, $delimiter);
            if ($headerRow === false) {
                fclose($in);
                respondError('The file appears to be empty.');
            }
            $out = fopen($dataFile, 'w');
            $totalRows = 0;
            while (($row = fgetcsv($in, 0, $delimiter)) !== false) {
                fputcsv($out, $row);
                $totalRows++;
            }
            fclose($in);
            fclose($out);
        } elseif ($ext === 'xlsx') {
            $rows = readXlsxRows($tmpPath);
            if (count($rows) < 1) respondError('The file appears to be empty.');
            $headerRow = array_shift($rows);
            $out = fopen($dataFile, 'w');
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
            $totalRows = count($rows);
        } else {
            respondError('Unsupported file type. Please upload a .csv or .xlsx file.');
        }
    } catch (Throwable $e) {
        @unlink($dataFile);
        respondError('Could not read the file: ' . $e->getMessage());
    }

    // Mobile number is the only truly required column — see the matching
    // per-row check below for why (per explicit instruction).
    $fieldByColumn = mapEcommerceHeaderRow($headerRow);
    if (!in_array('mobile_no', $fieldByColumn, true)) {
        @unlink($dataFile);
        respondError(
            'The file must have a "Mobile Number" column. ' .
            'Recognised headers: ' . implode(', ', array_unique(array_keys(ECOMMERCE_FIELD_HEADER_MAP)))
        );
    }

    file_put_contents("$tmpDir/$token.json", json_encode(['fieldByColumn' => $fieldByColumn]));

    echo json_encode(['ok' => true, 'token' => $token, 'totalRows' => $totalRows, 'batchSize' => BATCH_SIZE]);
    exit;
}

if ($action === 'chunk') {
    $token = $_POST['token'] ?? '';
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
        respondError('Invalid upload session.');
    }
    $dataFile = "$tmpDir/$token.csv";
    $metaFile = "$tmpDir/$token.json";
    if (!is_file($dataFile) || !is_file($metaFile)) {
        respondError('Upload session expired or not found — please re-upload.');
    }

    $meta = json_decode(file_get_contents($metaFile), true);
    // JSON object keys are always strings — mapEcommerceHeaderRow()'s column
    // indexes need to be ints again to match the row arrays fgetcsv() returns.
    $fieldByColumn = array_combine(
        array_map('intval', array_keys($meta['fieldByColumn'])),
        array_values($meta['fieldByColumn'])
    );

    // Skip-based resume rather than byte offsets/fseek — simpler and avoids
    // any fgetcsv+ftell edge cases; skipping via plain fgetcsv() reads is
    // fast enough (no DB work, no field mapping) that re-walking prior rows
    // each chunk is not a meaningful cost even at tens of thousands of rows.
    $skip = max(0, (int) ($_POST['skip'] ?? 0));

    $handle = fopen($dataFile, 'r');
    for ($i = 0; $i < $skip; $i++) {
        if (fgetcsv($handle) === false) break;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO ecommerce_orders (name, mobile_no, address, delivery_date, latitude, longitude)
         VALUES (:name, :mobile_no, :address, :delivery_date, :latitude, :longitude)'
    );

    $processed = 0;
    $inserted  = 0;
    $skippedReasons = [];
    $reachedEnd = false;

    while ($processed < BATCH_SIZE) {
        $row = fgetcsv($handle);
        if ($row === false) {
            $reachedEnd = true;
            break;
        }
        $processed++;

        // Counted separately from "missing mobile number" — a row that's
        // entirely empty (every field blank) is structurally different from
        // one with data in some other column but no mobile number, and the
        // two were getting conflated into one invisible number before
        // (found 2026-07-19: a real file's processed/inserted/skipped counts
        // didn't add up — 6,059 rows were vanishing into this branch with no
        // reporting at all). Confirmed via temporary diagnostic logging that
        // these are genuinely empty rows in the source file (a long
        // unbroken trailing block starting partway through — the classic
        // signature of a spreadsheet formatting/formula range extending
        // past the real data), not a parsing bug.
        if (count(array_filter($row, fn($c) => trim((string) $c) !== '')) === 0) {
            $skippedReasons[] = 'blank row';
            continue;
        }

        $rec = buildEcommerceRecord($row, $fieldByColumn);
        // Mobile number is the only truly required field — it's who you'd
        // actually contact for delivery. A blank name is still worth keeping
        // if there's a number to reach them on; a blank number isn't (per
        // explicit instruction — this used to also require name).
        if (($rec['mobile_no'] ?? '') === '') {
            $skippedReasons[] = 'missing mobile number';
            continue;
        }

        $latRaw = $rec['latitude'] ?? '';
        $lngRaw = $rec['longitude'] ?? '';
        if ($latRaw === '' && $lngRaw === '' && ($rec['location'] ?? '') !== '') {
            [$latRaw, $lngRaw] = splitLocationValue($rec['location']);
        }

        // A date field should never be able to sink the whole row — if
        // normalizeDeliveryDate() ever returns something MySQL's DATE column
        // won't accept (out of its 1000-9999 year range), drop just the date
        // instead of losing the row's name/mobile/address to an INSERT
        // failure (found 2026-07-19: unparseable dates were doing exactly
        // that instead of being skipped as just a blank date).
        $deliveryDate = normalizeDeliveryDate($rec['delivery_date'] ?? '');
        if ($deliveryDate !== null) {
            $y = (int) substr($deliveryDate, 0, 4);
            if ($y < 1000 || $y > 9999) $deliveryDate = null;
        }

        $params = [
            'name'          => mb_substr($rec['name'] ?? '', 0, 128),
            'mobile_no'     => mb_substr($rec['mobile_no'], 0, 15),
            'address'       => mb_substr($rec['address'] ?? '', 0, 512),
            'delivery_date' => $deliveryDate,
            'latitude'      => normalizeLatLng($latRaw, -90, 90),
            'longitude'     => normalizeLatLng($lngRaw, -180, 180),
        ];

        try {
            $stmt->execute($params);
            $inserted++;
        } catch (PDOException $e) {
            $skippedReasons[] = $e->getMessage();
        }
    }
    fclose($handle);

    if ($reachedEnd) {
        @unlink($dataFile);
        @unlink($metaFile);
    }

    echo json_encode([
        'ok'             => true,
        'processed'      => $processed,
        'inserted'       => $inserted,
        'skipped'        => count($skippedReasons),
        'skippedReasons' => $skippedReasons,
        'done'           => $reachedEnd,
    ]);
    exit;
}

respondError('Unknown action.');

<?php
require __DIR__ . '/../includes/auth.php';
requireAdmin('../login.php');
require_once __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/xlsx_reader.php';
require __DIR__ . '/../includes/import_helpers.php';
require __DIR__ . '/../includes/customer_code.php';

$stateTableMap = [
    'Karnataka'      => 'customers_karnataka',
    'Tamil Nadu'     => 'customers_tamil_nadu',
    'Kerala'         => 'customers_kerala',
    'Andhra Pradesh' => 'customers_andhra_pradesh',
];

$summary      = null;
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedState = trim($_POST['state'] ?? '');
    $targetTable   = $stateTableMap[$selectedState] ?? '';

    if ($targetTable === '') {
        $errorMessage = 'Please select a valid state before uploading.';
    } elseif (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
        $errorMessage = 'Please choose a CSV or XLSX file to upload (check it is under the server upload size limit).';
    } else {
        $tmpPath      = $_FILES['import_file']['tmp_name'];
        $originalName = $_FILES['import_file']['name'];
        $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        try {
            if ($ext === 'csv') {
                $firstLine = file($tmpPath, FILE_IGNORE_NEW_LINES)[0] ?? '';
                $delimiter = detectDelimiter($firstLine);
                $rows   = [];
                $handle = fopen($tmpPath, 'r');
                while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                    $rows[] = $row;
                }
                fclose($handle);
            } elseif ($ext === 'xlsx') {
                $rows = readXlsxRows($tmpPath);
            } else {
                throw new RuntimeException('Unsupported file type. Please upload a .csv or .xlsx file.');
            }

            if (count($rows) < 1) throw new RuntimeException('The file appears to be empty.');

            $headerRow     = array_shift($rows);
            $fieldByColumn = mapHeaderRow($headerRow);

            // Web upload is for quick/small batches — this loop inserts one row at a
            // time on the request thread, so a huge file risks a browser timeout
            // partway through with no way to resume. Millions-of-rows files should
            // go through cli/import_customers.php instead (streams + batches, resumable
            // via --skip-lines), which the page already points to above.
            $MAX_UPLOAD_ROWS = 5000;
            if (count($rows) > $MAX_UPLOAD_ROWS) {
                throw new RuntimeException(
                    'This file has ' . number_format(count($rows)) . ' data rows — the web importer is capped at '
                    . number_format($MAX_UPLOAD_ROWS) . ' rows per file. Split it into smaller files, or for large '
                    . 'files use the CLI: php cli/import_customers.php path\to\file.csv <State>'
                );
            }

            if (!in_array('name', $fieldByColumn, true) || !in_array('mobile_no', $fieldByColumn, true)) {
                throw new RuntimeException(
                    'The file must have a "Name" column and a "Mobile No" column. ' .
                    'Recognised headers: ' . implode(', ', array_unique(array_keys(FIELD_HEADER_MAP)))
                );
            }

            // Reserve a block of codes up front instead of scanning the whole table
            // for the current max (see includes/customer_code.php) — the REGEXP scan
            // this replaced took 110s+ on the larger state tables, well past this
            // page's execution time limit. count($rows) is already capped at
            // $MAX_UPLOAD_ROWS above, so reserving that many is always enough.
            $nextCodeNum = reserveCustomerCodeBlock($pdo, $targetTable, $MAX_UPLOAD_ROWS) + 1;

            $stmt = $pdo->prepare(
                "INSERT INTO `$targetTable`
                   (customer_code, name, mobile_no, dob, gender, father_name,
                    address, permanent_address, email, alternative_no, identity_no,
                    circle, merge_mob, pincode)
                 VALUES
                   (:customer_code, :name, :mobile_no, :dob, :gender, :father_name,
                    :address, :permanent_address, :email, :alternative_no, :identity_no,
                    :circle, :merge_mob, :pincode)"
            );

            $inserted = $skipped_count = $totalDataRows = 0;
            $skipped  = [];

            foreach ($rows as $rowIndex => $row) {
                $lineNo = $rowIndex + 2;
                if (count(array_filter($row, fn($c) => trim((string) $c) !== '')) === 0) continue;
                $totalDataRows++;

                $rec = buildCustomerRecord($row, $fieldByColumn);

                if (($rec['name'] ?? '') === '' || ($rec['mobile_no'] ?? '') === '') {
                    $skipped[] = "Row $lineNo: missing name or mobile number.";
                    $skipped_count++;
                    continue;
                }

                $customerCode = $rec['customer_code'] ?? '';
                if ($customerCode === '') {
                    $customerCode = 'CUST' . str_pad((string) $nextCodeNum, 4, '0', STR_PAD_LEFT);
                    $nextCodeNum++;
                }

                // Use empty string (never null) for NOT NULL columns in state tables
                $params = [
                    'customer_code'     => $customerCode,
                    'name'              => $rec['name'],
                    'mobile_no'         => $rec['mobile_no'],
                    'dob'               => normalizeDob($rec['dob'] ?? ''),
                    'gender'            => normalizeGender($rec['gender'] ?? '') ?? '',
                    'father_name'       => $rec['father_name']       ?? '',
                    'address'           => $rec['address']           ?? '',
                    'permanent_address' => $rec['permanent_address'] ?? '',
                    'email'             => $rec['email']             ?? '',
                    'alternative_no'    => $rec['alternative_no']    ?? '',
                    'identity_no'       => $rec['identity_no']       ?? '',
                    'circle'            => $rec['circle']            ?? '',
                    'merge_mob'         => $rec['merge_mob']         ?? '',
                    'pincode'           => $rec['pincode']           ?? '',
                ];

                try {
                    $stmt->execute($params);
                    $inserted++;
                } catch (PDOException $e) {
                    $skipped[] = "Row $lineNo: " . $e->getMessage();
                    $skipped_count++;
                }
            }

            $summary = compact('selectedState', 'targetTable', 'totalDataRows', 'inserted', 'skipped');
        } catch (RuntimeException $e) {
            $errorMessage = $e->getMessage();
        }
    }
}

$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-cloud-upload-fill"></i> Import Customer Data</h1>
  <p class="page-subtitle">
    Upload a <strong>.csv</strong> or <strong>.xlsx</strong> file. Select the state first — data goes into that state's table.
    Capped at <strong>5,000 rows</strong> per file here — for larger files use the CLI: <code>php cli/import_customers.php path\to\file.csv</code>
  </p>
</div>

<?php if ($errorMessage): ?>
  <div class="notice notice-danger">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <?= htmlspecialchars($errorMessage) ?>
  </div>
<?php endif; ?>

<?php if ($summary): ?>
  <?php $hasSkipped = count($summary['skipped']) > 0; ?>
  <div class="notice notice-<?= $hasSkipped ? 'warning' : 'success' ?>">
    <i class="bi bi-<?= $hasSkipped ? 'exclamation-circle-fill' : 'check-circle-fill' ?>"></i>
    <div>
      Imported into <strong><?= htmlspecialchars($summary['selectedState']) ?></strong>
      (<code><?= htmlspecialchars($summary['targetTable']) ?></code>) —
      processed <strong><?= $summary['totalDataRows'] ?></strong> row(s),
      <strong><?= $summary['inserted'] ?></strong> inserted,
      <strong><?= count($summary['skipped']) ?></strong> skipped.
      <?php if ($hasSkipped): ?>
        <ul class="skipped-list">
          <?php foreach (array_slice($summary['skipped'], 0, 20) as $reason): ?>
            <li><?= htmlspecialchars($reason) ?></li>
          <?php endforeach; ?>
          <?php if (count($summary['skipped']) > 20): ?>
            <li>&hellip; and <?= count($summary['skipped']) - 20 ?> more.</li>
          <?php endif; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-header">
    <i class="bi bi-file-earmark-arrow-up" style="color:var(--c-accent)"></i>
    <span class="card-title">Upload File</span>
    <a href="import_sample.php" class="btn btn-secondary btn-sm" style="margin-left:auto">
      <i class="bi bi-download"></i> Sample CSV
    </a>
  </div>
  <div class="card-body">
    <p class="text-muted text-sm" style="margin:0 0 16px">
      Recognised columns:
      <strong>Name</strong>, <strong>Mobile No</strong>, DOB, Gender, Father's Name,
      Address, Permanent Address, Email, Alternative No, Identity (PAN/Aadhaar/Licence),
      Customer Code, Circle, Merge Mobile, Pincode.
    </p>
    <form method="post" enctype="multipart/form-data">

      <div class="form-group" style="margin-bottom:16px">
        <label class="form-label" for="state-select" style="display:block;margin-bottom:6px;font-weight:600">
          <i class="bi bi-geo-alt-fill" style="color:var(--c-accent)"></i> Target State <span style="color:var(--c-danger)">*</span>
        </label>
        <select id="state-select" name="state" required
                style="padding:9px 14px;border:1px solid var(--c-border);border-radius:12px;
                       font-family:var(--font);font-size:13px;min-width:220px;cursor:pointer;outline:none;
                       background:var(--c-surface);color:var(--c-text);">
          <option value="">— Select State —</option>
          <?php foreach ($stateTableMap as $stateName => $tbl): ?>
            <option value="<?= htmlspecialchars($stateName) ?>"
              <?= (($summary['selectedState'] ?? '') === $stateName) ? 'selected' : '' ?>>
              <?= htmlspecialchars($stateName) ?> (<?= htmlspecialchars($tbl) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="import-zone" onclick="document.getElementById('import_file').click()">
        <i class="bi bi-cloud-arrow-up"></i>
        <p><strong>Click to browse</strong> or drag &amp; drop your file here</p>
        <p>.csv or .xlsx · Header row required · Max 5,000 rows</p>
        <input type="file" id="import_file" name="import_file"
               accept=".csv,.xlsx" required style="display:none"
               onchange="document.getElementById('file-name').textContent = this.files[0]?.name ?? ''">
        <p id="file-name" style="margin-top:8px;font-weight:600;color:var(--c-accent)"></p>
      </div>
      <div style="margin-top:16px">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-upload"></i> Upload &amp; Import
        </button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

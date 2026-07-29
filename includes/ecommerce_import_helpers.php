<?php
// Header-mapping helpers for the E-Commerce importer, parallel to
// includes/import_helpers.php but for ecommerce_orders' own field set —
// that file's FIELD_HEADER_MAP/CUSTOMER_COLUMNS/mapHeaderRow()/
// buildCustomerRecord() are all specific to the customer state-table schema
// (father's name, gender, identity, etc.), so this is a separate small set
// rather than overloading those. normalizeHeader(), detectDelimiter(),
// normalizeDob(), and excelSerialToDate() ARE generic and reused as-is —
// require includes/import_helpers.php (and xlsx_reader.php, for
// excelSerialToDate) alongside this file.

const ECOMMERCE_FIELD_HEADER_MAP = [
    'name' => 'name', 'customer_name' => 'name',
    'mobile' => 'mobile_no', 'mobile_no' => 'mobile_no', 'mobile_number' => 'mobile_no',
    'phone' => 'mobile_no', 'phone_number' => 'mobile_no',
    // Same header aliases as CUSTOMER_COLUMNS' alternative_no in
    // includes/import_helpers.php, kept consistent across both importers.
    'alternative_no' => 'alternative_no', 'alternative_number' => 'alternative_no',
    'alt_no' => 'alternative_no', 'alt' => 'alternative_no', 'alternate' => 'alternative_no',
    'alternate_number' => 'alternative_no', 'alternate_mobile' => 'alternative_no',
    'address' => 'address', 'delivery_address' => 'address',
    'delivery_date' => 'delivery_date', 'date' => 'delivery_date', 'deliverydate' => 'delivery_date',
    'latitude' => 'latitude', 'lat' => 'latitude',
    'longitude' => 'longitude', 'lng' => 'longitude', 'long' => 'longitude', 'lon' => 'longitude',
    // A single combined column, e.g. "12.9716,77.5946" — the source files
    // this importer actually receives use this format rather than separate
    // Latitude/Longitude columns. Split via splitLocationValue() below.
    'location' => 'location', 'coordinates' => 'location', 'coords' => 'location',
    'lat_long' => 'location', 'latlong' => 'location', 'gps' => 'location',
];

const ECOMMERCE_COLUMNS = ['name', 'mobile_no', 'alternative_no', 'address', 'delivery_date', 'latitude', 'longitude'];

function mapEcommerceHeaderRow(array $headerRow): array {
    $fieldByColumn = [];
    foreach ($headerRow as $i => $rawHeader) {
        $normalized = normalizeHeader((string) $rawHeader);
        if (isset(ECOMMERCE_FIELD_HEADER_MAP[$normalized])) {
            $fieldByColumn[$i] = ECOMMERCE_FIELD_HEADER_MAP[$normalized];
        }
    }
    return $fieldByColumn;
}

// Source files (typically Excel exports on Windows) are often Windows-1252,
// not UTF-8 — bytes like 0xA0 (non-breaking space) are valid Windows-1252 but
// not valid standalone UTF-8, and utf8mb4 columns reject them outright
// ("Incorrect string value: '\xA0...'"), silently killing every row that
// happens to contain one (found 2026-07-18: a huge fraction of a real
// import's "skipped" rows were this, not actually missing data). Convert
// from Windows-1252 when the raw bytes aren't valid UTF-8 to begin with;
// only fall back to stripping bytes if that conversion still doesn't yield
// clean UTF-8.
function sanitizeUtf8(string $v): string {
    if ($v === '' || mb_check_encoding($v, 'UTF-8')) return $v;
    $converted = @mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
    if ($converted !== false && mb_check_encoding($converted, 'UTF-8')) return $converted;
    return @iconv('UTF-8', 'UTF-8//IGNORE', $v) ?: '';
}

function buildEcommerceRecord(array $row, array $fieldByColumn): array {
    $rec = [];
    foreach ($fieldByColumn as $col => $field) {
        $v = sanitizeUtf8(trim((string) ($row[$col] ?? '')));
        // "\N" is the standard mysqldump/export NULL marker, not a real value
        $rec[$field] = ($v === '\N') ? '' : $v;
    }
    return $rec;
}

// Latitude/longitude come in as free text — only accept genuine numbers in a
// plausible range rather than silently storing garbage as NULL-equivalent 0.
function normalizeLatLng(string $value, float $min, float $max): ?float {
    $value = trim($value);
    if ($value === '' || !is_numeric($value)) return null;
    $f = (float) $value;
    return ($f >= $min && $f <= $max) ? $f : null;
}

// Splits a combined "12.9716,77.5946" location cell into [lat, lng] raw
// strings — comma is the format actually used in this app's source files,
// semicolon/pipe accepted too in case a file uses a different separator.
function splitLocationValue(string $value): array {
    $value = trim($value);
    if ($value === '') return ['', ''];
    $parts = preg_split('/[,;|]/', $value, 2);
    return [trim($parts[0] ?? ''), trim($parts[1] ?? '')];
}

// import_helpers.php's normalizeDob() falls through to strtotime() for
// anything non-numeric, and PHP's strtotime() treats "/"-separated dates as
// US-style MM/DD/YYYY — so an Indian-format date like "20/07/2026" silently
// fails to parse (there's no 20th month) instead of being read as 20 July.
// Parse D/M/Y explicitly first (falling back to M/D/Y only if D/M/Y isn't a
// valid date), same convention as the DD/MM/YYYY fields elsewhere in this app.
function normalizeDeliveryDate(string $value): ?string {
    $value = trim($value);
    if ($value === '') return null;

    // An 8-digit plain integer like "20260711" is YYYYMMDD — a completely
    // different numeric convention from an Excel serial date number, and far
    // larger than any real one (realistic Excel serials top out in the
    // 70,000s for a date centuries out). Checked first so it isn't fed
    // through excelSerialToDate() and misread as a serial — that produced
    // nonsense years like 57318 (found 2026-07-19, from a real import).
    if (preg_match('/^\d{8}$/', $value)) {
        $y = (int) substr($value, 0, 4);
        $mo = (int) substr($value, 4, 2);
        $d = (int) substr($value, 6, 2);
        if (checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
        return null;
    }

    if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{2,4})$#', $value, $m)) {
        $d = (int) $m[1]; $mo = (int) $m[2]; $y = (int) $m[3];
        if ($y < 100) $y += ($y <= 30) ? 2000 : 1900;
        if (!checkdate($mo, $d, $y)) {
            [$d, $mo] = [$mo, $d]; // wasn't valid as D/M/Y — try M/D/Y
        }
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    if (is_numeric($value)) {
        // Excel serial date — only accept a sane range (roughly 1970-2100).
        // excelSerialToDate() has no bounds checking of its own and will
        // happily turn ANY number into some date, however implausible.
        $f = (float) $value;
        return ($f >= 25569 && $f <= 73415) ? excelSerialToDate($value) : null;
    }

    $ts = strtotime($value);
    if ($ts === false) return null;
    $y = (int) date('Y', $ts);
    return ($y >= 1900 && $y <= 2200) ? date('Y-m-d', $ts) : null;
}

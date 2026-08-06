<?php

const FIELD_HEADER_MAP = [
    'name' => 'name',
    'customer_name' => 'name',
    'mobile' => 'mobile_no',
    'mobile_no' => 'mobile_no',
    'mobile_number' => 'mobile_no',
    'phone' => 'mobile_no',
    'phone_number' => 'mobile_no',
    'dob' => 'dob',
    'date_of_birth' => 'dob',
    'gender' => 'gender',
    'sex' => 'gender',
    'father_name' => 'father_name',
    'fathers_name' => 'father_name',
    'fname' => 'father_name',
    'address' => 'address',
    'permanent_address' => 'permanent_address',
    'per_address' => 'permanent_address',
    'email' => 'email',
    'email_address' => 'email',
    'alternative_no' => 'alternative_no',
    'alternative_number' => 'alternative_no',
    'alt_no' => 'alternative_no',
    'alt' => 'alternative_no',
    'identity_no' => 'identity_no',
    'identity' => 'identity_no',
    'identity_doc' => 'identity_no',
    'pan' => 'identity_no',
    'aadhar' => 'identity_no',
    'pan_aadhar_license' => 'identity_no',
    'customer_code' => 'customer_code',
    'code' => 'customer_code',
    'circle' => 'circle',
    'merge_mob' => 'merge_mob',
    'pincode' => 'pincode',
    'pin_code' => 'pincode',
];

const CUSTOMER_COLUMNS = [
    'customer_code', 'name', 'mobile_no', 'dob', 'gender', 'father_name',
    'address', 'permanent_address', 'email', 'alternative_no', 'identity_no',
    'circle', 'merge_mob', 'pincode',
];

function normalizeHeader(string $header): string {
    $h = strtolower(trim($header));
    $h = str_replace("'", '', $h);
    $h = preg_replace('/[^a-z0-9]+/', '_', $h);
    return trim($h, '_');
}

function normalizeGender(string $value): ?string {
    $v = strtoupper(trim($value));
    if ($v === '') return null;
    if (in_array($v, ['M', 'F', 'O'], true)) return $v;
    // strncmp(), not str_starts_with() - the latter is PHP 8.0+ only, and
    // this file needs to run on PHP 7.4 (found 2026-08-06: the production
    // IIS site serves PHP 7.4, not the PHP 8.3 this app is normally tested
    // against locally).
    if (strncmp($v, 'MALE', 4) === 0) return 'M';
    if (strncmp($v, 'FEMALE', 6) === 0) return 'F';
    return 'O';
}

function normalizeDob(string $value): ?string {
    $value = trim($value);
    if ($value === '') return null;
    if (is_numeric($value)) {
        return excelSerialToDate($value);
    }
    $ts = strtotime($value);
    return $ts !== false ? date('Y-m-d', $ts) : null;
}

function detectDelimiter(string $firstLine): string {
    $delimiter = ',';
    $maxCount = substr_count($firstLine, ',');
    foreach (["\t", ';'] as $candidate) {
        $count = substr_count($firstLine, $candidate);
        if ($count > $maxCount) {
            $maxCount = $count;
            $delimiter = $candidate;
        }
    }
    return $delimiter;
}

function mapHeaderRow(array $headerRow): array {
    $fieldByColumn = [];
    foreach ($headerRow as $i => $rawHeader) {
        $normalized = normalizeHeader((string) $rawHeader);
        if (isset(FIELD_HEADER_MAP[$normalized])) {
            $fieldByColumn[$i] = FIELD_HEADER_MAP[$normalized];
        }
    }
    return $fieldByColumn;
}

function buildCustomerRecord(array $row, array $fieldByColumn): array {
    $rec = [];
    foreach ($fieldByColumn as $col => $field) {
        $v = trim((string) ($row[$col] ?? ''));
        // "\N" is the standard mysqldump/export NULL marker, not a real value
        $rec[$field] = ($v === '\N') ? '' : $v;
    }
    return $rec;
}

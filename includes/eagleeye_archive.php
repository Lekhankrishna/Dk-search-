<?php
// Archives every agent's Advance Pan India search results to a shared file
// on the D: drive (2026-08-10) - same architecture/reasoning as
// includes/pan_india_archive.php and includes/pan_india_pro_archive.php
// (file-based, not a DB table, so this survives independently of the CRM's
// own database if that's ever wiped/migrated), adapted to THIS vendor's own
// data shape: eagleEyeSearch() returns one or more tables (theeagleeye.biz
// has multiple underlying data sources), each with whatever column headers
// that source's own HTML rendered - not a single fixed schema like Night
// Out's clean JSON API, and not Pan India's single flat list of "Label:
// value" text blocks either. Known field labels get their own column
// (Excel-friendly, matching the search form's own field names in
// eagleeye_client.php); anything else - a header naming/wording this
// wasn't written against - still gets kept, just folded into "Other
// Fields" instead of being silently dropped or requiring a schema change.

const EAGLEEYE_ARCHIVE_DIR = 'D:/AdvancePanIndiaSearchArchive';
const EAGLEEYE_ARCHIVE_CSV = EAGLEEYE_ARCHIVE_DIR . '/records.csv';
const EAGLEEYE_ARCHIVE_INDEX = EAGLEEYE_ARCHIVE_DIR . '/dedup_index.txt';

// Best-effort archive: never let a D:-drive/permission problem break the
// actual search response an agent is waiting on. $tables is the "tables"
// array from includes/eagleeye_client.php's eagleEyeSearch() - each table
// is ['headers' => [...], 'rows' => [label => value, ...]].
function archiveEagleEyeResults(array $tables, string $searchedBy, string $query): void {
    try {
        if (!is_dir(EAGLEEYE_ARCHIVE_DIR) && !@mkdir(EAGLEEYE_ARCHIVE_DIR, 0777, true)) return;

        $knownFields = [
            'name' => 'Name', 'full name' => 'Name',
            "father's name" => "Father's Name", 'father name' => "Father's Name", 'fname' => "Father's Name",
            'mobile' => 'Mobile', 'mobile number' => 'Mobile', 'phone' => 'Mobile', 'phone number' => 'Mobile', 'contact number' => 'Mobile',
            'alternate number' => 'Alternate Number', 'alt mobile' => 'Alternate Number', 'alternate mobile' => 'Alternate Number',
            'address' => 'Address', 'alt address' => 'Alt Address', 'alternate address' => 'Alt Address',
            'email' => 'Email', 'email address' => 'Email',
            'aadhaar' => 'Aadhaar Number', 'aadhaar number' => 'Aadhaar Number', 'aadhar number' => 'Aadhaar Number',
            'master id' => 'Identity', 'id' => 'Identity', 'identity' => 'Identity',
            'state' => 'State', 'city' => 'City', 'district' => 'District', 'operator' => 'Operator',
            'age' => 'Age', 'gender' => 'Gender', 'passport number' => 'Passport Number', 'passport' => 'Passport Number',
        ];
        $dataColumns = ['Name', "Father's Name", 'Mobile', 'Alternate Number', 'Address', 'Alt Address',
            'Email', 'Aadhaar Number', 'Identity', 'State', 'City', 'District', 'Operator', 'Age', 'Gender', 'Passport Number'];
        $header = array_merge(['Timestamp', 'Searched By', 'Query', 'Source'], $dataColumns, ['Other Fields']);

        // Fine to load the whole index at this project's search volume (a
        // handful of agents doing manual lookups, not a high-throughput feed).
        $seenHashes = is_file(EAGLEEYE_ARCHIVE_INDEX)
            ? array_flip(file(EAGLEEYE_ARCHIVE_INDEX, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
            : [];

        $newRows = [];
        $newHashes = [];

        foreach ($tables as $tableIndex => $table) {
            foreach (($table['rows'] ?? []) as $fields) {
                if (!$fields) continue;

                // Dedup on the record's OWN data, not the search query - the
                // same person found via two different searches should still
                // only be stored once.
                $fieldsForHash = $fields;
                ksort($fieldsForHash);
                $hash = md5(json_encode($fieldsForHash));
                if (isset($seenHashes[$hash]) || isset($newHashes[$hash])) continue;
                $newHashes[$hash] = true;

                $row = array_fill_keys($dataColumns, '');
                $other = [];
                foreach ($fields as $label => $value) {
                    $known = $knownFields[strtolower(trim((string) $label))] ?? null;
                    if ($known !== null) {
                        $row[$known] = $value;
                    } else {
                        $other[] = "$label: $value";
                    }
                }
                $row['Timestamp'] = date('Y-m-d H:i:s');
                $row['Searched By'] = $searchedBy;
                $row['Query'] = $query;
                $row['Source'] = 'Source ' . ($tableIndex + 1);
                $row['Other Fields'] = implode('; ', $other);
                $newRows[] = $row;
            }
        }

        if (!$newRows) return;

        $isNewFile = !is_file(EAGLEEYE_ARCHIVE_CSV);
        $fh = @fopen(EAGLEEYE_ARCHIVE_CSV, 'a');
        if ($fh && flock($fh, LOCK_EX)) {
            if ($isNewFile) fputcsv($fh, $header);
            foreach ($newRows as $row) fputcsv($fh, array_map(fn($c) => $row[$c] ?? '', $header));
            flock($fh, LOCK_UN);
        }
        if ($fh) fclose($fh);

        $ih = @fopen(EAGLEEYE_ARCHIVE_INDEX, 'a');
        if ($ih && flock($ih, LOCK_EX)) {
            foreach (array_keys($newHashes) as $hash) fwrite($ih, $hash . "\n");
            flock($ih, LOCK_UN);
        }
        if ($ih) fclose($ih);
    } catch (Throwable $e) {
        // Archiving is best-effort - a D:-drive or permission issue must
        // never surface as a search failure to the agent.
    }
}

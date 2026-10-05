<?php
// Archives every agent's Tracing 2.0 (Mobile Info) search results to a shared
// file on the D: drive - same architecture/reasoning as
// includes/pan_india_archive.php (file-based, not a DB table, so this
// survives independently of the CRM's own database if that's ever
// wiped/migrated). A single Mobile Info search can return several distinct
// records for one number (a number that's changed hands/been ported shows
// every linked subscriber - see Gas/lpg_web/mobile_info.py), so this writes
// one archive row per record, same as pan_india_archive.php's per-block rows.

const TRACING2_ARCHIVE_DIR = 'D:/Tracing2SearchArchive';
const TRACING2_ARCHIVE_CSV = TRACING2_ARCHIVE_DIR . '/records.csv';
const TRACING2_ARCHIVE_INDEX = TRACING2_ARCHIVE_DIR . '/dedup_index.txt';

// Best-effort archive: never let a D:-drive/permission problem break the
// actual search response an agent is waiting on. $records is the "records"
// array from a found tracing2_api.php response - each record is
// ['name' => ..., 'status' => ..., 'fields' => [['label' => ..., 'value' => ...], ...]].
function archiveTracing2Results(array $records, string $searchedBy, string $query): void {
    try {
        if (!is_dir(TRACING2_ARCHIVE_DIR) && !@mkdir(TRACING2_ARCHIVE_DIR, 0777, true)) return;

        // Known field labels get their own column (Excel-friendly); anything
        // else - a label wording that varies by record - still gets kept,
        // just folded into "Other Fields" instead of being silently dropped
        // or requiring a schema change. Labels observed live (2026-08-17):
        // Primary Node, Alternate Node, Network Circle, Father's Name,
        // Email Node, ID Linkage, Registry Address.
        $knownFields = [
            'primary node' => 'Primary Node', 'alternate node' => 'Alternate Node',
            'network circle' => 'Network Circle', "father's name" => "Father's Name",
            'email node' => 'Email Node', 'id linkage' => 'ID Linkage',
            'registry address' => 'Registry Address',
        ];
        $dataColumns = ['Name', 'Status', 'Primary Node', 'Alternate Node', 'Network Circle',
            "Father's Name", 'Email Node', 'ID Linkage', 'Registry Address'];
        $header = array_merge(['Timestamp', 'Searched By', 'Query'], $dataColumns, ['Other Fields']);

        $seenHashes = is_file(TRACING2_ARCHIVE_INDEX)
            ? array_flip(file(TRACING2_ARCHIVE_INDEX, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
            : [];

        $newRows = [];
        $newHashes = [];

        foreach ($records as $record) {
            $name = trim((string) ($record['name'] ?? ''));
            $status = trim((string) ($record['status'] ?? ''));
            $flatFields = [];
            foreach (($record['fields'] ?? []) as $field) {
                $label = trim((string) ($field['label'] ?? ''));
                if ($label === '') continue;
                $flatFields[$label] = (string) ($field['value'] ?? '');
            }
            if ($name === '' && !$flatFields) continue;

            // Dedup on the record's OWN data, not the search query - the
            // same subscriber found via two different numbers (primary vs
            // alternate) should still only be stored once.
            $fieldsForHash = $flatFields;
            $fieldsForHash['__name'] = $name;
            ksort($fieldsForHash);
            $hash = md5(json_encode($fieldsForHash));
            if (isset($seenHashes[$hash]) || isset($newHashes[$hash])) continue;
            $newHashes[$hash] = true;

            $row = array_fill_keys($dataColumns, '');
            $row['Name'] = $name;
            $row['Status'] = $status;
            $other = [];
            foreach ($flatFields as $label => $value) {
                $known = $knownFields[strtolower($label)] ?? null;
                if ($known !== null) {
                    $row[$known] = $value;
                } else {
                    $other[] = "$label: $value";
                }
            }
            $row['Timestamp'] = date('Y-m-d H:i:s');
            $row['Searched By'] = $searchedBy;
            $row['Query'] = $query;
            $row['Other Fields'] = implode('; ', $other);
            $newRows[] = $row;
        }

        if (!$newRows) return;

        $isNewFile = !is_file(TRACING2_ARCHIVE_CSV);
        $fh = @fopen(TRACING2_ARCHIVE_CSV, 'a');
        if ($fh && flock($fh, LOCK_EX)) {
            if ($isNewFile) fputcsv($fh, $header);
            foreach ($newRows as $row) fputcsv($fh, array_map(fn($c) => $row[$c] ?? '', $header));
            flock($fh, LOCK_UN);
        }
        if ($fh) fclose($fh);

        $ih = @fopen(TRACING2_ARCHIVE_INDEX, 'a');
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

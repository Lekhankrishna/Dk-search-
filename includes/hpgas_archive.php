<?php
// Archives every agent's HP LPG Search result to a shared file on the D:
// drive (2026-08-10) - same architecture/reasoning as
// includes/pan_india_archive.php, includes/pan_india_pro_archive.php, and
// includes/eagleeye_archive.php (file-based, not a DB table, so this
// survives independently of the CRM's own database if that's ever
// wiped/migrated). Unlike those, a single HP Gas search returns ONE
// consumer's record grouped into named sections (see
// Gas/lpg_web/hp_gas.py's run_hp_gas_bulk()) rather than a list of
// several people, so this writes exactly one archive row per found search.
// Field labels observed live (2026-08-10, real consumer record - Consumer
// Details/E-KYC Profile/Distributor Intelligence/Bank & LPG Linkage/
// Geo-Intelligence) get their own column; anything else - a section this
// wasn't written against, or a label wording that varies by consumer -
// still gets kept, just folded into "Other Fields" instead of being
// silently dropped or requiring a schema change.

const HPGAS_ARCHIVE_DIR = 'D:/HpGasSearchArchive';
const HPGAS_ARCHIVE_CSV = HPGAS_ARCHIVE_DIR . '/records.csv';
const HPGAS_ARCHIVE_INDEX = HPGAS_ARCHIVE_DIR . '/dedup_index.txt';

// Best-effort archive: never let a D:-drive/permission problem break the
// actual search response an agent is waiting on. $sections is the
// "sections" array from a found hp_gas_api.php response - each section is
// ['title' => ..., 'fields' => [['label' => ..., 'value' => ...], ...]].
function archiveHpGasResults(array $sections, string $searchedBy, string $query): void {
    try {
        if (!is_dir(HPGAS_ARCHIVE_DIR) && !@mkdir(HPGAS_ARCHIVE_DIR, 0777, true)) return;

        $knownFields = [
            'consumer name' => 'Consumer Name', 'consumer number' => 'Consumer Number',
            'consumer phone1' => 'Consumer Phone', 'consumer address' => 'Consumer Address',
            'status' => 'Status', 'gender' => 'Gender', 'is p m u y' => 'Is PMUY',
            'e k y c name' => 'eKYC Name', 'e k y c dob' => 'eKYC DOB', 'e k y c gender' => 'eKYC Gender',
            'e k y c address' => 'eKYC Address', 'e k y c date' => 'eKYC Date', 'e k y c mode' => 'eKYC Mode',
            'e k y c transaction id' => 'eKYC Transaction ID', 'ad number' => 'AD Number',
            'distributor address' => 'Distributor Address', 'distributor city' => 'Distributor City',
            'distributor contact' => 'Distributor Contact', 'distributor email' => 'Distributor Email',
            'distributor name' => 'Distributor Name', 'distributor number' => 'Distributor Number',
            'bank name' => 'Bank Name', 'bank acc no' => 'Bank Account No', 'bank i f s c' => 'Bank IFSC',
            'is ad linked to bank acc' => 'AD Linked To Bank', 'is ad linked to l p g acc' => 'AD Linked To LPG',
            'is bank acc linked to l p g' => 'Bank Linked To LPG',
            'unique consumer id' => 'Unique Consumer ID', 'cylinder type' => 'Cylinder Type',
            'c t c compliant' => 'CTC Compliant', 'latitude' => 'Latitude', 'longitude' => 'Longitude',
        ];
        $dataColumns = [
            'Consumer Name', 'Consumer Number', 'Consumer Phone', 'Consumer Address', 'Status', 'Gender', 'Is PMUY',
            'eKYC Name', 'eKYC DOB', 'eKYC Gender', 'eKYC Address', 'eKYC Date', 'eKYC Mode', 'eKYC Transaction ID', 'AD Number',
            'Distributor Address', 'Distributor City', 'Distributor Contact', 'Distributor Email', 'Distributor Name', 'Distributor Number',
            'Bank Name', 'Bank Account No', 'Bank IFSC', 'AD Linked To Bank', 'AD Linked To LPG', 'Bank Linked To LPG',
            'Unique Consumer ID', 'Cylinder Type', 'CTC Compliant', 'Latitude', 'Longitude',
        ];
        $header = array_merge(['Timestamp', 'Searched By', 'Query'], $dataColumns, ['Other Fields']);

        $seenHashes = is_file(HPGAS_ARCHIVE_INDEX)
            ? array_flip(file(HPGAS_ARCHIVE_INDEX, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
            : [];

        // Flatten every section's fields into one label => value map first -
        // dedup and column-mapping both work on the whole record at once,
        // same as the other archives, just with one record instead of many.
        $flatFields = [];
        foreach ($sections as $section) {
            foreach (($section['fields'] ?? []) as $field) {
                $label = trim((string) ($field['label'] ?? ''));
                if ($label === '') continue;
                $flatFields[$label] = (string) ($field['value'] ?? '');
            }
        }
        if (!$flatFields) return;

        // Dedup on the record's OWN data, not the search query - re-running
        // the same search (or finding the same consumer via a different
        // number, if that ever happens) should still only be stored once.
        $fieldsForHash = $flatFields;
        ksort($fieldsForHash);
        $hash = md5(json_encode($fieldsForHash));
        if (isset($seenHashes[$hash])) return;

        $row = array_fill_keys($dataColumns, '');
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

        $isNewFile = !is_file(HPGAS_ARCHIVE_CSV);
        $fh = @fopen(HPGAS_ARCHIVE_CSV, 'a');
        if ($fh && flock($fh, LOCK_EX)) {
            if ($isNewFile) fputcsv($fh, $header);
            fputcsv($fh, array_map(fn($c) => $row[$c] ?? '', $header));
            flock($fh, LOCK_UN);
        }
        if ($fh) fclose($fh);

        $ih = @fopen(HPGAS_ARCHIVE_INDEX, 'a');
        if ($ih && flock($ih, LOCK_EX)) {
            fwrite($ih, $hash . "\n");
            flock($ih, LOCK_UN);
        }
        if ($ih) fclose($ih);
    } catch (Throwable $e) {
        // Archiving is best-effort - a D:-drive or permission issue must
        // never surface as a search failure to the agent.
    }
}

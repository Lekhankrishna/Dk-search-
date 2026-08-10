<?php
// Archives every agent's Night Out search results to a shared file on the
// D: drive (2026-08-10, requested so admin has a running record of what's
// been found across the whole team, not just what's on screen at search
// time) - same architecture/reasoning as includes/pan_india_archive.php
// (file-based, not a DB table, so this survives independently of the CRM's
// own database if that's ever wiped/migrated), just with different columns:
// Night Out's results are already clean fixed-key JSON objects from
// includes/pan_india_pro_client.php (name/mobile/alt/fname/address/
// alt_address/email/year_of_registration/id), not Pan India's freeform
// "Label: value" text blocks - so there's no block-splitting/field-parsing
// step needed here at all.

const NIGHT_OUT_ARCHIVE_DIR = 'D:/NightOutSearchArchive';
const NIGHT_OUT_ARCHIVE_CSV = NIGHT_OUT_ARCHIVE_DIR . '/records.csv';
const NIGHT_OUT_ARCHIVE_INDEX = NIGHT_OUT_ARCHIVE_DIR . '/dedup_index.txt';

// Best-effort archive: never let a D:-drive/permission problem break the
// actual search response an agent is waiting on. $rows is the "rows" array
// from includes/pan_india_pro_client.php's panIndiaProSearch().
function archivePanIndiaProResults(array $rows, string $searchedBy, string $query): void {
    try {
        if (!is_dir(NIGHT_OUT_ARCHIVE_DIR) && !@mkdir(NIGHT_OUT_ARCHIVE_DIR, 0777, true)) return;

        // Same column labels shown in pan_india_pro.php's own results table
        // (Identity = the vendor's own "Master ID" field, see that page's
        // comment on the rename).
        $dataColumns = ['Name', 'Mobile', 'Alt. Mobile', "Father's Name", 'Address', 'Alt. Address', 'Email', 'Reg. Year', 'Identity'];
        $header = array_merge(['Timestamp', 'Searched By', 'Query'], $dataColumns);

        // Fine to load the whole index at this project's search volume (a
        // handful of agents doing manual lookups, not a high-throughput feed).
        $seenHashes = is_file(NIGHT_OUT_ARCHIVE_INDEX)
            ? array_flip(file(NIGHT_OUT_ARCHIVE_INDEX, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
            : [];

        $newRows = [];
        $newHashes = [];

        foreach ($rows as $r) {
            $row = [
                'Name' => (string) ($r['name'] ?? ''),
                'Mobile' => (string) ($r['mobile'] ?? ''),
                'Alt. Mobile' => (string) ($r['alt'] ?? ''),
                "Father's Name" => (string) ($r['fname'] ?? ''),
                'Address' => (string) ($r['address'] ?? ''),
                'Alt. Address' => (string) ($r['alt_address'] ?? ''),
                'Email' => (string) ($r['email'] ?? ''),
                'Reg. Year' => (string) ($r['year_of_registration'] ?? ''),
                'Identity' => (string) ($r['id'] ?? ''),
            ];

            // Dedup on the record's OWN data, not the search query - the
            // same person found via two different searches (mobile, then
            // name) should still only be stored once.
            $fieldsForHash = $row;
            ksort($fieldsForHash);
            $hash = md5(json_encode($fieldsForHash));
            if (isset($seenHashes[$hash]) || isset($newHashes[$hash])) continue;
            $newHashes[$hash] = true;

            $row['Timestamp'] = date('Y-m-d H:i:s');
            $row['Searched By'] = $searchedBy;
            $row['Query'] = $query;
            $newRows[] = $row;
        }

        if (!$newRows) return;

        $isNewFile = !is_file(NIGHT_OUT_ARCHIVE_CSV);
        $fh = @fopen(NIGHT_OUT_ARCHIVE_CSV, 'a');
        if ($fh && flock($fh, LOCK_EX)) {
            if ($isNewFile) fputcsv($fh, $header);
            foreach ($newRows as $row) fputcsv($fh, array_map(fn($c) => $row[$c] ?? '', $header));
            flock($fh, LOCK_UN);
        }
        if ($fh) fclose($fh);

        $ih = @fopen(NIGHT_OUT_ARCHIVE_INDEX, 'a');
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

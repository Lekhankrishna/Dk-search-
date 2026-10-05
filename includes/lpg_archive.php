<?php
// Archives LPG Search / LPG Bulk Search results to a shared file on the
// D: drive (2026-07-31), same purpose and pattern as
// includes/pan_india_archive.php: one running record across all agents,
// deduplicated, never blocking the actual search if D: has a problem.
// Simpler than the PAN India version - these results are already
// structured records (fixed keys from lpg_search.py), not free text that
// needs parsing into fields first.

const LPG_ARCHIVE_DIR = 'D:/LpgSearchArchive';
const LPG_ARCHIVE_CSV = LPG_ARCHIVE_DIR . '/records.csv';
const LPG_ARCHIVE_INDEX = LPG_ARCHIVE_DIR . '/dedup_index.txt';

// Same keys lpg_search.php/lpg_bulk_search.php's renderResults() reads off
// each record (r["Mobile Number"], r["Alternate Number"], etc).
const LPG_ARCHIVE_COLUMNS = [
    'Mobile Number', 'Alternate Number', 'Full Name', 'DOB',
    'Relationship Id', 'Address', 'Country', 'Pin Code', 'Urban/Rural',
];

// Best-effort archive: never let a D:-drive/permission problem break the
// actual search response an agent is waiting on. Called on every status
// poll while a job runs (lpg_search_api.php), not just at completion, so
// each number's record gets archived as soon as it's done rather than only
// if the whole batch finishes - repeat calls are harmless since the dedup
// index skips whatever's already been stored.
function archiveLpgResults(array $results, string $searchedBy): void {
    try {
        if (!is_dir(LPG_ARCHIVE_DIR) && !@mkdir(LPG_ARCHIVE_DIR, 0777, true)) return;

        $header = array_merge(['Timestamp', 'Searched By'], LPG_ARCHIVE_COLUMNS);

        $seenHashes = is_file(LPG_ARCHIVE_INDEX)
            ? array_flip(file(LPG_ARCHIVE_INDEX, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
            : [];

        $newRows = [];
        $newHashes = [];

        foreach ($results as $record) {
            // NOT_FOUND rows (see renderResults()'s notFound check) have
            // nothing worth archiving - skip rather than storing a row of
            // dashes for every not-found lookup.
            if (!is_array($record) || !empty($record['NOT_FOUND'])) continue;

            $row = ['Timestamp' => date('Y-m-d H:i:s'), 'Searched By' => $searchedBy];
            $hashParts = [];
            $hasData = false;
            foreach (LPG_ARCHIVE_COLUMNS as $col) {
                $value = trim((string) ($record[$col] ?? ''));
                $row[$col] = $value;
                $hashParts[$col] = $value;
                if ($value !== '') $hasData = true;
            }
            if (!$hasData) continue;

            // Dedup on the record's own data (fixed column order here, so no
            // sort needed for a stable hash) - the same number searched
            // again later, or by a different agent, is skipped rather than
            // duplicated. A record whose data genuinely changed (e.g. an
            // updated address on file) hashes differently and is kept as a
            // new row, not silently discarded.
            $hash = md5(json_encode($hashParts));
            if (isset($seenHashes[$hash]) || isset($newHashes[$hash])) continue;
            $newHashes[$hash] = true;
            $newRows[] = $row;
        }

        if (!$newRows) return;

        $isNewFile = !is_file(LPG_ARCHIVE_CSV);
        $fh = @fopen(LPG_ARCHIVE_CSV, 'a');
        if ($fh && flock($fh, LOCK_EX)) {
            if ($isNewFile) fputcsv($fh, $header);
            foreach ($newRows as $row) fputcsv($fh, array_map(fn($c) => $row[$c] ?? '', $header));
            flock($fh, LOCK_UN);
        }
        if ($fh) fclose($fh);

        $ih = @fopen(LPG_ARCHIVE_INDEX, 'a');
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

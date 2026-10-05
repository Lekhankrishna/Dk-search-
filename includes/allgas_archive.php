<?php
// Archives every agent's All Gas (tracekart.in Gas Connection) results to a
// shared CSV, same architecture as includes/tracekart_archive.php: file-
// based, deduplicated on the record's own data. Each record is stored as one
// JSON column rather than one CSV column per field, since the vendor's
// result layout may differ per provider - a fixed header written by the
// first search would misalign rows from a later one with other columns.
// Kept on C: (not D: like the older archives) so it doesn't depend on the
// old machine's drive.

const ALLGAS_ARCHIVE_DIR = 'D:/CRMData/AllGasSearchArchive';   // on D: with all other saved data (2026-10-04)
const ALLGAS_ARCHIVE_CSV = ALLGAS_ARCHIVE_DIR . '/records.csv';
const ALLGAS_ARCHIVE_INDEX = ALLGAS_ARCHIVE_DIR . '/dedup_index.txt';

// Best-effort: an archive problem must never fail a search the agent is
// waiting on. $rows is tracekartGasSearch()'s own "rows".
function archiveAllGasResults(array $rows, string $searchedBy, string $provider, string $mobile): void {
    try {
        if (!$rows) return;
        if (!is_dir(ALLGAS_ARCHIVE_DIR) && !@mkdir(ALLGAS_ARCHIVE_DIR, 0777, true)) return;

        $seenHashes = is_file(ALLGAS_ARCHIVE_INDEX)
            ? array_flip(file(ALLGAS_ARCHIVE_INDEX, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
            : [];

        $newRows = [];
        $newHashes = [];
        foreach ($rows as $row) {
            $fieldsForHash = $row;
            ksort($fieldsForHash);
            $hash = md5($provider . '|' . json_encode($fieldsForHash));
            if (isset($seenHashes[$hash]) || isset($newHashes[$hash])) continue;
            $newHashes[$hash] = true;
            $newRows[] = [
                date('Y-m-d H:i:s'), $searchedBy, TRACEKART_GAS_PROVIDERS[$provider] ?? $provider, $mobile,
                json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }
        if (!$newRows) return;

        $isNewFile = !is_file(ALLGAS_ARCHIVE_CSV);
        $fh = @fopen(ALLGAS_ARCHIVE_CSV, 'a');
        if ($fh && flock($fh, LOCK_EX)) {
            if ($isNewFile) fputcsv($fh, ['Timestamp', 'Searched By', 'Provider', 'Mobile', 'Record (JSON)'], ',', '"', '');
            foreach ($newRows as $r) fputcsv($fh, $r, ',', '"', '');
            flock($fh, LOCK_UN);
        }
        if ($fh) fclose($fh);

        $ih = @fopen(ALLGAS_ARCHIVE_INDEX, 'a');
        if ($ih && flock($ih, LOCK_EX)) {
            foreach (array_keys($newHashes) as $hash) fwrite($ih, $hash . "\n");
            flock($ih, LOCK_UN);
        }
        if ($ih) fclose($ih);
    } catch (Throwable $e) {
        // Archiving is best-effort.
    }
}

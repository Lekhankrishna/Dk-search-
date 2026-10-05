<?php
// Archives every agent's Advanced Search (tracekart.in) results to a shared
// file on the D: drive, same architecture as includes/pan_india_archive.php:
// file-based, not a DB table, so this survives independently of the CRM's
// own database. Dynamic columns (not a fixed set like
// includes/pan_india_pro_archive.php) since tracekart.in's own result table
// headers were never observed live - every test query came back "No
// records found" - so there's nothing to hardcode against yet; this reads
// whatever columns includes/tracekart_client.php's generic table parser
// found for a given result set.

const TRACEKART_ARCHIVE_DIR = 'D:/AdvancedSearchArchive';
const TRACEKART_ARCHIVE_CSV = TRACEKART_ARCHIVE_DIR . '/records.csv';
const TRACEKART_ARCHIVE_INDEX = TRACEKART_ARCHIVE_DIR . '/dedup_index.txt';

// Best-effort archive: never let a D:-drive/permission problem break the
// actual search response an agent is waiting on. $rows/$headers are
// tracekartBuildResults()'s own output shape.
function archiveTracekartResults(array $headers, array $rows, string $searchedBy, string $mode, string $query): void {
    try {
        if (!$rows) return;
        if (!is_dir(TRACEKART_ARCHIVE_DIR) && !@mkdir(TRACEKART_ARCHIVE_DIR, 0777, true)) return;

        $header = array_merge(['Timestamp', 'Searched By', 'Mode', 'Query'], $headers);

        $seenHashes = is_file(TRACEKART_ARCHIVE_INDEX)
            ? array_flip(file(TRACEKART_ARCHIVE_INDEX, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
            : [];

        $newRows = [];
        $newHashes = [];

        foreach ($rows as $row) {
            // Dedup on the record's OWN data, not the search query - the
            // same person found via two different searches (mobile, then
            // address) should still only be stored once.
            $fieldsForHash = $row;
            ksort($fieldsForHash);
            $hash = md5(json_encode($fieldsForHash));
            if (isset($seenHashes[$hash]) || isset($newHashes[$hash])) continue;
            $newHashes[$hash] = true;

            $newRows[] = array_merge([
                'Timestamp' => date('Y-m-d H:i:s'),
                'Searched By' => $searchedBy,
                'Mode' => $mode,
                'Query' => $query,
            ], $row);
        }

        if (!$newRows) return;

        $isNewFile = !is_file(TRACEKART_ARCHIVE_CSV);
        $fh = @fopen(TRACEKART_ARCHIVE_CSV, 'a');
        if ($fh && flock($fh, LOCK_EX)) {
            if ($isNewFile) fputcsv($fh, $header);
            foreach ($newRows as $row) fputcsv($fh, array_map(fn($c) => $row[$c] ?? '', $header));
            flock($fh, LOCK_UN);
        }
        if ($fh) fclose($fh);

        $ih = @fopen(TRACEKART_ARCHIVE_INDEX, 'a');
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

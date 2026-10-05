<?php
// Archives every agent's Tata Play Search result to a shared file on the D:
// drive (2026-08-13) - same file-based architecture/reasoning as
// includes/hpgas_archive.php and friends, just a fixed record (Account
// Name/Subscriber Id/Account Status/Address - see
// Gas/lpg_web/tataplay.py's run_tataplay_single()) instead of dynamic
// sections, since that's all the Siebel account record actually offers.

const TATAPLAY_ARCHIVE_DIR = 'D:/TataPlaySearchArchive';
const TATAPLAY_ARCHIVE_CSV = TATAPLAY_ARCHIVE_DIR . '/records.csv';
const TATAPLAY_ARCHIVE_INDEX = TATAPLAY_ARCHIVE_DIR . '/dedup_index.txt';

// Best-effort archive: never let a D:-drive/permission problem break the
// actual search response an agent is waiting on.
function archiveTataPlayResult(string $accountName, string $subscriberId, string $accountStatus, string $address, string $lastRechargeDate, string $searchedBy, string $query): void {
    try {
        if (!is_dir(TATAPLAY_ARCHIVE_DIR) && !@mkdir(TATAPLAY_ARCHIVE_DIR, 0777, true)) return;
        if ($subscriberId === '') return;

        // Dedup on the subscriber id + status, not the search query - the
        // same account found via a different mobile number (or a re-run
        // search) should still only be stored once per status seen. Address/
        // last recharge date aren't part of the hash - a status re-check
        // that happens to also pick up a freshly-filled-in address or a
        // newer recharge (see tataplay.py's best-effort extraction for both)
        // still counts as the same record.
        $hash = md5($subscriberId . '|' . $accountStatus);
        $seenHashes = is_file(TATAPLAY_ARCHIVE_INDEX)
            ? array_flip(file(TATAPLAY_ARCHIVE_INDEX, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
            : [];
        if (isset($seenHashes[$hash])) return;

        $header = ['Timestamp', 'Searched By', 'Query', 'Account Name', 'Subscriber Id', 'Account Status', 'Address', 'Last Recharge Date'];
        $row = [date('Y-m-d H:i:s'), $searchedBy, $query, $accountName, $subscriberId, $accountStatus, $address, $lastRechargeDate];

        $isNewFile = !is_file(TATAPLAY_ARCHIVE_CSV);
        $fh = @fopen(TATAPLAY_ARCHIVE_CSV, 'a');
        if ($fh && flock($fh, LOCK_EX)) {
            if ($isNewFile) fputcsv($fh, $header);
            fputcsv($fh, $row);
            flock($fh, LOCK_UN);
        }
        if ($fh) fclose($fh);

        $ih = @fopen(TATAPLAY_ARCHIVE_INDEX, 'a');
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

<?php
// Archives every agent's PAN India search results to a shared file on the
// D: drive (2026-07-31, requested so admin has a running record of what's
// been found across the whole team, not just what's on screen at search
// time - api/pan_india.php previously deliberately stored nothing at all).
// Deliberately file-based, not a DB table - D: is a separate physical drive
// from where the app/database live, so this survives independently of the
// CRM's own database if that's ever wiped/migrated.

const PAN_ARCHIVE_DIR = 'D:/PanIndiaSearchArchive';
const PAN_ARCHIVE_CSV = PAN_ARCHIVE_DIR . '/records.csv';
const PAN_ARCHIVE_INDEX = PAN_ARCHIVE_DIR . '/dedup_index.txt';

// A single bot message can bundle several distinct people, separated by a
// blank line (same finding as the frontend table, pan_india.php) - each
// block is archived as its own row rather than one row per API result.
function panArchiveSplitBlocks(string $text): array {
    $blocks = preg_split('/\r?\n\s*\r?\n+/', trim($text));
    if ($blocks === false) return [];
    return array_values(array_filter(array_map('trim', $blocks), fn($b) => $b !== ''));
}

// Same "Label: value" parsing as the frontend table - a label repeated
// within one block (e.g. two phone numbers for one person) is joined
// rather than the later value discarding the earlier one.
function panArchiveParseFields(string $block): array {
    $fields = [];
    foreach (preg_split('/\r\n|\r|\n/', $block) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (!preg_match('/^([^:]{1,40}):\s*(.*)$/', $line, $m)) continue;
        $label = trim($m[1]);
        $value = trim($m[2]);
        if ($value === '') $value = '—';
        $fields[$label] = isset($fields[$label]) ? $fields[$label] . '; ' . $value : $value;
    }
    return $fields;
}

// Best-effort archive: never let a D:-drive/permission problem break the
// actual search response an agent is waiting on. $results is the raw
// "results" array from the Telegram worker (each item has a "text" field).
function archivePanIndiaResults(array $results, string $searchedBy, string $queryType, string $query): void {
    try {
        if (!is_dir(PAN_ARCHIVE_DIR) && !@mkdir(PAN_ARCHIVE_DIR, 0777, true)) return;

        // Known field labels get their own column (Excel-friendly); anything
        // else - new/unrecognized labels from a future leak source - still
        // gets kept, just folded into "Other Fields" instead of being
        // silently dropped or requiring a schema change.
        $knownFields = [
            'phone number' => 'Phone Number', 'address' => 'Address', 'state' => 'State',
            'city' => 'City', 'district' => 'District', 'full name' => 'Full Name',
            'age' => 'Age', 'gender' => 'Gender', 'aadhaar number' => 'Aadhaar Number',
            'operator' => 'Operator', 'email' => 'Email', 'passport number' => 'Passport Number',
            'the name of the father' => "Father's Name",
        ];
        $dataColumns = ['Phone Number', 'Address', 'State', 'City', 'District', 'Full Name',
            'Age', 'Gender', 'Aadhaar Number', 'Operator', 'Email', 'Passport Number', "Father's Name"];
        $header = array_merge(['Timestamp', 'Searched By', 'Query Type', 'Query'], $dataColumns, ['Other Fields']);

        // Fine to load the whole index at this project's search volume (a
        // handful of agents doing manual lookups, not a high-throughput feed).
        $seenHashes = is_file(PAN_ARCHIVE_INDEX)
            ? array_flip(file(PAN_ARCHIVE_INDEX, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
            : [];

        $newRows = [];
        $newHashes = [];

        foreach ($results as $result) {
            $text = trim((string) ($result['text'] ?? ''));
            if ($text === '') continue;
            foreach (panArchiveSplitBlocks($text) as $block) {
                $fields = panArchiveParseFields($block);
                if (!$fields) continue;

                // Dedup on the record's OWN data, not the search query - the
                // same person found via two different searches (phone, then
                // email) should still only be stored once.
                $fieldsForHash = $fields;
                ksort($fieldsForHash);
                $hash = md5(json_encode($fieldsForHash));
                if (isset($seenHashes[$hash]) || isset($newHashes[$hash])) continue;
                $newHashes[$hash] = true;

                $row = array_fill_keys($dataColumns, '');
                $other = [];
                foreach ($fields as $label => $value) {
                    $known = $knownFields[strtolower(trim($label))] ?? null;
                    if ($known !== null) {
                        $row[$known] = $value;
                    } else {
                        $other[] = "$label: $value";
                    }
                }
                $row['Timestamp'] = date('Y-m-d H:i:s');
                $row['Searched By'] = $searchedBy;
                $row['Query Type'] = $queryType;
                $row['Query'] = $query;
                $row['Other Fields'] = implode('; ', $other);
                $newRows[] = $row;
            }
        }

        if (!$newRows) return;

        $isNewFile = !is_file(PAN_ARCHIVE_CSV);
        $fh = @fopen(PAN_ARCHIVE_CSV, 'a');
        if ($fh && flock($fh, LOCK_EX)) {
            if ($isNewFile) fputcsv($fh, $header);
            foreach ($newRows as $row) fputcsv($fh, array_map(fn($c) => $row[$c] ?? '', $header));
            flock($fh, LOCK_UN);
        }
        if ($fh) fclose($fh);

        $ih = @fopen(PAN_ARCHIVE_INDEX, 'a');
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

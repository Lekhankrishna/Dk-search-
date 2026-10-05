<?php
// Saved results for the live vendor-lookup tools - the first search for a
// given input goes to the real source; every repeat of it is answered from
// the saved copy here (added 2026-08-27; cached forever, no expiration).
//
// Storage moved to the D: drive (2026-10-04, per explicit instruction - C:
// is nearly full and all searched data should live on D:):
//   D:/CRMData/SavedResults/<table>/<k2>/<key>.json  - one file per search,
//                                                      what repeats read
//   D:/CRMData/SavedResults/<table>.csv              - one row per saved
//                                                      result, for Excel
// <table> is the name the callers already pass (search_cache_hp_gas, ...),
// so no caller changed. The old MySQL search_cache_* tables are only a
// fallback now: read when a result isn't on D: yet (and copied over on that
// read), and written only if D: can't be written to - so a drive problem
// never breaks a search. The per-tool CSV archives (includes/*_archive.php)
// are separate and unchanged.

const SAVED_RESULTS_DIR = 'D:/CRMData/SavedResults';

// Builds the fixed-width key from one or more raw search parameters. Always
// hashed (never the raw value). Case/whitespace are NOT normalized here -
// each caller already normalizes its own parameters before this is called,
// since what counts as "the same search" is tool-specific.
function searchCacheKey(string ...$parts): string {
    return md5(implode("\x1f", $parts));
}

function savedResultTableName(string $table): string {
    return preg_replace('/[^a-z0-9_]/i', '', $table);
}

function savedResultPath(string $table, string $searchKey): string {
    $key = preg_replace('/[^a-f0-9]/i', '', $searchKey);
    return SAVED_RESULTS_DIR . '/' . savedResultTableName($table) . '/' . substr($key, 0, 2) . '/' . $key . '.json';
}

// Writes the D: copy (JSON file + CSV row). Returns false if D: couldn't be
// written, so the caller can fall back to the database.
function savedResultWrite(string $table, string $searchKey, string $searchKeyDisplay, array $result, string $searchedBy, ?string $savedAt = null): bool {
    $path = savedResultPath($table, $searchKey);
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) return false;
    $savedAt = $savedAt ?? date('Y-m-d H:i:s');
    $payload = json_encode([
        'search' => $searchKeyDisplay,
        'searched_by' => $searchedBy,
        'saved_at' => $savedAt,
        'result' => $result,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) return false;
    // Write-then-rename so a reader never sees a half-written file.
    $tmp = $path . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $payload) === false) return false;
    if (!@rename($tmp, $path)) { @unlink($tmp); return false; }

    $csv = SAVED_RESULTS_DIR . '/' . savedResultTableName($table) . '.csv';
    $fh = @fopen($csv, 'a');
    if ($fh) {
        if (flock($fh, LOCK_EX)) {
            if (filesize($csv) === 0) {
                fwrite($fh, "\xEF\xBB\xBF");   // UTF-8 BOM so Excel reads it right
                fputcsv($fh, ['Saved At', 'Searched By', 'Search', 'Result']);
            }
            fputcsv($fh, [$savedAt, $searchedBy, $searchKeyDisplay, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
            fflush($fh);
            flock($fh, LOCK_UN);
        }
        fclose($fh);
    }
    return true;
}

// Returns the saved result for this exact search, or null on a miss
// (including any read error - a saved-data problem must never block the
// actual search).
function searchCacheGet(PDO $pdo, string $table, string $searchKey): ?array {
    $path = savedResultPath($table, $searchKey);
    if (is_file($path)) {
        $doc = json_decode((string) @file_get_contents($path), true);
        if (is_array($doc) && is_array($doc['result'] ?? null)) return $doc['result'];
    }
    // Not on D: yet - the pre-move database copy, copied to D: on the way out.
    try {
        $stmt = $pdo->prepare("SELECT search_key_display, result_json, searched_by, created_at FROM `$table` WHERE search_key = :k LIMIT 1");
        $stmt->execute(['k' => $searchKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $decoded = json_decode((string) $row['result_json'], true);
        if (!is_array($decoded)) return null;
        savedResultWrite($table, $searchKey, (string) $row['search_key_display'], $decoded, (string) $row['searched_by'], (string) $row['created_at']);
        return $decoded;
    } catch (Throwable $e) {
        return null;
    }
}

// Saves (or refreshes) the result for this search on D:. Best-effort - never
// throws, so a saving failure can't turn a successful search into an error.
function searchCacheStore(PDO $pdo, string $table, string $searchKey, string $searchKeyDisplay, array $result, string $searchedBy): void {
    try {
        if (savedResultWrite($table, $searchKey, $searchKeyDisplay, $result, $searchedBy)) return;
    } catch (Throwable $e) {
        // fall through to the database
    }
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO `$table` (search_key, search_key_display, result_json, searched_by, created_at)
             VALUES (:k, :kd, :j, :u, NOW())
             ON DUPLICATE KEY UPDATE result_json = VALUES(result_json), searched_by = VALUES(searched_by)"
        );
        $stmt->execute([
            'k'  => $searchKey,
            'kd' => mb_substr($searchKeyDisplay, 0, 255),
            'j'  => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'u'  => $searchedBy,
        ]);
    } catch (Throwable $e) {
        // best-effort - see comment above
    }
}

// A saved result handed to an agent is logged exactly like a live search
// (same search_type and query text), so a found saved result counts toward
// the agent's usage / monthly limit just like one fetched from the source
// (2026-10-04, per explicit instruction). $count is the result count the
// live path would have logged (0 = not found, which never counts).
function searchLogSavedResult(PDO $pdo, string $type, string $query, int $count): void {
    if (!isset($_SESSION['user_id'])) return;
    try {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $pdo->prepare(
            "INSERT INTO search_logs (user_id, search_type, search_query, result_count, ip_address)
             VALUES (:uid, :type, :q, :cnt, :ip)"
        )->execute([
            'uid' => $_SESSION['user_id'], 'type' => $type, 'q' => mb_substr($query, 0, 512),
            'cnt' => $count, 'ip' => substr($ip, 0, 45),
        ]);
    } catch (Throwable $e) {}
}

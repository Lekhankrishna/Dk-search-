<?php
// Server-side handler for "Advanced Search" - same monthly-limit/logging
// shape as pan_india_pro_api.php, calling includes/tracekart_client.php
// directly in-process (plain curl+cookie session, no separate Flask/
// Selenium service needed - see that file's own comment on why).
require __DIR__ . '/includes/auth.php';
requireAdvancedSearchAccess();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/tracekart_client.php';
require_once __DIR__ . '/includes/tracekart_archive.php';
require_once __DIR__ . '/includes/search_cache.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$state = trim((string) ($data['state'] ?? ''));
$mode = trim((string) ($data['mode'] ?? ''));
if (!isset(TRACEKART_STATES[$state])) {
    http_response_code(400);
    echo json_encode(['error' => 'Select a state to search.']);
    exit;
}
if (!isset(TRACEKART_STATES[$state]['modes'][$mode])) {
    http_response_code(400);
    echo json_encode(['error' => 'That search mode is not available for ' . TRACEKART_STATES[$state]['label'] . '.']);
    exit;
}
$fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];

// Read-through cache (2026-08-27) - a repeat of the exact same
// state+mode+fields is served instantly from our own database instead of
// going through tracekart.in again. Cached forever - see
// includes/search_cache.php's own header comment for the reasoning. Fields
// are sorted by key before hashing so the same search submitted with keys
// in a different order still hits the same cache entry.
$fieldsForKey = $fields;
ksort($fieldsForKey);
$cacheKey = searchCacheKey($state, $mode, json_encode($fieldsForKey));
$cached = searchCacheGet($pdo, 'search_cache_advanced_search', $cacheKey);
if ($cached !== null) {
    searchLogSavedResult($pdo, 'advanced_search', TRACEKART_STATES[$state]['label'] . ': ' . implode(' ', array_filter($fields, fn($v) => trim((string) $v) !== '')), (int) ($cached['totalResults'] ?? 0));
    echo json_encode($cached);
    exit;
}

// No monthly cap (removed 2026-08-12, per explicit instruction) - every
// agent with access gets unlimited Advanced Search searches. Still logged
// to search_logs below for Admin > Audit Log either way.
try {
    $result = tracekartSearch($state, $mode, $fields);
} catch (Throwable $e) {
    // The real message (credentials, curl errors, etc.) can name internal
    // file paths - logged for diagnosis, but never shown to the agent, who'd
    // just see it as a confusing raw error rather than something actionable.
    error_log('advanced_search_api.php: ' . $e->getMessage());
    http_response_code(502);
    echo json_encode(['error' => 'Server Down. Please try again later.']);
    exit;
}

// A completed search (found or not) still gets logged for Admin > Audit
// Log (no monthly limit to enforce anymore).
try {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $stateLabel = TRACEKART_STATES[$state]['label'];
    $queryText = $stateLabel . ': ' . implode(' ', array_filter($fields, fn($v) => trim((string) $v) !== ''));
    $pdo->prepare(
        "INSERT INTO search_logs (user_id, search_type, search_query, result_count, ip_address)
         VALUES (:uid, 'advanced_search', :q, :cnt, :ip)"
    )->execute([
        'uid' => $_SESSION['user_id'],
        'q' => substr($queryText, 0, 512),
        'cnt' => $result['totalResults'],
        'ip' => substr($ip, 0, 45),
    ]);

    archiveTracekartResults($result['headers'] ?? [], $result['rows'] ?? [], currentUser()['username'] ?? 'unknown', $mode, $queryText);
} catch (PDOException $e) {}

searchCacheStore($pdo, 'search_cache_advanced_search', $cacheKey, $queryText, $result, currentUser()['username'] ?? 'unknown');

echo json_encode($result);

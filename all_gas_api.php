<?php
// Server-side handler for "All Gas" - same shape as advanced_search_api.php,
// calling includes/tracekart_client.php's tracekartGasSearch() in-process
// (tracekart.in's Skip Trace "Gas Connection" service, same account as
// Advanced Search).
require __DIR__ . '/includes/auth.php';
requireAllGasAccess();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/tracekart_client.php';
require_once __DIR__ . '/includes/allgas_archive.php';
require_once __DIR__ . '/includes/search_cache.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$mobile = preg_replace('/\D+/', '', (string) ($data['mobile'] ?? ''));
$provider = trim((string) ($data['provider'] ?? ''));
if (strlen($mobile) !== 10) {
    http_response_code(400);
    echo json_encode(['error' => 'Enter a valid 10-digit mobile number.']);
    exit;
}
if (!isset(TRACEKART_GAS_PROVIDERS[$provider])) {
    http_response_code(400);
    echo json_encode(['error' => 'Select a gas provider.']);
    exit;
}

// Per-provider monthly limit (Admin > Agents, 2026-10-04) - same rules as
// hp_gas_api.php: checked BEFORE the cache (a saved result still counts as a
// result handed out), only found searches count, admins are never limited.
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
if (!$isAdmin) {
    $usage = allGasUsage($pdo, (int) $_SESSION['user_id'], $provider);
    if ($usage['used'] >= $usage['limit']) {
        http_response_code(429);
        echo json_encode([
            'error' => 'Monthly ' . TRACEKART_GAS_PROVIDERS[$provider] . " limit reached ({$usage['used']}/{$usage['limit']} this month). Contact your admin to increase it, or try again next month.",
            'used' => $usage['used'],
            'limit' => $usage['limit'],
        ]);
        exit;
    }
}

// Read-through cache - a repeat of the same provider+mobile is served from
// our own database instead of spending another vendor search.
$cacheKey = searchCacheKey($provider, $mobile);
$cached = searchCacheGet($pdo, 'search_cache_all_gas', $cacheKey);
if ($cached !== null) {
    // Logged like a live search, so it counts toward the monthly limit.
    try {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $pdo->prepare(
            "INSERT INTO search_logs (user_id, search_type, search_query, result_count, ip_address)
             VALUES (:uid, 'all_gas', :q, :cnt, :ip)"
        )->execute([
            'uid' => $_SESSION['user_id'],
            'q' => substr(TRACEKART_GAS_PROVIDERS[$provider] . ': ' . $mobile, 0, 512),
            'cnt' => (int) ($cached['totalResults'] ?? 0),
            'ip' => substr($ip, 0, 45),
        ]);
    } catch (PDOException $e) {}
    echo json_encode($cached);
    exit;
}

try {
    $result = tracekartGasSearch($mobile, $provider);
} catch (Throwable $e) {
    // The real message can name internal paths - logged, never shown.
    error_log('all_gas_api.php: ' . $e->getMessage());
    http_response_code(502);
    echo json_encode(['error' => 'Server Down. Please try again later.']);
    exit;
}

$queryText = TRACEKART_GAS_PROVIDERS[$provider] . ': ' . $mobile;
try {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $pdo->prepare(
        "INSERT INTO search_logs (user_id, search_type, search_query, result_count, ip_address)
         VALUES (:uid, 'all_gas', :q, :cnt, :ip)"
    )->execute([
        'uid' => $_SESSION['user_id'],
        'q' => substr($queryText, 0, 512),
        'cnt' => $result['totalResults'],
        'ip' => substr($ip, 0, 45),
    ]);
} catch (PDOException $e) {}

if ($result['rows']) {
    // One archive line per search result: the flat record when the vendor
    // rendered label/value cards, otherwise each table row.
    archiveAllGasResults(!empty($result['record']) ? [$result['record']] : $result['rows'],
        currentUser()['username'] ?? 'unknown', $provider, $mobile);
    // Only a real hit is cached - a "no records"/credit message from the
    // vendor may be temporary and must not hide a later real result.
    searchCacheStore($pdo, 'search_cache_all_gas', $cacheKey, $queryText, $result, currentUser()['username'] ?? 'unknown');
}

echo json_encode($result);

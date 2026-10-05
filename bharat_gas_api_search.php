<?php
// Server-side handler for "Bharat Gas Advanced" - same shape as all_gas_api.php,
// calling includes/nexora_client.php's nexoraBharatGasSearch() in-process
// (the paid Nexora API crm-app-v3's "Bharat Gas Advance" uses).
require __DIR__ . '/includes/auth.php';
requireBharatGasApiAccess();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/nexora_client.php';
require_once __DIR__ . '/includes/search_cache.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$mobile = preg_replace('/\D+/', '', (string) ($data['mobile'] ?? ''));
if (strlen($mobile) === 12 && strpos($mobile, '91') === 0) $mobile = substr($mobile, 2);
if (strlen($mobile) === 11 && $mobile[0] === '0') $mobile = substr($mobile, 1);
if (strlen($mobile) !== 10) {
    http_response_code(400);
    echo json_encode(['error' => 'Enter a valid 10-digit mobile number.']);
    exit;
}

// Monthly limit (Admin > Agents, 2026-10-04) - checked before the cache.
nexoraEnforceMonthlyLimit($pdo, 'bharat_gas_api', 'Bharat Gas Advanced');

// Read-through cache - a repeat of the same number is served from our own
// database instead of paying for another API call.
$cacheKey = searchCacheKey('bharat_gas_api', $mobile);
$cached = searchCacheGet($pdo, 'search_cache_bharat_gas_api', $cacheKey);
if ($cached !== null) {
    nexoraLogSearch($pdo, 'bharat_gas_api', $mobile, !empty($cached['found']));
    echo json_encode($cached + ['usage' => nexoraUsage($pdo, 'bharat_gas_api')]);
    exit;
}

try {
    $result = nexoraBharatGasSearch($mobile);
} catch (Throwable $e) {
    // The real message can name internal details - logged, never shown.
    error_log('bharat_gas_api_search.php: ' . $e->getMessage());
    // Counts as a used search too (NEXORA_COUNT_EVERY_SEARCH).
    nexoraLogSearch($pdo, 'bharat_gas_api', $mobile, false);
    http_response_code(502);
    echo json_encode(['error' => nexoraAgentError($e)]);
    exit;
}

try {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $pdo->prepare(
        "INSERT INTO search_logs (user_id, search_type, search_query, result_count, ip_address)
         VALUES (:uid, 'bharat_gas_api', :q, :cnt, :ip)"
    )->execute([
        'uid' => $_SESSION['user_id'],
        'q' => $mobile,
        'cnt' => $result['found'] ? 1 : 0,
        'ip' => substr($ip, 0, 45),
    ]);
} catch (PDOException $e) {}

// Only a real hit is cached - a miss may be temporary and must not hide a
// later real result.
if ($result['found']) {
    searchCacheStore($pdo, 'search_cache_bharat_gas_api', $cacheKey, $mobile, $result, currentUser()['username'] ?? 'unknown');
}

echo json_encode($result + ['usage' => nexoraUsage($pdo, 'bharat_gas_api')]);

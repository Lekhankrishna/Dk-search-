<?php
// Server-side proxy between the browser and the Indane Gas Pro automation,
// same shape as hp_gas_api.php: runs centrally via Gas/lpg_web's Flask
// service, browser never talks to app.cyfuture.co.in or Flask directly, and
// the app.cyfuture.co.in login lives in Gas/lpg_web/config.py, not in this
// app's database.
require __DIR__ . '/includes/auth.php';
requireIndaneGasProAccess();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/search_cache.php';

header('Content-Type: application/json');

const FLASK_BASE = 'http://127.0.0.1:9197';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$mobileNumber = preg_replace('/\D/', '', $data['mobileNumber'] ?? '');

if (strlen($mobileNumber) !== 10) {
    http_response_code(400);
    echo json_encode(['error' => 'Enter a valid 10-digit mobile number.']);
    exit;
}

// Read-through cache - unlimited/no quota tool (2026-09-03), so this is
// purely a speed optimization now, not a quota-avoidance path. Cached
// forever - see includes/search_cache.php's own header comment for the
// reasoning.
$cacheKey = searchCacheKey($mobileNumber);
$cached = searchCacheGet($pdo, 'search_cache_indane_gas_pro', $cacheKey);
if ($cached !== null) {
    searchLogSavedResult($pdo, 'indane_gas_pro', $mobileNumber, !empty($cached['found']) ? 1 : 0);
    http_response_code(200);
    echo json_encode($cached);
    exit;
}

$ch = curl_init(FLASK_BASE . '/api/indane-gas-pro');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
// Generous timeout - one real app.cyfuture.co.in page load, plus its own
// consumer-lookup AJAX round trip.
// 120s (was 60): the scraper now waits up to 45s for the site's own lookup
// on top of login/page loads - see indane_gas_pro.py's _try_company().
curl_setopt($ch, CURLOPT_TIMEOUT, 120);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['mobileNumber' => $mobileNumber]));
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err      = curl_error($ch);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['error' => "Could not reach the Indane Gas Pro service: $err"]);
    exit;
}

// Every completed attempt is still logged for Admin > Audit Log visibility
// (a failed login/timeout/unreachable-service attempt isn't logged at all).
$decoded = json_decode($response, true);
if ($httpCode === 200 && is_array($decoded) && array_key_exists('found', $decoded)) {
    try {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $pdo->prepare(
            "INSERT INTO search_logs (user_id, search_type, search_query, result_count, ip_address)
             VALUES (:uid, 'indane_gas_pro', :q, :cnt, :ip)"
        )->execute([
            'uid' => $_SESSION['user_id'],
            'q' => $mobileNumber,
            'cnt' => $decoded['found'] ? 1 : 0,
            'ip' => substr($ip, 0, 45),
        ]);
    } catch (PDOException $e) {}

    // Only real results are cached (see tracing2_api.php's same rule).
    if (!empty($decoded['found'])) {
        searchCacheStore($pdo, 'search_cache_indane_gas_pro', $cacheKey, $mobileNumber, $decoded, currentUser()['username'] ?? 'unknown');
    }
}

http_response_code($httpCode ?: 200);
echo $response;

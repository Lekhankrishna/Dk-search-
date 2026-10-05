<?php
// Server-side proxy between the browser and the Tata Play distributor SSO
// automation, same shape as hp_gas_api.php: runs centrally via Gas/lpg_web's
// Flask service, browser never talks to mysso.tataplay.com/Flask directly,
// and the Tata Play SSO login lives in Gas/lpg_web/tataplay.py, not in this
// app's database.
require __DIR__ . '/includes/auth.php';
requireTataPlayAccess();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/tataplay_archive.php';
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

// Read-through cache (2026-08-27) - a repeat search for the same number is
// served instantly from our own database instead of going through the SSO
// login + Siebel PRM quick-find again. Cached forever - see
// includes/search_cache.php's own header comment for the reasoning.
$cacheKey = searchCacheKey($mobileNumber);
$cached = searchCacheGet($pdo, 'search_cache_tata_play', $cacheKey);
if ($cached !== null) {
    searchLogSavedResult($pdo, 'tata_play', $mobileNumber, !empty($cached['found']) ? count($cached['accounts'] ?? []) : 0);
    http_response_code(200);
    echo json_encode($cached);
    exit;
}

$ch = curl_init(FLASK_BASE . '/api/tataplay');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
// Generous timeout - a fresh mysso.tataplay.com login plus the Siebel PRM
// quick-find, same reasoning as hp_gas_api.php.
curl_setopt($ch, CURLOPT_TIMEOUT, 90);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['mobileNumber' => $mobileNumber]));
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err      = curl_error($ch);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['error' => "Could not reach the Tata Play service: $err"]);
    exit;
}

// A genuinely completed lookup still gets logged for Admin > Audit Log
// (no monthly limit to enforce anymore) - a failed login, timeout, or
// unreachable service isn't the agent's fault. "found": false still
// counts as a completed search, same reasoning as hp_gas_api.php.
$decoded = json_decode($response, true);
if ($httpCode === 200 && is_array($decoded) && array_key_exists('found', $decoded)) {
    try {
        $accounts = $decoded['found'] ? ($decoded['accounts'] ?? []) : [];
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $pdo->prepare(
            "INSERT INTO search_logs (user_id, search_type, search_query, result_count, ip_address)
             VALUES (:uid, 'tata_play', :q, :cnt, :ip)"
        )->execute([
            'uid' => $_SESSION['user_id'],
            'q' => $mobileNumber,
            'cnt' => count($accounts),
            'ip' => substr($ip, 0, 45),
        ]);
    } catch (PDOException $e) {}

    // A search can genuinely match more than one account (see
    // tataplay.py's run_tataplay_single()) - archive each one; already
    // deduped per subscriber id + status inside archiveTataPlayResult()
    // itself, so this is safe to call once per account. tataplay.py
    // returns the address as separate fields now (Address Line 1/2,
    // Village/Town/City, Town, District, Tahsil, State, Pin Code) - the
    // archive's own CSV format stays a single "Address" column, built by
    // joining them here rather than growing the CSV to match every new
    // field this round added.
    foreach ($accounts as $account) {
        $addressParts = array_filter([
            $account['addressLine1'] ?? '',
            $account['addressLine2'] ?? '',
            $account['villageTownCity'] ?? '',
            $account['town'] ?? '',
            $account['district'] ?? '',
            $account['tahsil'] ?? '',
            $account['state'] ?? '',
            $account['pinCode'] ?? '',
        ], fn($v) => $v !== '');
        archiveTataPlayResult(
            $account['accountName'] ?? '',
            $account['subscriberId'] ?? '',
            $account['accountStatus'] ?? '',
            implode(', ', $addressParts),
            $account['lastRechargeDate'] ?? '',
            currentUser()['username'] ?? 'unknown',
            $mobileNumber
        );
    }

    // Only real results are cached (see tracing2_api.php's same rule).
    if (!empty($decoded['found'])) {
        searchCacheStore($pdo, 'search_cache_tata_play', $cacheKey, $mobileNumber, $decoded, currentUser()['username'] ?? 'unknown');
    }
}

http_response_code($httpCode ?: 200);
echo $response;

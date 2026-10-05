<?php
// Server-side proxy between the browser and the HP Gas Advanced automation,
// same shape as rc_print_api.php: runs centrally via Gas/lpg_web's Flask
// service, browser never talks to locateme.services or Flask directly, and
// the locateme.services login lives in Gas/lpg_web/rc_print.py (reused by
// hp_gas.py), not in this app's database.
require __DIR__ . '/includes/auth.php';
requireHpGasAccess();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/hpgas_archive.php';
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

// Each search spends real credits (150/search) on the single shared
// locateme.services account, so every agent is capped per calendar month
// (Admin > Agents > "HP Gas Monthly Limit") - same reasoning and query
// shape as rc_print_api.php's limit check. Admins bypass this entirely.
// Checked BEFORE the cache lookup below (2026-10-02, per explicit
// instruction: a cached result is still a result handed to the agent, so it
// must still be blocked once their quota is exhausted, exactly like a live
// fetch - a cache hit is no longer a free pass around the monthly limit).
if (($_SESSION['role'] ?? '') !== 'admin') {
    $stmt = $pdo->prepare('SELECT hp_gas_monthly_limit FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $limit = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM search_logs WHERE user_id = :id AND search_type = 'hp_gas' AND result_count > 0 AND searched_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
    );
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $usedThisMonth = (int) $stmt->fetchColumn();

    if ($usedThisMonth >= $limit) {
        http_response_code(429);
        echo json_encode([
            'error' => "Monthly HP Gas limit reached ($usedThisMonth/$limit this month). Contact your admin to increase it, or try again next month.",
            'used' => $usedThisMonth,
            'limit' => $limit,
        ]);
        exit;
    }
}

// Read-through cache (2026-08-27). Cached forever - see
// includes/search_cache.php's own header comment for the reasoning. The
// used/limit fields are deliberately left out of the cached payload and
// recomputed fresh below on every response (cached or live) so they never
// go stale. A hit still logs to search_logs and counts against the
// monthly limit below, same as a live fetch (2026-10-02 - see comment
// above the limit check).
$cacheKey = searchCacheKey($mobileNumber);
$cached = searchCacheGet($pdo, 'search_cache_hp_gas', $cacheKey);
if ($cached !== null) {
    try {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $pdo->prepare(
            "INSERT INTO search_logs (user_id, search_type, search_query, result_count, ip_address)
             VALUES (:uid, 'hp_gas', :q, :cnt, :ip)"
        )->execute([
            'uid' => $_SESSION['user_id'],
            'q' => $mobileNumber,
            'cnt' => !empty($cached['found']) ? 1 : 0,
            'ip' => substr($ip, 0, 45),
        ]);
    } catch (PDOException $e) {}

    if (($_SESSION['role'] ?? '') !== 'admin') {
        try {
            $stmt = $pdo->prepare('SELECT hp_gas_monthly_limit FROM users WHERE id = :id');
            $stmt->execute(['id' => $_SESSION['user_id']]);
            $cached['limit'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM search_logs WHERE user_id = :id AND search_type = 'hp_gas' AND result_count > 0 AND searched_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
            );
            $stmt->execute(['id' => $_SESSION['user_id']]);
            $cached['used'] = (int) $stmt->fetchColumn();
        } catch (PDOException $e) {}
    }
    http_response_code(200);
    echo json_encode($cached);
    exit;
}

$ch = curl_init(FLASK_BASE . '/api/hp-gas');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
// Generous timeout - a fresh locateme.services login plus their own search,
// same reasoning as rc_print_api.php.
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
    echo json_encode(['error' => "Could not reach the HP Gas service: $err"]);
    exit;
}

// Every completed attempt is still logged for Admin > Audit Log
// visibility (a failed login/timeout/unreachable-service attempt isn't
// logged at all, same as before) - but only a genuine "found": true result
// counts against the monthly limit (2026-08-28, per explicit instruction:
// a clean not-found result must not cost the agent quota just because the
// agent never actually got anything back). result_count is 0 on a
// not-found row, which is exactly what the quota queries above/below
// filter on via "AND result_count > 0" - nothing here needs to change,
// only what counts as "used" downstream.
$decoded = json_decode($response, true);
if ($httpCode === 200 && is_array($decoded) && array_key_exists('found', $decoded)) {
    try {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $pdo->prepare(
            "INSERT INTO search_logs (user_id, search_type, search_query, result_count, ip_address)
             VALUES (:uid, 'hp_gas', :q, :cnt, :ip)"
        )->execute([
            'uid' => $_SESSION['user_id'],
            'q' => $mobileNumber,
            'cnt' => $decoded['found'] ? 1 : 0,
            'ip' => substr($ip, 0, 45),
        ]);

        if (($_SESSION['role'] ?? '') !== 'admin') {
            $stmt = $pdo->prepare('SELECT hp_gas_monthly_limit FROM users WHERE id = :id');
            $stmt->execute(['id' => $_SESSION['user_id']]);
            $decoded['limit'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM search_logs WHERE user_id = :id AND search_type = 'hp_gas' AND result_count > 0 AND searched_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
            );
            $stmt->execute(['id' => $_SESSION['user_id']]);
            $decoded['used'] = (int) $stmt->fetchColumn();

            $response = json_encode($decoded);
        }
    } catch (PDOException $e) {}

    if (!empty($decoded['found']) && !empty($decoded['sections'])) {
        archiveHpGasResults($decoded['sections'], currentUser()['username'] ?? 'unknown', $mobileNumber);
    }

    // Only real results are cached (see tracing2_api.php's same rule).
    if (!empty($decoded['found'])) {
        $toCache = $decoded;
        unset($toCache['used'], $toCache['limit']);
        searchCacheStore($pdo, 'search_cache_hp_gas', $cacheKey, $mobileNumber, $toCache, currentUser()['username'] ?? 'unknown');
    }
}

http_response_code($httpCode ?: 200);
echo $response;

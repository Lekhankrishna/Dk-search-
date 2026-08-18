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

// Same monthly-cap pattern as hp_gas_api.php/rc_print_api.php - this is a
// real login against the distributor's own Tata Play account, so every
// agent is capped per calendar month (Admin > Agents > "Tata Play Monthly
// Limit"). Admins bypass this entirely.
if (($_SESSION['role'] ?? '') !== 'admin') {
    $stmt = $pdo->prepare('SELECT tata_play_monthly_limit FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $limit = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM search_logs WHERE user_id = :id AND search_type = 'tata_play' AND searched_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
    );
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $usedThisMonth = (int) $stmt->fetchColumn();

    if ($usedThisMonth >= $limit) {
        http_response_code(429);
        echo json_encode([
            'error' => "Monthly Tata Play limit reached ($usedThisMonth/$limit this month). Contact your admin to increase it, or try again next month.",
            'used' => $usedThisMonth,
            'limit' => $limit,
        ]);
        exit;
    }
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

// Only a genuinely completed lookup counts against the monthly limit and
// shows up in Admin > Audit Log - a failed login, timeout, or unreachable
// service isn't the agent's fault. "found": false still counts as a
// completed search, same reasoning as hp_gas_api.php.
$decoded = json_decode($response, true);
if ($httpCode === 200 && is_array($decoded) && array_key_exists('found', $decoded)) {
    try {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $pdo->prepare(
            "INSERT INTO search_logs (user_id, search_type, search_query, result_count, ip_address)
             VALUES (:uid, 'tata_play', :q, :cnt, :ip)"
        )->execute([
            'uid' => $_SESSION['user_id'],
            'q' => $mobileNumber,
            'cnt' => $decoded['found'] ? 1 : 0,
            'ip' => substr($ip, 0, 45),
        ]);

        if (($_SESSION['role'] ?? '') !== 'admin') {
            $stmt = $pdo->prepare('SELECT tata_play_monthly_limit FROM users WHERE id = :id');
            $stmt->execute(['id' => $_SESSION['user_id']]);
            $decoded['limit'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM search_logs WHERE user_id = :id AND search_type = 'tata_play' AND searched_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
            );
            $stmt->execute(['id' => $_SESSION['user_id']]);
            $decoded['used'] = (int) $stmt->fetchColumn();

            $response = json_encode($decoded);
        }
    } catch (PDOException $e) {}

    if (!empty($decoded['found'])) {
        archiveTataPlayResult(
            $decoded['accountName'] ?? '',
            $decoded['subscriberId'] ?? '',
            $decoded['accountStatus'] ?? '',
            $decoded['address'] ?? '',
            $decoded['lastRechargeDate'] ?? '',
            currentUser()['username'] ?? 'unknown',
            $mobileNumber
        );
    }
}

http_response_code($httpCode ?: 200);
echo $response;

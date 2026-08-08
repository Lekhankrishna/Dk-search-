<?php
// Server-side handler for "Advance Pan India" (theeagleeye.biz's Advanced
// Search) - same monthly-limit/logging shape as rc_print_api.php/
// hp_gas_api.php, but calls includes/eagleeye_client.php directly in-process
// rather than proxying to a separate Flask/Selenium service, since
// theeagleeye.biz needs nothing more than plain HTTP+cookies (see that
// file's own comment on why).
require __DIR__ . '/includes/auth.php';
requireEagleEyeAccess();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/eagleeye_client.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$params = [
    'name'      => trim($data['name'] ?? ''),
    'fname'     => trim($data['fname'] ?? ''),
    'mobile'    => trim($data['mobile'] ?? ''),
    'email'     => trim($data['email'] ?? ''),
    'address'   => trim($data['address'] ?? ''),
    'master_id' => trim($data['master_id'] ?? ''),
];

if (!array_filter($params, fn($v) => $v !== '')) {
    http_response_code(400);
    echo json_encode(['error' => 'Enter at least one search field.']);
    exit;
}

// Single shared monthly plan pool on theeagleeye.biz's side (observed
// "930 / 1000 searches" in its own navbar, 2026-08-08) - every agent is
// capped per calendar month (Admin > Agents > "Advance Pan India Monthly
// Limit") so one agent can't burn through the whole account's plan alone.
// Admins bypass this entirely, same as RC Print/HP Gas.
if (($_SESSION['role'] ?? '') !== 'admin') {
    $stmt = $pdo->prepare('SELECT eagle_eye_monthly_limit FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $limit = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM search_logs WHERE user_id = :id AND search_type = 'eagle_eye' AND searched_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
    );
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $usedThisMonth = (int) $stmt->fetchColumn();

    if ($usedThisMonth >= $limit) {
        http_response_code(429);
        echo json_encode([
            'error' => "Monthly Advance Pan India limit reached ($usedThisMonth/$limit this month). Contact your admin to increase it, or try again next month.",
            'used' => $usedThisMonth,
            'limit' => $limit,
        ]);
        exit;
    }
}

try {
    $result = eagleEyeSearch($params);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// A completed search (found or not) counts against the monthly limit and
// shows up in Admin > Audit Log.
try {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $queryText = implode(' ', array_filter($params, fn($v) => $v !== ''));
    $pdo->prepare(
        "INSERT INTO search_logs (user_id, search_type, search_query, result_count, ip_address)
         VALUES (:uid, 'eagle_eye', :q, :cnt, :ip)"
    )->execute([
        'uid' => $_SESSION['user_id'],
        'q' => substr($queryText, 0, 512),
        'cnt' => $result['totalResults'],
        'ip' => substr($ip, 0, 45),
    ]);

    if (($_SESSION['role'] ?? '') !== 'admin') {
        $stmt = $pdo->prepare('SELECT eagle_eye_monthly_limit FROM users WHERE id = :id');
        $stmt->execute(['id' => $_SESSION['user_id']]);
        $result['limit'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM search_logs WHERE user_id = :id AND search_type = 'eagle_eye' AND searched_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
        );
        $stmt->execute(['id' => $_SESSION['user_id']]);
        $result['used'] = (int) $stmt->fetchColumn();
    }
} catch (PDOException $e) {}

echo json_encode($result);

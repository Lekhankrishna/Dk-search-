<?php
// Server-side handler for "Night Out" - same monthly-limit/logging shape
// as advance_pan_india_api.php, but calls includes/pan_india_pro_client.php
// directly in-process rather than proxying to a separate Flask/Selenium
// service, since the vendor's backend needs nothing more than plain
// HTTP+bearer-token (see that file's own comment on why).
require __DIR__ . '/includes/auth.php';
requirePanIndiaProAccess();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/pan_india_pro_client.php';
require_once __DIR__ . '/includes/pan_india_pro_archive.php';

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

// Single shared account on the vendor's side (10000 searches/day observed
// 2026-08-09) - every agent is capped per calendar month (Admin > Agents >
// "Night Out Monthly Limit") so one agent can't burn through the whole
// account's daily quota alone. Admins bypass this entirely, same as RC
// Print/HP Gas/Advance Pan India.
if (($_SESSION['role'] ?? '') !== 'admin') {
    $stmt = $pdo->prepare('SELECT pan_india_pro_monthly_limit FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $limit = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM search_logs WHERE user_id = :id AND search_type = 'pan_india_pro' AND searched_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
    );
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $usedThisMonth = (int) $stmt->fetchColumn();

    if ($usedThisMonth >= $limit) {
        http_response_code(429);
        echo json_encode([
            'error' => "Monthly Night Out limit reached ($usedThisMonth/$limit this month). Contact your admin to increase it, or try again next month.",
            'used' => $usedThisMonth,
            'limit' => $limit,
        ]);
        exit;
    }
}

try {
    $result = panIndiaProSearch($params);
} catch (Throwable $e) {
    // The real message (credentials, curl errors, etc.) can name internal
    // file paths - logged for diagnosis, but never shown to the agent, who'd
    // just see it as a confusing raw error rather than something actionable.
    error_log('pan_india_pro_api.php: ' . $e->getMessage());
    http_response_code(502);
    echo json_encode(['error' => 'Server Down. Please try again later.']);
    exit;
}

// A completed search (found or not) counts against the monthly limit and
// shows up in Admin > Audit Log.
try {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $queryText = implode(' ', array_filter($params, fn($v) => $v !== ''));
    $pdo->prepare(
        "INSERT INTO search_logs (user_id, search_type, search_query, result_count, ip_address)
         VALUES (:uid, 'pan_india_pro', :q, :cnt, :ip)"
    )->execute([
        'uid' => $_SESSION['user_id'],
        'q' => substr($queryText, 0, 512),
        'cnt' => $result['totalResults'],
        'ip' => substr($ip, 0, 45),
    ]);

    archivePanIndiaProResults($result['rows'] ?? [], currentUser()['username'] ?? 'unknown', $queryText);

    if (($_SESSION['role'] ?? '') !== 'admin') {
        $stmt = $pdo->prepare('SELECT pan_india_pro_monthly_limit FROM users WHERE id = :id');
        $stmt->execute(['id' => $_SESSION['user_id']]);
        $result['limit'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM search_logs WHERE user_id = :id AND search_type = 'pan_india_pro' AND searched_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
        );
        $stmt->execute(['id' => $_SESSION['user_id']]);
        $result['used'] = (int) $stmt->fetchColumn();
    }
} catch (PDOException $e) {}

echo json_encode($result);

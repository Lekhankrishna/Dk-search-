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

// Single shared account on tracekart.in's side - every agent is capped per
// calendar month (Admin > Agents > "Advanced Search Monthly Limit") so one
// agent can't burn through the whole account's own daily/IP quota alone.
// Admins bypass this entirely, same as every other tool here.
if (($_SESSION['role'] ?? '') !== 'admin') {
    $stmt = $pdo->prepare('SELECT advanced_search_monthly_limit FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $limit = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM search_logs WHERE user_id = :id AND search_type = 'advanced_search' AND searched_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
    );
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $usedThisMonth = (int) $stmt->fetchColumn();

    if ($usedThisMonth >= $limit) {
        http_response_code(429);
        echo json_encode([
            'error' => "Monthly Advanced Search limit reached ($usedThisMonth/$limit this month). Contact your admin to increase it, or try again next month.",
            'used' => $usedThisMonth,
            'limit' => $limit,
        ]);
        exit;
    }
}

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

// A completed search (found or not) counts against the monthly limit and
// shows up in Admin > Audit Log.
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

    if (($_SESSION['role'] ?? '') !== 'admin') {
        $stmt = $pdo->prepare('SELECT advanced_search_monthly_limit FROM users WHERE id = :id');
        $stmt->execute(['id' => $_SESSION['user_id']]);
        $result['limit'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM search_logs WHERE user_id = :id AND search_type = 'advanced_search' AND searched_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
        );
        $stmt->execute(['id' => $_SESSION['user_id']]);
        $result['used'] = (int) $stmt->fetchColumn();
    }
} catch (PDOException $e) {}

echo json_encode($result);

<?php
require __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
ob_start();

// API requests must never redirect to login.php: fetch() would receive an
// HTML page and fail while trying to parse it as JSON.
if (!isLoggedIn() || !isSessionValid()) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'Your CRM session has expired or was replaced. Please sign in again.',
        'loginUrl' => 'login.php?reason=session_replaced',
    ]);
    exit;
}

require_once __DIR__ . '/../includes/telegram_worker_client.php';
set_time_limit(60);

function reply(int $status, array $data): never {
    // Third-party Telegram code can write diagnostic text. Never allow it to
    // prefix the JSON response consumed by fetch().
    if (ob_get_level() > 0) ob_clean();
    http_response_code($status);
    echo json_encode($data);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(405, ['ok' => false, 'error' => 'Method not allowed.']);

    // Input and results exist only for this request. They are not stored in
    // the database, a JSON file, the PHP session, or an application log.
    $type = $_POST['searchType'] ?? '';
    $query = trim((string) ($_POST['query'] ?? ''));
    if (!in_array($type, ['email', 'aadhaar', 'contact'], true) || $query === '') {
        reply(422, ['ok' => false, 'error' => 'A valid search type and query are required.']);
    }
    $response = telegramWorkerRequest(['action' => 'search', 'query' => $query], 30);
    reply(!empty($response['ok']) ? 200 : 503, $response + ['loginUrl' => 'telegram_login.php']);
} catch (Throwable $e) {
    reply(503, ['ok' => false, 'error' => $e->getMessage(), 'loginUrl' => 'telegram_login.php']);
}

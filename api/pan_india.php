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
require_once __DIR__ . '/../includes/pan_india_archive.php';
set_time_limit(60);

function reply(int $status, array $data): never {
    // Third-party Telegram code can write diagnostic text. Never allow it to
    // prefix the JSON response consumed by fetch().
    if (ob_get_level() > 0) ob_clean();
    http_response_code($status);
    echo json_encode($data);
    exit;
}

// Contact-number searches default to India's "91" country code + 10-digit
// number - a plain 10-digit entry (the normal case) gets "91" prepended so
// it matches the bot's own record format, without double-prefixing an
// entry that already includes it (with or without a "+"/spaces/dashes).
function normalizeContactQuery(string $query): string {
    $digits = preg_replace('/\D+/', '', $query);
    if (strlen($digits) === 10) {
        return '91' . $digits;
    }
    return $digits;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(405, ['ok' => false, 'error' => 'Method not allowed.']);

    $type = $_POST['searchType'] ?? '';
    $query = trim((string) ($_POST['query'] ?? ''));
    if (!in_array($type, ['email', 'aadhaar', 'contact'], true) || $query === '') {
        reply(422, ['ok' => false, 'error' => 'A valid search type and query are required.']);
    }
    if ($type === 'contact') {
        $query = normalizeContactQuery($query);
    }
    $response = telegramWorkerRequest(['action' => 'search', 'query' => $query], 30);
    if (empty($response['ok'])) {
        // Never forward the worker's own error text to the client - it can
        // name the underlying provider or its exact failure mode. Agents
        // just need to know the search didn't go through.
        reply(503, ['ok' => false, 'error' => 'Server is down. Please try again later.']);
    }
    archivePanIndiaResults($response['results'] ?? [], currentUser()['username'] ?? 'unknown', $type, $query);
    reply(200, $response);
} catch (Throwable $e) {
    // Same reasoning: connection refused, timeout, malformed response, etc.
    // all collapse to one generic message rather than leaking internals.
    reply(503, ['ok' => false, 'error' => 'Server is down. Please try again later.']);
}

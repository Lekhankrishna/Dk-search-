<?php
// Server-side proxy between the browser and the HP Gas Advanced automation,
// which runs centrally via Gas/lpg_web's Flask service - browser never talks
// to locateme.services or Flask directly, and the locateme.services login
// lives in Gas/lpg_web/rc_print.py (reused by hp_gas.py), not in this app's
// database.
//
// action=start/action=status, same shape as lpg_search_api.php (2026-08-11 -
// previously one blocking POST per single search). A single search is just
// a 1-number batch through this same job queue, not a separate code path -
// see Gas/lpg_web/hp_gas.py's run_hp_gas_bulk().
require __DIR__ . '/includes/auth.php';

header('Content-Type: application/json');

// Must never redirect to login.php: fetch() would receive an HTML page and
// fail trying to parse it as JSON - same guard as lpg_search_api.php.
if (!isLoggedIn() || !isSessionValid()) {
    http_response_code(401);
    echo json_encode([
        'error' => 'Your CRM session has expired or was replaced. Please sign in again.',
        'loginUrl' => 'login.php?reason=session_replaced',
    ]);
    exit;
}
if (!hasHpGasAccess()) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied: HP Gas access has not been granted for this account.']);
    exit;
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/hpgas_archive.php';

const FLASK_BASE = 'http://127.0.0.1:9197';

// Flat cap for everyone, agent or admin - not a technical ceiling, a
// deliberate usage cap, same reasoning as lpg_search.php's BULK_NUMBER_LIMIT.
// The monthly credit quota below is what actually limits how much of a
// batch an agent can afford, not this.
const HP_GAS_BULK_LIMIT = 10;

function callFlask(string $method, string $path, ?array $body = null): array {
    $ch = curl_init(FLASK_BASE . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // A batch of up to 10 can legitimately take several minutes (each
    // number a real locateme.services lookup, processed one at a time) -
    // this is just the proxy hop timeout for one HTTP call, not a limit on
    // the search job itself (the frontend polls action=status repeatedly
    // instead of holding one request open for the whole batch).
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        http_response_code(502);
        echo json_encode(['error' => "Could not reach the HP Gas service: $err"]);
        exit;
    }

    return ['httpCode' => $httpCode ?: 200, 'decoded' => json_decode($response, true), 'raw' => $response];
}

function hpGasQuota(int $userId): array {
    global $pdo;
    $stmt = $pdo->prepare('SELECT hp_gas_monthly_limit FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $limit = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM search_logs WHERE user_id = :id AND search_type = 'hp_gas' AND searched_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
    );
    $stmt->execute(['id' => $userId]);
    $used = (int) $stmt->fetchColumn();

    return ['used' => $used, 'limit' => $limit];
}

$action = $_GET['action'] ?? '';
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$userId = $_SESSION['user_id'];

if ($action === 'start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];

    $numbers = [];
    foreach (($data['numbers'] ?? []) as $n) {
        $digits = preg_replace('/\D/', '', (string) $n);
        if ($digits !== '') $numbers[] = $digits;
    }
    $numbers = array_slice($numbers, 0, HP_GAS_BULK_LIMIT);

    if (!$numbers) {
        http_response_code(400);
        echo json_encode(['error' => 'No numbers provided']);
        exit;
    }

    // Each number spends its own share of the single shared
    // locateme.services account's credits, so a batch of N counts as N
    // against the monthly limit - checked upfront against the WHOLE batch.
    // Rejecting outright rather than silently truncating avoids a
    // partially-run batch where an agent can't tell which numbers actually
    // got searched.
    if (!$isAdmin) {
        $quota = hpGasQuota($userId);
        if ($quota['used'] + count($numbers) > $quota['limit']) {
            http_response_code(429);
            echo json_encode([
                'error' => 'This batch of ' . count($numbers) . " would exceed your monthly HP Gas limit ({$quota['used']}/{$quota['limit']} used this month). Contact your admin to increase it, or try a smaller batch.",
                'used' => $quota['used'],
                'limit' => $quota['limit'],
            ]);
            exit;
        }
    }

    $result = callFlask('POST', '/api/hp-gas/search', ['numbers' => $numbers]);
    http_response_code($result['httpCode']);
    echo $result['raw'];
    exit;

} elseif ($action === 'status') {
    $jobId = $_GET['jobId'] ?? '';
    if (!preg_match('/^[0-9a-f]{32}$/', $jobId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid job id']);
        exit;
    }

    $result = callFlask('GET', '/api/hp-gas/search/' . $jobId);
    $decoded = $result['decoded'];

    if (is_array($decoded) && !empty($decoded['newlyCompleted'])) {
        // Only the first status poll to observe a job as "completed" bills
        // and logs it - repeat polls of an already-delivered job (a browser
        // retry, a duplicate tab) must not double-count against the monthly
        // limit. newlyCompleted is Flask's own job-lifecycle bookkeeping
        // (see app.py's _get_job()) - the actual counting/limit decision all
        // happens here, same as every other tool.
        $searchedBy = currentUser()['username'] ?? 'unknown';
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

        foreach (($decoded['results'] ?? []) as $record) {
            // A technical failure (session died mid-batch, a timeout) never
            // completed a real lookup, so it isn't billed or logged - same
            // as an unreachable service never reaching search_logs in the
            // old single-search flow.
            if (!empty($record['error'])) continue;

            try {
                $pdo->prepare(
                    "INSERT INTO search_logs (user_id, search_type, search_query, result_count, ip_address)
                     VALUES (:uid, 'hp_gas', :q, :cnt, :ip)"
                )->execute([
                    'uid' => $userId,
                    'q' => $record['mobileNumber'] ?? '',
                    'cnt' => !empty($record['found']) ? 1 : 0,
                    'ip' => substr($ip, 0, 45),
                ]);
            } catch (PDOException $e) {}

            if (!empty($record['found']) && !empty($record['sections'])) {
                archiveHpGasResults($record['sections'], $searchedBy, $record['mobileNumber'] ?? '');
            }
        }
    }

    if (is_array($decoded) && !$isAdmin) {
        $quota = hpGasQuota($userId);
        $decoded['used'] = $quota['used'];
        $decoded['limit'] = $quota['limit'];
    }

    http_response_code($result['httpCode']);
    echo $decoded !== null ? json_encode($decoded) : $result['raw'];
    exit;

} else {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
}

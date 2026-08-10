<?php
// Server-side proxy between the browser and the RC Print automation, which
// runs centrally on this same machine (Gas/lpg_web's Flask service, same
// process that already runs LPG bulk search) rather than anywhere the
// browser could reach directly - same shape as lpg_search_api.php. The
// locateme.services login itself lives in Gas/lpg_web/rc_print.py, not in
// this app's database, so there's no key/credential handling here at all -
// this just forwards the vehicle number and relays back whatever the
// automation returns (a PDF data URI, or an error).
require __DIR__ . '/includes/auth.php';
requireRcPrintAccess();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/rcprint_archive.php';

header('Content-Type: application/json');

const FLASK_BASE = 'http://127.0.0.1:9197';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$vehicleNumber = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $data['vehicleNumber'] ?? ''));

if ($vehicleNumber === '' || strlen($vehicleNumber) < 4 || strlen($vehicleNumber) > 15) {
    http_response_code(400);
    echo json_encode(['error' => 'Enter a valid vehicle registration number.']);
    exit;
}

// Each search spends real credits on the single shared locateme.services
// account (Gas/lpg_web/rc_print.py), so every agent is capped per calendar
// month (Admin > Agents > "RC Print Monthly Limit") to stop one agent from
// burning through the whole account's budget alone. Admins bypass this
// entirely - same admin-vs-agent split as LPG's number cap. Checked fresh
// from search_logs every request (not cached) so a same-month admin change
// or the start-of-month reset takes effect immediately, not just next login.
if (($_SESSION['role'] ?? '') !== 'admin') {
    $stmt = $pdo->prepare('SELECT rc_print_monthly_limit FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $limit = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM search_logs WHERE user_id = :id AND search_type = 'rc_print' AND searched_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
    );
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $usedThisMonth = (int) $stmt->fetchColumn();

    if ($usedThisMonth >= $limit) {
        http_response_code(429);
        echo json_encode([
            'error' => "Monthly RC Print limit reached ($usedThisMonth/$limit this month). Contact your admin to increase it, or try again next month.",
            'used' => $usedThisMonth,
            'limit' => $limit,
        ]);
        exit;
    }
}

$ch = curl_init(FLASK_BASE . '/api/rc-print');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
// Generous timeout - a fresh Selenium run here means a real locateme.services
// login plus their own PDF-generation server action, not a cached session,
// so this can legitimately take upwards of 30-45s (see rc_print.py's own
// 45s result-polling deadline).
curl_setopt($ch, CURLOPT_TIMEOUT, 90);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['vehicleNumber' => $vehicleNumber]));
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err      = curl_error($ch);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['error' => "Could not reach the RC Print service: $err"]);
    exit;
}

// Only a genuinely completed lookup counts against the monthly limit and
// shows up in Admin > Audit Log - a failed login, timeout, or unreachable
// service isn't the agent's fault and shouldn't eat into their quota.
$decoded = json_decode($response, true);
if ($httpCode === 200 && is_array($decoded) && !empty($decoded['pdfDataUri'])) {
    try {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $pdo->prepare(
            "INSERT INTO search_logs (user_id, search_type, search_query, result_count, ip_address)
             VALUES (:uid, 'rc_print', :q, 1, :ip)"
        )->execute(['uid' => $_SESSION['user_id'], 'q' => $vehicleNumber, 'ip' => substr($ip, 0, 45)]);

        // Echo back the post-search quota so rc_print.php's badge reflects
        // server truth (handles multiple tabs/devices open at once) instead
        // of the frontend just guessing "used + 1".
        if (($_SESSION['role'] ?? '') !== 'admin') {
            $stmt = $pdo->prepare('SELECT rc_print_monthly_limit FROM users WHERE id = :id');
            $stmt->execute(['id' => $_SESSION['user_id']]);
            $decoded['limit'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM search_logs WHERE user_id = :id AND search_type = 'rc_print' AND searched_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
            );
            $stmt->execute(['id' => $_SESSION['user_id']]);
            $decoded['used'] = (int) $stmt->fetchColumn();

            $response = json_encode($decoded);
        }
    } catch (PDOException $e) {}

    archiveRcPrintResult($decoded['pdfDataUri'], $vehicleNumber, currentUser()['username'] ?? 'unknown');
}

http_response_code($httpCode ?: 200);
echo $response;

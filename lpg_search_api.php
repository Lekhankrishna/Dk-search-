<?php
// Server-side proxy between the browser and the LPG search service, which
// now runs centrally on this same machine (found 2026-07-27, replacing the
// old per-agent-computer install) rather than one copy per agent's own
// computer. This is a plain same-origin AJAX call from the browser's point
// of view - no Private Network Access headers, no protocol handler, no
// download needed, since the browser never talks to the search service
// directly at all; PHP does, over loopback, entirely server-side.
require __DIR__ . '/includes/auth.php';
requireLpgSearchAccess(); // requireLogin() + a 403 for logged-in users without the "LPG Search Access" permission (Admin > Agents)

header('Content-Type: application/json');

const FLASK_BASE = 'http://127.0.0.1:9197';

function proxyToFlask(string $method, string $path, ?array $body = null): void {
    $ch = curl_init(FLASK_BASE . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // A single search can legitimately take well over a minute (up to 500
    // numbers for an admin, each a real SDMS portal lookup, processed one
    // at a time) - this is just the proxy hop timeout for one HTTP call,
    // not a limit on the search job itself
    // (the frontend polls /api/search/<job_id> repeatedly instead of
    // holding one request open for the whole batch).
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
        echo json_encode(['error' => "Could not reach the search service: $err"]);
        exit;
    }
    http_response_code($httpCode ?: 200);
    echo $response;
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    // The 10-number cap is meant for agents; admins can run larger batches.
    // isAdmin is decided here from the actual session role, not trusted from
    // the request body - app.py just does what this proxy tells it.
    $isAdmin = (currentUser()['role'] ?? '') === 'admin';
    proxyToFlask('POST', '/api/search', ['numbers' => $data['numbers'] ?? [], 'isAdmin' => $isAdmin]);
} elseif ($action === 'status') {
    $jobId = $_GET['jobId'] ?? '';
    if (!preg_match('/^[0-9a-f]{32}$/', $jobId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid job id']);
        exit;
    }
    proxyToFlask('GET', '/api/search/' . $jobId);
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
}

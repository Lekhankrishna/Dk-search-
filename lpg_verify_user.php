<?php
// Called by the locally-installed Flask tool (no CRM browser session at all
// - it's a background process on the agent's own computer) before it will
// run a search. Reuses the SAME per-agent capability key as
// lpg_autofill.php (lpgEnsureBookmarkletKey()) rather than inventing a
// second mechanism, so disabling an account, revoking LPG access, letting
// it expire, or regenerating the key (Admin > Agents) all take effect
// immediately - even on a computer this tool was already installed on
// (found 2026-07-26: a downloaded copy previously kept working forever
// once installed, with no way to cut off a specific agent's copy without
// rotating the shared SDMS password for everyone else).
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$key = $_GET['k'] ?? '';
if (!preg_match('/^[0-9a-f]{64}$/', $key)) {
    echo json_encode(['active' => false, 'reason' => 'invalid key']);
    exit;
}

$stmt = $pdo->prepare(
    'SELECT username, is_active, lpg_search_access, expires_at, session_token FROM users WHERE lpg_bookmarklet_key = :key'
);
$stmt->execute(['key' => $key]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo json_encode(['active' => false, 'reason' => 'unknown key']);
    exit;
}
if (!$user['is_active'] || !$user['lpg_search_access']) {
    echo json_encode(['active' => false, 'reason' => 'access not granted']);
    exit;
}
if ($user['expires_at'] !== null && strtotime($user['expires_at']) <= time()) {
    echo json_encode(['active' => false, 'reason' => 'account expired']);
    exit;
}
// session_token is cleared to NULL by logout.php on an explicit logout
// (found 2026-07-26 - it previously only ever got SET at login and never
// cleared, so it stayed populated forever and couldn't tell "logged out"
// apart from "logged in"). This makes that a live, real-time signal: log
// out of the CRM anywhere and this starts failing on this exact same check,
// immediately, the next time the tool polls it.
if ($user['session_token'] === null) {
    echo json_encode(['active' => false, 'reason' => 'logged out']);
    exit;
}

echo json_encode(['active' => true, 'username' => $user['username']]);

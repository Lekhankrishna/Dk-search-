<?php
// Self-service password change - reachable from the "Change Password" icon
// next to Logout in the sidebar (includes/header.php). Any logged-in user
// can change their own password here; changing an ANOTHER account's
// password is a separate, admin-only flow (admin/agents.php's edit modal).
require __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !isSessionValid()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Your session has expired. Please sign in again.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$currentPassword = (string) ($_POST['current_password'] ?? '');
$newPassword     = (string) ($_POST['new_password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');

if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'All fields are required.']);
    exit;
}
if (strlen($newPassword) < 6) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'New password must be at least 6 characters.']);
    exit;
}
if ($newPassword !== $confirmPassword) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'New password and confirmation do not match.']);
    exit;
}

$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
$stmt->execute(['id' => $_SESSION['user_id']]);
$hash = $stmt->fetchColumn();

if (!$hash || !password_verify($currentPassword, $hash)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Current password is incorrect.']);
    exit;
}

$pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
    ->execute(['hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $_SESSION['user_id']]);

// Same reasoning as admin/agents.php's own password-change: invalidates
// every session for the account, including this one - a password change is
// exactly the kind of event other open sessions (this one included)
// shouldn't silently survive.
$pdo->prepare('DELETE FROM user_sessions WHERE user_id = :id')->execute(['id' => $_SESSION['user_id']]);

echo json_encode(['ok' => true]);

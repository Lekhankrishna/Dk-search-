<?php
require __DIR__ . '/includes/auth.php';

// Removing this session's own row (not just the local PHP session) is what
// makes logout actually observable from outside this browser tab (found
// 2026-07-26) - isSessionValid() (includes/auth.php) is what checks
// user_sessions now. Deletes only THIS device's row, not every session on
// the account - with multiple simultaneous logins allowed, logging out on
// one device must not sign the account out everywhere else too.
if (isLoggedIn()) {
    $pdo->prepare('DELETE FROM user_sessions WHERE user_id = :id AND session_token = :token')
        ->execute(['id' => $_SESSION['user_id'], 'token' => $_SESSION['session_token'] ?? '']);
}

$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;

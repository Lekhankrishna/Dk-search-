<?php
require __DIR__ . '/includes/auth.php';

// Clearing this in the DB (not just the local PHP session) is what makes
// logout actually observable from outside this browser tab (found
// 2026-07-26) - previously session_token was only ever SET at login and
// never cleared, so it stayed populated forever even after "logging out",
// making it useless as a live "is this account currently logged in"
// signal for anything checking from outside (like the LPG tool's
// real-time login-sync check in lpg_verify_user.php).
if (isLoggedIn()) {
    $pdo->prepare('UPDATE users SET session_token = NULL WHERE id = :id')
        ->execute(['id' => $_SESSION['user_id']]);
}

$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;

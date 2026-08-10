<?php
session_start();

require_once __DIR__ . '/../config/db.php';

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

// A user can be signed in on up to users.max_concurrent_sessions devices at
// once (Admin > Agents > "Max Simultaneous Logins", default 1) - each an
// independent row in user_sessions rather than a single shared token.
// login.php evicts the least-recently-used row when a new login would
// exceed the limit, so a session found valid here is exactly "one of this
// account's currently allotted device slots". Checked once per request
// (static cache) since it costs a DB round trip.
function isSessionValid(): bool {
    global $pdo;
    if (!isLoggedIn()) return true;
    static $valid = null;
    if ($valid !== null) return $valid;
    $stmt = $pdo->prepare('SELECT session_token FROM user_sessions WHERE user_id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $sessionToken = (string) ($_SESSION['session_token'] ?? '');
    $valid = false;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $dbToken) {
        if (hash_equals((string) $dbToken, $sessionToken)) { $valid = true; break; }
    }
    // Touched only on a valid hit, not every request - this is what makes
    // "least-recently-used" eviction actually track real activity instead
    // of just login order (an idle-but-still-open tab should lose its slot
    // before one someone is actively using right now).
    if ($valid) {
        $pdo->prepare('UPDATE user_sessions SET last_seen_at = NOW() WHERE user_id = :id AND session_token = :token')
            ->execute(['id' => $_SESSION['user_id'], 'token' => $sessionToken]);
    }
    return $valid;
}

function requireLogin(string $loginPath = 'login.php'): void {
    if (!isLoggedIn()) {
        header('Location: ' . $loginPath);
        exit;
    }
    if (!isSessionValid()) {
        session_unset();
        session_destroy();
        header('Location: ' . $loginPath . '?reason=session_replaced');
        exit;
    }
}

function requireAdmin(string $loginPath = 'login.php'): void {
    requireLogin($loginPath);
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        die('Access denied: admin only.');
    }
}

// Checked fresh from the DB on every request (not cached in $_SESSION at
// login time) so that an admin revoking access via Admin > Agents takes
// effect immediately — not just the next time the affected user logs in.
function hasLpgSearchAccess(): bool {
    global $pdo;
    if (!isLoggedIn()) return false;
    static $access = null;
    if ($access !== null) return $access;
    $stmt = $pdo->prepare('SELECT lpg_search_access FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $access = (bool) $stmt->fetchColumn();
    return $access;
}

function requireLpgSearchAccess(string $loginPath = 'login.php'): void {
    requireLogin($loginPath);
    if (!hasLpgSearchAccess()) {
        http_response_code(403);
        die('Access denied: LPG Search access has not been granted for this account.');
    }
}

// Same pattern as hasLpgSearchAccess() - checked fresh from the DB every
// request so a revoke from Admin > Agents takes effect immediately. Defaults
// to NOT granted (see migrate_add_rc_print_access.sql).
function hasRcPrintAccess(): bool {
    global $pdo;
    if (!isLoggedIn()) return false;
    static $access = null;
    if ($access !== null) return $access;
    $stmt = $pdo->prepare('SELECT rc_print_access FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $access = (bool) $stmt->fetchColumn();
    return $access;
}

function requireRcPrintAccess(string $loginPath = 'login.php'): void {
    requireLogin($loginPath);
    if (!hasRcPrintAccess()) {
        http_response_code(403);
        die('Access denied: RC Print access has not been granted for this account.');
    }
}

// Same pattern as hasRcPrintAccess() - checked fresh from the DB every
// request so a revoke from Admin > Agents takes effect immediately.
function hasHpGasAccess(): bool {
    global $pdo;
    if (!isLoggedIn()) return false;
    static $access = null;
    if ($access !== null) return $access;
    $stmt = $pdo->prepare('SELECT hp_gas_access FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $access = (bool) $stmt->fetchColumn();
    return $access;
}

function requireHpGasAccess(string $loginPath = 'login.php'): void {
    requireLogin($loginPath);
    if (!hasHpGasAccess()) {
        http_response_code(403);
        die('Access denied: HP LPG Search access has not been granted for this account.');
    }
}

// Same pattern as hasHpGasAccess() - checked fresh from the DB every
// request so a revoke from Admin > Agents takes effect immediately.
function hasEagleEyeAccess(): bool {
    global $pdo;
    if (!isLoggedIn()) return false;
    static $access = null;
    if ($access !== null) return $access;
    $stmt = $pdo->prepare('SELECT eagle_eye_access FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $access = (bool) $stmt->fetchColumn();
    return $access;
}

function requireEagleEyeAccess(string $loginPath = 'login.php'): void {
    requireLogin($loginPath);
    if (!hasEagleEyeAccess()) {
        http_response_code(403);
        die('Access denied: Advance Pan India access has not been granted for this account.');
    }
}

// Same pattern as hasLpgSearchAccess() - checked fresh from the DB every
// request so a revoke from Admin > Agents takes effect immediately. Defaults
// to granted for existing accounts (see migrate_add_pan_india_access.sql);
// this function is what admin/agents.php's per-agent toggle actually
// controls going forward.
function hasPanIndiaAccess(): bool {
    global $pdo;
    if (!isLoggedIn()) return false;
    static $access = null;
    if ($access !== null) return $access;
    $stmt = $pdo->prepare('SELECT pan_india_access FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $access = (bool) $stmt->fetchColumn();
    return $access;
}

function requirePanIndiaAccess(string $loginPath = 'login.php'): void {
    requireLogin($loginPath);
    if (!hasPanIndiaAccess()) {
        http_response_code(403);
        die('Access denied: Pan India Search access has not been granted for this account.');
    }
}

// Same pattern as hasPanIndiaAccess() - checked fresh from the DB every
// request so a revoke from Admin > Agents takes effect immediately. Defaults
// to NOT granted (see migrate_add_pan_india_pro_access.sql).
function hasPanIndiaProAccess(): bool {
    global $pdo;
    if (!isLoggedIn()) return false;
    static $access = null;
    if ($access !== null) return $access;
    $stmt = $pdo->prepare('SELECT pan_india_pro_access FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $access = (bool) $stmt->fetchColumn();
    return $access;
}

function requirePanIndiaProAccess(string $loginPath = 'login.php'): void {
    requireLogin($loginPath);
    if (!hasPanIndiaProAccess()) {
        http_response_code(403);
        die('Access denied: Night Out access has not been granted for this account.');
    }
}

// Global settings row (id = 1) — created by migrate_add_whatsapp_button.sql.
// Cached per-request; an admin's save on whatsapp_settings.php takes effect
// on the very next request for every user, not just after their next login.
function whatsappSettings(): array {
    global $pdo;
    static $settings = null;
    if ($settings !== null) return $settings;
    $row = $pdo->query('SELECT is_enabled, phone_number, default_message FROM whatsapp_settings WHERE id = 1')->fetch();
    $settings = $row ?: ['is_enabled' => 0, 'phone_number' => '919901431238', 'default_message' => 'Hi'];
    return $settings;
}

// Visible only when BOTH the global switch is on AND this account is on the
// admin-selected allowlist — disabling the switch hides it for everyone
// even if individual users are still marked allowed (see whatsapp_settings.php).
function hasWhatsAppButtonAccess(): bool {
    global $pdo;
    if (!isLoggedIn()) return false;
    if (!whatsappSettings()['is_enabled']) return false;
    static $access = null;
    if ($access !== null) return $access;
    $stmt = $pdo->prepare('SELECT whatsapp_button_access FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $access = (bool) $stmt->fetchColumn();
    return $access;
}

function currentUser(): array {
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'username' => $_SESSION['username'] ?? null,
        'full_name' => $_SESSION['full_name'] ?? null,
        'role' => $_SESSION['role'] ?? null,
        'expires_at' => $_SESSION['expires_at'] ?? null,
    ];
}

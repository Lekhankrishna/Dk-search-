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
// to NOT granted (see migrate_add_locate_me_access.sql - renamed to
// tracing2_access by migrate_rename_locate_me_to_tracing2.sql).
function hasTracing2Access(): bool {
    global $pdo;
    if (!isLoggedIn()) return false;
    static $access = null;
    if ($access !== null) return $access;
    $stmt = $pdo->prepare('SELECT tracing2_access FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $access = (bool) $stmt->fetchColumn();
    return $access;
}

function requireTracing2Access(string $loginPath = 'login.php'): void {
    requireLogin($loginPath);
    if (!hasTracing2Access()) {
        http_response_code(403);
        die('Access denied: Tracing 2.0 access has not been granted for this account.');
    }
}

// The per-agent tool checklist set in Admin > Agents (see
// migrate_add_locate_me_tools.sql). NULL means "never explicitly
// configured" - distinct from a saved-but-empty array, which means an
// admin actively unchecked every box. Returned as-is (null or array); see
// hasTracing2ToolAccess() for how callers should interpret it.
function getUserTracing2Tools(): ?array {
    global $pdo;
    if (!isLoggedIn()) return [];
    static $tools = 'unset';
    if ($tools !== 'unset') return $tools;
    $stmt = $pdo->prepare('SELECT tracing2_tools FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $raw = $stmt->fetchColumn();
    $tools = ($raw === null || $raw === false) ? null : (json_decode((string) $raw, true) ?: []);
    return $tools;
}

// Per-agent credit-cost overrides set in Admin > Agents (see
// migrate_add_tracing2_tool_credits.sql). NULL means "no overrides at all
// for this agent" - every tool falls back to includes/tracing2_tools.php's
// global default (tracing2CreditsFor()). A real array is a partial map
// (slug => credits); a slug missing from it still falls back to the
// global default too - see tracing2CreditsForUser() for how callers
// should combine the two.
function getUserTracing2ToolCredits(): ?array {
    global $pdo;
    if (!isLoggedIn()) return null;
    static $credits = 'unset';
    if ($credits !== 'unset') return $credits;
    $stmt = $pdo->prepare('SELECT tracing2_tool_credits FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $raw = $stmt->fetchColumn();
    $credits = ($raw === null || $raw === false) ? null : (json_decode((string) $raw, true) ?: []);
    return $credits;
}

// Gates an individual Tracing 2.0 tool (e.g. 'mobile-info', 'upi-finder') -
// separate from hasTracing2Access(), which only gates the page as a whole.
// Admins bypass this (same as every other per-search quota/access check in
// this app); a NULL tool list (never explicitly configured) defaults to
// "every tool allowed" so the two accounts that already had this access
// before the per-tool column existed don't lose access they already had.
// rc-print/hp-gas-advanced are NOT covered by this - they have their own
// hasRcPrintAccess()/hasHpGasAccess() checks instead (see
// includes/tracing2_tools.php's 'requiresAccess').
function hasTracing2ToolAccess(string $toolSlug): bool {
    if (!hasTracing2Access()) return false;
    if (($_SESSION['role'] ?? '') === 'admin') return true;
    $tools = getUserTracing2Tools();
    if ($tools === null) return true;
    return in_array($toolSlug, $tools, true);
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
// request so a revoke from Admin > Agents takes effect immediately. Defaults
// to NOT granted (see migrate_add_indane_gas_access.sql). Indane Gas Info
// used to live under the generic Tracing 2.0 per-tool checklist
// (hasTracing2ToolAccess('indane-gas-info')) - promoted to its own
// dedicated access flag + count-based monthly quota (2026-08-18), same
// shape as RC Print/HP Gas Advanced, so it no longer draws from the shared
// tracing2_monthly_limit credit budget.
function hasIndaneGasAccess(): bool {
    global $pdo;
    if (!isLoggedIn()) return false;
    static $access = null;
    if ($access !== null) return $access;
    $stmt = $pdo->prepare('SELECT indane_gas_access FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $access = (bool) $stmt->fetchColumn();
    return $access;
}

function requireIndaneGasAccess(string $loginPath = 'login.php'): void {
    requireLogin($loginPath);
    if (!hasIndaneGasAccess()) {
        http_response_code(403);
        die('Access denied: Indane Gas access has not been granted for this account.');
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
        die('Access denied: HP Gas Search access has not been granted for this account.');
    }
}

// Same pattern as hasHpGasAccess() - checked fresh from the DB every
// request so a revoke from Admin > Agents takes effect immediately.
function hasTataPlayAccess(): bool {
    global $pdo;
    if (!isLoggedIn()) return false;
    static $access = null;
    if ($access !== null) return $access;
    $stmt = $pdo->prepare('SELECT tata_play_access FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $access = (bool) $stmt->fetchColumn();
    return $access;
}

function requireTataPlayAccess(string $loginPath = 'login.php'): void {
    requireLogin($loginPath);
    if (!hasTataPlayAccess()) {
        http_response_code(403);
        die('Access denied: Tata Play Search access has not been granted for this account.');
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

// Same pattern as hasPanIndiaProAccess() - checked fresh from the DB every
// request so a revoke from Admin > Agents takes effect immediately. Defaults
// to NOT granted (see migrate_add_advanced_search_access.sql).
function hasAdvancedSearchAccess(): bool {
    global $pdo;
    if (!isLoggedIn()) return false;
    static $access = null;
    if ($access !== null) return $access;
    $stmt = $pdo->prepare('SELECT advanced_search_access FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $access = (bool) $stmt->fetchColumn();
    return $access;
}

function requireAdvancedSearchAccess(string $loginPath = 'login.php'): void {
    requireLogin($loginPath);
    if (!hasAdvancedSearchAccess()) {
        http_response_code(403);
        die('Access denied: Advanced Search access has not been granted for this account.');
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

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

// Real client IP, not the loopback address of whatever's proxying the
// request in front of this server (see run_crm_server.ps1's own comment -
// this site is reached both directly on 127.0.0.1 and via the public
// datasearch.in router-forward) - X-Forwarded-For first, same precedent
// already used for search_logs.ip_address in every *_api.php file here,
// falling back to REMOTE_ADDR for a direct connection.
function clientIp(): string {
    return (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
}

// users.allowed_ips is a free-form, comma/newline-separated list of exact
// IPs (no CIDR ranges - kept simple on purpose, see
// migrate_add_allowed_ips.sql's own comment) - empty/NULL means
// unrestricted, same "opt-in, off by default" shape as every other
// per-account setting in this app. X-Forwarded-For can legitimately carry
// more than one hop (client, then each proxy in between) as a comma-
// separated chain - only the first entry (the original client) is checked
// against the allow-list, not the whole chain.
function isIpAllowed(?string $allowedIpsRaw, string $requestIp): bool {
    $allowedIpsRaw = trim((string) $allowedIpsRaw);
    if ($allowedIpsRaw === '') return true;

    $requestIp = trim(explode(',', $requestIp)[0]);
    $allowedIps = preg_split('/[\s,]+/', $allowedIpsRaw, -1, PREG_SPLIT_NO_EMPTY);
    return in_array($requestIp, $allowedIps, true);
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
    // Re-checked every request (not just at login) - an admin adding a
    // restriction, or an agent's device moving to a different network,
    // should take effect immediately rather than only on the next fresh
    // login.
    global $pdo;
    static $allowedIps = null;
    if ($allowedIps === null) {
        $stmt = $pdo->prepare('SELECT allowed_ips FROM users WHERE id = :id');
        $stmt->execute(['id' => $_SESSION['user_id']]);
        $allowedIps = (string) $stmt->fetchColumn();
    }
    if (!isIpAllowed($allowedIps, clientIp())) {
        session_unset();
        session_destroy();
        header('Location: ' . $loginPath . '?reason=ip_restricted');
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

// sub_admin is a restricted subset of admin: can reach Admin > Agents to
// create/manage agent accounts (capped at SUB_ADMIN_MAX_AGENTS there, and
// scoped to only the agents they personally created - see
// admin/agents.php), but every OTHER admin-only page/right (Audit Log,
// Import, LPG/WhatsApp Settings) stays real-admin-only - see
// isSubAdmin()/isMainAdmin() for the distinction call sites need. Not
// special-cased into any hasXAccess() bypass for the search tools
// themselves - a sub-admin is a plain agent there, same as anyone else;
// the only extra power is managing their own slice of agent accounts.
function requireAdminOrSubAdmin(string $loginPath = 'login.php'): void {
    requireLogin($loginPath);
    if (!in_array($_SESSION['role'] ?? '', ['admin', 'sub_admin'], true)) {
        http_response_code(403);
        die('Access denied: admin only.');
    }
}

function isSubAdmin(): bool {
    return ($_SESSION['role'] ?? '') === 'sub_admin';
}

function isMainAdmin(): bool {
    return ($_SESSION['role'] ?? '') === 'admin';
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

// Same pattern as hasRcPrintAccess() - a separate flag/quota since this is
// a distinct tool (Aadhaar to Ration Finder) pulled out of Tracing 2.0's
// shared credit pool into its own dedicated access+quota, same shape as
// RC Print/HP Gas Advanced.
function hasAadhaarToRationAccess(): bool {
    global $pdo;
    if (!isLoggedIn()) return false;
    static $access = null;
    if ($access !== null) return $access;
    $stmt = $pdo->prepare('SELECT aadhaar_to_ration_access FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $access = (bool) $stmt->fetchColumn();
    return $access;
}

function requireAadhaarToRationAccess(string $loginPath = 'login.php'): void {
    requireLogin($loginPath);
    if (!hasAadhaarToRationAccess()) {
        http_response_code(403);
        die('Access denied: Aadhaar to Family Members access has not been granted for this account.');
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

// Same pattern as hasIndaneGasAccess() - a separate flag/quota since this is
// a distinct integration (app.cyfuture.co.in) from Indane Gas's own
// locateme.services-backed tool.
function hasIndaneGasProAccess(): bool {
    global $pdo;
    if (!isLoggedIn()) return false;
    static $access = null;
    if ($access !== null) return $access;
    $stmt = $pdo->prepare('SELECT indane_gas_pro_access FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $access = (bool) $stmt->fetchColumn();
    return $access;
}

function requireIndaneGasProAccess(string $loginPath = 'login.php'): void {
    requireLogin($loginPath);
    if (!hasIndaneGasProAccess()) {
        http_response_code(403);
        die('Access denied: Indane Gas Pro access has not been granted for this account.');
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
        die('Access denied: Tata Sky DTH Search access has not been granted for this account.');
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

// E Commerce (ecommerce.php / api/ecommerce_search.php) - per-account flag
// (2026-10-04); existing accounts were defaulted to granted, see
// database/migrate_add_ecommerce_access.sql.
function hasEcommerceAccess(): bool {
    global $pdo;
    if (!isLoggedIn()) return false;
    static $access = null;
    if ($access !== null) return $access;
    $stmt = $pdo->prepare('SELECT ecommerce_access FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $access = (bool) $stmt->fetchColumn();
    return $access;
}

function requireEcommerceAccess(string $loginPath = 'login.php'): void {
    requireLogin($loginPath);
    if (!hasEcommerceAccess()) {
        http_response_code(403);
        die('Access denied: E Commerce access has not been granted for this account.');
    }
}

// All Gas (tracekart.in's Skip Trace "Gas Connection" service) - same
// pattern as hasAdvancedSearchAccess(), defaults to NOT granted (see
// database/migrate_add_all_gas.sql).
function hasAllGasAccess(): bool {
    global $pdo;
    if (!isLoggedIn()) return false;
    static $access = null;
    if ($access !== null) return $access;
    $stmt = $pdo->prepare('SELECT all_gas_access FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $access = (bool) $stmt->fetchColumn();
    return $access;
}

function requireAllGasAccess(string $loginPath = 'login.php'): void {
    requireLogin($loginPath);
    if (!hasAllGasAccess()) {
        http_response_code(403);
        die('Access denied: All Gas access has not been granted for this account.');
    }
}

// Indian Gas Advanced (Nexora's Indane gas connection lookup, 2026-10-04) - same
// pattern as hasAllGasAccess(), defaults to NOT granted (see
// database/migrate_add_indian_gas_api.sql).
function hasIndianGasApiAccess(): bool {
    global $pdo;
    if (!isLoggedIn()) return false;
    static $access = null;
    if ($access !== null) return $access;
    $stmt = $pdo->prepare('SELECT indian_gas_api_access FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $access = (bool) $stmt->fetchColumn();
    return $access;
}

function requireIndianGasApiAccess(string $loginPath = 'login.php'): void {
    requireLogin($loginPath);
    if (!hasIndianGasApiAccess()) {
        http_response_code(403);
        die('Access denied: Indian Gas Advanced access has not been granted for this account.');
    }
}

// HP Gas Advanced (Nexora's HP gas connection lookup, 2026-10-04) - same pattern,
// defaults to NOT granted (see database/migrate_add_hp_gas_api.sql).
function hasHpGasApiAccess(): bool {
    global $pdo;
    if (!isLoggedIn()) return false;
    static $access = null;
    if ($access !== null) return $access;
    $stmt = $pdo->prepare('SELECT hp_gas_api_access FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $access = (bool) $stmt->fetchColumn();
    return $access;
}

function requireHpGasApiAccess(string $loginPath = 'login.php'): void {
    requireLogin($loginPath);
    if (!hasHpGasApiAccess()) {
        http_response_code(403);
        die('Access denied: HP Gas Advanced access has not been granted for this account.');
    }
}

// Aadhaar to Family Advanced (Nexora's Aadhaar -> ration card + family lookup,
// 2026-10-04) - same pattern, defaults to NOT granted (see
// database/migrate_add_aadhaar_family_api.sql).
function hasAadhaarFamilyApiAccess(): bool {
    global $pdo;
    if (!isLoggedIn()) return false;
    static $access = null;
    if ($access !== null) return $access;
    $stmt = $pdo->prepare('SELECT aadhaar_family_api_access FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $access = (bool) $stmt->fetchColumn();
    return $access;
}

function requireAadhaarFamilyApiAccess(string $loginPath = 'login.php'): void {
    requireLogin($loginPath);
    if (!hasAadhaarFamilyApiAccess()) {
        http_response_code(403);
        die('Access denied: Aadhaar to Family Advanced access has not been granted for this account.');
    }
}

// Bharat Gas Advanced (Nexora's Bharat gas connection lookup, 2026-10-04) - same
// pattern, defaults to NOT granted (see database/migrate_add_bharat_gas_api.sql).
function hasBharatGasApiAccess(): bool {
    global $pdo;
    if (!isLoggedIn()) return false;
    static $access = null;
    if ($access !== null) return $access;
    $stmt = $pdo->prepare('SELECT bharat_gas_api_access FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $access = (bool) $stmt->fetchColumn();
    return $access;
}

function requireBharatGasApiAccess(string $loginPath = 'login.php'): void {
    requireLogin($loginPath);
    if (!hasBharatGasApiAccess()) {
        http_response_code(403);
        die('Access denied: Bharat Gas Advanced access has not been granted for this account.');
    }
}

// Mobile to Delivery Address (Nexora API v3 address lookup, 2026-10-04) - same
// pattern, defaults to NOT granted (see database/migrate_add_mobile_to_address.sql).
function hasMobileToAddressAccess(): bool {
    global $pdo;
    if (!isLoggedIn()) return false;
    static $access = null;
    if ($access !== null) return $access;
    $stmt = $pdo->prepare('SELECT mobile_to_address_access FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $access = (bool) $stmt->fetchColumn();
    return $access;
}

function requireMobileToAddressAccess(string $loginPath = 'login.php'): void {
    requireLogin($loginPath);
    if (!hasMobileToAddressAccess()) {
        http_response_code(403);
        die('Access denied: Mobile to Delivery Address access has not been granted for this account.');
    }
}

// Mobile to Delivery Address Advanced (Nexora mobile -> PAN prefill, 2026-10-04) - same
// pattern, defaults to NOT granted (see database/migrate_add_mobile_address_adv.sql).
function hasMobileAddressAdvAccess(): bool {
    global $pdo;
    if (!isLoggedIn()) return false;
    static $access = null;
    if ($access !== null) return $access;
    $stmt = $pdo->prepare('SELECT mobile_address_adv_access FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $access = (bool) $stmt->fetchColumn();
    return $access;
}

function requireMobileAddressAdvAccess(string $loginPath = 'login.php'): void {
    requireLogin($loginPath);
    if (!hasMobileAddressAdvAccess()) {
        http_response_code(403);
        die('Access denied: Mobile to Delivery Address Advanced access has not been granted for this account.');
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

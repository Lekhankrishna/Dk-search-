<?php
require __DIR__ . '/../includes/auth.php';
requireAdminOrSubAdmin('../login.php');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/tracing2_tools.php';

$message     = '';
$messageType = 'success';

// sub_admin can reach this page to create/manage agent accounts, but is
// capped at SUB_ADMIN_MAX_AGENTS accounts created BY that specific
// sub-admin (created_by), can only ever create/see plain 'agent' accounts
// (never admin/sub_admin - no privilege escalation via this form, and no
// visibility into anyone else's accounts), and can never act on an
// existing admin/sub_admin account. A real admin is unrestricted as before.
$actingIsSubAdmin = isSubAdmin();
const SUB_ADMIN_MAX_AGENTS = 30;

// A sub-admin can only ever delegate tool access they themselves were
// granted by a real admin (2026-10-02, per explicit instruction, found
// live: the UI was showing every tool as grantable regardless of what the
// sub-admin's own account actually had) - their own row's access flags are
// the ceiling for every agent they create or edit, enforced both here
// server-side (see $subAdminCanGrant() below) and in the form markup
// itself (an option the sub-admin can't delegate isn't rendered at all,
// not just disabled).
$SUB_ADMIN_GRANTABLE_FIELDS = [
    'lpg_search_access', 'ecommerce_access', 'tracing2_access', 'rc_print_access', 'hp_gas_access',
    'indane_gas_access', 'indane_gas_pro_access', 'tata_play_access',
    'aadhaar_to_ration_access', 'eagle_eye_access', 'pan_india_access',
    'pan_india_pro_access', 'advanced_search_access', 'all_gas_access',
    'mobile_to_address_access', 'mobile_address_adv_access',
    'indian_gas_api_access', 'hp_gas_api_access', 'aadhaar_family_api_access',
    'bharat_gas_api_access',
];
$subAdminOwnAccess = [];
$subAdminOwnTracing2Tools = null;
if ($actingIsSubAdmin) {
    $ownStmt = $pdo->prepare(
        'SELECT ' . implode(', ', $SUB_ADMIN_GRANTABLE_FIELDS) . ', tracing2_tools FROM users WHERE id = :id'
    );
    $ownStmt->execute(['id' => $_SESSION['user_id']]);
    $ownRow = $ownStmt->fetch() ?: [];
    foreach ($SUB_ADMIN_GRANTABLE_FIELDS as $field) {
        $subAdminOwnAccess[$field] = (bool) ($ownRow[$field] ?? false);
    }
    // NULL means "every tool allowed" (see hasTracing2ToolAccess()'s own
    // comment) - mirrored here so a sub-admin who was never explicitly
    // restricted can still delegate any Tracing 2.0 tool, not accidentally
    // locked out of all of them.
    $subAdminOwnTracing2Tools = $ownRow['tracing2_tools'] !== null
        ? (json_decode((string) $ownRow['tracing2_tools'], true) ?: [])
        : null;
}

// true for a real admin (unrestricted) or when the acting sub-admin's own
// account has this access field granted - the single check every
// create/edit path and every form checkbox below goes through.
function subAdminCanGrant(string $field): bool {
    global $actingIsSubAdmin, $subAdminOwnAccess;
    return !$actingIsSubAdmin || !empty($subAdminOwnAccess[$field]);
}

// Shared by both the create form and edit modal's Tracing 2.0 tool
// checklist - never trust raw POST values as tool slugs directly.
$TRACING2_SELECTABLE_SLUGS = array_keys(tracing2SelectableTools());

// Reads tracing2_tools[] from the current POST, filtered against the
// known-tool allowlist, and JSON-encodes it for storage. An admin
// unchecking every box submits no tracing2_tools[] entries at all, which
// still needs to save as an explicit '[]' (not skip the column) - see the
// NULL-vs-empty-array distinction in migrate_add_locate_me_tools.sql
// (column later renamed by migrate_rename_locate_me_to_tracing2.sql).
function tracing2ToolsFromPost(array $allowedSlugs): string {
    $submitted = $_POST['tracing2_tools'] ?? [];
    if (!is_array($submitted)) $submitted = [];
    $filtered = array_values(array_intersect($submitted, $allowedSlugs));
    return json_encode($filtered);
}

// Reads tracing2_tool_credits[slug] => value from the current POST (one
// number input per tool, see the "Tracing 2.0 — Select Tools" panel below)
// and JSON-encodes it as a slug => credits map for this one agent - see
// migrate_add_tracing2_tool_credits.sql and tracing2CreditsForUser(). Only
// known slugs are kept; blank/non-numeric/negative values are dropped
// rather than saved as 0, so that tool just falls back to the global
// default for this agent instead of becoming free.
function tracing2ToolCreditsFromPost(array $allowedSlugs): string {
    $submitted = $_POST['tracing2_tool_credits'] ?? [];
    if (!is_array($submitted)) $submitted = [];
    $filtered = [];
    foreach ($allowedSlugs as $slug) {
        if (!isset($submitted[$slug]) || $submitted[$slug] === '') continue;
        $value = (int) $submitted[$slug];
        if ($value < 0) continue;
        $filtered[$slug] = min(65535, $value);
    }
    return json_encode($filtered);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // A sub-admin can't act on an existing admin/sub_admin account through
    // ANY of the actions below (edit, the per-tool toggles, expiry, delete,
    // etc.) - checked once here, before the per-action branches, rather
    // than duplicated in each one. 'create' has no target id yet so it's
    // exempt (its own role-escalation guard is inline below); every other
    // action takes a plain 'id' POST field.
    if ($action !== 'create' && $actingIsSubAdmin) {
        $targetId = (int) ($_POST['id'] ?? 0);
        $targetRoleStmt = $pdo->prepare('SELECT role FROM users WHERE id = :id');
        $targetRoleStmt->execute(['id' => $targetId]);
        $targetRole = $targetRoleStmt->fetchColumn();
        if (in_array($targetRole, ['admin', 'sub_admin'], true)) {
            http_response_code(403);
            die('Access denied: sub-admins cannot act on admin or sub-admin accounts.');
        }
    }

    if ($action === 'create') {
        $username  = trim($_POST['username']  ?? '');
        $fullName  = trim($_POST['full_name'] ?? '');
        $mobileNo  = trim($_POST['mobile_no'] ?? '');
        $password  = $_POST['password']       ?? '';
        $submittedRole = $_POST['role'] ?? 'agent';
        $role = in_array($submittedRole, ['admin', 'sub_admin'], true) ? $submittedRole : 'agent';
        // A sub-admin can never create anything but a plain agent - checked
        // server-side, not just by hiding the role selector in the form.
        if ($actingIsSubAdmin) $role = 'agent';
        $lpgAccess = isset($_POST['lpg_search_access']) ? 1 : 0;
        $tracing2Access = isset($_POST['tracing2_access']) ? 1 : 0;
        $tracing2MonthlyLimit = min(65535, max(0, (int) ($_POST['tracing2_monthly_limit'] ?? 1000)));
        $tracing2Tools = tracing2ToolsFromPost($TRACING2_SELECTABLE_SLUGS);
        $tracing2ToolCredits = tracing2ToolCreditsFromPost($TRACING2_SELECTABLE_SLUGS);
        $rcPrintAccess = isset($_POST['rc_print_access']) ? 1 : 0;
        $rcPrintMonthlyLimit = min(65535, max(0, (int) ($_POST['rc_print_monthly_limit'] ?? 5)));
        $hpGasAccess = isset($_POST['hp_gas_access']) ? 1 : 0;
        $hpGasMonthlyLimit = min(65535, max(0, (int) ($_POST['hp_gas_monthly_limit'] ?? 5)));
        $indaneGasAccess = isset($_POST['indane_gas_access']) ? 1 : 0;
        $indaneGasMonthlyLimit = min(65535, max(0, (int) ($_POST['indane_gas_monthly_limit'] ?? 5)));
        $indaneGasProAccess = isset($_POST['indane_gas_pro_access']) ? 1 : 0;
        $tataPlayAccess = isset($_POST['tata_play_access']) ? 1 : 0;
        $tataPlayMonthlyLimit = min(65535, max(0, (int) ($_POST['tata_play_monthly_limit'] ?? 5)));
        $aadhaarToRationAccess = isset($_POST['aadhaar_to_ration_access']) ? 1 : 0;
        $aadhaarToRationMonthlyLimit = min(65535, max(0, (int) ($_POST['aadhaar_to_ration_monthly_limit'] ?? 5)));
        $eagleEyeAccess = isset($_POST['eagle_eye_access']) ? 1 : 0;
        $eagleEyeMonthlyLimit = min(65535, max(0, (int) ($_POST['eagle_eye_monthly_limit'] ?? 5)));
        $panIndiaAccess = isset($_POST['pan_india_access']) ? 1 : 0;
        $panIndiaProAccess = isset($_POST['pan_india_pro_access']) ? 1 : 0;
        $panIndiaProMonthlyLimit = min(65535, max(0, (int) ($_POST['pan_india_pro_monthly_limit'] ?? 5)));
        $advancedSearchAccess = isset($_POST['advanced_search_access']) ? 1 : 0;
        $ecommerceAccess = isset($_POST['ecommerce_access']) ? 1 : 0;
        $mobileToAddressAccess = isset($_POST['mobile_to_address_access']) ? 1 : 0;
        $mobileToAddressMonthlyLimit = min(65535, max(0, (int) ($_POST['mobile_to_address_monthly_limit'] ?? 50)));
        $mobileAddressAdvAccess = isset($_POST['mobile_address_adv_access']) ? 1 : 0;
        $mobileAddressAdvMonthlyLimit = min(65535, max(0, (int) ($_POST['mobile_address_adv_monthly_limit'] ?? 50)));
        $advancedSearchMonthlyLimit = min(65535, max(0, (int) ($_POST['advanced_search_monthly_limit'] ?? 5)));
        $allGasAccess = isset($_POST['all_gas_access']) ? 1 : 0;
        $allGasIndaneLimit = min(65535, max(0, (int) ($_POST['all_gas_indane_monthly_limit'] ?? 50)));
        $allGasBharatLimit = min(65535, max(0, (int) ($_POST['all_gas_bharat_monthly_limit'] ?? 50)));
        $allGasHpLimit     = min(65535, max(0, (int) ($_POST['all_gas_hp_monthly_limit'] ?? 50)));
        $indianGasApiAccess = isset($_POST['indian_gas_api_access']) ? 1 : 0;
        $indianGasApiMonthlyLimit = min(65535, max(0, (int) ($_POST['indian_gas_api_monthly_limit'] ?? 50)));
        $hpGasApiAccess = isset($_POST['hp_gas_api_access']) ? 1 : 0;
        $hpGasApiMonthlyLimit = min(65535, max(0, (int) ($_POST['hp_gas_api_monthly_limit'] ?? 50)));
        $aadhaarFamilyApiAccess = isset($_POST['aadhaar_family_api_access']) ? 1 : 0;
        $aadhaarFamilyApiMonthlyLimit = min(65535, max(0, (int) ($_POST['aadhaar_family_api_monthly_limit'] ?? 50)));
        $bharatGasApiAccess = isset($_POST['bharat_gas_api_access']) ? 1 : 0;
        $bharatGasApiMonthlyLimit = min(65535, max(0, (int) ($_POST['bharat_gas_api_monthly_limit'] ?? 50)));
        $maxSessions = max(1, (int) ($_POST['max_concurrent_sessions'] ?? 1));
        $allowedIps = trim((string) ($_POST['allowed_ips'] ?? ''));
        $expiresDate  = trim($_POST['expires_date'] ?? '');
        $expiresTime  = trim($_POST['expires_time'] ?? '') ?: '00:00';
        $expiresAtSql = $expiresDate !== '' ? "$expiresDate $expiresTime:00" : null;

        // A sub-admin can only grant a tool they themselves were granted -
        // forced here regardless of what was submitted (the form itself
        // doesn't even render a checkbox for a tool they lack, but this is
        // the real enforcement, not just hiding the option).
        if ($actingIsSubAdmin) {
            if (!$subAdminOwnAccess['lpg_search_access'])    $lpgAccess = 0;
            if (!$subAdminOwnAccess['tracing2_access'])      $tracing2Access = 0;
            if (!$subAdminOwnAccess['rc_print_access'])      $rcPrintAccess = 0;
            if (!$subAdminOwnAccess['hp_gas_access'])        $hpGasAccess = 0;
            if (!$subAdminOwnAccess['indane_gas_access'])    $indaneGasAccess = 0;
            if (!$subAdminOwnAccess['indane_gas_pro_access'])$indaneGasProAccess = 0;
            if (!$subAdminOwnAccess['tata_play_access'])     $tataPlayAccess = 0;
            if (!$subAdminOwnAccess['aadhaar_to_ration_access']) $aadhaarToRationAccess = 0;
            if (!$subAdminOwnAccess['eagle_eye_access'])     $eagleEyeAccess = 0;
            if (!$subAdminOwnAccess['pan_india_access'])     $panIndiaAccess = 0;
            if (!$subAdminOwnAccess['pan_india_pro_access']) $panIndiaProAccess = 0;
            if (!$subAdminOwnAccess['advanced_search_access']) $advancedSearchAccess = 0;
            if (!$subAdminOwnAccess['ecommerce_access'])     $ecommerceAccess = 0;
            if (!$subAdminOwnAccess['mobile_to_address_access']) $mobileToAddressAccess = 0;
            if (!$subAdminOwnAccess['mobile_address_adv_access']) $mobileAddressAdvAccess = 0;
            if (!$subAdminOwnAccess['all_gas_access'])       $allGasAccess = 0;
            if (!$subAdminOwnAccess['indian_gas_api_access']) $indianGasApiAccess = 0;
            if (!$subAdminOwnAccess['hp_gas_api_access']) $hpGasApiAccess = 0;
            if (!$subAdminOwnAccess['aadhaar_family_api_access']) $aadhaarFamilyApiAccess = 0;
            if (!$subAdminOwnAccess['bharat_gas_api_access']) $bharatGasApiAccess = 0;
            // Tracing 2.0's own per-tool selection is capped to the
            // intersection with what the sub-admin can themselves use -
            // $subAdminOwnTracing2Tools === null means "every tool", so no
            // extra filtering needed in that case.
            if ($subAdminOwnTracing2Tools !== null) {
                $submittedTools = json_decode($tracing2Tools, true) ?: [];
                $tracing2Tools = json_encode(array_values(array_intersect($submittedTools, $subAdminOwnTracing2Tools)));
            }
        }

        $subAdminAgentCount = 0;
        if ($actingIsSubAdmin) {
            $capStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE created_by = :me');
            $capStmt->execute(['me' => $_SESSION['user_id']]);
            $subAdminAgentCount = (int) $capStmt->fetchColumn();
        }

        if ($username === '' || $fullName === '' || strlen($password) < 6) {
            $message     = 'Username, full name, and a password of at least 6 characters are required.';
            $messageType = 'danger';
        } elseif ($actingIsSubAdmin && $subAdminAgentCount >= SUB_ADMIN_MAX_AGENTS) {
            $message     = 'You have reached your limit of ' . SUB_ADMIN_MAX_AGENTS . ' created agents. Contact the main admin to create more.';
            $messageType = 'danger';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO users (username, password_hash, full_name, mobile_no, role, created_by, lpg_search_access, tracing2_access, tracing2_monthly_limit, tracing2_tools, tracing2_tool_credits, rc_print_access, rc_print_monthly_limit, hp_gas_access, hp_gas_monthly_limit, indane_gas_access, indane_gas_monthly_limit, indane_gas_pro_access, tata_play_access, tata_play_monthly_limit, aadhaar_to_ration_access, aadhaar_to_ration_monthly_limit, eagle_eye_access, eagle_eye_monthly_limit, pan_india_access, pan_india_pro_access, pan_india_pro_monthly_limit, advanced_search_access, ecommerce_access, mobile_to_address_access, mobile_to_address_monthly_limit, mobile_address_adv_access, mobile_address_adv_monthly_limit, advanced_search_monthly_limit, all_gas_access, all_gas_indane_monthly_limit, all_gas_bharat_monthly_limit, all_gas_hp_monthly_limit, indian_gas_api_access, indian_gas_api_monthly_limit, hp_gas_api_access, hp_gas_api_monthly_limit, aadhaar_family_api_access, aadhaar_family_api_monthly_limit, bharat_gas_api_access, bharat_gas_api_monthly_limit, max_concurrent_sessions, allowed_ips, expires_at)
                 VALUES (:username, :hash, :full_name, :mobile_no, :role, :created_by, :lpg_access, :tracing2_access, :tracing2_monthly_limit, :tracing2_tools, :tracing2_tool_credits, :rc_print_access, :rc_print_monthly_limit, :hp_gas_access, :hp_gas_monthly_limit, :indane_gas_access, :indane_gas_monthly_limit, :indane_gas_pro_access, :tata_play_access, :tata_play_monthly_limit, :aadhaar_to_ration_access, :aadhaar_to_ration_monthly_limit, :eagle_eye_access, :eagle_eye_monthly_limit, :pan_india_access, :pan_india_pro_access, :pan_india_pro_monthly_limit, :advanced_search_access, :ecommerce_access, :mobile_to_address_access, :mobile_to_address_monthly_limit, :mobile_address_adv_access, :mobile_address_adv_monthly_limit, :advanced_search_monthly_limit, :all_gas_access, :all_gas_indane_monthly_limit, :all_gas_bharat_monthly_limit, :all_gas_hp_monthly_limit, :indian_gas_api_access, :indian_gas_api_monthly_limit, :hp_gas_api_access, :hp_gas_api_monthly_limit, :aadhaar_family_api_access, :aadhaar_family_api_monthly_limit, :bharat_gas_api_access, :bharat_gas_api_monthly_limit, :max_sessions, :allowed_ips, :expires_at)'
            );
            try {
                $stmt->execute([
                    'username'  => $username,
                    'hash'      => password_hash($password, PASSWORD_DEFAULT),
                    'full_name' => $fullName,
                    'mobile_no' => $mobileNo !== '' ? $mobileNo : null,
                    'role'      => $role,
                    'created_by'=> $_SESSION['user_id'],
                    'lpg_access'=> $lpgAccess,
                    'tracing2_access' => $tracing2Access,
                    'tracing2_monthly_limit' => $tracing2MonthlyLimit,
                    'tracing2_tools' => $tracing2Tools,
                    'tracing2_tool_credits' => $tracing2ToolCredits,
                    'rc_print_access' => $rcPrintAccess,
                    'rc_print_monthly_limit' => $rcPrintMonthlyLimit,
                    'hp_gas_access' => $hpGasAccess,
                    'hp_gas_monthly_limit' => $hpGasMonthlyLimit,
                    'indane_gas_access' => $indaneGasAccess,
                    'indane_gas_monthly_limit' => $indaneGasMonthlyLimit,
                    'indane_gas_pro_access' => $indaneGasProAccess,
                    'tata_play_access' => $tataPlayAccess,
                    'tata_play_monthly_limit' => $tataPlayMonthlyLimit,
                    'aadhaar_to_ration_access' => $aadhaarToRationAccess,
                    'aadhaar_to_ration_monthly_limit' => $aadhaarToRationMonthlyLimit,
                    'eagle_eye_access' => $eagleEyeAccess,
                    'eagle_eye_monthly_limit' => $eagleEyeMonthlyLimit,
                    'pan_india_access' => $panIndiaAccess,
                    'pan_india_pro_access' => $panIndiaProAccess,
                    'pan_india_pro_monthly_limit' => $panIndiaProMonthlyLimit,
                    'advanced_search_access' => $advancedSearchAccess,
                    'ecommerce_access' => $ecommerceAccess,
                    'mobile_to_address_access' => $mobileToAddressAccess,
                    'mobile_to_address_monthly_limit' => $mobileToAddressMonthlyLimit,
                    'mobile_address_adv_access' => $mobileAddressAdvAccess,
                    'mobile_address_adv_monthly_limit' => $mobileAddressAdvMonthlyLimit,
                    'advanced_search_monthly_limit' => $advancedSearchMonthlyLimit,
                    'all_gas_access' => $allGasAccess,
                    'all_gas_indane_monthly_limit' => $allGasIndaneLimit,
                    'all_gas_bharat_monthly_limit' => $allGasBharatLimit,
                    'all_gas_hp_monthly_limit' => $allGasHpLimit,
                    'indian_gas_api_access' => $indianGasApiAccess,
                    'indian_gas_api_monthly_limit' => $indianGasApiMonthlyLimit,
                    'hp_gas_api_access' => $hpGasApiAccess,
                    'hp_gas_api_monthly_limit' => $hpGasApiMonthlyLimit,
                    'aadhaar_family_api_access' => $aadhaarFamilyApiAccess,
                    'aadhaar_family_api_monthly_limit' => $aadhaarFamilyApiMonthlyLimit,
                    'bharat_gas_api_access' => $bharatGasApiAccess,
                    'bharat_gas_api_monthly_limit' => $bharatGasApiMonthlyLimit,
                    'max_sessions' => $maxSessions,
                    'allowed_ips' => $allowedIps !== '' ? $allowedIps : null,
                    'expires_at'=> $expiresAtSql,
                ]);
                $message = "Account <strong>" . htmlspecialchars($username) . "</strong> created successfully.";
            } catch (PDOException $e) {
                // The banner stays generic (real DB errors shouldn't leak to
                // agents), but the real message - which duplicate-key
                // violation, a schema mismatch, etc. - is logged here since
                // php.ini's own error_log path isn't readable outside the
                // web server's own account (found 2026-08-09 debugging a
                // "username may already exist" that turned out not to be one).
                error_log('[agents.php create] ' . $e->getMessage(), 3, __DIR__ . '/../agents_debug.log');
                $message     = 'Could not create account — username may already exist.';
                $messageType = 'danger';
            }
        }
    } elseif ($action === 'edit') {
        $id       = (int) ($_POST['id'] ?? 0);
        $username = trim($_POST['username']  ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $mobileNo = trim($_POST['mobile_no'] ?? '');
        $submittedRole = $_POST['role'] ?? 'agent';
        $role = in_array($submittedRole, ['admin', 'sub_admin'], true) ? $submittedRole : 'agent';
        // A sub-admin can never promote an account to admin/sub_admin via
        // edit either - the cross-account guard above already stops them
        // touching an EXISTING admin/sub_admin, but without this, editing
        // one of their OWN agents could still escalate that agent's role.
        if ($actingIsSubAdmin) $role = 'agent';
        $lpgAccess = isset($_POST['lpg_search_access']) ? 1 : 0;
        $tracing2Access = isset($_POST['tracing2_access']) ? 1 : 0;
        $tracing2MonthlyLimit = min(65535, max(0, (int) ($_POST['tracing2_monthly_limit'] ?? 1000)));
        $tracing2Tools = tracing2ToolsFromPost($TRACING2_SELECTABLE_SLUGS);
        $tracing2ToolCredits = tracing2ToolCreditsFromPost($TRACING2_SELECTABLE_SLUGS);
        $rcPrintAccess = isset($_POST['rc_print_access']) ? 1 : 0;
        $rcPrintMonthlyLimit = min(65535, max(0, (int) ($_POST['rc_print_monthly_limit'] ?? 5)));
        $hpGasAccess = isset($_POST['hp_gas_access']) ? 1 : 0;
        $hpGasMonthlyLimit = min(65535, max(0, (int) ($_POST['hp_gas_monthly_limit'] ?? 5)));
        $indaneGasAccess = isset($_POST['indane_gas_access']) ? 1 : 0;
        $indaneGasMonthlyLimit = min(65535, max(0, (int) ($_POST['indane_gas_monthly_limit'] ?? 5)));
        $indaneGasProAccess = isset($_POST['indane_gas_pro_access']) ? 1 : 0;
        $tataPlayAccess = isset($_POST['tata_play_access']) ? 1 : 0;
        $tataPlayMonthlyLimit = min(65535, max(0, (int) ($_POST['tata_play_monthly_limit'] ?? 5)));
        $aadhaarToRationAccess = isset($_POST['aadhaar_to_ration_access']) ? 1 : 0;
        $aadhaarToRationMonthlyLimit = min(65535, max(0, (int) ($_POST['aadhaar_to_ration_monthly_limit'] ?? 5)));
        $eagleEyeAccess = isset($_POST['eagle_eye_access']) ? 1 : 0;
        $eagleEyeMonthlyLimit = min(65535, max(0, (int) ($_POST['eagle_eye_monthly_limit'] ?? 5)));
        $panIndiaAccess = isset($_POST['pan_india_access']) ? 1 : 0;
        $panIndiaProAccess = isset($_POST['pan_india_pro_access']) ? 1 : 0;
        $panIndiaProMonthlyLimit = min(65535, max(0, (int) ($_POST['pan_india_pro_monthly_limit'] ?? 5)));
        $advancedSearchAccess = isset($_POST['advanced_search_access']) ? 1 : 0;
        $ecommerceAccess = isset($_POST['ecommerce_access']) ? 1 : 0;
        $mobileToAddressAccess = isset($_POST['mobile_to_address_access']) ? 1 : 0;
        $mobileToAddressMonthlyLimit = min(65535, max(0, (int) ($_POST['mobile_to_address_monthly_limit'] ?? 50)));
        $mobileAddressAdvAccess = isset($_POST['mobile_address_adv_access']) ? 1 : 0;
        $mobileAddressAdvMonthlyLimit = min(65535, max(0, (int) ($_POST['mobile_address_adv_monthly_limit'] ?? 50)));
        $advancedSearchMonthlyLimit = min(65535, max(0, (int) ($_POST['advanced_search_monthly_limit'] ?? 5)));
        $allGasAccess = isset($_POST['all_gas_access']) ? 1 : 0;
        $allGasIndaneLimit = min(65535, max(0, (int) ($_POST['all_gas_indane_monthly_limit'] ?? 50)));
        $allGasBharatLimit = min(65535, max(0, (int) ($_POST['all_gas_bharat_monthly_limit'] ?? 50)));
        $allGasHpLimit     = min(65535, max(0, (int) ($_POST['all_gas_hp_monthly_limit'] ?? 50)));
        $indianGasApiAccess = isset($_POST['indian_gas_api_access']) ? 1 : 0;
        $indianGasApiMonthlyLimit = min(65535, max(0, (int) ($_POST['indian_gas_api_monthly_limit'] ?? 50)));
        $hpGasApiAccess = isset($_POST['hp_gas_api_access']) ? 1 : 0;
        $hpGasApiMonthlyLimit = min(65535, max(0, (int) ($_POST['hp_gas_api_monthly_limit'] ?? 50)));
        $aadhaarFamilyApiAccess = isset($_POST['aadhaar_family_api_access']) ? 1 : 0;
        $aadhaarFamilyApiMonthlyLimit = min(65535, max(0, (int) ($_POST['aadhaar_family_api_monthly_limit'] ?? 50)));
        $bharatGasApiAccess = isset($_POST['bharat_gas_api_access']) ? 1 : 0;
        $bharatGasApiMonthlyLimit = min(65535, max(0, (int) ($_POST['bharat_gas_api_monthly_limit'] ?? 50)));
        $maxSessions = max(1, (int) ($_POST['max_concurrent_sessions'] ?? 1));
        $allowedIps = trim((string) ($_POST['allowed_ips'] ?? ''));
        $newPassword  = $_POST['new_password'] ?? '';
        $expiresDate  = trim($_POST['expires_date'] ?? '');
        $expiresTime  = trim($_POST['expires_time'] ?? '') ?: '00:00';
        $expiresAtSql = $expiresDate !== '' ? "$expiresDate $expiresTime:00" : null;

        // Same ceiling as the create path above - a sub-admin editing one
        // of their own agents can't grant (or keep granted) a tool beyond
        // their own current access either.
        if ($actingIsSubAdmin) {
            if (!$subAdminOwnAccess['lpg_search_access'])    $lpgAccess = 0;
            if (!$subAdminOwnAccess['tracing2_access'])      $tracing2Access = 0;
            if (!$subAdminOwnAccess['rc_print_access'])      $rcPrintAccess = 0;
            if (!$subAdminOwnAccess['hp_gas_access'])        $hpGasAccess = 0;
            if (!$subAdminOwnAccess['indane_gas_access'])    $indaneGasAccess = 0;
            if (!$subAdminOwnAccess['indane_gas_pro_access'])$indaneGasProAccess = 0;
            if (!$subAdminOwnAccess['tata_play_access'])     $tataPlayAccess = 0;
            if (!$subAdminOwnAccess['aadhaar_to_ration_access']) $aadhaarToRationAccess = 0;
            if (!$subAdminOwnAccess['eagle_eye_access'])     $eagleEyeAccess = 0;
            if (!$subAdminOwnAccess['pan_india_access'])     $panIndiaAccess = 0;
            if (!$subAdminOwnAccess['pan_india_pro_access']) $panIndiaProAccess = 0;
            if (!$subAdminOwnAccess['advanced_search_access']) $advancedSearchAccess = 0;
            if (!$subAdminOwnAccess['ecommerce_access'])     $ecommerceAccess = 0;
            if (!$subAdminOwnAccess['mobile_to_address_access']) $mobileToAddressAccess = 0;
            if (!$subAdminOwnAccess['mobile_address_adv_access']) $mobileAddressAdvAccess = 0;
            if (!$subAdminOwnAccess['all_gas_access'])       $allGasAccess = 0;
            if (!$subAdminOwnAccess['indian_gas_api_access']) $indianGasApiAccess = 0;
            if (!$subAdminOwnAccess['hp_gas_api_access']) $hpGasApiAccess = 0;
            if (!$subAdminOwnAccess['aadhaar_family_api_access']) $aadhaarFamilyApiAccess = 0;
            if (!$subAdminOwnAccess['bharat_gas_api_access']) $bharatGasApiAccess = 0;
            if ($subAdminOwnTracing2Tools !== null) {
                $submittedTools = json_decode($tracing2Tools, true) ?: [];
                $tracing2Tools = json_encode(array_values(array_intersect($submittedTools, $subAdminOwnTracing2Tools)));
            }
        }

        if ($username === '' || $fullName === '') {
            $message     = 'Username and full name are required.';
            $messageType = 'danger';
        } elseif ($newPassword !== '' && strlen($newPassword) < 6) {
            $message     = 'New password must be at least 6 characters (or leave it blank to keep the current one).';
            $messageType = 'danger';
        } else {
            $sql = 'UPDATE users SET username = :username, full_name = :full_name, mobile_no = :mobile_no, role = :role, lpg_search_access = :lpg_access, tracing2_access = :tracing2_access, tracing2_monthly_limit = :tracing2_monthly_limit, tracing2_tools = :tracing2_tools, tracing2_tool_credits = :tracing2_tool_credits, rc_print_access = :rc_print_access, rc_print_monthly_limit = :rc_print_monthly_limit, hp_gas_access = :hp_gas_access, hp_gas_monthly_limit = :hp_gas_monthly_limit, indane_gas_access = :indane_gas_access, indane_gas_monthly_limit = :indane_gas_monthly_limit, indane_gas_pro_access = :indane_gas_pro_access, tata_play_access = :tata_play_access, tata_play_monthly_limit = :tata_play_monthly_limit, aadhaar_to_ration_access = :aadhaar_to_ration_access, aadhaar_to_ration_monthly_limit = :aadhaar_to_ration_monthly_limit, eagle_eye_access = :eagle_eye_access, eagle_eye_monthly_limit = :eagle_eye_monthly_limit, pan_india_access = :pan_india_access, pan_india_pro_access = :pan_india_pro_access, pan_india_pro_monthly_limit = :pan_india_pro_monthly_limit, advanced_search_access = :advanced_search_access, ecommerce_access = :ecommerce_access, mobile_to_address_access = :mobile_to_address_access, mobile_to_address_monthly_limit = :mobile_to_address_monthly_limit, mobile_address_adv_access = :mobile_address_adv_access, mobile_address_adv_monthly_limit = :mobile_address_adv_monthly_limit, advanced_search_monthly_limit = :advanced_search_monthly_limit, all_gas_access = :all_gas_access, all_gas_indane_monthly_limit = :all_gas_indane_monthly_limit, all_gas_bharat_monthly_limit = :all_gas_bharat_monthly_limit, all_gas_hp_monthly_limit = :all_gas_hp_monthly_limit, indian_gas_api_access = :indian_gas_api_access, indian_gas_api_monthly_limit = :indian_gas_api_monthly_limit, hp_gas_api_access = :hp_gas_api_access, hp_gas_api_monthly_limit = :hp_gas_api_monthly_limit, aadhaar_family_api_access = :aadhaar_family_api_access, aadhaar_family_api_monthly_limit = :aadhaar_family_api_monthly_limit, bharat_gas_api_access = :bharat_gas_api_access, bharat_gas_api_monthly_limit = :bharat_gas_api_monthly_limit, max_concurrent_sessions = :max_sessions, allowed_ips = :allowed_ips, expires_at = :expires_at';
            $params = [
                'username'  => $username,
                'full_name' => $fullName,
                'mobile_no' => $mobileNo !== '' ? $mobileNo : null,
                'role'      => $role,
                'lpg_access'=> $lpgAccess,
                'tracing2_access' => $tracing2Access,
                'tracing2_monthly_limit' => $tracing2MonthlyLimit,
                'tracing2_tools' => $tracing2Tools,
                'tracing2_tool_credits' => $tracing2ToolCredits,
                'rc_print_access' => $rcPrintAccess,
                'rc_print_monthly_limit' => $rcPrintMonthlyLimit,
                'hp_gas_access' => $hpGasAccess,
                'hp_gas_monthly_limit' => $hpGasMonthlyLimit,
                'indane_gas_access' => $indaneGasAccess,
                'indane_gas_monthly_limit' => $indaneGasMonthlyLimit,
                'indane_gas_pro_access' => $indaneGasProAccess,
                'tata_play_access' => $tataPlayAccess,
                'tata_play_monthly_limit' => $tataPlayMonthlyLimit,
                'aadhaar_to_ration_access' => $aadhaarToRationAccess,
                'aadhaar_to_ration_monthly_limit' => $aadhaarToRationMonthlyLimit,
                'eagle_eye_access' => $eagleEyeAccess,
                'eagle_eye_monthly_limit' => $eagleEyeMonthlyLimit,
                'pan_india_access' => $panIndiaAccess,
                'pan_india_pro_access' => $panIndiaProAccess,
                'pan_india_pro_monthly_limit' => $panIndiaProMonthlyLimit,
                'advanced_search_access' => $advancedSearchAccess,
                    'ecommerce_access' => $ecommerceAccess,
                    'mobile_to_address_access' => $mobileToAddressAccess,
                    'mobile_to_address_monthly_limit' => $mobileToAddressMonthlyLimit,
                    'mobile_address_adv_access' => $mobileAddressAdvAccess,
                    'mobile_address_adv_monthly_limit' => $mobileAddressAdvMonthlyLimit,
                'advanced_search_monthly_limit' => $advancedSearchMonthlyLimit,
                'all_gas_access' => $allGasAccess,
                    'all_gas_indane_monthly_limit' => $allGasIndaneLimit,
                    'all_gas_bharat_monthly_limit' => $allGasBharatLimit,
                    'all_gas_hp_monthly_limit' => $allGasHpLimit,
                    'indian_gas_api_access' => $indianGasApiAccess,
                    'indian_gas_api_monthly_limit' => $indianGasApiMonthlyLimit,
                    'hp_gas_api_access' => $hpGasApiAccess,
                    'hp_gas_api_monthly_limit' => $hpGasApiMonthlyLimit,
                    'aadhaar_family_api_access' => $aadhaarFamilyApiAccess,
                    'aadhaar_family_api_monthly_limit' => $aadhaarFamilyApiMonthlyLimit,
                    'bharat_gas_api_access' => $bharatGasApiAccess,
                    'bharat_gas_api_monthly_limit' => $bharatGasApiMonthlyLimit,
                'max_sessions' => $maxSessions,
                'allowed_ips' => $allowedIps !== '' ? $allowedIps : null,
                'expires_at'=> $expiresAtSql,
                'id'        => $id,
            ];
            // Changing the password invalidates every one of that account's
            // current sessions - if the password was changed because it
            // leaked, none of the old sessions (not just one) should survive it.
            if ($newPassword !== '') {
                $sql .= ', password_hash = :hash';
                $params['hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
            }
            $sql .= ' WHERE id = :id';
            try {
                $pdo->prepare($sql)->execute($params);
                if ($newPassword !== '') {
                    $pdo->prepare('DELETE FROM user_sessions WHERE user_id = :id')->execute(['id' => $id]);
                }
                $message = "Account <strong>" . htmlspecialchars($username) . "</strong> updated successfully.";
            } catch (PDOException $e) {
                error_log('[agents.php edit] ' . $e->getMessage(), 3, __DIR__ . '/../agents_debug.log');
                $message     = 'Could not update account — username may already be taken.';
                $messageType = 'danger';
            }
        }
    } elseif ($action === 'toggle_lpg') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE users SET lpg_search_access = 1 - lpg_search_access WHERE id = :id');
        $stmt->execute(['id' => $id]);
    } elseif ($action === 'toggle_tracing2') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE users SET tracing2_access = 1 - tracing2_access WHERE id = :id');
        $stmt->execute(['id' => $id]);
    } elseif ($action === 'toggle_rc_print') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE users SET rc_print_access = 1 - rc_print_access WHERE id = :id');
        $stmt->execute(['id' => $id]);
    } elseif ($action === 'toggle_hp_gas') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE users SET hp_gas_access = 1 - hp_gas_access WHERE id = :id');
        $stmt->execute(['id' => $id]);
    } elseif ($action === 'toggle_tata_play') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE users SET tata_play_access = 1 - tata_play_access WHERE id = :id');
        $stmt->execute(['id' => $id]);
    } elseif ($action === 'toggle_eagle_eye') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE users SET eagle_eye_access = 1 - eagle_eye_access WHERE id = :id');
        $stmt->execute(['id' => $id]);
    } elseif ($action === 'toggle_pan_india') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE users SET pan_india_access = 1 - pan_india_access WHERE id = :id');
        $stmt->execute(['id' => $id]);
    } elseif ($action === 'toggle_pan_india_pro') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE users SET pan_india_pro_access = 1 - pan_india_pro_access WHERE id = :id');
        $stmt->execute(['id' => $id]);
    } elseif ($action === 'toggle_advanced_search') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE users SET advanced_search_access = 1 - advanced_search_access WHERE id = :id');
        $stmt->execute(['id' => $id]);
    } elseif ($action === 'toggle_mobile_address_adv') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE users SET mobile_address_adv_access = 1 - mobile_address_adv_access WHERE id = :id');
        $stmt->execute(['id' => $id]);
    } elseif ($action === 'toggle_mobile_to_address') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE users SET mobile_to_address_access = 1 - mobile_to_address_access WHERE id = :id');
        $stmt->execute(['id' => $id]);
    } elseif ($action === 'toggle_ecommerce') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE users SET ecommerce_access = 1 - ecommerce_access WHERE id = :id');
        $stmt->execute(['id' => $id]);
    } elseif ($action === 'toggle_all_gas') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE users SET all_gas_access = 1 - all_gas_access WHERE id = :id');
        $stmt->execute(['id' => $id]);
    } elseif ($action === 'toggle_indian_gas_api') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE users SET indian_gas_api_access = 1 - indian_gas_api_access WHERE id = :id');
        $stmt->execute(['id' => $id]);
    } elseif ($action === 'toggle_hp_gas_api') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE users SET hp_gas_api_access = 1 - hp_gas_api_access WHERE id = :id');
        $stmt->execute(['id' => $id]);
    } elseif ($action === 'toggle_aadhaar_family_api') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE users SET aadhaar_family_api_access = 1 - aadhaar_family_api_access WHERE id = :id');
        $stmt->execute(['id' => $id]);
    } elseif ($action === 'toggle_bharat_gas_api') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE users SET bharat_gas_api_access = 1 - bharat_gas_api_access WHERE id = :id');
        $stmt->execute(['id' => $id]);
    } elseif ($action === 'regenerate_lpg_key') {
        // Invalidates that agent's current bookmarklet immediately — the next
        // visit to lpg_search.php lazily generates a fresh key (see
        // lpgEnsureBookmarkletKey). Use this if a bookmarklet is ever
        // suspected to have leaked, without having to rotate the shared SDMS
        // password for every other agent.
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE users SET lpg_bookmarklet_key = NULL WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $message = 'LPG bookmarklet key reset — the agent must revisit LPG Search to get a new one.';
    } elseif ($action === 'set_expiry') {
        $id        = (int) ($_POST['id'] ?? 0);
        $expiresDate  = trim($_POST['expires_date'] ?? '');
        $expiresTime  = trim($_POST['expires_time'] ?? '') ?: '00:00';
        $expiresAtSql = $expiresDate !== '' ? "$expiresDate $expiresTime:00" : null;
        $stmt = $pdo->prepare('UPDATE users SET expires_at = :expires_at WHERE id = :id');
        $stmt->execute(['expires_at' => $expiresAtSql, 'id' => $id]);
        $message = $expiresAtSql ? 'Expiry date updated.' : 'Expiry removed.';
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id !== (int) $_SESSION['user_id']) {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM search_logs WHERE user_id = :id')->execute(['id' => $id]);
            $pdo->prepare('DELETE FROM user_sessions WHERE user_id = :id')->execute(['id' => $id]);
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
            $pdo->commit();
            $message     = 'Account deleted successfully.';
            $messageType = 'warning';
        } else {
            $message     = 'You cannot delete your own account.';
            $messageType = 'danger';
        }
    }
}

// A sub-admin only ever sees the accounts THEY created - a real admin
// still sees everyone. Every stat below, the table, and the CSV export all
// flow from this one already-scoped $users list, so nothing downstream
// needs its own per-row created_by check.
$usersSelectSql = 'SELECT id, username, full_name, mobile_no, role, created_by, is_active, lpg_search_access, lpg_bookmarklet_key, tracing2_access, tracing2_monthly_limit, tracing2_tools, tracing2_tool_credits, rc_print_access, rc_print_monthly_limit, hp_gas_access, hp_gas_monthly_limit, indane_gas_access, indane_gas_monthly_limit, indane_gas_pro_access, tata_play_access, tata_play_monthly_limit, aadhaar_to_ration_access, aadhaar_to_ration_monthly_limit, eagle_eye_access, eagle_eye_monthly_limit, pan_india_access, pan_india_pro_access, pan_india_pro_monthly_limit, advanced_search_access, ecommerce_access, mobile_to_address_access, mobile_to_address_monthly_limit, mobile_address_adv_access, mobile_address_adv_monthly_limit, advanced_search_monthly_limit, all_gas_access, all_gas_indane_monthly_limit, all_gas_bharat_monthly_limit, all_gas_hp_monthly_limit, indian_gas_api_access, indian_gas_api_monthly_limit, hp_gas_api_access, hp_gas_api_monthly_limit, aadhaar_family_api_access, aadhaar_family_api_monthly_limit, bharat_gas_api_access, bharat_gas_api_monthly_limit, max_concurrent_sessions, allowed_ips, expires_at, created_at, last_login_at FROM users';
if ($actingIsSubAdmin) {
    $usersStmt = $pdo->prepare($usersSelectSql . ' WHERE created_by = :me ORDER BY created_at DESC');
    $usersStmt->execute(['me' => $_SESSION['user_id']]);
    $users = $usersStmt->fetchAll();
} else {
    $users = $pdo->query($usersSelectSql . ' ORDER BY created_at DESC')->fetchAll();
}

// Summary stats for the admin view. "Logged In" counts users who have ever
// signed in at least once (last_login_at is set) — the users table only
// stores the MOST RECENT login timestamp per user, not a running count of
// every login event, so this is "how many accounts have been used", not a
// cumulative login-event total (that data was never recorded).
$totalUsers    = count($users);
$totalAdmins   = 0;
$totalSubAdmins = 0;
$totalAgents   = 0;
$loggedInCount = 0;
$expiredCount  = 0;
foreach ($users as $u) {
    if ($u['role'] === 'admin') $totalAdmins++;
    elseif ($u['role'] === 'sub_admin') $totalSubAdmins++;
    else $totalAgents++;
    if ($u['last_login_at'] !== null) $loggedInCount++;
    if ($u['expires_at'] !== null && strtotime($u['expires_at']) <= time()) $expiredCount++;
}
// $users IS already just this sub-admin's own accounts (see above), so the
// quota display is simply how many of those exist - no per-row match needed.
$mySubAdminAgentCount = $actingIsSubAdmin ? $totalUsers : 0;

// Per-sub-admin creation breakdown, main admin only - lets a real admin see
// how many agents each sub-admin has created. A LEFT JOIN (not counting
// $users, which is already scoped away for a sub-admin actor) so a
// sub-admin who hasn't created anyone yet still shows up here with 0, not
// silently missing.
$subAdminBreakdown = [];
if (!$actingIsSubAdmin) {
    $subAdminBreakdown = $pdo->query(
        "SELECT sa.id, sa.username, sa.full_name, sa.is_active,
                COUNT(a.id) AS agents_created
         FROM users sa
         LEFT JOIN users a ON a.created_by = sa.id
         WHERE sa.role = 'sub_admin'
         GROUP BY sa.id, sa.username, sa.full_name, sa.is_active
         ORDER BY agents_created DESC, sa.username ASC"
    )->fetchAll();
}

// Resolves each row's created_by id to a display name in the accounts
// table, without an N+1 query per row.
$creatorNames = [];
$creatorIds = array_values(array_unique(array_filter(array_column($users, 'created_by'))));
if ($creatorIds) {
    $placeholders = implode(',', array_fill(0, count($creatorIds), '?'));
    $creatorStmt = $pdo->prepare("SELECT id, username FROM users WHERE id IN ($placeholders)");
    $creatorStmt->execute($creatorIds);
    foreach ($creatorStmt->fetchAll() as $row) {
        $creatorNames[(int) $row['id']] = $row['username'];
    }
}

$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<style>
  /* Compact create-account layout (2026-08-08) - the flat inline-form flex
     row this used to be stopped fitting once RC Print/HP Gas/Advance Pan
     India each added their own access checkbox + monthly-limit number
     input on top of the original LPG Search checkbox, so everything's
     grouped into rows/a grid here instead of one long wrap. */
  .agent-create-form{padding:16px 20px;background:var(--c-surface-2);border-bottom:1px solid var(--c-border);}
  .acf-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px;}
  .acf-row:last-child{margin-bottom:0;}
  .acf-row input[type=text],.acf-row input[type=tel],.acf-row input[type=password],
  .acf-row input[type=time],.acf-row select{
    padding:8px 12px;border:1px solid var(--c-border);border-radius:var(--r-md);
    font-family:var(--font);font-size:13px;outline:none;
    background:var(--c-surface);transition:border-color var(--t),box-shadow var(--t);color:var(--c-text);
  }
  .acf-row input:focus,.acf-row select:focus{border-color:var(--c-accent);box-shadow:0 0 0 3px var(--c-accent-glow);}
  .acf-section-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;
    color:var(--c-text-soft);margin-bottom:8px;display:flex;align-items:center;gap:6px;}
  .acf-section-label i{color:var(--c-accent);font-size:12px;}
  .acf-feature-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:10px;margin-bottom:14px;}
  .acf-feature{position:relative;display:flex;align-items:center;gap:10px;padding:10px 12px 10px 14px;
    border:1px solid var(--c-border);border-radius:var(--r-md);background:var(--c-surface-2);
    font-size:12.5px;font-weight:600;color:var(--c-text);cursor:pointer;
    transition:border-color 150ms,background 150ms,box-shadow 150ms,transform 150ms;}
  .acf-feature:hover{border-color:var(--c-accent);transform:translateY(-1px);}
  .acf-feature:has(input:checked){background:var(--c-accent-light);border-color:var(--c-accent);
    box-shadow:0 2px 10px rgba(46,158,63,.2);color:var(--c-accent-hover);}
  .acf-feature input[type=checkbox]{width:16px;height:16px;flex-shrink:0;accent-color:var(--c-accent);cursor:pointer;}
  .acf-feature span{flex:1;}
  .acf-feature .acf-limit{display:flex;align-items:center;gap:4px;flex:0 0 auto;font-size:11px;font-weight:500;color:var(--c-text-soft);}
  .acf-feature:has(input:checked) .acf-limit{color:var(--c-accent-hover);}
  /* All Gas: three per-provider limits - the tile takes its own full row so
     they sit side by side without squeezing (2026-10-04). */
  .acf-feature.acf-feature--wide{grid-column:1 / -1;flex-wrap:wrap;}
  .acf-feature .acf-limit.acf-limit--multi{gap:14px;flex-wrap:wrap;}
  .acf-limit--multi .acf-limit-item{display:inline-flex;align-items:center;gap:5px;white-space:nowrap;flex:0 0 auto;}
  /* All Gas Advanced: one full-row tile holding its five tabs, each with its
     own checkbox + monthly limit (2026-10-04, per explicit instruction). */
  .acf-group{grid-column:1 / -1;border:1px solid var(--c-border);border-radius:10px;background:var(--c-surface,#fff);padding:10px 14px 12px;}
  .acf-group-title{font-weight:700;font-size:13px;margin-bottom:8px;display:flex;align-items:center;gap:6px;color:var(--c-text,#1f2937);}
  .acf-group-title i{color:var(--c-accent);}
  .acf-group-items{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:8px;}
  .acf-group-item{display:flex;align-items:center;gap:8px;padding:7px 10px;border:1px solid var(--c-border);border-radius:8px;cursor:pointer;transition:all 150ms;}
  .acf-group-item:hover{border-color:var(--c-accent);}
  .acf-group-item:has(input:checked){background:var(--c-accent-light);border-color:var(--c-accent);color:var(--c-accent-hover);font-weight:600;}
  .acf-group-item input[type=checkbox]{width:16px;height:16px;flex-shrink:0;accent-color:var(--c-accent);cursor:pointer;}
  .acf-group-item .acf-group-name{flex:1;}
  .acf-group-item .acf-limit{display:flex;align-items:center;gap:4px;font-size:11px;font-weight:500;color:var(--c-text-soft);}
  .acf-group-item .acf-limit input{width:44px;padding:3px 5px;border:1px solid var(--c-border);border-radius:6px;font-size:12px;}
  .acf-feature .acf-limit input{width:44px;padding:3px 5px;border:1px solid var(--c-border);border-radius:6px;
    background:var(--c-surface);color:var(--c-text);font-size:11.5px;text-align:center;font-weight:600;}
  .acf-inline-field{display:flex;align-items:center;gap:6px;font-size:13px;color:var(--c-text);white-space:nowrap;}
  .acf-inline-field input{width:56px;padding:8px 10px;border:1px solid var(--c-border);border-radius:var(--r-md);
    background:var(--c-surface);color:var(--c-text);font-size:13px;outline:none;}

  /* Tracing 2.0's per-tool checklist - expands below the Feature Access grid
     when its own "Tracing 2.0" checkbox is ticked (JS-toggled, see bottom of
     this page), rather than living inside that one grid cell where 22
     tools would never fit. */
  .acf-tracing2-tools{display:none;margin:-4px 0 14px;padding:12px 14px;
    border:1px dashed var(--c-accent);border-radius:var(--r-md);background:var(--c-accent-light);}
  .acf-tracing2-tools.open{display:block;}
  .acf-tracing2-tools-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;
    color:var(--c-accent-hover);margin-bottom:8px;}
  .acf-tools-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:6px 10px;}
  .acf-tool-check{display:flex;align-items:center;gap:8px;font-size:11.5px;color:var(--c-text);
    cursor:pointer;padding:5px 8px;border-radius:6px;}
  .acf-tool-check:hover{background:var(--c-surface);}
  .acf-tool-check input{width:14px;height:14px;flex-shrink:0;accent-color:var(--c-accent);cursor:pointer;}
  .acf-tool-check span{flex:1;white-space:normal;line-height:1.3;word-break:break-word;}
  /* Per-agent credit-cost input (2026-08-18, replaces the old read-only
     "100 cr" badge) - narrow number input + unit label, same shape as the
     "/mo" monthly-limit inputs elsewhere on this page (.acf-limit input). */
  .acf-tool-check .acf-tool-credit{flex:0 0 auto;display:flex;align-items:center;gap:3px;font-size:10.5px;
    color:var(--c-accent-hover);font-weight:700;white-space:nowrap;}
  .acf-tool-credit input{width:48px;padding:2px 5px;border:1px solid var(--c-border);border-radius:6px;
    background:var(--c-surface);color:var(--c-text);font-size:11px;text-align:center;font-weight:600;}

  /* Edit modal widened (2026-08-08, widened further same day) - the shared
     .modal-box max-width (420px, used by every modal in the app) left
     almost no room once Feature Access grew to 5 checkboxes + 3
     monthly-limit inputs; scoped to just this modal via its own ID rather
     than raising the shared default, which other (genuinely small) modals
     elsewhere still want. Wide enough that basic info fits one row of 4
     and Max Logins/Expiry one row of 3 on a normal desktop window.
     Feature Access itself auto-wraps (minmax(190px,1fr)) rather than a
     fixed column count - a fixed repeat(N,1fr) has to be re-tuned by hand
     every time a feature is added and, worse, doesn't wrap at all on a
     narrower/non-maximized window - it just runs the last item(s) off the
     edge instead (found 2026-08-10 with 7 features + number inputs). */
  #edit-modal-overlay .modal-box{max-width:960px;width:96vw;}
  #edit-modal-overlay .edit-basic-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:0 16px;}
  #edit-modal-overlay .edit-basic-grid .form-group{margin-bottom:14px;}
  #edit-modal-overlay .acf-feature-grid{grid-template-columns:repeat(auto-fill,minmax(190px,1fr));}
  #edit-modal-overlay .edit-settings-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:0 16px;align-items:end;}
  #edit-modal-overlay .edit-settings-grid .form-group{margin-bottom:14px;}
  @media (max-width:700px){
    #edit-modal-overlay .edit-basic-grid{grid-template-columns:1fr 1fr;}
    #edit-modal-overlay .edit-settings-grid{grid-template-columns:1fr 1fr;}
  }
  @media (max-width:560px){
    #edit-modal-overlay .edit-basic-grid{grid-template-columns:1fr;}
    #edit-modal-overlay .edit-settings-grid{grid-template-columns:1fr;}
  }
</style>

<div class="page-header" style="display:flex;align-items:center;flex-wrap:wrap;gap:12px">
  <div>
    <h1 class="page-title"><i class="bi bi-people-fill"></i> Manage Agents &amp; Admins</h1>
    <p class="page-subtitle">Create, enable/disable, or remove DK Search accounts.</p>
  </div>
  <?php if ($actingIsSubAdmin): ?>
    <span class="badge <?= $mySubAdminAgentCount >= SUB_ADMIN_MAX_AGENTS ? 'badge-danger' : 'badge-neutral' ?>"
          style="margin-left:auto" title="Agents you've created, out of your limit">
      <?= $mySubAdminAgentCount ?>/<?= SUB_ADMIN_MAX_AGENTS ?> agents created
    </span>
  <?php endif; ?>
</div>

<div class="sp-stats mb-4">
  <div class="sp-stat sp-stat--accent">
    <span><?= $totalUsers ?></span>
    <label>Total Users</label>
  </div>
  <div class="sp-stat">
    <span><?= $totalAdmins ?></span>
    <label>Admins</label>
  </div>
  <?php if (!$actingIsSubAdmin): ?>
  <div class="sp-stat">
    <span><?= $totalSubAdmins ?></span>
    <label>Sub Admins</label>
  </div>
  <?php endif; ?>
  <div class="sp-stat">
    <span><?= $totalAgents ?></span>
    <label>Agents</label>
  </div>
  <div class="sp-stat" title="Accounts that have signed in at least once">
    <span><?= $loggedInCount ?></span>
    <label>Logged In</label>
  </div>
  <div class="sp-stat">
    <span><?= $expiredCount ?></span>
    <label>Expired</label>
  </div>
</div>

<?php if (!$actingIsSubAdmin && $subAdminBreakdown): ?>
<div class="card mb-4">
  <div class="card-header">
    <i class="bi bi-diagram-3-fill" style="color:var(--c-accent)"></i>
    <span class="card-title">Sub-Admin Agent Creation</span>
  </div>
  <div class="card-body p-0">
    <table class="results-table">
      <thead>
        <tr>
          <th>Sub Admin</th>
          <th>Status</th>
          <th>Agents Created</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($subAdminBreakdown as $sa): ?>
          <tr>
            <td><strong><?= htmlspecialchars($sa['username']) ?></strong> <span class="text-sm text-muted"><?= htmlspecialchars($sa['full_name']) ?></span></td>
            <td><span class="badge <?= $sa['is_active'] ? 'badge-success' : 'badge-neutral' ?>"><?= $sa['is_active'] ? 'Active' : 'Disabled' ?></span></td>
            <td>
              <span class="badge <?= (int) $sa['agents_created'] >= SUB_ADMIN_MAX_AGENTS ? 'badge-danger' : 'badge-neutral' ?>">
                <?= (int) $sa['agents_created'] ?>/<?= SUB_ADMIN_MAX_AGENTS ?>
              </span>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($message): ?>
  <div class="notice notice-<?= htmlspecialchars($messageType) ?>">
    <i class="bi bi-<?= $messageType === 'danger' ? 'exclamation-triangle-fill' : ($messageType === 'warning' ? 'exclamation-circle-fill' : 'check-circle-fill') ?>"></i>
    <?= $message ?>
  </div>
<?php endif; ?>

<!-- Create account form -->
<div class="card mb-4">
  <div class="card-header">
    <i class="bi bi-person-plus-fill" style="color:var(--c-accent)"></i>
    <span class="card-title">Create New Account</span>
  </div>
  <form method="post" class="agent-create-form">
    <input type="hidden" name="action" value="create">

    <div class="acf-row">
      <input type="text"     name="username"   placeholder="Username"          required style="min-width:130px">
      <input type="text"     name="full_name"  placeholder="Full Name"         required style="min-width:160px">
      <input type="tel"      name="mobile_no"  placeholder="Mobile Number (optional)" style="min-width:170px">
      <div class="password-field-wrap" style="min-width:160px">
        <input type="password" name="password" id="create-password" placeholder="Password (min 6)" required minlength="6" style="width:100%">
        <button type="button" class="password-toggle-btn" id="create-password-toggle-btn" aria-label="Show password">
          <i class="bi bi-eye-fill" id="create-password-toggle-icon"></i>
        </button>
      </div>
      <?php if ($actingIsSubAdmin): ?>
        <!-- A sub-admin can only ever create plain agents - no role picker
             shown at all (server-side already forces role='agent'
             regardless of what's submitted, so this is just not misleading
             the UI into implying a choice that doesn't exist). -->
        <input type="hidden" name="role" value="agent">
      <?php else: ?>
        <select name="role" style="min-width:110px">
          <option value="agent">Agent</option>
          <option value="sub_admin">Sub Admin</option>
          <option value="admin">Admin</option>
        </select>
      <?php endif; ?>
    </div>

    <div class="acf-section-label"><i class="bi bi-shield-lock-fill"></i> Feature Access</div>
    <div class="acf-feature-grid">
      <?php if (subAdminCanGrant('ecommerce_access')): ?>
      <label class="acf-feature">
        <input type="checkbox" name="ecommerce_access" value="1">
        <span>E Commerce</span>
      </label>
      <?php endif; ?>
      <?php if (subAdminCanGrant('lpg_search_access')): ?>
      <?php /* LPG Search and Tracing 2.0 hidden from Admin > Agents per
         explicit instruction (2026-10-04) - kept in the form (not removed)
         so saving an agent never silently changes their existing access. */ ?>
      <label class="acf-feature" style="display:none">
        <input type="checkbox" name="lpg_search_access" value="1">
        <span>LPG Search</span>
      </label>
      <?php endif; ?>
      <?php if (subAdminCanGrant('tracing2_access')): ?>
      <label class="acf-feature" style="display:none">
        <input type="checkbox" name="tracing2_access" id="create-tracing2_access" value="1">
        <span>Tracing 2.0</span>
        <span class="acf-limit" title="Total locateme.services credits this agent can spend per calendar month, across whichever tools are checked below - a cheap 1-credit search and an expensive 150-credit search count differently against this budget, not 1-for-1. Ignored for admins.">
          <input type="number" name="tracing2_monthly_limit" value="1000" min="0" max="65535" onclick="event.stopPropagation()">cr/mo
        </span>
      </label>
      <?php endif; ?>
      <?php if (subAdminCanGrant('pan_india_access')): ?>
      <label class="acf-feature">
        <input type="checkbox" name="pan_india_access" value="1">
        <span>Pan India</span>
      </label>
      <?php endif; ?>
      <?php if (subAdminCanGrant('rc_print_access')): ?>
      <label class="acf-feature">
        <input type="checkbox" name="rc_print_access" value="1">
        <span>RC Print</span>
        <span class="acf-limit" title="How many RC Print searches this agent can run per calendar month - each one spends real credits on the shared locateme.services account. Ignored for admins.">
          <input type="number" name="rc_print_monthly_limit" value="5" min="0" max="65535" onclick="event.stopPropagation()">/mo
        </span>
      </label>
      <?php endif; ?>
      <?php if (subAdminCanGrant('tata_play_access')): ?>
      <label class="acf-feature">
        <input type="checkbox" name="tata_play_access" value="1">
        <span>TATA SKY DTH</span>
      </label>
      <?php endif; ?>
      <?php if (subAdminCanGrant('aadhaar_to_ration_access')): ?>
      <label class="acf-feature">
        <input type="checkbox" name="aadhaar_to_ration_access" value="1">
        <span>Aadhaar to Family Members</span>
        <span class="acf-limit" title="How many Aadhaar to Family Members searches this agent can run per calendar month - each one spends real credits on the shared locateme.services account. Ignored for admins.">
          <input type="number" name="aadhaar_to_ration_monthly_limit" value="5" min="0" max="65535" onclick="event.stopPropagation()">/mo
        </span>
      </label>
      <?php endif; ?>
      <?php if (subAdminCanGrant('aadhaar_family_api_access')): ?>
      <label class="acf-feature">
        <input type="checkbox" name="aadhaar_family_api_access" value="1">
        <span>Aadhaar to Family Advanced</span>
        <span class="acf-limit" title="Searches per calendar month - every search counts, found or not. Ignored for admins.">
          <input type="number" name="aadhaar_family_api_monthly_limit" value="50" min="0" max="65535" onclick="event.stopPropagation()">/mo
        </span>
      </label>
      <?php endif; ?>
      <?php if (subAdminCanGrant('eagle_eye_access')): ?>
      <label class="acf-feature">
        <input type="checkbox" name="eagle_eye_access" value="1">
        <span>Advance Pan India</span>
      </label>
      <?php endif; ?>
      <?php if (subAdminCanGrant('pan_india_pro_access')): ?>
      <label class="acf-feature">
        <input type="checkbox" name="pan_india_pro_access" value="1">
        <span>Night Out</span>
      </label>
      <?php endif; ?>
      <?php if (subAdminCanGrant('advanced_search_access')): ?>
      <label class="acf-feature">
        <input type="checkbox" name="advanced_search_access" value="1">
        <span>Advanced Search</span>
      </label>
      <?php endif; ?>
      <?php if (subAdminCanGrant('mobile_to_address_access')): ?>
      <label class="acf-feature">
        <input type="checkbox" name="mobile_to_address_access" value="1">
        <span>Mobile to Delivery Address</span>
        <span class="acf-limit" title="Found searches per calendar month - each one spends API credits. Ignored for admins.">
          <input type="number" name="mobile_to_address_monthly_limit" value="50" min="0" max="65535" onclick="event.stopPropagation()">/mo
        </span>
      </label>
      <?php endif; ?>
      <?php if (subAdminCanGrant('mobile_address_adv_access')): ?>
      <label class="acf-feature">
        <input type="checkbox" name="mobile_address_adv_access" value="1">
        <span>Mobile to Delivery Address Advanced</span>
        <span class="acf-limit" title="Found searches per calendar month - each one spends API credits. Ignored for admins.">
          <input type="number" name="mobile_address_adv_monthly_limit" value="50" min="0" max="65535" onclick="event.stopPropagation()">/mo
        </span>
      </label>
      <?php endif; ?>
      <?php if (subAdminCanGrant('all_gas_access')): ?>
      <label class="acf-feature acf-feature--wide">
        <input type="checkbox" name="all_gas_access" value="1">
        <span>All Gas</span>
        <span class="acf-limit acf-limit--multi" style="display:none" title="Found searches per calendar month on All Gas, per provider. Ignored for admins.">
          <span class="acf-limit-item">Indane <input type="number" name="all_gas_indane_monthly_limit" value="50" min="0" max="65535" onclick="event.stopPropagation()"></span>
          <span class="acf-limit-item">Bharat <input type="number" name="all_gas_bharat_monthly_limit" value="50" min="0" max="65535" onclick="event.stopPropagation()"></span>
          <span class="acf-limit-item">HP <input type="number" name="all_gas_hp_monthly_limit" value="50" min="0" max="65535" onclick="event.stopPropagation()">/mo</span>
        </span>
      </label>
      <?php endif; ?>
      <?php if (subAdminCanGrant('indane_gas_pro_access')): ?>
      <label class="acf-feature">
        <input type="checkbox" name="indane_gas_pro_access" value="1">
        <span>Indane Gas Pro</span>
      </label>
      <?php endif; ?>
      <div class="acf-group">
        <div class="acf-group-title"><i class="bi bi-fire"></i> All Gas Advanced</div>
        <div class="acf-group-items">
          <label class="acf-group-item"<?= subAdminCanGrant('indane_gas_access') ? '' : ' style="display:none"' ?>><input type="checkbox" name="indane_gas_access" value="1"><span class="acf-group-name">Indian Gas</span><span class="acf-limit" title="How many Indane Gas searches this agent can run per calendar month - each one spends real credits (100/search) on the shared locateme.services account. Ignored for admins."> <input type="number" name="indane_gas_monthly_limit" value="5" min="0" max="65535" onclick="event.stopPropagation()">/mo </span></label>
          <label class="acf-group-item"<?= subAdminCanGrant('indian_gas_api_access') ? '' : ' style="display:none"' ?>><input type="checkbox" name="indian_gas_api_access" value="1"><span class="acf-group-name">Indian Gas Advanced</span><span class="acf-limit" title="Found searches per calendar month - each one spends API credits. Ignored for admins."> <input type="number" name="indian_gas_api_monthly_limit" value="50" min="0" max="65535" onclick="event.stopPropagation()">/mo </span></label>
          <label class="acf-group-item"<?= subAdminCanGrant('hp_gas_access') ? '' : ' style="display:none"' ?>><input type="checkbox" name="hp_gas_access" value="1"><span class="acf-group-name">HP Gas</span><span class="acf-limit" title="How many HP LPG searches this agent can run per calendar month - each one spends real credits (150/search) on the shared locateme.services account. Ignored for admins."> <input type="number" name="hp_gas_monthly_limit" value="5" min="0" max="65535" onclick="event.stopPropagation()">/mo </span></label>
          <label class="acf-group-item"<?= subAdminCanGrant('hp_gas_api_access') ? '' : ' style="display:none"' ?>><input type="checkbox" name="hp_gas_api_access" value="1"><span class="acf-group-name">HP Gas Advanced</span><span class="acf-limit" title="Found searches per calendar month - each one spends API credits. Ignored for admins."> <input type="number" name="hp_gas_api_monthly_limit" value="50" min="0" max="65535" onclick="event.stopPropagation()">/mo </span></label>
          <label class="acf-group-item"<?= subAdminCanGrant('bharat_gas_api_access') ? '' : ' style="display:none"' ?>><input type="checkbox" name="bharat_gas_api_access" value="1"><span class="acf-group-name">Bharat Gas Advanced</span><span class="acf-limit" title="Found searches per calendar month - each one spends API credits. Ignored for admins."> <input type="number" name="bharat_gas_api_monthly_limit" value="50" min="0" max="65535" onclick="event.stopPropagation()">/mo </span></label>
        </div>
      </div>
    </div>

    <?php if (subAdminCanGrant('tracing2_access')): ?>
    <div class="acf-tracing2-tools" id="create-tracing2-tools-panel" style="display:none">
      <div class="acf-tracing2-tools-label"><i class="bi bi-geo-alt-fill"></i> Tracing 2.0 — Select Tools</div>
      <div class="acf-tools-grid">
        <?php foreach (tracing2SelectableTools() as $slug => $t): ?>
          <?php if ($actingIsSubAdmin && $subAdminOwnTracing2Tools !== null && !in_array($slug, $subAdminOwnTracing2Tools, true)) continue; ?>
          <label class="acf-tool-check">
            <input type="checkbox" name="tracing2_tools[]" value="<?= htmlspecialchars($slug) ?>" checked>
            <span title="<?= htmlspecialchars($t['label']) ?>"><?= htmlspecialchars($t['label']) ?></span>
            <span class="acf-tool-credit">
              <input type="number" name="tracing2_tool_credits[<?= htmlspecialchars($slug) ?>]"
                     value="<?= (int) tracing2CreditsFor($slug) ?>" min="0" max="65535" onclick="event.stopPropagation()"> cr
            </span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="acf-row">
      <input type="text" name="allowed_ips" placeholder="Allowed IPs (comma-separated, blank = any)"
             title="Restrict this account to signing in only from these exact IP addresses - comma or newline separated, no CIDR ranges. Leave blank to allow any network." style="min-width:260px">
      <label class="acf-inline-field"
             title="How many devices can be signed into this account at the same time. Logging in beyond this limit signs out whichever device has been idle longest.">
        Max Logins
        <input type="number" name="max_concurrent_sessions" value="1" min="1" max="50">
      </label>
      <input type="text" name="expires_date" placeholder="DD/MM/YYYY" pattern="\d{2}/\d{2}/\d{4}" maxlength="10"
             title="Expiry date, DD/MM/YYYY (leave blank for no expiry)" style="min-width:140px">
      <input type="time" name="expires_time" title="Expiry time (defaults to 00:00)" style="min-width:110px">
      <button type="submit" class="btn btn-primary btn-sm">
        <i class="bi bi-person-plus"></i> Create Account
      </button>
    </div>
  </form>
</div>

<!-- Users table -->
<div class="card">
  <div class="card-header">
    <i class="bi bi-list-ul" style="color:var(--c-accent)"></i>
    <span class="card-title">All Accounts</span>
    <span class="badge badge-neutral" id="accounts-count"><?= count($users) ?> total</span>
    <div style="margin-left:auto;display:flex;align-items:center;gap:8px">
      <button type="button" class="btn btn-sm btn-success" id="export-accounts-btn">
        <i class="bi bi-file-earmark-excel"></i> Download Excel
      </button>
      <select id="account-role-filter" class="dt-select">
        <option value="">All Roles</option>
        <option value="admin">Admin</option>
        <option value="sub_admin">Sub Admin</option>
        <option value="agent">Agent</option>
      </select>
      <div class="dt-search-box">
        <i class="bi bi-search"></i>
        <input type="text" id="account-search-input" placeholder="Search username, name, or mobile…">
      </div>
    </div>
  </div>
  <div class="card-body p-0">
    <table class="results-table agents-table" id="accounts-table">
      <thead>
        <tr>
          <th style="display:none">ID</th><?php /* hidden per explicit instruction (2026-10-05) */ ?>
          <th>Username</th>
          <th>Mobile Number</th>
          <th>Role</th>
          <th style="display:none">Created By</th><?php /* hidden per explicit instruction (2026-10-05) */ ?>
          <th>Status</th>
          <th>E Commerce</th>
          <?php /* LPG / Tracing 2.0 columns hidden per explicit instruction (2026-10-05), not removed. */ ?>
          <th style="display:none">LPG</th>
          <th style="display:none">Tracing 2.0</th>
          <th>Pan India</th>
          <th>RC Print</th>
          <?php /* HP Gas / Indane Gas columns hidden per explicit instruction (2026-10-05) - both are set in the All Gas Advanced tile. */ ?>
          <th style="display:none">HP Gas</th>
          <th style="display:none">Indane Gas</th>
          <th>Indane Gas Pro</th>
          <th>TATA SKY DTH</th>
          <th>Aadhaar to Family Members</th>
          <th>Aadhaar to Family Advanced</th>
          <th>Adv. Pan India</th>
          <th>Night Out</th>
          <th>Advanced Search</th>
          <th>Mobile to Delivery Address</th>
          <th>Mobile to Delivery Address Advanced</th>
<th>All Gas</th>
          <th>Indian Gas Advanced</th>
          <th>HP Gas Advanced</th>
          <th>Bharat Gas Advanced</th>
          <th>Set Expiry</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u):
        $isExpired   = $u['expires_at'] !== null && strtotime($u['expires_at']) <= time();
        $expiryDateValue = $u['expires_at'] ? date('d/m/Y', strtotime($u['expires_at'])) : '';
        $expiryTimeValue = $u['expires_at'] ? date('H:i', strtotime($u['expires_at'])) : '';
        $isSelf      = (int) $u['id'] === (int) $_SESSION['user_id'];

        if ($isExpired) { $statusClass = 'badge-danger';   $statusLabel = 'Expired'; }
        elseif ($u['is_active']) { $statusClass = 'badge-success'; $statusLabel = 'Active'; }
        else                     { $statusClass = 'badge-neutral'; $statusLabel = 'Disabled'; }
      ?>
        <tr data-role="<?= htmlspecialchars($u['role']) ?>"
            data-search="<?= htmlspecialchars(strtolower($u['username'] . ' ' . $u['full_name'] . ' ' . ($u['mobile_no'] ?? ''))) ?>">
          <td class="text-sm text-muted" style="display:none">#<?= (int) $u['id'] ?></td>
          <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
          <td class="text-sm text-muted"><?= htmlspecialchars($u['mobile_no'] ?? '') ?: '<span class="na">—</span>' ?></td>
          <td>
            <span class="badge <?= $u['role'] === 'admin' ? 'badge-warning' : ($u['role'] === 'sub_admin' ? 'badge-primary' : 'badge-info') ?>">
              <?= $u['role'] === 'sub_admin' ? 'Sub Admin' : ucfirst($u['role']) ?>
            </span>
          </td>
          <td class="text-sm text-muted" style="display:none">
            <?= $u['created_by'] !== null && isset($creatorNames[(int) $u['created_by']])
                ? htmlspecialchars($creatorNames[(int) $u['created_by']])
                : '<span class="na">—</span>' ?>
          </td>
          <td><span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span></td>
          <td>
            <span class="badge <?= $u['ecommerce_access'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $u['ecommerce_access'] ? 'Granted' : 'Not Granted' ?>
            </span>
          </td>
          <td style="display:none">
            <span class="badge <?= $u['lpg_search_access'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $u['lpg_search_access'] ? 'Granted' : 'Not Granted' ?>
            </span>
          </td>
          <td style="display:none">
            <span class="badge <?= $u['tracing2_access'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $u['tracing2_access'] ? 'Granted' : 'Not Granted' ?>
            </span>
            <?php if ($u['tracing2_access']):
              $uToolCount = $u['tracing2_tools'] !== null ? count(json_decode($u['tracing2_tools'], true) ?: []) : count($TRACING2_SELECTABLE_SLUGS);
            ?>
              <div class="text-sm text-muted" style="margin-top:2px">
                <?= $uToolCount ?>/<?= count($TRACING2_SELECTABLE_SLUGS) ?> tools<?= $u['role'] !== 'admin' ? ', ' . (int) $u['tracing2_monthly_limit'] . ' cr/mo' : '' ?>
              </div>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $u['pan_india_access'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $u['pan_india_access'] ? 'Granted' : 'Not Granted' ?>
            </span>
          </td>
          <td>
            <span class="badge <?= $u['rc_print_access'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $u['rc_print_access'] ? 'Granted' : 'Not Granted' ?>
            </span>
            <?php if ($u['rc_print_access'] && $u['role'] !== 'admin'): ?>
              <div class="text-sm text-muted" style="margin-top:2px"><?= (int) $u['rc_print_monthly_limit'] ?>/month</div>
            <?php endif; ?>
          </td>
          <td style="display:none">
            <span class="badge <?= $u['hp_gas_access'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $u['hp_gas_access'] ? 'Granted' : 'Not Granted' ?>
            </span>
            <?php if ($u['hp_gas_access'] && $u['role'] !== 'admin'): ?>
              <div class="text-sm text-muted" style="margin-top:2px"><?= (int) $u['hp_gas_monthly_limit'] ?>/month</div>
            <?php endif; ?>
          </td>
          <td style="display:none">
            <span class="badge <?= $u['indane_gas_access'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $u['indane_gas_access'] ? 'Granted' : 'Not Granted' ?>
            </span>
            <?php if ($u['indane_gas_access'] && $u['role'] !== 'admin'): ?>
              <div class="text-sm text-muted" style="margin-top:2px"><?= (int) $u['indane_gas_monthly_limit'] ?>/month</div>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $u['indane_gas_pro_access'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $u['indane_gas_pro_access'] ? 'Granted' : 'Not Granted' ?>
            </span>
          </td>
          <td>
            <span class="badge <?= $u['tata_play_access'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $u['tata_play_access'] ? 'Granted' : 'Not Granted' ?>
            </span>
          </td>
          <td>
            <span class="badge <?= $u['aadhaar_to_ration_access'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $u['aadhaar_to_ration_access'] ? 'Granted' : 'Not Granted' ?>
            </span>
            <?php if ($u['aadhaar_to_ration_access'] && $u['role'] !== 'admin'): ?>
              <div class="text-sm text-muted" style="margin-top:2px"><?= (int) $u['aadhaar_to_ration_monthly_limit'] ?>/month</div>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $u['aadhaar_family_api_access'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $u['aadhaar_family_api_access'] ? 'Granted' : 'Not Granted' ?>
            </span>
            <?php if ($u['aadhaar_family_api_access'] && $u['role'] !== 'admin'): ?>
              <div class="text-sm text-muted" style="margin-top:2px"><?= (int) $u['aadhaar_family_api_monthly_limit'] ?>/month</div>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $u['eagle_eye_access'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $u['eagle_eye_access'] ? 'Granted' : 'Not Granted' ?>
            </span>
          </td>
          <td>
            <span class="badge <?= $u['pan_india_pro_access'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $u['pan_india_pro_access'] ? 'Granted' : 'Not Granted' ?>
            </span>
          </td>
          <td>
            <span class="badge <?= $u['advanced_search_access'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $u['advanced_search_access'] ? 'Granted' : 'Not Granted' ?>
            </span>
          </td>
          <td>
            <span class="badge <?= $u['mobile_to_address_access'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $u['mobile_to_address_access'] ? 'Granted' : 'Not Granted' ?>
            </span>
            <?php if ($u['mobile_to_address_access'] && $u['role'] !== 'admin'): ?>
              <div class="text-sm text-muted" style="margin-top:2px"><?= (int) $u['mobile_to_address_monthly_limit'] ?>/month</div>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $u['mobile_address_adv_access'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $u['mobile_address_adv_access'] ? 'Granted' : 'Not Granted' ?>
            </span>
            <?php if ($u['mobile_address_adv_access'] && $u['role'] !== 'admin'): ?>
              <div class="text-sm text-muted" style="margin-top:2px"><?= (int) $u['mobile_address_adv_monthly_limit'] ?>/month</div>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $u['all_gas_access'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $u['all_gas_access'] ? 'Granted' : 'Not Granted' ?>
            </span>
            <?php if ($u['all_gas_access'] && $u['role'] !== 'admin'): ?>
              <div class="text-sm text-muted" style="margin-top:2px">Indane <?= (int) $u['all_gas_indane_monthly_limit'] ?> &middot; Bharat <?= (int) $u['all_gas_bharat_monthly_limit'] ?> &middot; HP <?= (int) $u['all_gas_hp_monthly_limit'] ?> /month</div>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $u['indian_gas_api_access'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $u['indian_gas_api_access'] ? 'Granted' : 'Not Granted' ?>
            </span>
            <?php if ($u['indian_gas_api_access'] && $u['role'] !== 'admin'): ?>
              <div class="text-sm text-muted" style="margin-top:2px"><?= (int) $u['indian_gas_api_monthly_limit'] ?>/month</div>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $u['hp_gas_api_access'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $u['hp_gas_api_access'] ? 'Granted' : 'Not Granted' ?>
            </span>
            <?php if ($u['hp_gas_api_access'] && $u['role'] !== 'admin'): ?>
              <div class="text-sm text-muted" style="margin-top:2px"><?= (int) $u['hp_gas_api_monthly_limit'] ?>/month</div>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $u['bharat_gas_api_access'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $u['bharat_gas_api_access'] ? 'Granted' : 'Not Granted' ?>
            </span>
            <?php if ($u['bharat_gas_api_access'] && $u['role'] !== 'admin'): ?>
              <div class="text-sm text-muted" style="margin-top:2px"><?= (int) $u['bharat_gas_api_monthly_limit'] ?>/month</div>
            <?php endif; ?>
          </td>
          <td>
            <form method="post" class="expiry-form">
              <input type="hidden" name="action" value="set_expiry">
              <input type="hidden" name="id"     value="<?= (int) $u['id'] ?>">
              <input type="text" name="expires_date" value="<?= htmlspecialchars($expiryDateValue) ?>"
                     placeholder="DD/MM/YYYY" pattern="\d{2}/\d{2}/\d{4}" maxlength="10"
                     title="<?= $u['expires_at'] ? 'Current: '.$u['expires_at'] : 'No expiry set' ?>">
              <input type="time" name="expires_time" value="<?= htmlspecialchars($expiryTimeValue) ?>"
                     title="<?= $u['expires_at'] ? 'Current: '.$u['expires_at'] : 'No expiry set' ?>">
              <button type="submit" class="btn btn-sm btn-secondary">
                <i class="bi bi-clock"></i> Save
              </button>
            </form>
            <?php if ($u['expires_at']): ?>
              <div class="text-sm" style="margin-top:4px;color:<?= $isExpired ? 'var(--c-danger)' : 'var(--c-text-muted)' ?>">
                <i class="bi bi-<?= $isExpired ? 'exclamation-circle' : 'calendar-check' ?>"></i>
                <?= htmlspecialchars($u['expires_at']) ?>
              </div>
            <?php endif; ?>
          </td>
          <td class="action-cell">
            <button type="button" class="btn btn-sm btn-secondary"
                    onclick="openEditModal(<?= (int) $u['id'] ?>, <?= htmlspecialchars(json_encode($u['username']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($u['full_name']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($u['mobile_no'] ?? ''), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($u['role']), ENT_QUOTES) ?>, <?= (int) $u['lpg_search_access'] ?>, <?= (int) $u['tracing2_access'] ?>, <?= (int) $u['tracing2_monthly_limit'] ?>, <?= htmlspecialchars(json_encode($u['tracing2_tools'] !== null ? (json_decode($u['tracing2_tools'], true) ?: []) : null), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($u['tracing2_tool_credits'] !== null ? (json_decode($u['tracing2_tool_credits'], true) ?: []) : null), ENT_QUOTES) ?>, <?= (int) $u['rc_print_access'] ?>, <?= (int) $u['rc_print_monthly_limit'] ?>, <?= (int) $u['hp_gas_access'] ?>, <?= (int) $u['hp_gas_monthly_limit'] ?>, <?= (int) $u['indane_gas_access'] ?>, <?= (int) $u['indane_gas_monthly_limit'] ?>, <?= (int) $u['indane_gas_pro_access'] ?>, <?= (int) $u['tata_play_access'] ?>, <?= (int) $u['tata_play_monthly_limit'] ?>, <?= (int) $u['aadhaar_to_ration_access'] ?>, <?= (int) $u['aadhaar_to_ration_monthly_limit'] ?>, <?= (int) $u['eagle_eye_access'] ?>, <?= (int) $u['eagle_eye_monthly_limit'] ?>, <?= (int) $u['pan_india_access'] ?>, <?= (int) $u['pan_india_pro_access'] ?>, <?= (int) $u['pan_india_pro_monthly_limit'] ?>, <?= (int) $u['advanced_search_access'] ?>, <?= (int) $u['advanced_search_monthly_limit'] ?>, <?= (int) $u['max_concurrent_sessions'] ?>, <?= htmlspecialchars(json_encode($u['allowed_ips'] ?? ''), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($expiryDateValue), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($expiryTimeValue), ENT_QUOTES) ?>, <?= (int) $u['all_gas_access'] ?>, <?= (int) $u['indian_gas_api_access'] ?>, <?= (int) $u['hp_gas_api_access'] ?>, <?= (int) $u['aadhaar_family_api_access'] ?>, <?= (int) $u['bharat_gas_api_access'] ?>, <?= (int) $u['all_gas_indane_monthly_limit'] ?>, <?= (int) $u['all_gas_bharat_monthly_limit'] ?>, <?= (int) $u['all_gas_hp_monthly_limit'] ?>, <?= (int) $u['indian_gas_api_monthly_limit'] ?>, <?= (int) $u['hp_gas_api_monthly_limit'] ?>, <?= (int) $u['bharat_gas_api_monthly_limit'] ?>, <?= (int) $u['mobile_to_address_access'] ?>, <?= (int) $u['mobile_to_address_monthly_limit'] ?>, <?= (int) $u['mobile_address_adv_access'] ?>, <?= (int) $u['mobile_address_adv_monthly_limit'] ?>, <?= (int) $u['ecommerce_access'] ?>, <?= (int) $u['aadhaar_family_api_monthly_limit'] ?>)">
              <i class="bi bi-pencil-square"></i> Edit
            </button>
            <?php if ($u['lpg_search_access'] && $u['lpg_bookmarklet_key']): ?>
              <form method="post" style="display:inline"
                    onsubmit="return confirm('Reset <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>\'s LPG bookmarklet? Their current one will stop working until they revisit LPG Search.')">
                <input type="hidden" name="action" value="regenerate_lpg_key">
                <input type="hidden" name="id"     value="<?= (int) $u['id'] ?>">
                <button type="submit" class="btn btn-sm btn-secondary" title="Reset this agent's LPG bookmarklet key">
                  <i class="bi bi-arrow-repeat"></i> Reset Key
                </button>
              </form>
            <?php endif; ?>
            <?php if (!$isSelf): ?>
              <form method="post" style="display:inline"
                    onsubmit="return confirm('Delete account \'<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>\'?\nSearch history will also be deleted. This cannot be undone.')">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id"     value="<?= (int) $u['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger">
                  <i class="bi bi-trash3"></i> Delete
                </button>
              </form>
            <?php else: ?>
              <span class="badge badge-neutral"><i class="bi bi-person-check"></i> You</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Edit account modal (shared, populated via JS) -->
<div class="modal-overlay" id="edit-modal-overlay" style="display:none" onclick="if(event.target===this) closeEditModal()">
  <div class="modal-box">
    <div class="modal-header">
      <span><i class="bi bi-pencil-square"></i> Edit Account</span>
      <button type="button" class="modal-close-btn" onclick="closeEditModal()" aria-label="Close">&times;</button>
    </div>
    <form method="post" id="edit-form">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="edit-id">
      <div class="edit-basic-grid">
        <div class="form-group">
          <label class="form-label" for="edit-username">Username</label>
          <input type="text" class="form-control" name="username" id="edit-username" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="edit-full_name">Full Name</label>
          <input type="text" class="form-control" name="full_name" id="edit-full_name" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="edit-mobile_no">Mobile Number</label>
          <input type="tel" class="form-control" name="mobile_no" id="edit-mobile_no" placeholder="Optional">
        </div>
        <div class="form-group">
          <label class="form-label" for="edit-role">Role</label>
          <?php if ($actingIsSubAdmin): ?>
            <!-- A sub-admin can only ever edit their own plain-agent accounts
                 (the cross-account guard and the forced role='agent' on
                 submit already stop any escalation) - fixed display, no
                 picker implying a choice that doesn't exist. -->
            <input type="text" class="form-control" value="Agent" disabled>
            <input type="hidden" name="role" value="agent">
          <?php else: ?>
            <select class="form-control" name="role" id="edit-role">
              <option value="agent">Agent</option>
              <option value="sub_admin">Sub Admin</option>
              <option value="admin">Admin</option>
            </select>
          <?php endif; ?>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label"><i class="bi bi-shield-lock-fill"></i> Feature Access</label>
        <div class="acf-feature-grid">
          <label class="acf-feature"<?= subAdminCanGrant('ecommerce_access') ? '' : ' style="display:none"' ?>>
            <input type="checkbox" name="ecommerce_access" id="edit-ecommerce_access" value="1">
            <span>E Commerce</span>
          </label>
          <?php /* Hidden, not removed - see the create form's note above. */ ?>
          <label class="acf-feature" style="display:none">
            <input type="checkbox" name="lpg_search_access" id="edit-lpg_search_access" value="1">
            <span>LPG Search</span>
          </label>
          <label class="acf-feature" style="display:none">
            <input type="checkbox" name="tracing2_access" id="edit-tracing2_access" value="1">
            <span>Tracing 2.0</span>
            <span class="acf-limit" title="Total locateme.services credits this agent can spend per calendar month, across whichever tools are checked below - a cheap 1-credit search and an expensive 150-credit search count differently against this budget, not 1-for-1. Ignored for admins.">
              <input type="number" name="tracing2_monthly_limit" id="edit-tracing2_monthly_limit" value="1000" min="0" max="65535" onclick="event.stopPropagation()">cr/mo
            </span>
          </label>
          <label class="acf-feature"<?= subAdminCanGrant('pan_india_access') ? '' : ' style="display:none"' ?>>
            <input type="checkbox" name="pan_india_access" id="edit-pan_india_access" value="1">
            <span>Pan India</span>
          </label>
          <label class="acf-feature"<?= subAdminCanGrant('rc_print_access') ? '' : ' style="display:none"' ?>>
            <input type="checkbox" name="rc_print_access" id="edit-rc_print_access" value="1">
            <span>RC Print</span>
            <span class="acf-limit" title="How many RC Print searches this agent can run per calendar month - each one spends real credits on the shared locateme.services account. Ignored for admins.">
              <input type="number" name="rc_print_monthly_limit" id="edit-rc_print_monthly_limit" value="5" min="0" max="65535" onclick="event.stopPropagation()">/mo
            </span>
          </label>
          <label class="acf-feature"<?= subAdminCanGrant('tata_play_access') ? '' : ' style="display:none"' ?>>
            <input type="checkbox" name="tata_play_access" id="edit-tata_play_access" value="1">
            <span>TATA SKY DTH</span>
          </label>
          <label class="acf-feature"<?= subAdminCanGrant('aadhaar_to_ration_access') ? '' : ' style="display:none"' ?>>
            <input type="checkbox" name="aadhaar_to_ration_access" id="edit-aadhaar_to_ration_access" value="1">
            <span>Aadhaar to Family Members</span>
            <span class="acf-limit" title="How many Aadhaar to Family Members searches this agent can run per calendar month - each one spends real credits on the shared locateme.services account. Ignored for admins.">
              <input type="number" name="aadhaar_to_ration_monthly_limit" id="edit-aadhaar_to_ration_monthly_limit" value="5" min="0" max="65535" onclick="event.stopPropagation()">/mo
            </span>
          </label>
          <label class="acf-feature"<?= subAdminCanGrant('aadhaar_family_api_access') ? '' : ' style="display:none"' ?>>
            <input type="checkbox" name="aadhaar_family_api_access" id="edit-aadhaar_family_api_access" value="1">
            <span>Aadhaar to Family Advanced</span>
            <span class="acf-limit" title="Searches per calendar month - every search counts, found or not. Ignored for admins.">
              <input type="number" name="aadhaar_family_api_monthly_limit" id="edit-aadhaar_family_api_monthly_limit" value="50" min="0" max="65535" onclick="event.stopPropagation()">/mo
            </span>
          </label>
          <label class="acf-feature"<?= subAdminCanGrant('eagle_eye_access') ? '' : ' style="display:none"' ?>>
            <input type="checkbox" name="eagle_eye_access" id="edit-eagle_eye_access" value="1">
            <span>Advance Pan India</span>
          </label>
          <label class="acf-feature"<?= subAdminCanGrant('pan_india_pro_access') ? '' : ' style="display:none"' ?>>
            <input type="checkbox" name="pan_india_pro_access" id="edit-pan_india_pro_access" value="1">
            <span>Night Out</span>
          </label>
          <label class="acf-feature"<?= subAdminCanGrant('advanced_search_access') ? '' : ' style="display:none"' ?>>
            <input type="checkbox" name="advanced_search_access" id="edit-advanced_search_access" value="1">
            <span>Advanced Search</span>
          </label>
          <label class="acf-feature"<?= subAdminCanGrant('mobile_to_address_access') ? '' : ' style="display:none"' ?>>
            <input type="checkbox" name="mobile_to_address_access" id="edit-mobile_to_address_access" value="1">
            <span>Mobile to Delivery Address</span>
            <span class="acf-limit" title="Found searches per calendar month - each one spends API credits. Ignored for admins.">
              <input type="number" name="mobile_to_address_monthly_limit" id="edit-mobile_to_address_monthly_limit" value="50" min="0" max="65535" onclick="event.stopPropagation()">/mo
            </span>
          </label>
          <label class="acf-feature"<?= subAdminCanGrant('mobile_address_adv_access') ? '' : ' style="display:none"' ?>>
            <input type="checkbox" name="mobile_address_adv_access" id="edit-mobile_address_adv_access" value="1">
            <span>Mobile to Delivery Address Advanced</span>
            <span class="acf-limit" title="Found searches per calendar month - each one spends API credits. Ignored for admins.">
              <input type="number" name="mobile_address_adv_monthly_limit" id="edit-mobile_address_adv_monthly_limit" value="50" min="0" max="65535" onclick="event.stopPropagation()">/mo
            </span>
          </label>
          <label class="acf-feature acf-feature--wide"<?= subAdminCanGrant('all_gas_access') ? '' : ' style="display:none"' ?>>
            <input type="checkbox" name="all_gas_access" id="edit-all_gas_access" value="1">
            <span>All Gas</span>
            <span class="acf-limit acf-limit--multi" style="display:none" title="Found searches per calendar month on All Gas, per provider. Ignored for admins.">
              <span class="acf-limit-item">Indane <input type="number" name="all_gas_indane_monthly_limit" id="edit-all_gas_indane_monthly_limit" value="50" min="0" max="65535" onclick="event.stopPropagation()"></span>
              <span class="acf-limit-item">Bharat <input type="number" name="all_gas_bharat_monthly_limit" id="edit-all_gas_bharat_monthly_limit" value="50" min="0" max="65535" onclick="event.stopPropagation()"></span>
              <span class="acf-limit-item">HP <input type="number" name="all_gas_hp_monthly_limit" id="edit-all_gas_hp_monthly_limit" value="50" min="0" max="65535" onclick="event.stopPropagation()">/mo</span>
            </span>
          </label>
          <label class="acf-feature"<?= subAdminCanGrant('indane_gas_pro_access') ? '' : ' style="display:none"' ?>>
            <input type="checkbox" name="indane_gas_pro_access" id="edit-indane_gas_pro_access" value="1">
            <span>Indane Gas Pro</span>
          </label>
          <div class="acf-group">
            <div class="acf-group-title"><i class="bi bi-fire"></i> All Gas Advanced</div>
            <div class="acf-group-items">
              <label class="acf-group-item"<?= subAdminCanGrant('indane_gas_access') ? '' : ' style="display:none"' ?>><input type="checkbox" name="indane_gas_access" id="edit-indane_gas_access" value="1"><span class="acf-group-name">Indian Gas</span><span class="acf-limit" title="How many Indane Gas searches this agent can run per calendar month - each one spends real credits (100/search) on the shared locateme.services account. Ignored for admins."> <input type="number" name="indane_gas_monthly_limit" id="edit-indane_gas_monthly_limit" value="5" min="0" max="65535" onclick="event.stopPropagation()">/mo </span></label>
              <label class="acf-group-item"<?= subAdminCanGrant('indian_gas_api_access') ? '' : ' style="display:none"' ?>><input type="checkbox" name="indian_gas_api_access" id="edit-indian_gas_api_access" value="1"><span class="acf-group-name">Indian Gas Advanced</span><span class="acf-limit" title="Found searches per calendar month - each one spends API credits. Ignored for admins."> <input type="number" name="indian_gas_api_monthly_limit" id="edit-indian_gas_api_monthly_limit" value="50" min="0" max="65535" onclick="event.stopPropagation()">/mo </span></label>
              <label class="acf-group-item"<?= subAdminCanGrant('hp_gas_access') ? '' : ' style="display:none"' ?>><input type="checkbox" name="hp_gas_access" id="edit-hp_gas_access" value="1"><span class="acf-group-name">HP Gas</span><span class="acf-limit" title="How many HP LPG searches this agent can run per calendar month - each one spends real credits (150/search) on the shared locateme.services account. Ignored for admins."> <input type="number" name="hp_gas_monthly_limit" id="edit-hp_gas_monthly_limit" value="5" min="0" max="65535" onclick="event.stopPropagation()">/mo </span></label>
              <label class="acf-group-item"<?= subAdminCanGrant('hp_gas_api_access') ? '' : ' style="display:none"' ?>><input type="checkbox" name="hp_gas_api_access" id="edit-hp_gas_api_access" value="1"><span class="acf-group-name">HP Gas Advanced</span><span class="acf-limit" title="Found searches per calendar month - each one spends API credits. Ignored for admins."> <input type="number" name="hp_gas_api_monthly_limit" id="edit-hp_gas_api_monthly_limit" value="50" min="0" max="65535" onclick="event.stopPropagation()">/mo </span></label>
              <label class="acf-group-item"<?= subAdminCanGrant('bharat_gas_api_access') ? '' : ' style="display:none"' ?>><input type="checkbox" name="bharat_gas_api_access" id="edit-bharat_gas_api_access" value="1"><span class="acf-group-name">Bharat Gas Advanced</span><span class="acf-limit" title="Found searches per calendar month - each one spends API credits. Ignored for admins."> <input type="number" name="bharat_gas_api_monthly_limit" id="edit-bharat_gas_api_monthly_limit" value="50" min="0" max="65535" onclick="event.stopPropagation()">/mo </span></label>
            </div>
          </div>
        </div>
        <div class="acf-tracing2-tools" id="edit-tracing2-tools-panel" style="display:none">
          <div class="acf-tracing2-tools-label"><i class="bi bi-geo-alt-fill"></i> Tracing 2.0 — Select Tools</div>
          <div class="acf-tools-grid">
            <?php foreach (tracing2SelectableTools() as $slug => $t): ?>
              <?php $editToolHidden = $actingIsSubAdmin && $subAdminOwnTracing2Tools !== null && !in_array($slug, $subAdminOwnTracing2Tools, true); ?>
              <label class="acf-tool-check"<?= $editToolHidden ? ' style="display:none"' : '' ?>>
                <input type="checkbox" name="tracing2_tools[]" class="edit-tracing2-tool" value="<?= htmlspecialchars($slug) ?>">
                <span title="<?= htmlspecialchars($t['label']) ?>"><?= htmlspecialchars($t['label']) ?></span>
                <span class="acf-tool-credit">
                  <input type="number" name="tracing2_tool_credits[<?= htmlspecialchars($slug) ?>]" class="edit-tracing2-tool-credit"
                         data-slug="<?= htmlspecialchars($slug) ?>" data-default="<?= (int) tracing2CreditsFor($slug) ?>"
                         value="<?= (int) tracing2CreditsFor($slug) ?>" min="0" max="65535" onclick="event.stopPropagation()"> cr
                </span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label" for="edit-allowed_ips"
               title="Restrict this account to signing in only from these exact IP addresses - comma or newline separated, no CIDR ranges. Leave blank to allow any network.">
          Allowed IPs
        </label>
        <input type="text" class="form-control" name="allowed_ips" id="edit-allowed_ips" placeholder="Blank = any network">
      </div>
      <div class="edit-settings-grid">
        <div class="form-group">
          <label class="form-label" for="edit-max_concurrent_sessions"
                 title="How many devices can be signed into this account at the same time. Logging in beyond this limit signs out whichever device has been idle longest.">
            Max Simultaneous Logins
          </label>
          <input type="number" class="form-control" name="max_concurrent_sessions" id="edit-max_concurrent_sessions"
                 value="1" min="1" max="50">
        </div>
        <div class="form-group">
          <label class="form-label" for="edit-expires_date">Expiry Date</label>
          <input type="text" class="form-control" name="expires_date" id="edit-expires_date"
                 placeholder="DD/MM/YYYY" pattern="\d{2}/\d{2}/\d{4}" maxlength="10" title="DD/MM/YYYY — leave blank for no expiry">
        </div>
        <div class="form-group">
          <label class="form-label" for="edit-expires_time">Expiry Time</label>
          <input type="time" class="form-control" name="expires_time" id="edit-expires_time" title="Defaults to 00:00">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label" for="edit-new_password">New Password</label>
        <div class="password-field-wrap">
          <input type="password" class="form-control" name="new_password" id="edit-new_password"
                 minlength="6" placeholder="Leave blank to keep current password" autocomplete="new-password">
          <button type="button" class="password-toggle-btn" id="edit-password-toggle-btn" aria-label="Show password">
            <i class="bi bi-eye-fill" id="edit-password-toggle-icon"></i>
          </button>
        </div>
        <div class="text-sm text-muted" style="margin-top:4px">Leave blank to keep the current password unchanged. Setting a new one signs the account out everywhere.</div>
      </div>
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-circle"></i> Save Changes
      </button>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
function openEditModal(id, username, fullName, mobileNo, role, lpgAccess, tracing2Access, tracing2MonthlyLimit, tracing2Tools, tracing2ToolCredits, rcPrintAccess, rcPrintMonthlyLimit, hpGasAccess, hpGasMonthlyLimit, indaneGasAccess, indaneGasMonthlyLimit, indaneGasProAccess, tataPlayAccess, tataPlayMonthlyLimit, aadhaarToRationAccess, aadhaarToRationMonthlyLimit, eagleEyeAccess, eagleEyeMonthlyLimit, panIndiaAccess, panIndiaProAccess, panIndiaProMonthlyLimit, advancedSearchAccess, advancedSearchMonthlyLimit, maxSessions, allowedIps, expiresDate, expiresTime, allGasAccess, indianGasApiAccess, hpGasApiAccess, aadhaarFamilyApiAccess, bharatGasApiAccess, allGasIndaneLimit, allGasBharatLimit, allGasHpLimit, indianGasApiMonthlyLimit, hpGasApiMonthlyLimit, bharatGasApiMonthlyLimit, mobileToAddressAccess, mobileToAddressMonthlyLimit, mobileAddressAdvAccess, mobileAddressAdvMonthlyLimit, ecommerceAccess, aadhaarFamilyApiMonthlyLimit) {
  document.getElementById('edit-id').value = id;
  document.getElementById('edit-username').value = username;
  document.getElementById('edit-full_name').value = fullName;
  document.getElementById('edit-mobile_no').value = mobileNo;
  // Not present at all for a sub-admin actor (fixed "Agent" display
  // instead of a picker - see the PHP above) - guarded rather than assumed
  // present, same reasoning as every other role-conditional element here.
  const editRoleSelect = document.getElementById('edit-role');
  if (editRoleSelect) editRoleSelect.value = role;
  document.getElementById('edit-lpg_search_access').checked = !!lpgAccess;
  document.getElementById('edit-tracing2_access').checked = !!tracing2Access;
  document.getElementById('edit-tracing2_monthly_limit').value = tracing2MonthlyLimit;
  // tracing2Tools is null (never explicitly configured - see
  // migrate_add_locate_me_tools.sql, column later renamed by
  // migrate_rename_locate_me_to_tracing2.sql) or an array of allowed tool
  // slugs. null shows every box checked (matching what hasTracing2ToolAccess()
  // actually grants in that case); a real array checks only its entries.
  document.querySelectorAll('.edit-tracing2-tool').forEach(cb => {
    cb.checked = tracing2Tools === null || tracing2Tools.includes(cb.value);
  });
  // tracing2ToolCredits is null (this agent has no overrides at all) or a
  // partial slug => credits map - a slug missing from it still falls back
  // to that tool's own global default (data-default), same rule
  // tracing2CreditsForUser() applies server-side.
  document.querySelectorAll('.edit-tracing2-tool-credit').forEach(input => {
    const slug = input.dataset.slug;
    const override = tracing2ToolCredits !== null ? tracing2ToolCredits[slug] : undefined;
    input.value = (override !== undefined && override !== null) ? override : input.dataset.default;
  });
  toggleTracing2ToolsPanel(document.getElementById('edit-tracing2_access'), document.getElementById('edit-tracing2-tools-panel'));
  document.getElementById('edit-rc_print_access').checked = !!rcPrintAccess;
  document.getElementById('edit-rc_print_monthly_limit').value = rcPrintMonthlyLimit;
  document.getElementById('edit-hp_gas_access').checked = !!hpGasAccess;
  document.getElementById('edit-hp_gas_monthly_limit').value = hpGasMonthlyLimit;
  document.getElementById('edit-indane_gas_access').checked = !!indaneGasAccess;
  document.getElementById('edit-indane_gas_monthly_limit').value = indaneGasMonthlyLimit;
  document.getElementById('edit-indane_gas_pro_access').checked = !!indaneGasProAccess;
  document.getElementById('edit-tata_play_access').checked = !!tataPlayAccess;
  document.getElementById('edit-aadhaar_to_ration_access').checked = !!aadhaarToRationAccess;
  document.getElementById('edit-aadhaar_to_ration_monthly_limit').value = aadhaarToRationMonthlyLimit;
  document.getElementById('edit-eagle_eye_access').checked = !!eagleEyeAccess;
  document.getElementById('edit-pan_india_access').checked = !!panIndiaAccess;
  document.getElementById('edit-pan_india_pro_access').checked = !!panIndiaProAccess;
  document.getElementById('edit-advanced_search_access').checked = !!advancedSearchAccess;
  document.getElementById('edit-ecommerce_access').checked = !!ecommerceAccess;
  document.getElementById('edit-mobile_to_address_access').checked = !!mobileToAddressAccess;
  document.getElementById('edit-mobile_to_address_monthly_limit').value = mobileToAddressMonthlyLimit;
  document.getElementById('edit-mobile_address_adv_access').checked = !!mobileAddressAdvAccess;
  document.getElementById('edit-mobile_address_adv_monthly_limit').value = mobileAddressAdvMonthlyLimit;
  document.getElementById('edit-all_gas_access').checked = !!allGasAccess;
  document.getElementById('edit-all_gas_indane_monthly_limit').value = allGasIndaneLimit;
  document.getElementById('edit-all_gas_bharat_monthly_limit').value = allGasBharatLimit;
  document.getElementById('edit-all_gas_hp_monthly_limit').value = allGasHpLimit;
  document.getElementById('edit-indian_gas_api_access').checked = !!indianGasApiAccess;
  document.getElementById('edit-indian_gas_api_monthly_limit').value = indianGasApiMonthlyLimit;
  document.getElementById('edit-hp_gas_api_access').checked = !!hpGasApiAccess;
  document.getElementById('edit-hp_gas_api_monthly_limit').value = hpGasApiMonthlyLimit;
  document.getElementById('edit-aadhaar_family_api_access').checked = !!aadhaarFamilyApiAccess;
  document.getElementById('edit-aadhaar_family_api_monthly_limit').value = aadhaarFamilyApiMonthlyLimit;
  document.getElementById('edit-bharat_gas_api_access').checked = !!bharatGasApiAccess;
  document.getElementById('edit-bharat_gas_api_monthly_limit').value = bharatGasApiMonthlyLimit;
  document.getElementById('edit-max_concurrent_sessions').value = maxSessions;
  document.getElementById('edit-allowed_ips').value = allowedIps || '';
  document.getElementById('edit-expires_date').value = expiresDate;
  document.getElementById('edit-expires_time').value = expiresTime;
  document.getElementById('edit-new_password').value = '';
  document.getElementById('edit-modal-overlay').style.display = 'flex';
}
function closeEditModal() {
  document.getElementById('edit-modal-overlay').style.display = 'none';
}

// Tracing 2.0's per-tool checklist only makes sense once Tracing 2.0 itself is
// checked - shown/hidden in lockstep with that one checkbox, same idea as
// index.php's field-group show/hide per search mode.
function toggleTracing2ToolsPanel(checkbox, panel) {
  panel.classList.toggle('open', checkbox.checked);
}
const createTracing2Checkbox = document.getElementById('create-tracing2_access');
if (createTracing2Checkbox) {
  createTracing2Checkbox.addEventListener('change', function () {
    toggleTracing2ToolsPanel(this, document.getElementById('create-tracing2-tools-panel'));
  });
}
document.getElementById('edit-tracing2_access').addEventListener('change', function () {
  toggleTracing2ToolsPanel(this, document.getElementById('edit-tracing2-tools-panel'));
});
document.getElementById('edit-password-toggle-btn').addEventListener('click', () => {
  const input = document.getElementById('edit-new_password');
  const icon  = document.getElementById('edit-password-toggle-icon');
  const showing = input.type === 'text';
  input.type = showing ? 'password' : 'text';
  icon.className = showing ? 'bi bi-eye-fill' : 'bi bi-eye-slash-fill';
});
document.getElementById('create-password-toggle-btn').addEventListener('click', () => {
  const input = document.getElementById('create-password');
  const icon  = document.getElementById('create-password-toggle-icon');
  const showing = input.type === 'text';
  input.type = showing ? 'password' : 'text';
  icon.className = showing ? 'bi bi-eye-fill' : 'bi bi-eye-slash-fill';
});

/* Expiry date fields are plain text (DD/MM/YYYY) rather than native
   <input type="date"> — a native date input's displayed format follows the
   browser/OS locale (e.g. shows mm/dd/yyyy on an en-US browser regardless of
   this page), which can't be forced to DD/MM/YYYY from the page itself.
   Convert to the ISO format the backend/DB expect right before each form
   submits, so expires_date still posts as YYYY-MM-DD like before. */
function expiryDatePad(n) { return String(n).padStart(2, '0'); }
function expiryDateValid(y, mo, d) {
  if (mo < 1 || mo > 12 || d < 1) return false;
  return d <= new Date(y, mo, 0).getDate();
}
function parseExpiryDate(s) {
  s = (s || '').trim();
  if (!s) return '';
  const m = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(s);
  if (!m) return null;
  const d = +m[1], mo = +m[2], y = +m[3];
  return expiryDateValid(y, mo, d) ? `${y}-${expiryDatePad(mo)}-${expiryDatePad(d)}` : null;
}
document.querySelectorAll('form.agent-create-form, form.expiry-form, #edit-form').forEach(form => {
  form.addEventListener('submit', e => {
    const input = form.querySelector('input[name="expires_date"]');
    if (!input) return;
    const iso = parseExpiryDate(input.value);
    if (iso === null) {
      e.preventDefault();
      alert('Expiry date must be a valid DD/MM/YYYY date, or left blank.');
      input.focus();
      return;
    }
    input.value = iso;
  });
});

/* Guard against double-submitting Create Account (found 2026-08-09 - a slow
   response or an accidental double-click could send the same "create" POST
   twice; the first request succeeds and the second then fails on the now-real
   duplicate username, showing a confusing "already exists" error for an
   account that was in fact just created). Runs after the expiry-date
   validation above, so a rejected (preventDefault'd) submit leaves the
   button alone for the agent to fix and resubmit. */
document.querySelector('form.agent-create-form').addEventListener('submit', e => {
  if (e.defaultPrevented) return;
  const btn = e.target.querySelector('button[type="submit"]');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Creating…';
  }
});

/* Account search/filter — plain client-side row show/hide (this table tops
   out around a few hundred rows, so no need for DataTables here). Matches
   against username, full name, and mobile number together (see data-search
   on each <tr>), combined with the role dropdown. */
(function () {
  const searchInput = document.getElementById('account-search-input');
  const roleFilter   = document.getElementById('account-role-filter');
  const rows          = Array.from(document.querySelectorAll('#accounts-table tbody tr'));
  const countBadge    = document.getElementById('accounts-count');

  function applyFilter() {
    const q    = searchInput.value.trim().toLowerCase();
    const role = roleFilter.value;
    let visible = 0;
    rows.forEach(row => {
      const matchesSearch = q === '' || row.dataset.search.includes(q);
      const matchesRole   = role === '' || row.dataset.role === role;
      const show = matchesSearch && matchesRole;
      row.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    countBadge.textContent = visible === rows.length ? `${rows.length} total` : `${visible} of ${rows.length}`;
  }
  searchInput.addEventListener('input', applyFilter);
  roleFilter.addEventListener('change', applyFilter);
})();

/* Download Excel - the full account list (not just whatever the search/role
   filter above currently shows), same tokens/columns as the table itself.
   Built from server-emitted data rather than scraping the DOM, since the
   table's own cells are badges/forms, not plain text. */
const AGENTS_EXPORT_DATA = <?= json_encode(array_map(function ($u) {
    $isExpired = $u['expires_at'] !== null && strtotime($u['expires_at']) <= time();
    return [
        'id' => $u['id'],
        'username' => $u['username'],
        'full_name' => $u['full_name'],
        'mobile_no' => $u['mobile_no'] ?? '',
        'role' => $u['role'] === 'sub_admin' ? 'Sub Admin' : ucfirst($u['role']),
        'created_by' => $u['created_by'] !== null && isset($creatorNames[(int) $u['created_by']]) ? $creatorNames[(int) $u['created_by']] : '',
        'status' => $isExpired ? 'Expired' : ($u['is_active'] ? 'Active' : 'Paused'),
        'lpg' => $u['lpg_search_access'] ? 'Granted' : 'Not Granted',
        'tracing2' => $u['tracing2_access'] ? "Granted ({$u['tracing2_monthly_limit']} cr/mo)" : 'Not Granted',
        'pan_india' => $u['pan_india_access'] ? 'Granted' : 'Not Granted',
        'rc_print' => $u['rc_print_access'] ? "Granted ({$u['rc_print_monthly_limit']}/mo)" : 'Not Granted',
        'hp_gas' => $u['hp_gas_access'] ? "Granted ({$u['hp_gas_monthly_limit']}/mo)" : 'Not Granted',
        'indane_gas' => $u['indane_gas_access'] ? "Granted ({$u['indane_gas_monthly_limit']}/mo)" : 'Not Granted',
        'indane_gas_pro' => $u['indane_gas_pro_access'] ? 'Granted' : 'Not Granted',
        'tata_play' => $u['tata_play_access'] ? 'Granted' : 'Not Granted',
        'aadhaar_to_ration' => $u['aadhaar_to_ration_access'] ? "Granted ({$u['aadhaar_to_ration_monthly_limit']}/mo)" : 'Not Granted',
        'adv_pan_india' => $u['eagle_eye_access'] ? 'Granted' : 'Not Granted',
        'pan_india_pro' => $u['pan_india_pro_access'] ? 'Granted' : 'Not Granted',
        'advanced_search' => $u['advanced_search_access'] ? 'Granted' : 'Not Granted',
        'ecommerce' => $u['ecommerce_access'] ? 'Granted' : 'Not Granted',
        'mobile_to_address' => $u['mobile_to_address_access'] ? "Granted ({$u['mobile_to_address_monthly_limit']}/mo)" : 'Not Granted',
        'mobile_address_adv' => $u['mobile_address_adv_access'] ? "Granted ({$u['mobile_address_adv_monthly_limit']}/mo)" : 'Not Granted',
        'all_gas' => $u['all_gas_access'] ? "Granted (Indane {$u['all_gas_indane_monthly_limit']}, Bharat {$u['all_gas_bharat_monthly_limit']}, HP {$u['all_gas_hp_monthly_limit']}/mo)" : 'Not Granted',
        'indian_gas_api' => $u['indian_gas_api_access'] ? "Granted ({$u['indian_gas_api_monthly_limit']}/mo)" : 'Not Granted',
        'hp_gas_api' => $u['hp_gas_api_access'] ? "Granted ({$u['hp_gas_api_monthly_limit']}/mo)" : 'Not Granted',
        'aadhaar_family_api' => $u['aadhaar_family_api_access'] ? "Granted ({$u['aadhaar_family_api_monthly_limit']}/mo)" : 'Not Granted',
        'bharat_gas_api' => $u['bharat_gas_api_access'] ? "Granted ({$u['bharat_gas_api_monthly_limit']}/mo)" : 'Not Granted',
        'max_logins' => $u['max_concurrent_sessions'],
        'allowed_ips' => $u['allowed_ips'] ?: 'Any',
        'expires_at' => $u['expires_at'] ? date('d/m/Y H:i', strtotime($u['expires_at'])) : 'No expiry',
        'created_at' => $u['created_at'] ? date('d/m/Y H:i', strtotime($u['created_at'])) : '',
        'last_login_at' => $u['last_login_at'] ? date('d/m/Y H:i', strtotime($u['last_login_at'])) : 'Never',
    ];
}, $users), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

document.getElementById('export-accounts-btn').addEventListener('click', () => {
  const headers = ['ID', 'Username', 'Full Name', 'Mobile Number', 'Role', 'Created By', 'Status', 'E Commerce', 'LPG', 'Tracing 2.0', 'Pan India', 'RC Print', 'HP Gas', 'Indane Gas', 'Indane Gas Pro', 'TATA SKY DTH', 'Aadhaar to Family Members', 'Adv. Pan India', 'Night Out', 'Advanced Search', 'Mobile to Delivery Address', 'Mobile to Delivery Address Advanced', 'All Gas', 'Indian Gas Advanced', 'HP Gas Advanced', 'Aadhaar to Family Advanced', 'Bharat Gas Advanced', 'Max Logins', 'Allowed IPs', 'Expiry', 'Created At', 'Last Login'];
  const aoa = [headers, ...AGENTS_EXPORT_DATA.map(u => [
    u.id, u.username, u.full_name, u.mobile_no, u.role, u.created_by, u.status, u.ecommerce, u.lpg, u.tracing2, u.pan_india,
    u.rc_print, u.hp_gas, u.indane_gas, u.indane_gas_pro, u.tata_play, u.aadhaar_to_ration, u.adv_pan_india, u.pan_india_pro, u.advanced_search, u.mobile_to_address, u.mobile_address_adv, u.all_gas, u.indian_gas_api, u.hp_gas_api, u.aadhaar_family_api, u.bharat_gas_api, u.max_logins, u.allowed_ips, u.expires_at, u.created_at, u.last_login_at,
  ])];
  const ws = XLSX.utils.aoa_to_sheet(aoa);
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Accounts');
  const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-');
  XLSX.writeFile(wb, `crm-agents-export-${stamp}.xlsx`);
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>

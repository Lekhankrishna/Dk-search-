<?php
// Server-side proxy between the browser and the locateme.services tool
// automations, same shape as hp_gas_api.php/rc_print_api.php: runs
// centrally via Gas/lpg_web's Flask service (the generic /api/tracing2-tool
// route, backed by Gas/lpg_web/tracing2_tools.py), browser never talks to
// locateme.services or Flask directly, and the locateme.services login
// lives in Gas/lpg_web/rc_print.py, not in this app's database.
require __DIR__ . '/includes/auth.php';
// Not requireTracing2Access() here - rc-print/hp-gas/indane-gas each have
// their OWN independent access flag (checked below, per-tool, in the
// switch) and must work for an agent who has THAT flag but not the
// generic "Tracing 2.0" one. Every other tool still ends up gated on
// tracing2_access regardless, since hasTracing2ToolAccess() (the
// default-case check below) already requires it internally.
requireLogin();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/tracing2_archive.php';
require_once __DIR__ . '/includes/tracing2_tools.php';
require_once __DIR__ . '/includes/rcprint_archive.php';
require_once __DIR__ . '/includes/search_cache.php';

header('Content-Type: application/json');

const FLASK_BASE = 'http://127.0.0.1:9197';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$data  = json_decode(file_get_contents('php://input'), true) ?: [];
$tool  = (string) ($data['tool'] ?? '');
$query = trim((string) ($data['query'] ?? ''));

if (!isset(TRACING2_TOOLS[$tool])) {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown Tracing 2.0 tool.']);
    exit;
}

// RC Print/HP Gas Advanced keep the exact same input normalization/
// validation their old standalone pages (rc_print_api.php/hp_gas_api.php)
// enforced, rather than the generic 1-100 char check below - preserves
// behavior parity now that they're reached through this shared endpoint.
if ($tool === 'rc-print') {
    $query = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $query));
    if ($query === '' || strlen($query) < 4 || strlen($query) > 15) {
        http_response_code(400);
        echo json_encode(['error' => 'Enter a valid vehicle registration number.']);
        exit;
    }
} elseif ($tool === 'hp-gas-advanced') {
    $query = preg_replace('/\D/', '', $query);
    if (strlen($query) !== 10) {
        http_response_code(400);
        echo json_encode(['error' => 'Enter a valid 10-digit mobile number.']);
        exit;
    }
} elseif ($query === '' || mb_strlen($query) > 100) {
    http_response_code(400);
    echo json_encode(['error' => 'Enter a value to search.']);
    exit;
}

// rc-print and hp-gas-advanced keep their OWN pre-existing access flag,
// monthly-limit column, and search_logs search_type (continuing the exact
// same usage count agents already had before these were folded into
// Tracing 2.0 as tabs) - see includes/tracing2_tools.php's comment on why
// they don't share tracing2_access/tracing2_monthly_limit like every
// other tool here.
$requiresAccess = TRACING2_TOOLS[$tool]['requiresAccess'] ?? null;

switch ($requiresAccess) {
    case 'rc_print':
        if (!hasRcPrintAccess()) {
            http_response_code(403);
            echo json_encode(['error' => 'RC Print access has not been granted for this account.']);
            exit;
        }
        $limitColumn = 'rc_print_monthly_limit';
        $searchType  = 'rc_print';
        $limitLabel  = 'RC Print';
        break;
    case 'hp_gas':
        if (!hasHpGasAccess()) {
            http_response_code(403);
            echo json_encode(['error' => 'HP LPG Search access has not been granted for this account.']);
            exit;
        }
        $limitColumn = 'hp_gas_monthly_limit';
        $searchType  = 'hp_gas';
        $limitLabel  = 'HP LPG Search';
        break;
    case 'indane_gas':
        if (!hasIndaneGasAccess()) {
            http_response_code(403);
            echo json_encode(['error' => 'Indane Gas access has not been granted for this account.']);
            exit;
        }
        $limitColumn = 'indane_gas_monthly_limit';
        $searchType  = 'indane_gas';
        $limitLabel  = 'Indane Gas';
        break;
    default:
        // Per-tool checklist (Admin > Agents > "Tracing 2.0" -> expandable
        // tool list, see migrate_add_locate_me_tools.sql) - on top of the
        // page-level requireTracing2Access() check above, an agent can be
        // restricted to a subset of tools rather than all-or-nothing.
        if (!hasTracing2ToolAccess($tool)) {
            http_response_code(403);
            echo json_encode(['error' => TRACING2_TOOLS[$tool]['label'] . ' access has not been granted for this account.']);
            exit;
        }
        $limitColumn = 'tracing2_monthly_limit';
        $searchType  = 'tracing2';
        $limitLabel  = 'Tracing 2.0';
}

// Computed here (rather than alongside $creditsSpent below) because the
// read-through cache check below also needs $quotaUnit/$quotaSql to report
// accurate used/limit numbers on a cache hit.
$quotaIsCredits = ($requiresAccess === null);
$quotaUnit      = $quotaIsCredits ? 'credits' : 'searches';
// "AND result_count > 0" (2026-08-28, per explicit instruction) - a clean
// not-found result must not cost the agent quota just because the agent
// never actually got anything back. result_count is already 0 on every
// not-found row for every branch that logs here (rc-print, hp-gas-advanced,
// indane_gas, and the generic credits-based tools below), so this one
// filter is enough for both the count-based and credits-based quota style.
$quotaSql = $quotaIsCredits
    ? "SELECT COALESCE(SUM(credits_spent), 0) FROM search_logs WHERE user_id = :id AND search_type = :type AND result_count > 0 AND searched_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
    : "SELECT COUNT(*) FROM search_logs WHERE user_id = :id AND search_type = :type AND result_count > 0 AND searched_at >= DATE_FORMAT(NOW(), '%Y-%m-01')";

// The generic Tracing 2.0 bucket (every tool except RC Print/HP Gas Advanced)
// tracks the monthly limit as an actual CREDIT budget (2026-08-17) - a
// cheap 1-credit search and an expensive 150-credit search used to count
// identically against "N searches/month", which didn't reflect real
// locateme.services spend. RC Print/HP Gas Advanced stay count-based
// instead - each is a single fixed-cost tool (150 credits every time), so
// a search count there is already just a constant multiple of credits,
// and their existing admin-facing "N/month" limits predate this change.
// Per-agent override (Admin > Agents > "Tracing 2.0 — Select Tools", each
// tool's own credit-cost input) takes precedence over the global default -
// rc-print/hp-gas-advanced are never in that per-agent map (excluded from
// the checklist, see includes/tracing2_tools.php), so they always fall
// through to their fixed 150-credit global cost regardless. Computed here
// (needed by both the cache-hit log below and the live-fetch log further
// down) rather than once inline - a cache hit still spends the same credits
// a live fetch would (2026-10-02, see comment above the limit check).
$creditsSpent = tracing2CreditsForUser($tool, getUserTracing2ToolCredits());

// Every tool spends real credits on the single shared locateme.services
// account, so every agent is capped per calendar month (Admin > Agents) -
// admins bypass this entirely, same as every other metered tool in this app.
// Checked BEFORE the cache lookup below (2026-10-02, per explicit
// instruction: a cached result is still a result handed to the agent, so it
// must still be blocked once their quota is exhausted, exactly like a live
// fetch - a cache hit is no longer a free pass around the monthly limit).
if (($_SESSION['role'] ?? '') !== 'admin') {
    $stmt = $pdo->prepare("SELECT $limitColumn FROM users WHERE id = :id");
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $limit = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare($quotaSql);
    $stmt->execute(['id' => $_SESSION['user_id'], 'type' => $searchType]);
    $usedThisMonth = (int) $stmt->fetchColumn();

    if ($usedThisMonth >= $limit) {
        http_response_code(429);
        echo json_encode([
            'error' => "Monthly $limitLabel limit reached ($usedThisMonth/$limit $quotaUnit this month). Contact your admin to increase it, or try again next month.",
            'used' => $usedThisMonth,
            'limit' => $limit,
            'unit' => $quotaUnit,
        ]);
        exit;
    }
}

// Read-through cache (2026-08-27). Cached forever - see
// includes/search_cache.php's own header comment for the reasoning.
// rc-print/hp-gas-advanced share the SAME cache tables (and key format -
// just the normalized query, no tool slug) as their own standalone
// rc_print_api.php/hp_gas_api.php, so a result cached via either entry
// point is a hit from the other too. Every other tool uses its own
// table, keyed on tool+query since one query string can mean different
// things across ~24 different tools. A hit still logs to search_logs and
// counts against the monthly limit above, same as a live fetch (2026-10-02
// - see comment above the limit check).
if ($tool === 'rc-print') {
    $cacheTable = 'search_cache_rc_print';
    $cacheKey   = searchCacheKey($query);
} elseif ($tool === 'hp-gas-advanced') {
    $cacheTable = 'search_cache_hp_gas';
    $cacheKey   = searchCacheKey($query);
} else {
    $cacheTable = 'search_cache_tracing2';
    $cacheKey   = searchCacheKey($tool, $query);
}
$cached = searchCacheGet($pdo, $cacheTable, $cacheKey);
if ($cached !== null) {
    try {
        // Same recordCount derivation as the live-fetch branch below.
        if ($tool === 'rc-print') {
            $recordCount = !empty($cached['pdfDataUri']) ? 1 : 0;
        } else {
            $recordCount = !empty($cached['found']) ? count($cached['records'] ?? []) : 0;
        }
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $pdo->prepare(
            "INSERT INTO search_logs (user_id, search_type, search_query, result_count, credits_spent, ip_address)
             VALUES (:uid, :type, :q, :cnt, :credits, :ip)"
        )->execute([
            'uid' => $_SESSION['user_id'],
            'type' => $searchType,
            'q' => TRACING2_TOOLS[$tool]['label'] . ': ' . $query,
            'cnt' => $recordCount,
            'credits' => $creditsSpent,
            'ip' => substr($ip, 0, 45),
        ]);
    } catch (PDOException $e) {}

    $cached['unit'] = $quotaUnit;
    if (($_SESSION['role'] ?? '') !== 'admin') {
        try {
            $stmt = $pdo->prepare("SELECT $limitColumn FROM users WHERE id = :id");
            $stmt->execute(['id' => $_SESSION['user_id']]);
            $cached['limit'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->prepare($quotaSql);
            $stmt->execute(['id' => $_SESSION['user_id'], 'type' => $searchType]);
            $cached['used'] = (int) $stmt->fetchColumn();
        } catch (PDOException $e) {}
    }
    http_response_code(200);
    echo json_encode($cached);
    exit;
}

$ch = curl_init(FLASK_BASE . '/api/tracing2-tool');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
// Generous timeout - a fresh locateme.services login plus their own search,
// same reasoning as hp_gas_api.php.
curl_setopt($ch, CURLOPT_TIMEOUT, 90);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['toolSlug' => $tool, 'query' => $query]));
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err      = curl_error($ch);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['error' => "Could not reach the Tracing 2.0 service: $err"]);
    exit;
}

// Only a genuinely completed lookup counts against the monthly limit and
// shows up in Admin > Audit Log - a failed login, timeout, or unreachable
// service isn't the agent's fault. "found": false (a clean not-found result)
// still counts as a completed search - same reasoning as hp_gas_api.php
// (both spend locateme.services credits regardless of hit/miss).
$decoded = json_decode($response, true);
if ($httpCode === 200 && is_array($decoded) && array_key_exists('found', $decoded)) {
    try {
        // RC Print's success shape has no "records" array (its result is a
        // PDF, not label/value fields) - count as 1 completed lookup on
        // success, matching the old rc_print_api.php's own logging exactly.
        if ($tool === 'rc-print') {
            $recordCount = !empty($decoded['pdfDataUri']) ? 1 : 0;
        } else {
            $recordCount = $decoded['found'] ? count($decoded['records'] ?? []) : 0;
        }
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $pdo->prepare(
            "INSERT INTO search_logs (user_id, search_type, search_query, result_count, credits_spent, ip_address)
             VALUES (:uid, :type, :q, :cnt, :credits, :ip)"
        )->execute([
            'uid' => $_SESSION['user_id'],
            'type' => $searchType,
            'q' => TRACING2_TOOLS[$tool]['label'] . ': ' . $query,
            'cnt' => $recordCount,
            'credits' => $creditsSpent,
            'ip' => substr($ip, 0, 45),
        ]);

        $decoded['unit'] = $quotaUnit;
        if (($_SESSION['role'] ?? '') !== 'admin') {
            $stmt = $pdo->prepare("SELECT $limitColumn FROM users WHERE id = :id");
            $stmt->execute(['id' => $_SESSION['user_id']]);
            $decoded['limit'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->prepare($quotaSql);
            $stmt->execute(['id' => $_SESSION['user_id'], 'type' => $searchType]);
            $decoded['used'] = (int) $stmt->fetchColumn();

            $response = json_encode($decoded);
        }
    } catch (PDOException $e) {}

    if ($tool === 'rc-print' && !empty($decoded['pdfDataUri'])) {
        archiveRcPrintResult($decoded['pdfDataUri'], $query, currentUser()['username'] ?? 'unknown');
    } elseif (!empty($decoded['found']) && !empty($decoded['records'])) {
        archiveTracing2Results($decoded['records'], currentUser()['username'] ?? 'unknown', TRACING2_TOOLS[$tool]['label'] . ': ' . $query);
    }

    // Only real results are cached (2026-10-03): a "not found" from a
    // browser-scraped site can be a slow scan or a site hiccup, and caching
    // it would hide the real record from every later search of that number.
    $isRealResult = $tool === 'rc-print' ? !empty($decoded['pdfDataUri']) : (!empty($decoded['found']) && !empty($decoded['records']));
    if ($isRealResult) {
        $toCache = $decoded;
        unset($toCache['used'], $toCache['limit'], $toCache['unit']);
        searchCacheStore($pdo, $cacheTable, $cacheKey, TRACING2_TOOLS[$tool]['label'] . ': ' . $query, $toCache, currentUser()['username'] ?? 'unknown');
    }
}

http_response_code($httpCode ?: 200);
echo $response;

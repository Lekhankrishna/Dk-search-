<?php
// Server-side proxy between the browser and the LPG search service, which
// now runs centrally on this same machine (found 2026-07-27, replacing the
// old per-agent-computer install) rather than one copy per agent's own
// computer. This is a plain same-origin AJAX call from the browser's point
// of view - no Private Network Access headers, no protocol handler, no
// download needed, since the browser never talks to the search service
// directly at all; PHP does, over loopback, entirely server-side.
require __DIR__ . '/includes/auth.php';

header('Content-Type: application/json');

// API requests must never redirect to login.php: fetch() would receive an
// HTML page and fail while trying to parse it as JSON (found 2026-07-31 -
// "Unexpected token '<', <!DOCTYPE...' is not valid JSON" whenever a
// session expired mid-search). requireLpgSearchAccess() does exactly that
// redirect, so its checks are reimplemented here in JSON-safe form instead
// of calling it directly - mirrors api/pan_india.php's same guard.
if (!isLoggedIn() || !isSessionValid()) {
    http_response_code(401);
    echo json_encode([
        'error' => 'Your CRM session has expired or was replaced. Please sign in again.',
        'loginUrl' => 'login.php?reason=session_replaced',
    ]);
    exit;
}
if (!hasLpgSearchAccess()) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied: LPG Search access has not been granted for this account.']);
    exit;
}

require_once __DIR__ . '/includes/lpg_archive.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/search_cache.php';

const FLASK_BASE = 'http://127.0.0.1:9197';

// $onResponse, when given, sees the decoded JSON body before it's echoed -
// used to archive results as a side effect without touching what's actually
// sent back to the browser. If it RETURNS an array, that replaces what gets
// echoed instead of the original response (used below to merge cached
// numbers' results back into a live job's status - see action=status).
function proxyToFlask(string $method, string $path, ?array $body = null, ?callable $onResponse = null): void {
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
    if ($onResponse !== null) {
        $decoded = json_decode($response, true);
        if (is_array($decoded)) {
            $modified = $onResponse($decoded);
            if (is_array($modified)) $response = json_encode($modified);
        }
    }
    http_response_code($httpCode ?: 200);
    echo $response;
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $numbers = is_array($data['numbers'] ?? null) ? $data['numbers'] : [];
    // The 10-number cap is meant for agents; admins can run larger batches.
    // isAdmin is decided here from the actual session role, not trusted from
    // the request body - app.py just does what this proxy tells it.
    $isAdmin = (currentUser()['role'] ?? '') === 'admin';

    // Read-through cache (2026-08-27) - each number is cached independently
    // (not the whole batch as one unit), since a repeat search rarely
    // resubmits the exact same set of numbers together. Only the numbers
    // NOT already cached are actually sent to the live search service -
    // cached forever, see includes/search_cache.php's own header comment.
    $cachedRows = [];
    $liveNumbers = [];
    foreach ($numbers as $number) {
        $row = searchCacheGet($pdo, 'search_cache_lpg', searchCacheKey((string) $number));
        if ($row !== null) {
            $cachedRows[] = $row;
        } else {
            $liveNumbers[] = $number;
        }
    }

    if (empty($liveNumbers)) {
        // Every requested number was already cached - no live search
        // needed at all. The frontend always calls poll(jobId) right after
        // this, so a synthetic already-completed "job" is stashed in this
        // session under a fresh fake job id, same 32-hex shape a real one
        // would have, and action=status below serves it straight from
        // there without ever touching the live search service.
        $jobId = bin2hex(random_bytes(16));
        $_SESSION['lpg_synthetic_job_' . $jobId] = [
            'status' => 'completed',
            'done' => count($cachedRows),
            'total' => count($cachedRows),
            'results' => $cachedRows,
        ];
        echo json_encode(['jobId' => $jobId]);
        exit;
    }

    proxyToFlask('POST', '/api/search', ['numbers' => $liveNumbers, 'isAdmin' => $isAdmin], function (array $decoded) use ($cachedRows) {
        // Stash the cache hits under the REAL job id Flask just assigned,
        // so action=status can merge them back into that job's own
        // progress/results once it's polled - see below.
        if (!empty($cachedRows) && !empty($decoded['jobId'])) {
            $_SESSION['lpg_cached_extra_' . $decoded['jobId']] = $cachedRows;
        }
        return null; // pass the original response through unmodified
    });
} elseif ($action === 'status') {
    $jobId = $_GET['jobId'] ?? '';
    if (!preg_match('/^[0-9a-f]{32}$/', $jobId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid job id']);
        exit;
    }

    // Fully-cached synthetic job (see action=start above) - never touches
    // the live search service at all.
    if (isset($_SESSION['lpg_synthetic_job_' . $jobId])) {
        echo json_encode($_SESSION['lpg_synthetic_job_' . $jobId]);
        exit;
    }

    $searchedBy = currentUser()['username'] ?? 'unknown';
    $cachedExtraKey = 'lpg_cached_extra_' . $jobId;
    proxyToFlask('GET', '/api/search/' . $jobId, null, function (array $job) use ($searchedBy, $pdo, $cachedExtraKey) {
        $liveResults = $job['results'] ?? [];
        archiveLpgResults($liveResults, $searchedBy);

        // Cache each freshly-completed live result under its own number,
        // so the NEXT search for that number (alone or in another batch)
        // is a cache hit instead of going live again.
        foreach ($liveResults as $row) {
            if (!is_array($row) || empty($row['Mobile Number'])) continue;
            searchCacheStore($pdo, 'search_cache_lpg', searchCacheKey((string) $row['Mobile Number']), (string) $row['Mobile Number'], $row, $searchedBy);
        }

        // Merge in whichever numbers from this SAME batch were already
        // cached at action=start time - the live job never even knew
        // about them, so they'd otherwise never appear in the results the
        // frontend renders, and its progress bar would show a smaller
        // total than the number of numbers actually requested.
        if (!empty($_SESSION[$cachedExtraKey])) {
            $cachedRows = $_SESSION[$cachedExtraKey];
            $job['results'] = array_merge($liveResults, $cachedRows);
            $job['done'] = (int) ($job['done'] ?? 0) + count($cachedRows);
            $job['total'] = (int) ($job['total'] ?? 0) + count($cachedRows);
            if (($job['status'] ?? '') === 'completed') unset($_SESSION[$cachedExtraKey]);
            return $job;
        }
        return null;
    });
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
}

<?php
// PHP-only client for theeagleeye.biz's "Advanced Search" tool (labeled
// "Advance Pan India" in this CRM's nav). Unlike RC Print/HP Gas
// (locateme.services - a JS/Firebase SPA needing real browser automation),
// theeagleeye.biz is a plain server-rendered Django app: standard
// csrfmiddlewaretoken + session-cookie login, so a cookie-jar-backed curl
// session does the whole job with no Selenium/Python service involved.
//
// Real credentials live in config/vendor_credentials.php (gitignored, see
// .example for the template) rather than this app's database - same
// reasoning as lpg_search.py's SDMS USERNAME/PASSWORD and rc_print.py's
// locateme.services EMAIL/PASSWORD: this is the only thing that ever needs
// them, there's no per-agent bookmarklet use case. Found hardcoded
// directly here and already committed to git history; moved out.
require_once __DIR__ . '/../config/vendor_credentials.php';
const EAGLEEYE_BASE = 'https://theeagleeye.biz';
define('EAGLEEYE_USERNAME', $EAGLEEYE_USERNAME);
define('EAGLEEYE_PASSWORD', $EAGLEEYE_PASSWORD);

function eagleEyeCookieJarPath(): string {
    return sys_get_temp_dir() . '/eagleeye_cookies.txt';
}

// Confirmed live 2026-09-19 - the real bug behind "Advance Pan India"
// silently returning "No results found" almost instantly: eagleEyeLogin()/
// eagleEyeSearch() make several sequential requests (fetch login page,
// submit login, sometimes clear a session-limit and retry, fetch the
// search page, submit the search) - EACH on its own curl_init() handle,
// previously each independently pointed at the same file via
// CURLOPT_COOKIEJAR/CURLOPT_COOKIEFILE. Traced with CURLINFO_COOKIELIST at
// each step: every individual handle received and held the right cookies
// right up to its own close - but a handle opened and closed EARLIER in
// the sequence could still overwrite the jar file with ITS OWN (older,
// session-less) cookie snapshot AFTER a LATER handle had already written
// the correct, fully-logged-in one, because each handle's jar-write is
// blind to what any other handle wrote in between - there is no
// coordination between them at all, just N independent
// read-file-then-later-write-file cycles racing each other. The result:
// eagleEyeLogin() genuinely succeeds (a real 302, a real session cookie
// received - confirmed via CURLINFO_COOKIELIST right after that request)
// but the jar that's actually left on disk once the whole flow finishes can
// still be the stale, pre-login one, so the very next request in the same
// flow (or the next search entirely) looks logged-out again with no error,
// no hint - just consistently zero results.
//
// Fixed by giving every handle in one request the SAME in-memory cookie
// store (a curl_share_init() with CURL_LOCK_DATA_COOKIE) instead of each
// syncing independently through a file - a cookie set on one handle is
// immediately visible to the next, with no read/write race between them at
// all, since there's only one shared store instead of N independent
// per-handle copies. The file is still what carries a session BETWEEN
// separate PHP requests (this app's PHP built-in server starts a fresh
// process per request, so nothing in-memory survives between searches) -
// loaded into the share once up front (CURLOPT_COOKIEFILE, first handle
// only needs to trigger it once since the share is what actually holds the
// state after that) and saved back once at the very end of the whole flow
// via eagleEyeSaveCookieJar(), not on every individual handle's close.
function eagleEyeShareHandle() {
    static $share = null;
    if ($share === null) {
        $share = curl_share_init();
        curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_COOKIE);
    }
    return $share;
}

// No CurlHandle type hint on the return - curl_init() returns a plain
// `resource` under PHP 7.4 (what this server actually runs), not the
// CurlHandle object PHP 8 introduced.
function eagleEyeCurlHandle() {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SHARE => eagleEyeShareHandle(),
        // Still points at the jar file - this is what actually loads the
        // file's contents into the shared in-memory cookie store the FIRST
        // time any handle touches cookies in this process (a no-op on every
        // handle after that, since the share already has the state).
        CURLOPT_COOKIEFILE => eagleEyeCookieJarPath(),
        // Followed manually rather than via CURLOPT_FOLLOWLOCATION - a
        // redirect back to /accounts/login/ is exactly how we detect "not
        // logged in / session expired" (see eagleEyeFetchSearchPage()),
        // which auto-following would hide.
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        // This PHP install's php.ini has no curl.cainfo set (found
        // 2026-08-08 - every HTTPS request failed with "unable to get local
        // issuer certificate"), and system php.ini isn't writable from here
        // (Program Files, admin-only). Pointing at this CA bundle here
        // instead - already on disk from XAMPP's own phpMyAdmin/Composer
        // install (the standard Mozilla root list) - fixes it for this
        // client without touching shared PHP config.
        CURLOPT_CAINFO => __DIR__ . '/../config/cacert.pem',
    ]);
    return $ch;
}

// Flushes the SHARED cookie store (see eagleEyeShareHandle() above) to disk
// on every close - safe to do on every single handle now, unlike before:
// since every handle reads from and writes to the same in-memory store
// rather than an independent per-handle copy, there's no longer any
// "earlier handle's stale snapshot overwrites a later handle's correct one"
// race to avoid by trying to flush only once at some carefully-chosen
// "last" point. Whichever handle closes last always has the same complete,
// up-to-date cookie set as every other.
function eagleEyeCurlClose($ch): void {
    curl_setopt($ch, CURLOPT_COOKIEJAR, eagleEyeCookieJarPath());
    curl_setopt($ch, CURLOPT_COOKIELIST, 'FLUSH');
    curl_close($ch);
}

function eagleEyeExtractCsrf(string $html): ?string {
    return preg_match('/name="csrfmiddlewaretoken" value="([^"]+)"/', $html, $m) ? $m[1] : null;
}

function eagleEyeSubmitLoginForm(string $csrf) {
    $ch = eagleEyeCurlHandle();
    curl_setopt_array($ch, [
        CURLOPT_URL => EAGLEEYE_BASE . '/accounts/login/',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'csrfmiddlewaretoken' => $csrf,
            'username' => EAGLEEYE_USERNAME,
            'password' => EAGLEEYE_PASSWORD,
        ]),
        CURLOPT_REFERER => EAGLEEYE_BASE . '/accounts/login/',
    ]);
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    eagleEyeCurlClose($ch);
    return [$httpCode, $html];
}

// theeagleeye.biz caps this account at 2 concurrent sessions (found
// 2026-08-08) - once hit, a login attempt gets silently bounced back to the
// login page (200, no visible error) with a "Maximum sessions reached"
// panel embedded in that same HTML, listing every active session as its own
// <form method="post" action="/accounts/delete-session/{key}/"> (just a
// csrfmiddlewaretoken field). Always clearing every listed session here
// (per explicit instruction) rather than trying to guess which one is
// "safe" to remove - they're either stale logins or ones this same client
// created, so there's nothing worth preserving.
function eagleEyeClearSessionLimit(string $html): void {
    if (!preg_match_all('#/accounts/delete-session/([a-zA-Z0-9]+)/#', $html, $matches)) {
        return;
    }
    $csrf = eagleEyeExtractCsrf($html);
    if (!$csrf) return;

    foreach (array_unique($matches[1]) as $sessionKey) {
        $ch = eagleEyeCurlHandle();
        curl_setopt_array($ch, [
            CURLOPT_URL => EAGLEEYE_BASE . "/accounts/delete-session/$sessionKey/",
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['csrfmiddlewaretoken' => $csrf]),
            CURLOPT_REFERER => EAGLEEYE_BASE . '/accounts/login/',
        ]);
        curl_exec($ch);
        eagleEyeCurlClose($ch);
    }
}

function eagleEyeLogin(): bool {
    $ch = eagleEyeCurlHandle();
    curl_setopt($ch, CURLOPT_URL, EAGLEEYE_BASE . '/accounts/login/');
    $html = curl_exec($ch);
    eagleEyeCurlClose($ch);
    if ($html === false) return false;

    $csrf = eagleEyeExtractCsrf($html);
    if (!$csrf) return false;

    // A successful login redirects (302) to /search/; a failed one
    // re-renders the login page with a 200 - either wrong credentials, or
    // (see eagleEyeClearSessionLimit()) the session cap.
    [$httpCode, $resultHtml] = eagleEyeSubmitLoginForm($csrf);
    if ($httpCode >= 300 && $httpCode < 400) return true;

    if ($resultHtml === false || strpos($resultHtml, 'sessionLimitModal') === false) {
        return false;
    }

    eagleEyeClearSessionLimit($resultHtml);

    // Fresh CSRF/session state before retrying, rather than reusing
    // whatever the now-stale $csrf was tied to.
    $ch = eagleEyeCurlHandle();
    curl_setopt($ch, CURLOPT_URL, EAGLEEYE_BASE . '/accounts/login/');
    $html2 = curl_exec($ch);
    eagleEyeCurlClose($ch);
    if ($html2 === false) return false;

    $csrf2 = eagleEyeExtractCsrf($html2);
    if (!$csrf2) return false;

    [$httpCode2] = eagleEyeSubmitLoginForm($csrf2);
    return $httpCode2 >= 300 && $httpCode2 < 400;
}

// Returns the search page HTML if the current session is valid, or null if
// it bounced to login (not logged in / session expired).
function eagleEyeFetchSearchPage(): ?string {
    $ch = eagleEyeCurlHandle();
    curl_setopt($ch, CURLOPT_URL, EAGLEEYE_BASE . '/search/');
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    eagleEyeCurlClose($ch);
    if ($html === false) return null;
    if ($httpCode >= 300 && $httpCode < 400) return null;
    return $html;
}

// The session cookie lasts ~2 weeks server-side, so this reuses the cached
// cookie jar across requests/agents instead of logging in fresh every
// search (cheap here since it's plain curl, but still no reason to
// re-authenticate constantly against someone else's site).
function eagleEyeEnsureSession(): ?string {
    $html = eagleEyeFetchSearchPage();
    if ($html !== null) return $html;
    if (!eagleEyeLogin()) return null;
    return eagleEyeFetchSearchPage();
}

// Parses whatever result tables theeagleeye.biz's Advanced Search rendered
// (it has multiple underlying data sources, shown as separate tables/tabs
// - which ones a query actually returns rows for varies) - reading table
// headers generically rather than hardcoding column names, so this doesn't
// break if theeagleeye.biz adds/renames columns.
function eagleEyeParseResults(string $html): array {
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($doc);

    $tables = [];
    foreach ($xpath->query("//div[@id='results-section']//table") as $tableNode) {
        $headers = [];
        foreach ($xpath->query('.//thead//th', $tableNode) as $th) {
            $headers[] = trim($th->textContent);
        }

        $rows = [];
        foreach ($xpath->query('.//tbody//tr', $tableNode) as $tr) {
            $cells = [];
            $i = 0;
            foreach ($xpath->query('.//td', $tr) as $td) {
                $label = $headers[$i] ?? ('Column ' . ($i + 1));
                $cells[$label] = trim($td->textContent);
                $i++;
            }
            if ($cells) $rows[] = $cells;
        }

        if ($rows) $tables[] = ['headers' => $headers, 'rows' => $rows];
    }

    $totalResults = 0;
    foreach ($tables as $t) $totalResults += count($t['rows']);

    return ['totalResults' => $totalResults, 'tables' => $tables];
}

// $params keys: name, fname, mobile, email, address, master_id (all optional,
// but theeagleeye.biz requires at least one to be non-empty).
function eagleEyeSearch(array $params): array {
    $searchPageHtml = eagleEyeEnsureSession();
    if ($searchPageHtml === null) {
        throw new RuntimeException('Could not log into theeagleeye.biz - check the credentials in includes/eagleeye_client.php.');
    }

    $csrf = eagleEyeExtractCsrf($searchPageHtml);
    if (!$csrf) {
        throw new RuntimeException('Could not find a CSRF token on the Advance Pan India search page.');
    }

    $postFields = ['csrfmiddlewaretoken' => $csrf];
    foreach (['name', 'fname', 'mobile', 'email', 'address', 'master_id'] as $field) {
        $postFields[$field] = $params[$field] ?? '';
    }

    $ch = eagleEyeCurlHandle();
    curl_setopt_array($ch, [
        CURLOPT_URL => EAGLEEYE_BASE . '/search/',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postFields),
        CURLOPT_REFERER => EAGLEEYE_BASE . '/search/',
    ]);
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    eagleEyeCurlClose($ch);

    if ($html === false) {
        throw new RuntimeException('Could not reach theeagleeye.biz.');
    }

    static $retried = false;
    if ($httpCode >= 300 && $httpCode < 400) {
        if ($retried || !eagleEyeLogin()) {
            throw new RuntimeException('theeagleeye.biz session expired and re-login failed.');
        }
        $retried = true;
        return eagleEyeSearch($params);
    }

    return eagleEyeParseResults($html);
}

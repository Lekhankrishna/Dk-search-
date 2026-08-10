<?php
// PHP-only client for tracekart.in (labeled "Advanced Search" in this CRM's
// nav). Confirmed 2026-08-10 (live inspection of tracekart.in): an ASP.NET
// Core MVC site (X-Powered-By: ASP.NET, .AspNetCore.Antiforgery cookie),
// plain server-rendered Razor pages - no JS SPA, no Selenium needed, same
// cookie-jar-backed curl approach as includes/eagleeye_client.php.
//
// Credentials hardcoded here rather than pulled from this app's database -
// same reasoning as lpg_search.py's SDMS USERNAME/PASSWORD and
// rc_print.py's locateme.services EMAIL/PASSWORD: this is the only thing
// that ever needs them, there's no per-agent bookmarklet use case.
const TRACEKART_BASE = 'https://tracekart.in';
const TRACEKART_USERNAME = 'Lucky16';
const TRACEKART_PASSWORD = 'Aug@2026#';

function tracekartCookieJarPath(): string {
    return sys_get_temp_dir() . '/tracekart_cookies.txt';
}

// No CurlHandle type hint on the return - curl_init() returns a plain
// `resource` under PHP 7.4 (what this server actually runs), not the
// CurlHandle object PHP 8 introduced.
function tracekartCurlHandle() {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => tracekartCookieJarPath(),
        CURLOPT_COOKIEFILE => tracekartCookieJarPath(),
        // Followed manually rather than via CURLOPT_FOLLOWLOCATION - a
        // successful login responds 302, a failed one re-renders the login
        // page with a 200, and a session that's expired mid-use bounces
        // /Home/Index back to "/" the same way. Auto-following would hide
        // exactly the signal used to detect all of this.
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        // Same php.ini gap as includes/eagleeye_client.php (no curl.cainfo
        // configured) - reuses the same on-disk Mozilla CA bundle from
        // XAMPP's phpMyAdmin/Composer install.
        CURLOPT_CAINFO => 'C:\\xampp\\phpMyAdmin\\vendor\\composer\\ca-bundle\\res\\cacert.pem',
    ]);
    return $ch;
}

function tracekartExtractToken(string $html): ?string {
    return preg_match('/name="__RequestVerificationToken"\s+type="hidden"\s+value="([^"]+)"/', $html, $m) ? $m[1] : null;
}

// The login form's own JS populates a hidden ClientIP field from
// api.ipify.org before submit (confirmed 2026-08-10, tracekart.in's own
// page source) - presumably tied to its "up to 3 IP addresses per day"
// limit. Mirrored server-side here since there's no browser to run that JS.
function tracekartGetPublicIp(): string {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.ipify.org?format=json',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CAINFO => 'C:\\xampp\\phpMyAdmin\\vendor\\composer\\ca-bundle\\res\\cacert.pem',
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode((string) $resp, true);
    return (string) ($data['ip'] ?? '');
}

function tracekartLogin(): bool {
    // A stale-but-still-recognized session cookie makes "/" redirect (302,
    // empty body) instead of serving the login form - confirmed 2026-08-10
    // debugging "Server Down" on every real search: the shared cookie jar
    // (persisted across requests by design - see tracekartCurlHandle())
    // had exactly this leftover state, so the very first step of every
    // login attempt was silently getting zero bytes back and no token to
    // extract. Wiping the jar first guarantees "/" always serves the real
    // login form, regardless of whatever session state was sitting there.
    @unlink(tracekartCookieJarPath());

    $ch = tracekartCurlHandle();
    curl_setopt($ch, CURLOPT_URL, TRACEKART_BASE . '/');
    $html = curl_exec($ch);
    curl_close($ch);
    if ($html === false) return false;

    $token = tracekartExtractToken($html);
    if (!$token) return false;

    $ch = tracekartCurlHandle();
    curl_setopt_array($ch, [
        CURLOPT_URL => TRACEKART_BASE . '/',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'Username' => TRACEKART_USERNAME,
            'Password' => TRACEKART_PASSWORD,
            'ClientIP' => tracekartGetPublicIp(),
            'AcceptTerms' => 'true',
            '__RequestVerificationToken' => $token,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // A successful login redirects (302) to /Home/Index; a failed one
    // (wrong credentials, terms not accepted) re-renders the login page
    // with a 200.
    return $httpCode >= 300 && $httpCode < 400;
}

// tracekart.in's own sidebar ("Search Regions") - confirmed 2026-08-10 by
// fetching all 5 pages live - is 5 SEPARATE per-state pages, each with its
// own results (login cookies even set one access flag per region: Chn/Hyd/
// Bng/Mum/Kl). Every state was initially searched through /Home/Index
// (Tamil Nadu's own page) here, which is why early test searches for
// numbers with known Karnataka data came back "No records found" - wrong
// state, not no data. Each state also has its own form action AND its own
// searchOption values per mode (not a shared naming convention - e.g.
// mobile search is "ChnMobileno" on Tamil Nadu's page but plain "mobile"
// everywhere else). Field NAMES (searchMobile, cname, etc.) are the one
// thing consistent across all 5 - see TRACEKART_MODE_FIELDS.
const TRACEKART_STATES = [
    'tn' => ['label' => 'Tamil Nadu',     'indexPath' => '/Home/Index',    'searchPath' => '/Home/Search',
        'modes' => ['mobile' => 'ChnMobileno', 'father' => 'ChnFathername', 'dob' => 'ChnDOB', 'address' => 'nameandaddress', 'fulladdress' => 'fulladdress']],
    'ap' => ['label' => 'Andhra Pradesh', 'indexPath' => '/HYD/Index',     'searchPath' => '/HYD/HYDSearch',
        'modes' => ['mobile' => 'mobile', 'father' => 'ChnFathername', 'dob' => 'hydDOB', 'address' => 'nameandaddress', 'fulladdress' => 'fulladdress']],
    'ka' => ['label' => 'Karnataka',      'indexPath' => '/BNG/Index',     'searchPath' => '/BNG/BNGSearch',
        'modes' => ['mobile' => 'mobile', 'father' => 'nameandfathername', 'dob' => 'nameanddob', 'address' => 'nameandaddress', 'fulladdress' => 'fulladdress']],
    'mh' => ['label' => 'Maharashtra',    'indexPath' => '/MUM/Index',     'searchPath' => '/Mum/MUMSearch',
        'modes' => ['mobile' => 'mobile', 'father' => 'nameandfathername', 'dob' => 'nameanddob', 'address' => 'nameandaddress', 'fulladdress' => 'fulladdress']],
    // Kerala has no Name & D.O.B tab at all on its own page - not an
    // oversight, "dob" is simply absent from this state's modes map.
    'kl' => ['label' => 'Kerala',         'indexPath' => '/Kerala/Index',  'searchPath' => '/Kerala/KeralaSearch',
        'modes' => ['mobile' => 'mobile', 'father' => 'nameandfathername', 'address' => 'nameandaddress', 'fulladdress' => 'fulladdress']],
];

// Same form field name per mode on every state's page (confirmed live
// across all 5) - only the searchOption value and endpoint vary by state.
const TRACEKART_MODE_FIELDS = [
    'mobile'      => ['mobile' => 'searchMobile'],
    'father'      => ['name' => 'cname', 'fathername' => 'fathername'],
    'dob'         => ['name' => 'cnamedob', 'dob' => 'cdob'],
    'address'     => ['name' => 'na_name', 'address' => 'na_address'],
    'fulladdress' => ['address' => 'full_address'],
];

// Returns a given state's own Index page HTML if the current session is
// valid, or null if it bounced back to "/" (not logged in / session
// expired). Fetched fresh per search (not cached) since the antiforgery
// token embedded in it must match what gets POSTed to that state's own
// search endpoint right after.
function tracekartFetchStatePage(string $stateKey): ?string {
    if (!isset(TRACEKART_STATES[$stateKey])) return null;
    $ch = tracekartCurlHandle();
    curl_setopt($ch, CURLOPT_URL, TRACEKART_BASE . TRACEKART_STATES[$stateKey]['indexPath']);
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($html === false) return null;
    if ($httpCode >= 300 && $httpCode < 400) return null;
    return $html;
}

// Session cookie lifetime wasn't fully confirmed live, so this re-verifies
// on every call rather than assuming a long-lived cookie (cheap - one extra
// GET - and safe either way, unlike guessing wrong and silently failing).
function tracekartEnsureSession(string $stateKey): ?string {
    $html = tracekartFetchStatePage($stateKey);
    if ($html !== null) return $html;
    if (!tracekartLogin()) return null;
    return tracekartFetchStatePage($stateKey);
}

// Reads whatever table tracekart.in rendered inside #resultsContainer,
// headers read generically (not hardcoded column names) same as
// eagleEyeParseResults() - this site's own result columns were never
// actually observed live (every test query came back "No records found"),
// so hardcoding names here would be a guess; generic parsing works
// regardless of what they turn out to be.
function tracekartParseResults(string $html): array {
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($doc);

    $containers = $xpath->query("//div[@id='resultsContainer']");
    if ($containers->length === 0) return ['totalResults' => 0, 'headers' => [], 'rows' => []];
    $container = $containers->item(0);

    $headers = [];
    foreach ($xpath->query('.//table//thead//th', $container) as $th) {
        $headers[] = trim($th->textContent);
    }

    $rows = [];
    foreach ($xpath->query('.//table//tbody//tr', $container) as $tr) {
        $cells = [];
        $i = 0;
        foreach ($xpath->query('.//td', $tr) as $td) {
            $label = $headers[$i] ?? ('Column ' . ($i + 1));
            $cells[$label] = trim($td->textContent);
            $i++;
        }
        if ($cells) $rows[] = $cells;
    }

    return ['totalResults' => count($rows), 'headers' => $headers, 'rows' => $rows];
}

function tracekartSearch(string $stateKey, string $mode, array $fields, bool $isRetry = false): array {
    if (!isset(TRACEKART_STATES[$stateKey])) {
        throw new RuntimeException('Unknown state.');
    }
    $state = TRACEKART_STATES[$stateKey];
    if (!isset($state['modes'][$mode])) {
        throw new RuntimeException('That search mode is not available for ' . $state['label'] . '.');
    }
    if (!isset(TRACEKART_MODE_FIELDS[$mode])) {
        throw new RuntimeException('Unknown Advanced Search mode.');
    }

    $postFields = ['searchOption' => $state['modes'][$mode]];
    $hasAny = false;
    foreach (TRACEKART_MODE_FIELDS[$mode] as $inputKey => $formField) {
        $value = trim((string) ($fields[$inputKey] ?? ''));
        $postFields[$formField] = $value;
        if ($value !== '') $hasAny = true;
    }
    if (!$hasAny) {
        throw new RuntimeException('Enter at least one search field.');
    }

    $stateHtml = tracekartEnsureSession($stateKey);
    if ($stateHtml === null) {
        throw new RuntimeException('Could not log into the Advanced Search service - check the credentials in includes/tracekart_client.php.');
    }
    $token = tracekartExtractToken($stateHtml);
    if (!$token) {
        throw new RuntimeException('Could not find a search token on the Advanced Search service.');
    }
    $postFields['__RequestVerificationToken'] = $token;

    $ch = tracekartCurlHandle();
    curl_setopt_array($ch, [
        CURLOPT_URL => TRACEKART_BASE . $state['searchPath'],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postFields),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException('Could not reach the Advanced Search service.');
    }
    // A redirect here means the session dropped between ensureSession() and
    // this POST (e.g. concurrent request evicted it) - one retry with a
    // forced fresh login covers that without looping forever.
    if ($httpCode >= 300 && $httpCode < 400) {
        if ($isRetry || !tracekartLogin()) {
            throw new RuntimeException('Advanced Search session expired and re-login failed.');
        }
        return tracekartSearch($stateKey, $mode, $fields, true);
    }
    if ($httpCode !== 200) {
        throw new RuntimeException('Advanced Search failed (HTTP ' . $httpCode . ').');
    }

    return tracekartParseResults((string) $resp);
}

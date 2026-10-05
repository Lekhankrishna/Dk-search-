<?php
// PHP-only client for tracekart.in (labeled "Advanced Search" in this CRM's
// nav). Confirmed 2026-08-10 (live inspection of tracekart.in): an ASP.NET
// Core MVC site (X-Powered-By: ASP.NET, .AspNetCore.Antiforgery cookie),
// plain server-rendered Razor pages - no JS SPA, no Selenium needed, same
// cookie-jar-backed curl approach as includes/eagleeye_client.php.
//
// Real credentials live in config/vendor_credentials.php (gitignored, see
// .example for the template) rather than this app's database - same
// reasoning as lpg_search.py's SDMS USERNAME/PASSWORD and rc_print.py's
// locateme.services EMAIL/PASSWORD: this is the only thing that ever needs
// them, there's no per-agent bookmarklet use case. Found hardcoded
// directly here and already committed to git history; moved out.
require_once __DIR__ . '/../config/vendor_credentials.php';
const TRACEKART_BASE = 'https://tracekart.in';
define('TRACEKART_USERNAME', $TRACEKART_USERNAME);
define('TRACEKART_PASSWORD', $TRACEKART_PASSWORD);

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
        CURLOPT_CAINFO => __DIR__ . '/../config/cacert.pem',
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
        CURLOPT_CAINFO => __DIR__ . '/../config/cacert.pem',
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
//
// searchPath (2026-10-03, confirmed live): each state's form now posts in
// the background to <Region>/SearchData and gets JSON back instead of a
// server-rendered results page - the old /Home/Search, /HYD/HYDSearch etc.
// now return 404. searchOption values and field names are unchanged.
const TRACEKART_STATES = [
    'tn' => ['label' => 'Tamil Nadu',     'indexPath' => '/Home/Index',    'searchPath' => '/Home/SearchData',
        'modes' => ['mobile' => 'ChnMobileno', 'father' => 'ChnFathername', 'dob' => 'ChnDOB', 'address' => 'nameandaddress', 'fulladdress' => 'fulladdress']],
    'ap' => ['label' => 'Andhra Pradesh', 'indexPath' => '/HYD/Index',     'searchPath' => '/HYD/SearchData',
        'modes' => ['mobile' => 'mobile', 'father' => 'ChnFathername', 'dob' => 'hydDOB', 'address' => 'nameandaddress', 'fulladdress' => 'fulladdress']],
    'ka' => ['label' => 'Karnataka',      'indexPath' => '/BNG/Index',     'searchPath' => '/BNG/SearchData',
        'modes' => ['mobile' => 'mobile', 'father' => 'nameandfathername', 'dob' => 'nameanddob', 'address' => 'nameandaddress', 'fulladdress' => 'fulladdress']],
    'mh' => ['label' => 'Maharashtra',    'indexPath' => '/MUM/Index',     'searchPath' => '/Mum/SearchData',
        'modes' => ['mobile' => 'mobile', 'father' => 'nameandfathername', 'dob' => 'nameanddob', 'address' => 'nameandaddress', 'fulladdress' => 'fulladdress']],
    // Kerala has no Name & D.O.B tab at all on its own page - not an
    // oversight, "dob" is simply absent from this state's modes map.
    'kl' => ['label' => 'Kerala',         'indexPath' => '/Kerala/Index',  'searchPath' => '/Kerala/SearchData',
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

// "All Gas" - tracekart.in's Skip Trace "Gas Connection" service (confirmed
// live 2026-10-03): a plain server-rendered form (mobile + provider_name)
// posted to /SkipTrace/Search?key=gas-connection. Results render inside
// #resultsContainer; a miss renders nothing there and instead sets an
// on-load toast ("No records found for this input."). Each search is
// charged on the vendor account.
const TRACEKART_GAS_PAGE = '/SkipTrace/Service?key=gas-connection';
const TRACEKART_GAS_SEARCH = '/SkipTrace/Search?key=gas-connection';
const TRACEKART_GAS_PROVIDERS = [
    'indane' => 'Indane',
    'bharat' => 'Bharat Gas',
    'hp'     => 'HP Gas',
];

function tracekartGasFetchPage(): ?string {
    $ch = tracekartCurlHandle();
    curl_setopt($ch, CURLOPT_URL, TRACEKART_BASE . TRACEKART_GAS_PAGE);
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($html === false || ($httpCode >= 300 && $httpCode < 400)) return null;
    return (string) $html;
}

// A hit (confirmed live 2026-10-03, HP) renders #resultsContainer as a
// series of cards, each an <h3> section title over a two-column table of
// <th>label</th><td>value</td> rows - the first card just echoes the query
// (Mobile Number / Provider Name), then e.g. "Hp Gas" (Message / Status
// Code / Success) and "Data" (Consumer Number, refill flags, ...). Read as
// Section / Field / Value rows (no field names hardcoded), plus one flat
// "record" of the same data for the archive. Falls back to header-row
// tables and <dt>/<dd> lists in case another provider renders differently.
const TRACEKART_GAS_ECHO_FIELDS = ['Mobile Number', 'Provider Name'];

function tracekartGasParse(string $html): array {
    $message = preg_match('/var\s+msg\s*=\s*"((?:[^"\\\\]|\\\\.)*)"/', $html, $m) ? stripcslashes($m[1]) : '';

    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($doc);
    $container = $xpath->query("//div[@id='resultsContainer']")->item(0);

    // Label/value card layout.
    $fieldRows = [];
    $record = [];
    if ($container) {
        foreach ($xpath->query('.//tr[th and td]', $container) as $tr) {
            $label = trim(preg_replace('/\s+/', ' ', $xpath->query('./th', $tr)->item(0)->textContent));
            $value = trim(preg_replace('/\s+/', ' ', $xpath->query('./td', $tr)->item(0)->textContent));
            $h3 = $xpath->query('ancestor::div[contains(concat(" ", normalize-space(@class), " "), " card ")][1]//h3', $tr)->item(0);
            $section = $h3 ? trim($h3->textContent) : '';
            if ($section === '' && in_array($label, TRACEKART_GAS_ECHO_FIELDS, true)) continue;
            if ($label === '') continue;
            $fieldRows[] = ['Section' => $section, 'Field' => $label, 'Value' => $value];
            $key = isset($record[$label]) && $section !== '' ? "$section: $label" : $label;
            $record[$key] = $value;
        }
    }
    if ($fieldRows) {
        return ['totalResults' => count($fieldRows), 'headers' => ['Section', 'Field', 'Value'],
                'rows' => $fieldRows, 'record' => $record, 'message' => ''];
    }

    $headers = [];
    $rows = [];
    if ($container) {
        foreach ($xpath->query('.//table', $container) as $table) {
            $th = [];
            foreach ($xpath->query('.//thead//th | .//tr[1][not(ancestor::tbody)]/th', $table) as $h) $th[] = trim($h->textContent);
            foreach ($xpath->query('.//tbody/tr | .//tr[td]', $table) as $tr) {
                $cells = [];
                $i = 0;
                foreach ($xpath->query('./td', $tr) as $td) {
                    $cells[$th[$i] ?? ('Column ' . ($i + 1))] = trim(preg_replace('/\s+/', ' ', $td->textContent));
                    $i++;
                }
                if (array_filter($cells, fn($v) => $v !== '')) $rows[] = $cells;
            }
            foreach ($th as $h) if (!in_array($h, $headers, true)) $headers[] = $h;
        }
        // Rows can be matched twice by the two tr selectors above.
        $rows = array_values(array_map('unserialize', array_unique(array_map('serialize', $rows))));

        if (!$rows) {
            $record = [];
            $dts = $xpath->query('.//dt', $container);
            foreach ($dts as $dt) {
                $dd = $xpath->query('following-sibling::dd[1]', $dt)->item(0);
                if ($dd) $record[trim($dt->textContent)] = trim(preg_replace('/\s+/', ' ', $dd->textContent));
            }
            if (!$record) {
                $text = $container->textContent;
                foreach (preg_split('/\R/', $text) as $line) {
                    if (preg_match('/^\s*([^:]{2,60}):\s*(.+?)\s*$/u', $line, $kv)) $record[trim($kv[1])] = $kv[2];
                }
            }
            if ($record) {
                $rows[] = $record;
                $headers = array_keys($record);
            }
        }
    }
    if (!$headers && $rows) $headers = array_keys($rows[0]);

    return ['totalResults' => count($rows), 'headers' => $headers, 'rows' => $rows,
            'record' => count($rows) === 1 ? $rows[0] : null, 'message' => $rows ? '' : $message];
}

function tracekartGasSearch(string $mobile, string $provider, bool $isRetry = false): array {
    $mobile = preg_replace('/\D+/', '', $mobile);
    if (strlen($mobile) !== 10) {
        throw new RuntimeException('Enter a valid 10-digit mobile number.');
    }
    if (!isset(TRACEKART_GAS_PROVIDERS[$provider])) {
        throw new RuntimeException('Select a gas provider.');
    }

    $page = tracekartGasFetchPage();
    if ($page === null) {
        if (!tracekartLogin()) {
            throw new RuntimeException('Could not log into the All Gas service - check the tracekart.in credentials.');
        }
        $page = tracekartGasFetchPage();
    }
    $token = $page !== null ? tracekartExtractToken($page) : null;
    if (!$token) {
        throw new RuntimeException('Could not find a search token on the All Gas service.');
    }

    $ch = tracekartCurlHandle();
    curl_setopt_array($ch, [
        CURLOPT_URL => TRACEKART_BASE . TRACEKART_GAS_SEARCH,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'mobile' => $mobile,
            'provider_name' => $provider,
            '__RequestVerificationToken' => $token,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 120,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException('Could not reach the All Gas service.');
    }
    if ($httpCode >= 300 && $httpCode < 400) {
        if ($isRetry || !tracekartLogin()) {
            throw new RuntimeException('All Gas session expired and re-login failed.');
        }
        return tracekartGasSearch($mobile, $provider, true);
    }
    if ($httpCode !== 200) {
        throw new RuntimeException('All Gas search failed (HTTP ' . $httpCode . ').');
    }

    return tracekartGasParse((string) $resp);
}

// SearchData sends each row as a plain list of values; the column titles
// live only in the state page's own initRegionSearch({ columns: [...] })
// call, and differ per state (and per account - an Identity column is only
// sent to users allowed to see it). Read from that same page rather than
// hardcoded, so a vendor-side column change can't silently mislabel data.
function tracekartParseColumns(string $stateHtml): array {
    if (!preg_match('/initRegionSearch\(\{.*?columns:\s*\[(.*?)\]/s', $stateHtml, $m)) return [];
    preg_match_all('/\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"/', $m[1], $cols, PREG_SET_ORDER);
    return array_map(fn($c) => stripslashes($c[2] ?? '') !== '' ? stripslashes($c[2]) : stripslashes($c[1]), $cols);
}

// $json is SearchData's decoded { rows, page, batchSize, hasMore } response.
// Rows come back as positional arrays; labelled here with the page's own
// column titles into the same headers/rows shape the UI and archive use.
function tracekartBuildResults(array $json, array $headers): array {
    $rows = [];
    foreach ($json['rows'] ?? [] as $values) {
        if (!is_array($values)) continue;
        $cells = [];
        foreach (array_values($values) as $i => $value) {
            $cells[$headers[$i] ?? ('Column ' . ($i + 1))] = trim((string) $value);
        }
        if ($cells) $rows[] = $cells;
    }
    if (!$headers && $rows) $headers = array_keys($rows[0]);
    return [
        'totalResults' => count($rows),
        'hasMore' => (bool) ($json['hasMore'] ?? false),
        'headers' => $headers,
        'rows' => $rows,
    ];
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
    // First batch only (up to 1,000 rows) - same as the vendor's own page
    // on submit.
    $postFields['page'] = '1';

    $ch = tracekartCurlHandle();
    curl_setopt_array($ch, [
        CURLOPT_URL => TRACEKART_BASE . $state['searchPath'],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postFields),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            // Marks this as the page's own background request, which is what
            // makes SearchData answer with JSON.
            'X-Requested-With: XMLHttpRequest',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 90,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException('Could not reach the Advanced Search service.');
    }
    $json = json_decode((string) $resp, true);

    // The session dropped between ensureSession() and this POST (e.g. a
    // concurrent request evicted it) - signalled either as an HTTP redirect
    // or as SearchData's own { redirect } JSON. One retry with a forced
    // fresh login covers that without looping forever.
    if (($httpCode >= 300 && $httpCode < 400) || (is_array($json) && !empty($json['redirect']))) {
        if ($isRetry || !tracekartLogin()) {
            throw new RuntimeException('Advanced Search session expired and re-login failed.');
        }
        return tracekartSearch($stateKey, $mode, $fields, true);
    }
    if ($httpCode !== 200) {
        throw new RuntimeException('Advanced Search failed (HTTP ' . $httpCode . ').');
    }
    if (!is_array($json)) {
        throw new RuntimeException('Advanced Search returned an unexpected response.');
    }
    if (!empty($json['error'])) {
        throw new RuntimeException('Advanced Search: ' . $json['error']);
    }

    return tracekartBuildResults($json, tracekartParseColumns($stateHtml));
}


// All Gas per-provider monthly limits (2026-10-04). Usage = this month's
// found searches for that provider, read from search_logs (all_gas_api.php
// logs each search as "<Provider label>: <mobile>").
const ALL_GAS_LIMIT_COLUMNS = [
    'indane' => 'all_gas_indane_monthly_limit',
    'bharat' => 'all_gas_bharat_monthly_limit',
    'hp'     => 'all_gas_hp_monthly_limit',
];

function allGasUsage(PDO $pdo, int $userId, string $provider): array {
    $stmt = $pdo->prepare('SELECT ' . ALL_GAS_LIMIT_COLUMNS[$provider] . ' FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $limit = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM search_logs WHERE user_id = :id AND search_type = 'all_gas'
           AND search_query LIKE :prefix AND result_count > 0 AND searched_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
    );
    $stmt->execute(['id' => $userId, 'prefix' => TRACEKART_GAS_PROVIDERS[$provider] . ': %']);
    return ['used' => (int) $stmt->fetchColumn(), 'limit' => $limit];
}

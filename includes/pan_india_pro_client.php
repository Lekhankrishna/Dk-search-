<?php
// PHP-only client for a third-party "Knotorious Search" API (labeled "Pan
// India Pro" in this CRM's nav - the vendor name is deliberately not shown
// anywhere in the UI, same as Advance Pan India/theeagleeye.biz). Unlike
// RC Print/HP Gas (locateme.services - a JS/Firebase SPA needing real
// browser automation), this vendor's frontend is a Next.js SPA but its
// actual backend is a plain JSON REST API (api.knotorious.us) that can be
// called directly - no Selenium needed. The API rejects requests without
// browser-like Origin/Referer/User-Agent headers ("automated access is not
// permitted"), but doesn't otherwise fingerprint the client.
//
// Real credentials live in config/vendor_credentials.php (gitignored, see
// .example for the template) rather than this app's database - same
// reasoning as lpg_search.py's SDMS USERNAME/PASSWORD and rc_print.py's
// locateme.services EMAIL/PASSWORD: this is the only thing that ever needs
// them, there's no per-agent bookmarklet use case. Found hardcoded
// directly here and already committed to git history; moved out.
require_once __DIR__ . '/../config/vendor_credentials.php';
const PAN_INDIA_PRO_API_BASE = 'https://api.knotorious.us';
const PAN_INDIA_PRO_FRONTEND = 'https://www.knotoriousai.online';
define('PAN_INDIA_PRO_EMAIL', $PAN_INDIA_PRO_EMAIL);
define('PAN_INDIA_PRO_PASSWORD', $PAN_INDIA_PRO_PASSWORD);

function panIndiaProTokenCachePath(): string {
    return sys_get_temp_dir() . '/pan_india_pro_token.json';
}

// No CurlHandle type hint on the return - curl_init() returns a plain
// `resource` under PHP 7.4 (what this server actually runs), not the
// CurlHandle object PHP 8 introduced.
function panIndiaProCurlHandle() {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        // Every caller sets its own complete CURLOPT_HTTPHEADER (Content-Type
        // and/or Authorization vary per request) - curl_setopt() replaces the
        // whole header list rather than merging, so there's no default set
        // here for it to matter.
        //
        // Same php.ini gap as includes/eagleeye_client.php (no curl.cainfo
        // configured) - uses the Mozilla CA bundle kept in config/, since
        // XAMPP's copy no longer exists on this machine.
        CURLOPT_CAINFO => __DIR__ . '/../config/cacert.pem',
    ]);
    return $ch;
}

// Revokes every currently active device session (per explicit instruction
// established for the same situation on Advance Pan India/theeagleeye.biz -
// they're either stale logins or ones this same client created, so there's
// nothing worth preserving) before retrying login.
function panIndiaProClearDeviceLimit(array $activeDevices): void {
    foreach ($activeDevices as $device) {
        $sessionId = $device['id'] ?? null;
        if (!$sessionId) continue;
        $ch = panIndiaProCurlHandle();
        curl_setopt_array($ch, [
            CURLOPT_URL => PAN_INDIA_PRO_API_BASE . '/auth/revoke-session',
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Origin: ' . PAN_INDIA_PRO_FRONTEND,
                'Referer: ' . PAN_INDIA_PRO_FRONTEND . '/',
                'Accept: application/json, text/plain, */*',
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'email' => PAN_INDIA_PRO_EMAIL,
                'password' => PAN_INDIA_PRO_PASSWORD,
                'session_id' => $sessionId,
            ]),
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}

// Logs in fresh and caches the returned JWT (server-side exp is 6h - see the
// login response's own "exp"/"nbf"/"iat" claims - refreshed a little early
// here via panIndiaProGetToken()'s own margin). On a device-limit-exceeded
// (409) response, clears every active device and retries once - this
// account is capped at a handful of concurrent devices, and this client
// reusing a cached token (see panIndiaProGetToken()) rather than logging in
// per-search keeps it well under that in normal use.
function panIndiaProLogin(bool $isRetry = false): ?string {
    $ch = panIndiaProCurlHandle();
    curl_setopt_array($ch, [
        CURLOPT_URL => PAN_INDIA_PRO_API_BASE . '/auth/login',
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Origin: ' . PAN_INDIA_PRO_FRONTEND,
            'Referer: ' . PAN_INDIA_PRO_FRONTEND . '/',
            'Accept: application/json, text/plain, */*',
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'email' => PAN_INDIA_PRO_EMAIL,
            'password' => PAN_INDIA_PRO_PASSWORD,
        ]),
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) return null;

    $data = json_decode($body, true);

    if (!$isRetry && $httpCode === 409 && ($data['error'] ?? '') === 'device_limit_exceeded') {
        panIndiaProClearDeviceLimit($data['active_devices'] ?? []);
        return panIndiaProLogin(true);
    }

    if ($httpCode !== 200 || empty($data['token'])) {
        return null;
    }

    file_put_contents(panIndiaProTokenCachePath(), json_encode([
        'token' => $data['token'],
        'obtained_at' => time(),
    ]));
    return $data['token'];
}

// Reuses the cached JWT until it's within 30 minutes of the server's 6-hour
// expiry, rather than logging in fresh for every search - see the comment
// on panIndiaProLogin() for why (device-limit headroom).
function panIndiaProGetToken(): ?string {
    $path = panIndiaProTokenCachePath();
    if (is_file($path)) {
        $cached = json_decode((string) file_get_contents($path), true);
        $obtainedAt = $cached['obtained_at'] ?? 0;
        if (!empty($cached['token']) && (time() - $obtainedAt) < (5.5 * 3600)) {
            return $cached['token'];
        }
    }
    return panIndiaProLogin();
}

// $params keys: name, fname, mobile, email, address, master_id (all
// optional, but at least one must be non-empty) - joined into the single
// space-separated "field:value" query string this API's own frontend sends
// (confirmed 2026-08-09: "mobile:9876543210 name:sharma" style, matching
// the placeholder labels on the vendor's own search form).
function panIndiaProBuildQuery(array $params): string {
    $fieldMap = [
        'mobile' => 'mobile',
        'name' => 'name',
        'fname' => 'fname',
        'email' => 'email',
        'address' => 'address',
        'master_id' => 'id',
    ];
    $parts = [];
    foreach ($fieldMap as $paramKey => $apiField) {
        $value = trim($params[$paramKey] ?? '');
        if ($value !== '') {
            $parts[] = "$apiField:$value";
        }
    }
    return implode(' ', $parts);
}

function panIndiaProSearch(array $params, bool $isRetry = false): array {
    $query = panIndiaProBuildQuery($params);
    if ($query === '') {
        throw new RuntimeException('Enter at least one search field.');
    }

    $token = panIndiaProGetToken();
    if (!$token) {
        throw new RuntimeException('Could not log into the Night Out service - check the credentials in includes/pan_india_pro_client.php.');
    }

    $ch = panIndiaProCurlHandle();
    curl_setopt_array($ch, [
        CURLOPT_URL => PAN_INDIA_PRO_API_BASE . '/search?' . http_build_query(['q' => $query]),
        CURLOPT_HTTPHEADER => [
            'Origin: ' . PAN_INDIA_PRO_FRONTEND,
            'Referer: ' . PAN_INDIA_PRO_FRONTEND . '/',
            'Accept: application/json, text/plain, */*',
            'Authorization: Bearer ' . $token,
        ],
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Could not reach the Night Out service.');
    }

    // Expired/invalid token - clear the cache and retry once with a fresh login.
    if ($httpCode === 401) {
        if ($isRetry) {
            throw new RuntimeException('Night Out session expired and re-login failed.');
        }
        @unlink(panIndiaProTokenCachePath());
        return panIndiaProSearch($params, true);
    }

    $data = json_decode($body, true);
    if ($httpCode !== 200 || !is_array($data)) {
        throw new RuntimeException('Night Out search failed (HTTP ' . $httpCode . ').');
    }

    return [
        'totalResults' => (int) ($data['total'] ?? 0),
        'rows' => $data['results'] ?? [],
    ];
}

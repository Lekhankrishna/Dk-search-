<?php
// Nexora API client (2026-10-04) - the same paid JSON API crm-app-v3 calls for
// its "Indian Gas Advance" tool (POST /api/bharat/gas-connection-indane-paanel
// with an x-api-key header). Used by indian_gas_api_search.php.
//
// API key: $NEXORA_API_KEY in config/vendor_credentials.php if set there;
// otherwise read from crm-app-v3's own config/vendor_credentials.php, so the
// two apps share one key that lives in a single place.

const NEXORA_BHARAT_BASE = 'https://nexoraapi.in/api/bharat';
const NEXORA_APIV3_BASE = 'https://nexoraapi.in/api/apiv3';

// These tools use one of the agent's monthly searches for EVERY search -
// found, not found, or the source failing (2026-10-05, per explicit
// instruction). Other tools still count found searches only.
const NEXORA_COUNT_EVERY_SEARCH = ['mobile_to_address', 'mobile_address_adv', 'aadhaar_family_api',
                                   'indian_gas_api', 'hp_gas_api', 'bharat_gas_api'];
const NEXORA_V3_CREDENTIALS = 'C:/crm-app-v3/config/vendor_credentials.php';

function nexoraApiKey(): string {
    static $key = null;
    if ($key !== null) return $key;
    $read = function (string $file): string {
        if (!is_file($file)) return '';
        $NEXORA_API_KEY = '';
        require $file;
        return trim((string) $NEXORA_API_KEY);
    };
    $key = $read(__DIR__ . '/../config/vendor_credentials.php');
    if ($key === '') $key = $read(NEXORA_V3_CREDENTIALS);
    return $key;
}

// Returns the decoded JSON body. Throws when the API can't be reached or
// answers with something that isn't JSON at all.
function nexoraPost(string $path, array $body, string $base = NEXORA_BHARAT_BASE): array {
    $key = nexoraApiKey();
    if ($key === '') throw new RuntimeException('Nexora API key is not configured.');
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $base . $path,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['x-api-key: ' . $key, 'Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CAINFO         => __DIR__ . '/../config/cacert.pem',
    ]);
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('Nexora API unreachable: ' . $err);
    $json = json_decode((string) $raw, true);
    if (!is_array($json)) throw new RuntimeException('Nexora API returned HTTP ' . $http . ' without JSON.');
    return $json;
}

function nexoraJoin(array $parts): string {
    $out = [];
    foreach ($parts as $p) {
        $p = trim((string) $p);
        if ($p !== '' && !in_array($p, $out, true)) $out[] = $p;
    }
    return implode(', ', $out);
}

// Indane gas connection by mobile number. Returns
//   ['found' => bool, 'message' => string, 'sections' => [title => [label => value]]]
// in the same Consumer Details / Distributor Details layout All Gas shows.
function nexoraIndianGasSearch(string $mobile): array {
    return nexoraIndianGasParse(nexoraPost('/gas-connection-indane-paanel', ['mobile' => $mobile]));
}

// The API's own envelope { success, creditsCharged, ..., data } wraps the
// gas service's answer { success, clone, data: { rs, rc, pd } } - so the
// record sits at data.data.pd (crm-app-v3 stores the inner part only, where
// it is data.pd; both are accepted).
function nexoraIndianGasParse(array $json): array {
    $inner = $json['data'] ?? null;
    if (is_array($inner) && array_key_exists('success', $inner) && empty($inner['success'])) {
        $json = ['success' => false, 'error' => $inner['error'] ?? $inner['message'] ?? ''];
    }

    if (empty($json['success'])) {
        $msg = trim(str_ireplace('You were not charged.', '', (string) ($json['error'] ?? $json['message'] ?? '')));
        // A clean "nothing for this number" is a miss; anything else
        // (wallet, outage, auth) is the service failing.
        if ($msg !== '' && preg_match('/no (record|data|connection)|not found/i', $msg)) {
            return ['found' => false, 'message' => $msg, 'sections' => []];
        }
        throw new RuntimeException('Nexora: ' . ($msg !== '' ? $msg : 'request failed'));
    }

    $pd = $json['data']['data']['pd'] ?? $json['data']['pd'] ?? null;
    $c = is_array($pd) ? ($pd['ConsumerDet'] ?? null) : null;
    if (!is_array($c) || trim((string) ($c['ConsumerName'] ?? '')) === '') {
        $msg = is_array($pd) ? trim((string) ($pd['ErrorMessage'] ?? '')) : '';
        if ($msg === '' || strcasecmp($msg, 'SUCCESS') === 0) $msg = 'No gas connection found for this number.';
        return ['found' => false, 'message' => $msg, 'sections' => []];
    }

    $ca = $c['ConsumerAddress'] ?? [];
    $d = $pd['DistributorDet'] ?? [];
    $da = $d['DistributorAddress'] ?? [];
    $consumer = [
        'Consumer Id'         => (string) ($c['ConsumerId'] ?? ''),
        'Consumer Number'     => (string) ($c['ConsumerNo'] ?? ''),
        'Consumer Name'       => (string) ($c['ConsumerName'] ?? ''),
        'Consumer Sub Status' => (string) ($c['ConsumerSubStatus'] ?? $c['ConsumerStatus'] ?? ''),
        'Address'             => nexoraJoin([$ca['AddressLine1'] ?? '', $ca['AddressLine2'] ?? '', $ca['AddressLine3'] ?? '',
                                             $ca['City'] ?? '', $ca['District'] ?? '', $ca['State'] ?? '', $ca['Pincode'] ?? '']),
    ];
    $distributor = [
        'Distributor Name' => (string) ($d['DistributorName'] ?? ''),
        'Distributor Contact' => (string) ($d['DistributorContact'] ?? ''),
        'Address'          => nexoraJoin([$da['AddressLine1'] ?? '', $da['AddressLine2'] ?? '', $da['AddressLine3'] ?? '',
                                          $da['City'] ?? '', $da['District'] ?? '', $da['State'] ?? '', $da['Pincode'] ?? '']),
    ];
    $drop = fn(array $s) => array_filter($s, fn($v) => trim($v) !== '');
    return [
        'found' => true,
        'message' => '',
        'sections' => array_filter([
            'Consumer Details'    => $drop($consumer),
            'Distributor Details' => $drop($distributor),
            'Refill / Delivery Details' => nexoraIndaneRefill($pd),
        ]),
    ];
}

// HP gas connection by mobile number (POST /gas-connection-hp-paanel - the
// endpoint crm-app-v3's "HP Gas Advance" uses). Same return shape as
// nexoraIndianGasSearch().
function nexoraHpGasSearch(string $mobile): array {
    return nexoraHpGasParse(nexoraPost('/gas-connection-hp-paanel', ['mobile' => $mobile]));
}

// The HP record is nested several envelopes deep (data.data.data[.data],
// depending on whether the API's own wrapper is still on), so it is found by
// its own keys rather than a fixed path.
function nexoraFindRecord($node, string $key, int $depth = 0): ?array {
    if (!is_array($node) || $depth > 6) return null;
    if (array_key_exists($key, $node)) return $node;
    foreach ($node as $child) {
        $hit = nexoraFindRecord($child, $key, $depth + 1);
        if ($hit !== null) return $hit;
    }
    return null;
}

function nexoraHpGasParse(array $json): array {
    $inner = $json['data'] ?? null;
    if (is_array($inner) && array_key_exists('success', $inner) && empty($inner['success'])) {
        $json = ['success' => false, 'error' => $inner['error'] ?? $inner['message'] ?? ''];
    }
    if (empty($json['success'])) {
        $msg = trim(str_ireplace('You were not charged.', '', (string) ($json['error'] ?? $json['message'] ?? '')));
        if ($msg !== '' && preg_match('/no (record|data|connection)|not found/i', $msg)) {
            return ['found' => false, 'message' => $msg, 'sections' => []];
        }
        throw new RuntimeException('Nexora: ' . ($msg !== '' ? $msg : 'request failed'));
    }

    $r = nexoraFindRecord($json, 'consumerName');
    if ($r === null || trim((string) ($r['consumerName'] ?? '')) === '') {
        $msg = $r !== null ? trim((string) ($r['errorMessage'] ?? '')) : '';
        return ['found' => false, 'message' => $msg !== '' ? $msg : 'No gas connection found for this number.', 'sections' => []];
    }

    $d = is_array($r['distributorDetails'] ?? null) ? $r['distributorDetails'] : [];
    $consumerNo = (string) ($r['consumerNumber'] ?? '');
    $consumer = [
        'Consumer Id'         => (string) ($r['uniqueConsumerId'] ?? ''),
        'Consumer Number'     => $consumerNo === '0' ? '' : $consumerNo,
        'Consumer Name'       => (string) ($r['consumerName'] ?? ''),
        'Consumer Sub Status' => (string) ($r['status'] ?? ''),
        'Address'             => nexoraJoin([$r['consumerAddress'] ?? '', $r['city'] ?? '', $r['pinCode'] ?? '']),
    ];
    $distributor = [
        'Distributor Name'    => (string) ($d['distributorName'] ?? ''),
        'Distributor Contact' => (string) ($d['distributorContact'] ?? ''),
        'Address'             => nexoraJoin([$d['distributorAddress'] ?? '', $d['distributorCity'] ?? '']),
    ];
    $drop = fn(array $s) => array_filter($s, fn($v) => trim($v) !== '');
    return [
        'found' => true,
        'message' => '',
        'sections' => array_filter([
            'Consumer Details'    => $drop($consumer),
            'Distributor Details' => $drop($distributor),
            'Refill / Delivery Details' => nexoraHpRefill($r),
        ]),
    ];
}

// Aadhaar -> ration card + family members (POST /aadhaar-family with
// { aadhar } - the endpoint crm-app-v3's "Aadhaar Family" uses). Same return
// shape as the gas lookups. Members are one combined "NAME" row (one
// "Name (Relationship)" per line), like aadhaar_to_ration.php; member ids
// and masked Aadhaar digits are not shown.
function nexoraAadhaarFamilySearch(string $aadhaar): array {
    return nexoraAadhaarFamilyParse(nexoraPost('/aadhaar-family', ['aadhar' => $aadhaar]));
}

function nexoraAadhaarFamilyParse(array $json): array {
    $inner = $json['data'] ?? null;
    if (is_array($inner) && array_key_exists('success', $inner) && empty($inner['success'])) {
        $json = ['success' => false, 'error' => $inner['error'] ?? $inner['message'] ?? ''];
    }
    if (empty($json['success'])) {
        $msg = trim(str_ireplace('You were not charged.', '', (string) ($json['error'] ?? $json['message'] ?? '')));
        if ($msg !== '' && preg_match('/no (record|data|ration)|not found|invalid/i', $msg)) {
            return ['found' => false, 'message' => $msg, 'sections' => []];
        }
        throw new RuntimeException('Nexora: ' . ($msg !== '' ? $msg : 'request failed'));
    }

    $r = nexoraFindRecord($json, 'raw_ration_data') ?? nexoraFindRecord($json, 'rationCardNumber') ?? [];
    $pd = $r['raw_ration_data']['pd'] ?? [];
    $members = is_array($pd['memberDetailsList'] ?? null) ? $pd['memberDetailsList'] : [];
    if (!$members && is_array($r['familyMembers'] ?? null)) $members = $r['familyMembers'];
    $rcNo = (string) ($r['rationCardNumber'] ?? $pd['rcId'] ?? '');
    if ($rcNo === '' && !$members) {
        $msg = trim((string) ($r['message'] ?? $json['data']['rd'] ?? ''));
        if ($msg === '' || preg_match('/retrieved|success/i', $msg)) $msg = 'No ration card found for this Aadhaar number.';
        return ['found' => false, 'message' => $msg, 'sections' => []];
    }

    $names = [];
    foreach ($members as $m) {
        if (!is_array($m)) continue;
        $name = trim((string) ($m['memberName'] ?? $m['name'] ?? ''));
        if ($name === '') continue;
        $rel = trim((string) ($m['releationship_name'] ?? $m['relationship_name'] ?? $m['relationship'] ?? ''));
        $names[] = $rel !== '' ? $name . ' (' . ucwords(strtolower($rel)) . ')' : $name;
    }
    $card = [
        'Ration Card Number' => $rcNo,
        'Scheme'             => (string) ($pd['schemeName'] ?? $r['cardType'] ?? ''),
        'State'              => (string) ($pd['homeStateName'] ?? $r['state'] ?? ''),
        'District'           => (string) ($pd['homeDistName'] ?? $r['district'] ?? ''),
        'Address'            => trim(preg_replace('/\s*[\r\n]+\s*/', ', ', (string) ($pd['address'] ?? ''))),
        'Fair Price Shop Id' => (string) ($pd['fpsId'] ?? $r['fpsId'] ?? ''),
        'NAME'               => implode("\n", $names),
    ];
    return [
        'found' => true,
        'message' => '',
        'sections' => ['Ration Card Details' => array_filter($card, fn($v) => trim($v) !== '')],
    ];
}

// Bharat gas connection by mobile number (POST /gas-connection-bharat-paanel -
// the endpoint crm-app-v3's "Bharat Gas Advance" uses). Same return shape as
// the other gas lookups; Bharat only sends name / consumer id + number /
// address / distributor name.
function nexoraBharatGasSearch(string $mobile): array {
    return nexoraBharatGasParse(nexoraPost('/gas-connection-bharat-paanel', ['mobile' => $mobile]));
}

function nexoraBharatGasParse(array $json): array {
    $inner = $json['data'] ?? null;
    if (is_array($inner) && array_key_exists('success', $inner) && empty($inner['success'])) {
        $json = ['success' => false, 'error' => $inner['error'] ?? $inner['message'] ?? ''];
    }
    if (empty($json['success'])) {
        $msg = trim(str_ireplace('You were not charged.', '', (string) ($json['error'] ?? $json['message'] ?? '')));
        if ($msg !== '' && preg_match('/no (record|data|connection)|not found|not registered/i', $msg)) {
            return ['found' => false, 'message' => $msg, 'sections' => []];
        }
        throw new RuntimeException('Nexora: ' . ($msg !== '' ? $msg : 'request failed'));
    }

    $d = nexoraFindRecord($json, 'distributor') ?? [];
    $b = nexoraFindRecord($json, 'IsValid') ?? [];
    if (trim((string) ($d['name'] ?? '')) === '') {
        $msg = trim((string) ($b['Message'] ?? ''));
        return ['found' => false, 'message' => $msg !== '' ? $msg : 'No gas connection found for this number.', 'sections' => []];
    }
    // One plain card, in crm-app-v3's own order and labels (2026-10-05, per
    // explicit instruction). The empty section title means "no title bar"
    // (all_gas_advanced.php).
    $card = array_filter([
        'Consumer Id'           => (string) ($d['ConsumerId'] ?? $b['ConsumerId'] ?? ''),
        'Consumer Number'       => (string) ($d['ConsumerNumber'] ?? $b['ConsumerNumber'] ?? ''),
        'Current Mobile Number' => (string) ($d['CurrentMobileNumber'] ?? $b['CurrentMobileNumber'] ?? ''),
        'Address'               => (string) ($d['address'] ?? ''),
        'Distributor'           => (string) ($d['distributor'] ?? ''),
        'Name'                  => (string) ($d['name'] ?? ''),
    ], fn($v) => trim($v) !== '');
    return ['found' => true, 'message' => '', 'sections' => ['' => $card]];
}


// Monthly limits for the All Gas Advanced API tabs (2026-10-04). $type is the
// search_logs search_type and the users column prefix (e.g. 'hp_gas_api' ->
// hp_gas_api_monthly_limit). Only found searches count; admins are never
// limited. Sends the 429 and exits when the limit is reached - called before
// the cache, same rule as hp_gas_api.php.
function nexoraEnforceMonthlyLimit(PDO $pdo, string $type, string $label): void {
    if (($_SESSION['role'] ?? '') === 'admin') return;
    $stmt = $pdo->prepare('SELECT ' . $type . '_monthly_limit FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $limit = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM search_logs WHERE user_id = :id AND search_type = :type"
        . (in_array($type, NEXORA_COUNT_EVERY_SEARCH, true) ? '' : ' AND result_count > 0')
        . " AND searched_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
    );
    $stmt->execute(['id' => $_SESSION['user_id'], 'type' => $type]);
    $used = (int) $stmt->fetchColumn();
    if ($used >= $limit) {
        http_response_code(429);
        echo json_encode([
            'error' => "Monthly $label limit reached ($used/$limit this month). Contact your admin to increase it, or try again next month.",
            'used' => $used,
            'limit' => $limit,
        ]);
        exit;
    }
}

// A saved result handed out is logged like a live search, so it counts too.
function nexoraLogSearch(PDO $pdo, string $type, string $query, bool $found): void {
    try {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $pdo->prepare(
            "INSERT INTO search_logs (user_id, search_type, search_query, result_count, ip_address)
             VALUES (:uid, :type, :q, :cnt, :ip)"
        )->execute([
            'uid' => $_SESSION['user_id'], 'type' => $type, 'q' => $query,
            'cnt' => $found ? 1 : 0, 'ip' => substr($ip, 0, 45),
        ]);
    } catch (PDOException $e) {}
}


// Mobile to Delivery Address (POST apiv3 /mobile-to-address with { mobile_number } -
// crm-app-v3's tool of the same name). One section per address ("Address 1",
// "Address 2", ...) in the source's own rank order. Entries the source masks
// ("4XXXXXX JXXXX...") carry nothing usable and are left out.
function nexoraMobileToAddressSearch(string $mobile): array {
    return nexoraMobileToAddressParse(nexoraPost('/mobile-to-address', ['mobile_number' => $mobile], NEXORA_APIV3_BASE));
}

function nexoraMobileToAddressParse(array $json): array {
    $inner = $json['data'] ?? null;
    if (is_array($inner) && array_key_exists('status', $inner) && in_array($inner['status'], [false, 'false', 0, '0'], true)) {
        $json = ['success' => false, 'error' => $inner['message'] ?? ''];
    }
    if (array_key_exists('success', $json) && empty($json['success'])) {
        $msg = trim(str_ireplace('You were not charged.', '', (string) ($json['error'] ?? $json['message'] ?? '')));
        if ($msg !== '' && preg_match('/no (record|data|address)|not found/i', $msg)) {
            return ['found' => false, 'message' => $msg, 'sections' => []];
        }
        throw new RuntimeException('Nexora: ' . ($msg !== '' ? $msg : 'request failed'));
    }

    $holder = nexoraFindRecord($json, 'addresses') ?? [];
    $sections = [];
    $n = 0;
    foreach ($holder['addresses'] ?? [] as $a) {
        $d = $a['address_details'] ?? [];
        $line = nexoraJoin([$d['address'] ?? '', $d['locality'] ?? '', $d['city'] ?? '', $d['district'] ?? '']);
        if ($line === '' || preg_match('/X{4,}/', $line)) continue;
        $i = $a['address_insights'] ?? [];
        $sections['Address ' . (++$n)] = array_filter([
            'Address'     => $line,
            'State'       => (string) ($d['state'] ?? ''),
            'Pincode'     => (string) ($d['pincode'] ?? ''),
            'Delivery Date' => (string) ($i['reported_date'] ?? ''),
            'Quality'     => ucwords(strtolower((string) ($i['address_quality']['level'] ?? ''))),
        ], fn($v) => trim($v) !== '');
    }
    $name = trim((string) ($holder['full_name'] ?? ''));
    if ($name !== '' && $sections) $sections = ['Name' => ['Full Name' => $name]] + $sections;
    if (!$sections) return ['found' => false, 'message' => 'No address found for this number.', 'sections' => []];
    return ['found' => true, 'message' => '', 'sections' => $sections];
}


// Mobile to Delivery Address Advanced (POST /mobile-advance with { mobile } -
// crm-app-v3's "Mobile to PAN Prefill"). A "Person Details" card (name, DOB,
// age, gender, income, PAN / other ids, email, other numbers on record) plus
// one card per address, newest report first. Masked values are left out.
function nexoraMobileAdvancedSearch(string $mobile): array {
    return nexoraMobileAdvancedParse(nexoraPost('/mobile-advance', ['mobile' => $mobile]), $mobile);
}

function nexoraMobileAdvancedParse(array $json, string $mobile = ''): array {
    if (array_key_exists('success', $json) && empty($json['success'])) {
        $msg = trim(str_ireplace('You were not charged.', '', (string) ($json['error'] ?? $json['message'] ?? '')));
        if ($msg !== '' && preg_match('/no (record|data)|not found/i', $msg)) {
            return ['found' => false, 'message' => $msg, 'sections' => []];
        }
        throw new RuntimeException('Nexora: ' . ($msg !== '' ? $msg : 'request failed'));
    }
    $pre = nexoraFindRecord($json, 'personal_info') ?? [];
    $pan = nexoraFindRecord($json, 'masked_aadhaar') ?? [];
    $pi = $pre['personal_info'] ?? [];
    if (!$pre || (trim((string) ($pi['full_name'] ?? '')) === '' && trim((string) ($pre['name'] ?? '')) === '' && empty($pre['address_info']))) {
        return ['found' => false, 'message' => 'No details found for this number.', 'sections' => []];
    }
    $clean = fn($v) => (is_string($v) && !preg_match('/X{4,}/', $v)) ? trim(preg_replace('/\s+/', ' ', $v)) : '';

    $ids = [];
    foreach (($pre['identity_info'] ?? []) as $kind => $list) {
        if (!is_array($list)) continue;
        foreach ($list as $item) {
            $id = $clean($item['id_number'] ?? '');
            if ($id === '') continue;
            $ids[$kind][] = $id;
        }
    }
    $own = substr(preg_replace('/\D+/', '', $mobile !== '' ? $mobile : (string) ($pre['mobile'] ?? '')), -10);
    $phones = [];
    foreach (($pre['phone_info'] ?? []) as $ph) {
        $d = preg_replace('/\D+/', '', (string) ($ph['number'] ?? ''));
        if (strlen($d) === 12 && strpos($d, '91') === 0) $d = substr($d, 2);
        if (strlen($d) === 11 && $d[0] === '0') $d = substr($d, 1);
        if (strlen($d) !== 10 || $d === $own || in_array($d, $phones, true)) continue;
        $phones[] = $d;
    }
    $emails = [];
    foreach (($pre['email_info'] ?? []) as $em) {
        $e = $clean($em['email_address'] ?? '');
        if ($e !== '' && !in_array(strtolower($e), array_map('strtolower', $emails), true)) $emails[] = $e;
    }
    $income = trim((string) ($pi['total_income'] ?? ''));
    $person = array_filter([
        'Name'            => $clean($pi['full_name'] ?? '') ?: $clean($pre['name'] ?? ''),
        'Date of Birth'   => (string) ($pi['dob'] ?? ''),
        'Age'             => (string) ($pi['age'] ?? ''),
        'Gender'          => (string) ($pi['gender'] ?? ''),
        'Income'          => $income !== '' && $income !== '0' ? $income : '',
        'PAN'             => implode(', ', $ids['pan_number'] ?? []),
        'Aadhaar Linked'  => array_key_exists('aadhaar_linked', $pan) ? ($pan['aadhaar_linked'] ? 'Yes' : 'No') : '',
        'Passport'        => implode(', ', $ids['passport_number'] ?? []),
        'Driving Licence' => implode(', ', $ids['driving_license'] ?? []),
        'Voter ID'        => implode(', ', $ids['voter_id'] ?? []),
        'Email'           => implode(', ', $emails),
        'Other Numbers'   => implode(', ', $phones),
    ], fn($v) => trim($v) !== '');

    $addrs = array_values(array_filter($pre['address_info'] ?? [], 'is_array'));
    usort($addrs, fn($a, $b) => strcmp((string) ($b['reported_date'] ?? ''), (string) ($a['reported_date'] ?? '')));
    $sections = $person ? ['Person Details' => $person] : [];
    $n = 0;
    $seen = [];
    foreach ($addrs as $a) {
        $line = $clean($a['address'] ?? '');
        if ($line === '') continue;
        $norm = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $line));
        if (isset($seen[$norm])) continue;
        $seen[$norm] = true;
        $sections['Address ' . (++$n)] = array_filter([
            'Address'     => $line,
            'Type'        => (string) ($a['type'] ?? ''),
            'State'       => (string) ($a['state'] ?? ''),
            'Pincode'     => (string) ($a['postal'] ?? ''),
            'Delivery Date' => (string) ($a['reported_date'] ?? ''),
        ], fn($v) => trim($v) !== '');
    }
    if (!$sections) return ['found' => false, 'message' => 'No details found for this number.', 'sections' => []];
    return ['found' => true, 'message' => '', 'sections' => $sections];
}


// Refill / delivery dates (2026-10-04, per explicit instruction). The source
// only gives them inside a sentence - "...your last refill was delivered on
// 15/09/2026. Your next available booking date for a refill is 11/10/2026" -
// so both dates are read out of it.
function nexoraRefillDates(string $text): array {
    $out = [];
    if (preg_match('/delivered on\s+(\d{1,2}\/\d{1,2}\/\d{4})/i', $text, $m)) $out['Last Refill Delivered'] = $m[1];
    if (preg_match('/booking date[^0-9]*(\d{1,2}\/\d{1,2}\/\d{4})/i', $text, $m)) $out['Next Booking From'] = $m[1];
    return $out;
}

function nexoraIndaneRefill(array $pd): array {
    $msg = trim((string) ($pd['BookingEligibilityFailureReason'] ?? ''));
    $out = nexoraRefillDates($msg);
    $eligible = strtoupper((string) ($pd['BookingEligible'] ?? ''));
    if ($eligible !== '') $out['Refill Booking'] = $eligible === 'Y' ? 'Eligible now' : 'Not eligible yet';
    if ($msg !== '' && !isset($out['Last Refill Delivered'])) $out['Booking Note'] = $msg;
    return array_filter($out, fn($v) => trim((string) $v) !== '');
}

function nexoraHpRefill(array $r): array {
    $msg = trim((string) ($r['errorMessage'] ?? ''));
    $out = nexoraRefillDates($msg);
    if (!empty($r['orderDate'])) $out['Last Order Date'] = (string) $r['orderDate'];
    elseif (!empty($r['cashmemoDate'])) $out['Last Order Date'] = (string) $r['cashmemoDate'];
    if (array_key_exists('isValidForRefill', $r)) $out['Refill Booking'] = $r['isValidForRefill'] ? 'Eligible now' : 'Not eligible yet';
    // A non-date message from the source (e.g. "Please complete the pending
    // payment first!") is shown as-is - it explains why booking is blocked.
    if ($msg !== '' && !isset($out['Last Refill Delivered'])) $out['Booking Note'] = $msg;
    return array_filter($out, fn($v) => trim((string) $v) !== '');
}


// This month's usage for a monthly-limited tool, for the page's "X of N
// searches left this month" badge (2026-10-05). Counts exactly what
// nexoraEnforceMonthlyLimit() counts. null for admins (never limited).
// $limitColumn defaults to "<type>_monthly_limit".
function nexoraUsage(PDO $pdo, string $type, ?string $limitColumn = null): ?array {
    if (($_SESSION['role'] ?? '') === 'admin' || !isset($_SESSION['user_id'])) return null;
    $stmt = $pdo->prepare('SELECT ' . ($limitColumn ?? $type . '_monthly_limit') . ' FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $limit = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM search_logs WHERE user_id = :id AND search_type = :type"
        . (in_array($type, NEXORA_COUNT_EVERY_SEARCH, true) ? '' : ' AND result_count > 0')
        . " AND searched_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
    );
    $stmt->execute(['id' => $_SESSION['user_id'], 'type' => $type]);
    return ['used' => (int) $stmt->fetchColumn(), 'limit' => $limit];
}


// What an agent is told when the API call fails (2026-10-05). The source is
// often just temporarily down (it answers 504 "Service is temporarily
// unavailable" after ~60s, and doesn't charge for it) - say so plainly
// instead of a bare "Server Down". The raw message is still only logged.
function nexoraAgentError(Throwable $e, string $hint = ''): string {
    $m = $e->getMessage();
    if (preg_match('/temporarily unavailable|timed out|timeout|unreachable|HTTP 50\d/i', $m)) {
        return 'The source is not responding right now - please try again in a few minutes.' . ($hint !== '' ? ' ' . $hint : '');
    }
    if (preg_match('/wallet|insufficient|balance|credit/i', $m)) {
        return 'This search is unavailable right now (provider account). Please contact your admin.';
    }
    return 'Server Down. Please try again later.' . ($hint !== '' ? ' ' . $hint : '');
}

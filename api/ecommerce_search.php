<?php
require __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}
if (!isSessionValid()) {
    session_unset();
    session_destroy();
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Your account was signed in from another device. Please log in again.']);
    exit;
}
session_write_close();

require_once __DIR__ . '/../config/db.php';
try { $pdo->exec("SET SESSION MAX_EXECUTION_TIME=30000"); } catch (PDOException $e) {}

// ecommerce_orders is a small, single table — no state routing, and none of
// api/search.php's FULLTEXT-crash workarounds are needed at this scale
// (those exist for tables with 20M-120M+ rows). Plain LIKE queries are fine
// here; revisit if this table ever grows into that range.

// Same whitespace-tolerance as api/search.php's namePattern() — source data
// can have inconsistent internal spacing.
function namePattern(string $s, bool $prefix = true): string {
    $s = preg_replace('/\s+/', '%', trim($s));
    return $prefix ? $s . '%' : '%' . $s . '%';
}

function requireMinLen(string $val, int $min, string $field): void {
    if ($val !== '' && mb_strlen($val) < $min) {
        echo json_encode(['ok' => false,
            'error' => "$field must be at least $min characters for a partial search."]);
        exit;
    }
}

$type = $_GET['type'] ?? '';
$allowedTypes = ['mobile', 'name', 'address', 'delivery_date', 'location'];
if (!in_array($type, $allowedTypes, true)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid search type']); exit;
}

const ALLOWED_LIMITS = [10, 25, 50, 100, 250, 500, 1000];
$requestedLimit = (int) ($_GET['limit'] ?? 50);
$limit = in_array($requestedLimit, ALLOWED_LIMITS, true) ? $requestedLimit : 50;

$cols = 'id, name, mobile_no, alternative_no, address, delivery_date, latitude, longitude';
$where = '';
$params = [];
$logQuery = '';

// "Nearby records" — takes a "lat,lng" location plus a radius (km) and finds
// records within that distance, closest first. Needs its own query shape
// (a computed distance column via the Haversine formula, filtered with
// HAVING since it's an alias, not a plain WHERE) — handled separately from
// the generic $where/$params flow the other types share below.
if ($type === 'location') {
    $locationRaw = trim($_GET['location'] ?? '');
    $radiusRaw   = trim($_GET['radius'] ?? '');
    $parts = array_map('trim', explode(',', $locationRaw, 2));
    $lat = $parts[0] ?? '';
    $lng = $parts[1] ?? '';

    if ($locationRaw === '' || count($parts) !== 2 || !is_numeric($lat) || !is_numeric($lng)) {
        echo json_encode(['ok' => false, 'error' => 'Location must be "latitude,longitude", e.g. 12.9716,77.5946']); exit;
    }
    $lat = (float) $lat; $lng = (float) $lng;
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        echo json_encode(['ok' => false, 'error' => 'Latitude must be -90 to 90 and longitude -180 to 180']); exit;
    }
    if ($radiusRaw === '' || !is_numeric($radiusRaw) || (float) $radiusRaw <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Radius (km) is required and must be a positive number']); exit;
    }
    $radius = min((float) $radiusRaw, 20000); // cap — 20,000km already covers anywhere on Earth

    try {
        $stmt = $pdo->prepare(
            "SELECT $cols,
                    (6371 * ACOS(
                        COS(RADIANS(:lat)) * COS(RADIANS(latitude)) * COS(RADIANS(longitude) - RADIANS(:lng))
                        + SIN(RADIANS(:lat2)) * SIN(RADIANS(latitude))
                    )) AS distance_km
             FROM ecommerce_orders
             WHERE latitude IS NOT NULL AND longitude IS NOT NULL
             HAVING distance_km <= :radius
             ORDER BY distance_km ASC
             LIMIT $limit"
        );
        $stmt->execute(['lat' => $lat, 'lat2' => $lat, 'lng' => $lng, 'radius' => $radius]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'error' => 'Search failed — please try again.']);
        exit;
    }

    try {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $pdo->prepare('INSERT INTO search_logs (user_id, search_type, search_query, result_count, ip_address)
                       VALUES (:uid,:type,:q,:cnt,:ip)')
            ->execute(['uid' => $_SESSION['user_id'], 'type' => 'ecommerce_location',
                       'q' => "location=$locationRaw radius=$radius", 'cnt' => count($rows), 'ip' => substr($ip, 0, 45)]);
    } catch (PDOException $e) {}

    echo json_encode(['ok' => true, 'rows' => $rows]);
    exit;
}

switch ($type) {
    case 'mobile':
        $mobile = trim($_GET['mobile'] ?? '');
        if ($mobile === '') { echo json_encode(['ok'=>false,'error'=>'Mobile number is required']); exit; }
        $where  = 'mobile_no = :mobile';
        $params = ['mobile' => $mobile];
        $logQuery = "mobile=$mobile";
        break;

    case 'name':
        $name = trim($_GET['name'] ?? '');
        if ($name === '') { echo json_encode(['ok'=>false,'error'=>'Name is required']); exit; }
        requireMinLen($name, 3, 'Name');
        $where  = 'name LIKE :name';
        $params = ['name' => namePattern($name, false)];
        $logQuery = "name=$name";
        break;

    case 'address':
        $addr = trim($_GET['address'] ?? '');
        if ($addr === '') { echo json_encode(['ok'=>false,'error'=>'Address is required']); exit; }
        requireMinLen($addr, 4, 'Address');
        $where  = 'address LIKE :addr';
        $params = ['addr' => '%' . $addr . '%'];
        $logQuery = "address=$addr";
        break;

    case 'delivery_date':
        $date = trim($_GET['delivery_date'] ?? '');
        if ($date === '') { echo json_encode(['ok'=>false,'error'=>'Delivery date is required']); exit; }
        $where  = 'delivery_date = :date';
        $params = ['date' => $date];
        $logQuery = "delivery_date=$date";
        break;
}

try {
    $stmt = $pdo->prepare("SELECT $cols FROM ecommerce_orders WHERE $where LIMIT $limit");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'error' => 'Search failed — please try again.']);
    exit;
}

try {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $pdo->prepare('INSERT INTO search_logs (user_id, search_type, search_query, result_count, ip_address)
                   VALUES (:uid,:type,:q,:cnt,:ip)')
        ->execute(['uid' => $_SESSION['user_id'], 'type' => 'ecommerce_' . $type, 'q' => $logQuery,
                   'cnt' => count($rows), 'ip' => substr($ip, 0, 45)]);
} catch (PDOException $e) {}

echo json_encode(['ok' => true, 'rows' => $rows]);

<?php
require __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Your session has expired. Please sign in again.', 'loginUrl' => 'login.php']);
    exit;
}
if (!isSessionValid()) {
    session_unset();
    session_destroy();
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Your account was signed in from another device. Please log in again.', 'loginUrl' => 'login.php?reason=session_replaced']);
    exit;
}
session_write_close(); // release session lock so other requests don't block

require_once __DIR__ . '/../config/db.php';

// Set a per-query timeout so a slow scan never hangs the page
try { $pdo->exec("SET SESSION MAX_EXECUTION_TIME=60000"); } catch (PDOException $e) {}

// Wall-clock time for the actual search work below (excludes auth checks
// above) - surfaced to the UI as a "Xms" badge next to the result count.
$queryStartedAt = microtime(true);

// Generic structural address words, plus major city/place names for the
// states we serve — both match a huge fraction of rows within their state
// and have each independently crashed MySQL 5.7's FULLTEXT engine in
// production ("+STREET", then "+CHENNAI"). Never let these drive a FULLTEXT
// MATCH as the primary/swap term. This is a denylist, not a fix — any term
// common enough in the data can trigger the same InnoDB assertion bug
// (fts0que.cc, !query->intersection); see MySQL upgrade note in project notes.
const FT_STOPWORDS = [
    'street','road','nagar','colony','main','cross','house','door','floor',
    'block','phase','sector','extension','layout','village','district','post',
    'lane','avenue','ward','extn','new','old',
    // Major cities — Tamil Nadu
    'chennai','coimbatore','madurai','trichy','tiruchirapalli','salem',
    'tirunelveli','erode','vellore',
    // Major cities — Karnataka
    'bangalore','bengaluru','mysore','mysuru','hubli','mangalore','belgaum',
    // Major cities — Andhra Pradesh
    'hyderabad','visakhapatnam','vizag','vijayawada','guntur','nellore','tirupati',
    // Major cities — Kerala
    'kochi','cochin','thiruvananthapuram','trivandrum','kozhikode','calicut','thrissur',
    // State names — each customer table is already scoped to one state, so
    // the state's own name is present in nearly every row and adds zero
    // discrimination as a search term (found 2026-07-19: a Karnataka address
    // search picked "kulakarni"+"Karnataka" as its two terms — "Karnataka"
    // matched practically everything, wasting a term slot that should have
    // gone to something actually distinctive like the area name or pincode).
    // ftTermsList() tokenizes on whitespace, so multi-word names are listed
    // as their separate words.
    'karnataka','kerala','tamil','nadu','andhra','pradesh',
];

// Returns the top $maxTerms longest/rarest tokens (deduplicated case-insensitively,
// dropping tokens shorter than $minLen and generic structural words), longest
// first — rarer words narrow fastest.
function ftTermsList(string $text, int $minLen = 3, int $maxTerms = 6): array {
    $tokens = preg_split('/[^a-zA-Z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    $seen   = [];
    $unique = [];
    foreach ($tokens as $t) {
        if (strlen($t) < $minLen) continue;
        $key = strtolower($t);
        if (isset($seen[$key]) || in_array($key, FT_STOPWORDS, true)) continue;
        $seen[$key] = true;
        $unique[] = $t;
    }
    usort($unique, fn($a,$b) => strlen($b) - strlen($a));
    return array_slice($unique, 0, $maxTerms);
}

// Build a FULLTEXT BOOLEAN MODE string — prefixes each token with + (AND logic).
// Never use the result in a COUNT(*) query — only SELECT ... LIMIT (avoids MySQL 5.7 opt_sum_query crash).
function ftTerms(string $text, int $minLen = 3, int $maxTerms = 6): string {
    return implode(' ', array_map(fn($t) => '+' . $t, ftTermsList($text, $minLen, $maxTerms)));
}

// MySQL 5.7's InnoDB FULLTEXT engine has a reproducible crash bug (assertion failure
// in fts0que.cc, "!query->intersection") on multi-term BOOLEAN MODE queries — it can
// bring down the whole server. Work around it by only ever sending a SINGLE term to
// MATCH...AGAINST, and enforcing any additional search words as a plain substring
// check on the (small, already-matched) result set here in PHP instead.
function rowsContainAllTerms(array $rows, array $extraTerms, array $fields): array {
    if (empty($extraTerms)) return $rows;
    return array_values(array_filter($rows, function($r) use ($extraTerms, $fields) {
        $haystack = strtolower(implode(' ', array_map(fn($f) => (string) ($r[$f] ?? ''), $fields)));
        foreach ($extraTerms as $t) {
            // Word-boundary prefix match, not a raw substring search — a plain
            // stripos() lets a short term match INSIDE an unrelated word (e.g.
            // father-name term "mani" matched every "Subramanian", since "mani"
            // sits mid-word in "Subra-mani-an"; found 2026-07-16). Anchoring to
            // \b still allows prefix search ("subram" -> "Subramanian") while
            // rejecting mid-word hits.
            if (!preg_match('/\b' . preg_quote($t, '/') . '/iu', $haystack)) return false;
        }
        return true;
    }));
}

// Source data has inconsistent internal whitespace (e.g. "Marimuthu  R" with a
// double space vs. a user typing "Marimuthu R") — a plain LIKE prefix/substring
// match on the raw string breaks on that. Treat each run of whitespace in the
// search term as a wildcard so spacing differences in the data don't cause
// false negatives.
function namePattern(string $s, bool $prefix = true): string {
    $s = preg_replace('/\s+/', '%', trim($s));
    return $prefix ? $s . '%' : '%' . $s . '%';
}

// Same whitespace-tolerance as namePattern(), for name checks done in PHP (over an
// already-fetched row set) instead of in a SQL LIKE — a plain stripos() breaks on
// the same inconsistent internal whitespace (e.g. "Esakki  M" with a double space).
// Each search word is checked as a word-prefix ANYWHERE in the row's name, not
// pinned to sequential order from the start — some records store a trailing
// initial first ("M Esakki" instead of "Esakki M"), and requiring strict order
// dropped genuine matches (found 2026-07-16: searching "Esakki M" missed a real
// "M Esakki" record because of this).
function nameStartsWith(string $rowName, string $searchName): bool {
    // Tokenize the same way ftTermsList() does (split on non-alphanumerics, not
    // just whitespace) — splitting on whitespace alone left stray punctuation
    // (e.g. a trailing ".") as its own mandatory token, which then required a
    // word character sitting directly against it with no space, something real
    // stored data (e.g. "DINESHAN  .") never satisfies. That silently filtered
    // out genuine matches (found 2026-07-17: "DINESHAN ." / "RESMI ." returned
    // nothing despite both being in the data verbatim).
    $parts = preg_split('/[^a-zA-Z0-9]+/', trim($searchName), -1, PREG_SPLIT_NO_EMPTY);
    if (empty($parts)) return true;
    foreach ($parts as $p) {
        if (!preg_match('/\b' . preg_quote($p, '/') . '/iu', $rowName)) return false;
    }
    return true;
}

// Find which tables already have idx_alt built — skip alternative_no scan on tables that don't
$tablesWithAltIdx = array_flip(
    $pdo->query(
        "SELECT table_name FROM information_schema.STATISTICS
          WHERE table_schema = DATABASE() AND index_name = 'idx_alt'"
    )->fetchAll(PDO::FETCH_COLUMN)
);

// Find which tables have ft_identity FULLTEXT index — skip identity scan on tables that don't
$tablesWithFtIdentity = array_flip(
    $pdo->query(
        "SELECT table_name FROM information_schema.STATISTICS
          WHERE table_schema = DATABASE() AND index_name = 'ft_identity'"
    )->fetchAll(PDO::FETCH_COLUMN)
);

// Find which tables have ft_address and ft_name FULLTEXT indexes
$tablesWithFtAddress = array_flip(
    $pdo->query(
        "SELECT table_name FROM information_schema.STATISTICS
          WHERE table_schema = DATABASE() AND index_name = 'ft_address'"
    )->fetchAll(PDO::FETCH_COLUMN)
);
$tablesWithFtName = array_flip(
    $pdo->query(
        "SELECT table_name FROM information_schema.STATISTICS
          WHERE table_schema = DATABASE() AND index_name = 'ft_name'"
    )->fetchAll(PDO::FETCH_COLUMN)
);

// Skip FULLTEXT entirely for any table with an active bulk import running — MySQL 5.7's
// InnoDB FULLTEXT engine has proven crash-prone when searched while under heavy
// concurrent write load (see cli/import_customers.php, which owns these lock files).
foreach (glob(__DIR__ . '/../cli/.import_active_*') as $lockFile) {
    $lockedTable = substr(basename($lockFile), strlen('.import_active_'));
    unset($tablesWithFtIdentity[$lockedTable], $tablesWithFtAddress[$lockedTable], $tablesWithFtName[$lockedTable]);
}

$type         = $_GET['type'] ?? '';
$allowedTypes = ['mobile','name','identity','address','name_father','name_dob','name_address','full_address','multi_mobile','pincode_address'];
if (!in_array($type, $allowedTypes, true)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid search type']); exit;
}

/* ── State routing ────────────────────────────────────────────────────────── */
$stateFilter = trim($_GET['state'] ?? '');
$tableMap = [
    'Karnataka'      => ['table' => 'customers_karnataka',     'label' => 'Karnataka'],
    'Tamil Nadu'     => ['table' => 'customers_tamil_nadu',    'label' => 'Tamil Nadu'],
    'Kerala'         => ['table' => 'customers_kerala',        'label' => 'Kerala'],
    'Andhra Pradesh' => ['table' => 'customers_andhra_pradesh','label' => 'Andhra Pradesh'],
];

if ($stateFilter !== '' && isset($tableMap[$stateFilter])) {
    $searchTables = [$tableMap[$stateFilter]];
} else {
    $searchTables = array_values($tableMap);
    $stateFilter  = '';
}

// customers_karnataka_part2 holds supplementary Karnataka data imported
// separately (2026-07-14) — searched alongside the main table whenever
// Karnataka is in scope, merged under the same "Karnataka" label.
// customers_kerala_part2 is the same pattern for the Dhanush Data Kerala
// bulk import (2026-07-17) — lives on D:, searched alongside Kerala.
foreach ($searchTables as $entry) {
    if ($entry['table'] === 'customers_karnataka') {
        $searchTables[] = ['table' => 'customers_karnataka_part2', 'label' => 'Karnataka'];
    }
    if ($entry['table'] === 'customers_kerala') {
        $searchTables[] = ['table' => 'customers_kerala_part2', 'label' => 'Kerala'];
    }
}

// address/permanent_address are wrapped in REPLACE() for DISPLAY only - the
// Karnataka bdata import (cli/import_karnataka_bdata.php) copied its source
// postal-address column through verbatim, and that upstream data already
// had its parts flattened together with "!" as an internal separator
// (found 2026-08-03). Two chained REPLACE()s so "word! word" (space already
// present) doesn't end up double-spaced after becoming ", " - only a bare
// "!" with no following space gets one inserted. WHERE/MATCH clauses below
// still search the raw, unmodified column - only the returned column here
// is affected, so this can't change which rows a search finds.
$cols = "id, customer_code, name, mobile_no, dob, gender, father_name,
         REPLACE(REPLACE(address, '! ', ', '), '!', ', ') AS address,
         REPLACE(REPLACE(permanent_address, '! ', ', '), '!', ', ') AS permanent_address,
         email, alternative_no, identity_no";

// Requested page size from the "Show entries" dropdown — only an allow-listed
// value is accepted (never pass the raw GET value into a LIMIT clause).
// 1000 is the max because that's the same ceiling already enforced on FULLTEXT
// fetches below as a MySQL 5.7 FTS-crash safeguard — no point allowing a higher
// non-FTS limit than the FTS path can ever actually deliver.
const ALLOWED_LIMITS = [10, 25, 50, 100, 250, 500, 1000];
$requestedLimit = (int) ($_GET['limit'] ?? 50);
$limit    = in_array($requestedLimit, ALLOWED_LIMITS, true) ? $requestedLimit : 50;
$logQuery = '';

/* ── Minimum length guard for LIKE '%…%' searches ───────────────────────── */
function requireMinLen(string $val, int $min, string $field): void {
    if ($val !== '' && mb_strlen($val) < $min) {
        echo json_encode(['ok' => false,
            'error' => "$field must be at least $min characters for a partial search."]);
        exit;
    }
}

/* ── Build SQL for each type ─────────────────────────────────────────────── */
// $queryMode: 'where' = single query with WHERE clause
//             'union' = run two queries and merge (mobile OR logic)
//             'in'    = WHERE … IN (bulk mobile)
$queryMode       = 'where';
$where           = '';
$params          = [];
$where2          = '';   // used only for union mode
$params2         = [];
$extraSubQueries = [];   // additional union sub-queries (e.g. +91 variants)

switch ($type) {

    case 'mobile':
        $mobile = trim($_GET['mobile'] ?? '');
        if ($mobile === '') { echo json_encode(['ok'=>false,'error'=>'Mobile number is required']); exit; }
        // Normalise: strip leading +91/91 prefix so bare 10-digit and +91-prefixed variants both match
        $mobile10  = preg_replace('/^\+91/', '', $mobile);
        $mobile10  = preg_replace('/^91(\d{10})$/', '$1', $mobile10);
        $mobile91  = '+91' . $mobile10;
        // Use UNION ALL so each index (idx_mobile, idx_alt) is used separately — much faster than OR
        // Four exact sub-queries: mobile bare, mobile +91, alt bare, alt +91 — all use B-tree indexes
        $queryMode = 'union';
        $where   = 'mobile_no = :mobile';
        $params  = ['mobile' => $mobile10];
        $where2  = 'alternative_no = :mobile';
        $params2 = ['mobile' => $mobile10];
        // extra sub-queries for +91-prefixed variants — appended inside the union loop below
        $extraSubQueries = [
            ['mobile_no = :mobile91',      ['mobile91' => $mobile91]],
            ['alternative_no = :mobile91', ['mobile91' => $mobile91]],
        ];
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

    case 'identity':
        $identity = trim($_GET['identity'] ?? '');
        if ($identity === '') { echo json_encode(['ok'=>false,'error'=>'Identity number is required']); exit; }
        requireMinLen($identity, 4, 'Identity number');
        $where  = 'identity_no LIKE :identity';
        $params = ['identity' => '%' . $identity . '%'];
        $logQuery = "identity=$identity";
        break;

    case 'name_father':
        $name   = trim($_GET['name']        ?? '');
        $father = trim($_GET['father_name'] ?? '');
        if ($name === '' && $father === '') { echo json_encode(['ok'=>false,'error'=>"Name or father's name required"]); exit; }
        requireMinLen($name, 3, 'Name');
        requireMinLen($father, 3, "Father's name");
        $conds = [];
        if ($name   !== '') { $conds[] = 'name LIKE :name';               $params['name']        = namePattern($name, false); }
        if ($father !== '') { $conds[] = 'father_name LIKE :father_name'; $params['father_name'] = namePattern($father, false); }
        $where = implode(' AND ', $conds);
        $logQuery = "name=$name father=$father";
        break;

    case 'name_dob':
        $name = trim($_GET['name'] ?? '');
        $dob  = trim($_GET['dob']  ?? '');
        if ($name === '' && $dob === '') { echo json_encode(['ok'=>false,'error'=>'Name or DOB required']); exit; }
        requireMinLen($name, 3, 'Name');
        $conds = [];
        if ($name !== '') { $conds[] = 'name LIKE :name'; $params['name'] = namePattern($name, false); }
        if ($dob  !== '') { $conds[] = 'dob = :dob';      $params['dob']  = $dob; }
        $where = implode(' AND ', $conds);
        $logQuery = "name=$name dob=$dob";
        break;

    case 'name_address':
        $name = trim($_GET['name']    ?? '');
        $addr = trim($_GET['address'] ?? '');
        if ($name === '' && $addr === '') { echo json_encode(['ok'=>false,'error'=>'Name or address required']); exit; }
        requireMinLen($name, 3, 'Name');
        requireMinLen($addr, 4, 'Address');
        $conds = [];
        if ($name !== '') { $conds[] = 'name LIKE :name'; $params['name'] = namePattern($name, false); }
        if ($addr !== '') {
            $conds[] = '(address LIKE :addr OR permanent_address LIKE :addr2)';
            $params['addr'] = "%$addr%"; $params['addr2'] = "%$addr%";
        }
        $where = implode(' AND ', $conds);
        $logQuery = "name=$name addr=$addr";
        break;

    case 'pincode_address':
        $pincode = trim($_GET['pincode'] ?? '');
        $addr    = trim($_GET['address'] ?? '');
        if ($pincode === '' && $addr === '') { echo json_encode(['ok'=>false,'error'=>'Pincode or address required']); exit; }
        if ($pincode !== '' && !preg_match('/^\d{3,6}$/', $pincode)) { echo json_encode(['ok'=>false,'error'=>'Pincode must be 3-6 digits']); exit; }
        requireMinLen($addr, 4, 'Address');
        $conds = [];
        if ($pincode !== '') { $conds[] = 'pincode LIKE :pincode'; $params['pincode'] = $pincode . '%'; }
        if ($addr !== '') {
            $conds[] = '(address LIKE :addr OR permanent_address LIKE :addr2)';
            $params['addr'] = "%$addr%"; $params['addr2'] = "%$addr%";
        }
        $where = implode(' AND ', $conds);
        $logQuery = "pincode=$pincode addr=$addr";
        break;

    case 'address':
    case 'full_address':
        $addr = trim($_GET['address'] ?? '');
        if ($addr === '') { echo json_encode(['ok'=>false,'error'=>'Address is required']); exit; }
        requireMinLen($addr, 4, 'Address');
        $where  = '(address LIKE :addr OR permanent_address LIKE :addr2)';
        $params = ['addr' => "%$addr%", 'addr2' => "%$addr%"];
        $logQuery = "address=$addr";
        break;

    case 'multi_mobile':
        $raw = trim($_GET['mobiles'] ?? '');
        if ($raw === '') { echo json_encode(['ok'=>false,'error'=>'Mobile numbers required']); exit; }
        $numbers = array_slice(array_unique(preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY)), 0, 50);
        if (empty($numbers)) { echo json_encode(['ok'=>false,'error'=>'Mobile numbers required']); exit; }
        $queryMode = 'in';
        $placeholders = [];
        foreach ($numbers as $i => $num) { $placeholders[] = ":mob$i"; $params["mob$i"] = $num; }
        $where = 'mobile_no IN (' . implode(',', $placeholders) . ')';
        $limit = max($limit, 500); // bulk lookup — guarantee room for every pasted number regardless of the dropdown
        $logQuery = 'mobiles=' . implode(',', $numbers);
        break;

    default:
        echo json_encode(['ok'=>false,'error'=>'Unknown type']); exit;
}

/* ── Query each table ────────────────────────────────────────────────────── */
$allRows = [];

foreach ($searchTables as $entry) {
    $tbl   = $entry['table'];
    $label = $entry['label'];

    $forceIdx = '';
    $extraTerms = [];
    $ftInfo = null; // set by identity/address branches below to enable the swap-retry fallback
    $nameFilterPrefix = null;    // set by name_address when name must be filtered in PHP, not SQL
    $pincodeFilterPrefix = null; // set by pincode_address when pincode must be filtered in PHP, not SQL
    $btreeFallback = null;       // set by name_father's FULLTEXT branch — used if FULLTEXT itself fails
    if ($queryMode === 'union') {
        // Separate indexed queries — much faster than OR on two columns
        // Only run the alternative_no sub-query if idx_alt is built on this table
        $subQueries = [[$where, $params]];
        if (isset($tablesWithAltIdx[$tbl])) {
            $subQueries[] = [$where2, $params2];
        }
        // Append any extra sub-queries (e.g. +91-prefixed mobile variants)
        if (!empty($extraSubQueries)) {
            foreach ($extraSubQueries as [$ew, $ep]) {
                // only add alt sub-queries if idx_alt exists on this table
                if (strpos($ew, 'alternative_no') !== false && !isset($tablesWithAltIdx[$tbl])) continue;
                $subQueries[] = [$ew, $ep];
            }
        }
        $rows = [];
        foreach ($subQueries as [$w,$p]) {
            try {
                $s = $pdo->prepare("SELECT $cols FROM `$tbl` WHERE $w LIMIT $limit");
                $s->execute($p);
                $rows = array_merge($rows, $s->fetchAll(PDO::FETCH_ASSOC));
            } catch (PDOException $e) {
                // skip this sub-query on error — keep results from other sub-query
            }
        }
        // deduplicate by row id — prevents the same physical row appearing
        // twice when it matches both mobile_no and alternative_no queries
        $seen = []; $rows = array_filter($rows, function($r) use (&$seen) {
            if (isset($seen[$r['id']])) return false;
            return $seen[$r['id']] = true;
        });
        $rows = array_values($rows);
    } else {
        // Use FULLTEXT MATCH...AGAINST when index exists — falls back to LIKE or skip when not
        if ($type === 'name') {
            // idx_name (name(32)) — prefix B-tree, instant
            $actualWhere  = 'name LIKE :name';
            $actualParams = ['name' => namePattern($name)];

        } elseif ($type === 'identity') {
            $ft = isset($tablesWithFtIdentity[$tbl]) ? ftTermsList($identity, 3) : [];
            if (!empty($ft)) {
                // Single term only (crash workaround, see rowsContainAllTerms) — any
                // further tokens are enforced afterward as a PHP-side filter.
                $actualWhere  = "MATCH(identity_no) AGAINST(:identity IN BOOLEAN MODE)";
                $actualParams = ['identity' => '+' . $ft[0]];
                $extraTerms   = array_map('strtolower', array_slice($ft, 1));
                $ftInfo = ['tokens' => $ft, 'matchCols' => 'identity_no', 'paramName' => 'identity', 'filterFields' => ['identity_no']];
            } else {
                $actualWhere  = $where;
                $actualParams = $params;
            }

        } elseif ($type === 'full_address') {
            if (!isset($tablesWithFtAddress[$tbl])) continue;
            // Full Address pastes a complete address, so every found token is required
            // TOGETHER in one boolean-mode MATCH — InnoDB intersects them at the FTS
            // index level, which is far more precise than the single-term-primary +
            // PHP-filtered pattern used by loose 'address' below (that pattern only
            // samples the first 100 raw hits of ONE term via LIMIT, which can miss the
            // target entirely on any common street/area name on a 126M-row table —
            // confirmed 2026-07-16: a real search for "Sundarapuram"+"Puliyanthope"
            // matched 62,967 rows on the single term alone, and the target row wasn't
            // among the arbitrary first 100). Capped at 4 terms: tested safe and
            // selective (~1,300-1,800 rows) on real data; 5+ terms can exceed
            // innodb_ft_result_cache_limit even though the final AND'd count is small,
            // because InnoDB computes each term's full posting list before intersecting.
            $ft = ftTermsList($addr, 5, 4);
            if (empty($ft)) $ft = ftTermsList($addr, 3, 2);
            if (empty($ft)) continue;
            $actualWhere  = "MATCH(address, permanent_address) AGAINST(:addr IN BOOLEAN MODE)";
            $actualParams = ['addr' => implode(' ', array_map(fn($t) => '+' . $t, $ft))];
            $ftInfo = ['tokens' => $ft, 'matchCols' => 'address, permanent_address', 'paramName' => 'addr', 'filterFields' => ['address', 'permanent_address']];
            // Safety net if even 4 ANDed terms exceed the FTS result cache limit
            // (e.g. every token happens to be a very common one) — retry with just
            // the 2 longest/most distinctive terms, tested safe on the same data.
            $btreeFallback = [
                'where'  => 'MATCH(address, permanent_address) AGAINST(:fb_addr IN BOOLEAN MODE)',
                'params' => ['fb_addr' => implode(' ', array_map(fn($t) => '+' . $t, array_slice($ft, 0, 2)))],
            ];

        } elseif ($type === 'address') {
            if (!isset($tablesWithFtAddress[$tbl])) continue;
            // minLen=5 skips short common words (Tamil=5 excluded, Nadu=4 excluded)
            $ft = ftTermsList($addr, 5, 2);
            if (empty($ft)) $ft = ftTermsList($addr, 3, 1); // fallback: any 1 token
            if (empty($ft)) continue;
            // Single term only (crash workaround, see rowsContainAllTerms) — the 2nd
            // token (if any) is enforced afterward as a PHP-side filter, not intersected in SQL.
            $actualWhere  = "MATCH(address, permanent_address) AGAINST(:addr IN BOOLEAN MODE)";
            $actualParams = ['addr' => '+' . $ft[0]];
            $extraTerms   = array_map('strtolower', array_slice($ft, 1));
            $ftInfo = ['tokens' => $ft, 'matchCols' => 'address, permanent_address', 'paramName' => 'addr', 'filterFields' => ['address', 'permanent_address']];

        } elseif ($type === 'name_father' && !empty($name) && !empty($father) && isset($tablesWithFtName[$tbl])) {
            // idx_name_father (name(32), father_name(32)) turns into a huge "Using
            // where" scan when name is a common prefix (e.g. "Kumar" -> millions
            // of index rows on the big tables) — a LIKE range on the leading
            // composite column blocks efficient use of the trailing column.
            // Route through ft_name instead: FULLTEXT match on name (crash-safe),
            // father_name verified via PHP filter afterward.
            $ft = ftTermsList($name, 3, 3);
            // ftTermsList only picks tokens >=3 chars, so a trailing single-letter
            // initial ("Esakki M") gets dropped from the FULLTEXT term entirely —
            // the SQL match alone would then return every "Esakki ___" regardless
            // of the initial. Verify the full typed name against the row afterward
            // (found 2026-07-16, alongside the father-name substring-match bug).
            $nameFilterPrefix = $name;
            if (!empty($ft)) {
                $fatherFt = ftTermsList($father, 3, 2);
                // Require every found name word AND father word together in ONE
                // boolean-mode MATCH. Name-only terms aren't enough when the name is
                // just one very common single word ("Marimuthu" alone -> 611,758 rows
                // on customers_tamil_nadu) — with nothing to AND against on the name
                // side, a real match ("Marimuthu"/"Chellappan") can be completely
                // absent from even a 2000-row sample, since father is only checked as
                // a PHP post-filter over that same narrow sample. ANDing the father
                // token(s) directly into the SQL MATCH narrowed that same case to 183
                // candidates (found & fixed 2026-07-16).
                $actualWhere  = "MATCH(name, father_name) AGAINST(:ft_name IN BOOLEAN MODE)";
                $actualParams = ['ft_name' => implode(' ', array_map(fn($t) => '+' . $t, array_merge($ft, $fatherFt)))];
                $extraTerms   = array_map('strtolower', $fatherFt);
                $ftInfo = ['tokens' => array_merge($ft, $extraTerms), 'matchCols' => 'name, father_name', 'paramName' => 'ft_name', 'filterFields' => ['father_name']];
                // If the name is common enough that FULLTEXT itself exceeds
                // innodb_ft_result_cache_limit (e.g. "Kumar" -> 3M+ rows), fall
                // back to the slow-but-correct B-tree scan rather than silently
                // returning nothing for that table.
                $btreeFallback = [
                    'where'  => 'name LIKE :name AND father_name LIKE :father_name',
                    'params' => ['name' => namePattern($name), 'father_name' => namePattern($father)],
                ];
            } else {
                $conds = []; $p = [];
                $conds[] = 'name LIKE :name';               $p['name']        = namePattern($name);
                $conds[] = 'father_name LIKE :father_name'; $p['father_name'] = namePattern($father);
                $actualWhere  = implode(' AND ', $conds);
                $actualParams = $p;
            }

        } elseif ($type === 'name_father' && !empty($name)) {
            // Name given, father blank — idx_name_father's leading column,
            // a plain prefix range scan, fast at any table size. Same trailing-
            // initial issue as above ("Esakki M" LIKE pattern alone is too loose,
            // see namePattern) — verify the full name afterward too.
            $actualWhere  = 'name LIKE :name';
            $actualParams = ['name' => namePattern($name)];
            $nameFilterPrefix = $name;

        } elseif ($type === 'name_father' && !empty($father)) {
            // Father's name given alone — NOT the leading column of
            // idx_name_father, so this can't range-scan that index at all;
            // it was silently falling through to a full table scan (found
            // hanging indefinitely on customers_tamil_nadu, 126M rows).
            // Route through ft_name instead, same single-term FULLTEXT
            // pattern used everywhere else in this file.
            if (isset($tablesWithFtName[$tbl])) {
                $ft = ftTermsList($father, 3, 1);
                if (!empty($ft)) {
                    $actualWhere  = "MATCH(name, father_name) AGAINST(:ft_father IN BOOLEAN MODE)";
                    $actualParams = ['ft_father' => '+' . $ft[0]];
                    $extraTerms   = array_map('strtolower', array_slice(ftTermsList($father, 3, 3), 1));
                    $ftInfo = ['tokens' => $ft, 'matchCols' => 'name, father_name', 'paramName' => 'ft_father', 'filterFields' => ['father_name']];
                    $btreeFallback = [
                        'where'  => 'father_name LIKE :father_name',
                        'params' => ['father_name' => namePattern($father)],
                    ];
                } else {
                    continue; // no usable token — skip this table rather than full-scan it
                }
            } else {
                continue; // no FULLTEXT available on this table — skip rather than full-scan it
            }

        } elseif ($type === 'name_dob') {
            // When both name+dob given: FORCE idx_name_dob (name(32),dob) — optimizer picks idx_dob
            // otherwise (146K rows scanned). idx_name_dob + prefix reduces to tens of rows.
            $conds = []; $p = []; $forceIdx = '';
            if (!empty($name) && !empty($dob)) {
                $conds     = ['name LIKE :name', 'dob = :dob'];
                $p         = ['name' => namePattern($name), 'dob' => $dob];
                $forceIdx  = 'FORCE INDEX (idx_name_dob)';
            } elseif (!empty($dob))  { $conds[] = 'dob = :dob';      $p['dob']  = $dob; }
              elseif (!empty($name)) { $conds[] = 'name LIKE :name';  $p['name'] = namePattern($name); }
            $actualWhere  = $conds ? implode(' AND ', $conds) : $where;
            $actualParams = $conds ? $p : $params;

        } elseif ($type === 'name_address' && isset($tablesWithFtName[$tbl])) {
            $ftConds = []; $ftParams = [];
            if (!empty($addr) && isset($tablesWithFtAddress[$tbl])) {
                $ft = ftTermsList($addr, 5, 2);
                if (empty($ft)) $ft = ftTermsList($addr, 3, 1);
                if (!empty($ft)) {
                    // Single term only (crash workaround) — 2nd token enforced via PHP filter below.
                    $ftConds[] = "MATCH(address, permanent_address) AGAINST(:ft_addr IN BOOLEAN MODE)";
                    $ftParams['ft_addr'] = '+' . $ft[0];
                    $extraTerms = array_map('strtolower', array_slice($ft, 1));
                    $ftInfo = ['tokens' => $ft, 'matchCols' => 'address, permanent_address', 'paramName' => 'ft_addr', 'filterFields' => ['address', 'permanent_address']];
                }
            } elseif (!empty($addr)) {
                $ftConds[] = "(address LIKE :addr OR permanent_address LIKE :addr2)";
                $ftParams['addr'] = "%$addr%"; $ftParams['addr2'] = "%$addr%";
            }
            if (!empty($name)) {
                if (!empty($ftConds)) {
                    // Don't AND this into the SQL alongside the FULLTEXT MATCH — InnoDB can't
                    // intersect a FULLTEXT index with a BTREE index, so a combined WHERE forces
                    // a per-row scan of every FULLTEXT hit (25s+ measured on customers_tamil_nadu's
                    // 126M rows vs ~1-2s for the FULLTEXT match alone). Filter by name in PHP
                    // instead, over the already-small LIMIT 100 window.
                    $nameFilterPrefix = $name;
                } else {
                    // No FULLTEXT condition in play here — plain B-tree prefix on name is fine.
                    $ftConds[] = "name LIKE :ft_name";
                    $ftParams['ft_name'] = namePattern($name);
                }
            }
            $actualWhere  = !empty($ftConds) ? implode(' AND ', $ftConds) : $where;
            $actualParams = !empty($ftConds) ? $ftParams : $params;

        } elseif ($type === 'pincode_address') {
            $conds = []; $p = [];
            if (!empty($addr)) {
                if (isset($tablesWithFtAddress[$tbl])) {
                    $ft = ftTermsList($addr, 5, 2);
                    if (empty($ft)) $ft = ftTermsList($addr, 3, 1);
                    if (!empty($ft)) {
                        // Single term only (crash workaround) — 2nd token enforced via PHP filter below.
                        $conds[] = "MATCH(address, permanent_address) AGAINST(:ft_addr IN BOOLEAN MODE)";
                        $p['ft_addr'] = '+' . $ft[0];
                        $extraTerms = array_map('strtolower', array_slice($ft, 1));
                        $ftInfo = ['tokens' => $ft, 'matchCols' => 'address, permanent_address', 'paramName' => 'ft_addr', 'filterFields' => ['address', 'permanent_address']];
                    }
                } else {
                    $conds[] = "(address LIKE :addr OR permanent_address LIKE :addr2)";
                    $p['addr'] = "%$addr%"; $p['addr2'] = "%$addr%";
                }
            }
            if (!empty($pincode)) {
                if (!empty($conds)) {
                    // Same reasoning as name_address above: don't combine a BTREE pincode
                    // condition with the FULLTEXT MATCH in one SQL statement — filter in PHP.
                    $pincodeFilterPrefix = $pincode;
                } else {
                    // idx_pincode / idx_name_pincode — B-tree prefix, instant
                    $conds[] = 'pincode LIKE :pincode';
                    $p['pincode'] = $pincode . '%';
                }
            }
            $actualWhere  = $conds ? implode(' AND ', $conds) : $where;
            $actualParams = $conds ? $p : $params;

        } else {
            $actualWhere  = $where;
            $actualParams = $params;
        }

        try {
            $idxHint = isset($forceIdx) && $forceIdx !== '' ? " $forceIdx" : '';
            // LIMIT 2000 crashed MySQL 5.7's FULLTEXT engine in production on "+STREET"
            // (now excluded via FT_STOPWORDS); LIMIT 1000 then crashed it again on
            // "+CHENNAI" (now also excluded). Lowered to 100 — the crash risk isn't
            // eliminated (any sufficiently common term can still trip InnoDB's FTS
            // assertion bug regardless of LIMIT), but a smaller result window is one
            // more lever to reduce how often it's hit alongside the denylist.
            // name_father/name_address/pincode_address are the exception: these
            // all filter a SECOND field in PHP after the FULLTEXT match
            // (father_name / name / pincode), and a moderately common primary
            // term (e.g. "Sudarsanan", 1000+ raw matches) can succeed under the
            // 128MB FTS cache limit yet still miss every true combined match
            // within a 100-row sample. MySQL 8.0's fts0que.cc crash bug is
            // fixed — the remaining risk on an oversized term is a graceful,
            // caught error, not a crash — so these can safely use a much wider
            // sampling window instead of falling back to the removed ~25-60s
            // combined FULLTEXT+BTREE query.
            $usesSecondaryFilter = $nameFilterPrefix !== null || $pincodeFilterPrefix !== null
                                 || $type === 'name_father' || $type === 'full_address';
            // name_father's multi-term AND'd match (see above) still leaves a
            // moderately-sized candidate pool on very common name combinations
            // (~1,491 rows measured for "Prakash"+"Chandran" on the Tamil Nadu
            // table) — 2000 comfortably covers that without meaningfully costing
            // more than 1000, since the query already stops at the true (small)
            // intersected result count either way.
            $fetchLimit = ($type === 'name_father' && $ftInfo !== null) ? 2000
                        : (($usesSecondaryFilter && $ftInfo !== null) ? 1000
                        : (($ftInfo !== null) ? 100 : $limit));
            $stmt = $pdo->prepare("SELECT $cols FROM `$tbl`$idxHint WHERE $actualWhere LIMIT $fetchLimit");
            $stmt->execute($actualParams);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            if ($btreeFallback !== null) {
                // FULLTEXT failed (most likely "FTS query exceeds result cache
                // limit" on an extremely common name) — fall back to the direct
                // B-tree scan for this table. Slow on the huge tables, but correct.
                try {
                    $fallbackStmt = $pdo->prepare("SELECT $cols FROM `$tbl` WHERE {$btreeFallback['where']} LIMIT $limit");
                    $fallbackStmt->execute($btreeFallback['params']);
                    $rows = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);
                    $extraTerms = []; // already fully matched by the B-tree condition — no PHP re-filter needed
                } catch (PDOException $e2) {
                    continue;
                }
            } else {
                continue; // skip table on timeout or error
            }
        }

        if (!empty($extraTerms)) {
            $fields = $type === 'identity'    ? ['identity_no']
                    : ($type === 'name_father' ? ['father_name']
                    : ['address', 'permanent_address']);
            $rows = rowsContainAllTerms($rows, $extraTerms, $fields);
        }
        if ($nameFilterPrefix !== null) {
            $rows = array_values(array_filter($rows, fn($r) => nameStartsWith((string) ($r['name'] ?? ''), $nameFilterPrefix)));
        }
        if ($pincodeFilterPrefix !== null) {
            // strncmp(), not str_starts_with() - the latter is PHP 8.0+ only,
            // and this file needs to stay compatible with the production IIS
            // site, which serves PHP 7.4 (found 2026-08-06).
            $rows = array_values(array_filter($rows, fn($r) => strncmp((string) ($r['pincode'] ?? ''), $pincodeFilterPrefix, strlen($pincodeFilterPrefix)) === 0));
        }

        // Swap-retry: if the primary term's window still didn't contain a real match
        // *after every filter above* (not just the address extraTerms), try the 2nd
        // token as the primary term instead — same LIMIT 100 ceiling, different candidate
        // window. Checking emptiness only post-extraTerms (pre name/pincode filter) misses
        // the common case where a common address term returns 100 real address matches
        // that just don't happen to contain the requested name/pincode.
        // Skipped for name_father: it already runs a second query's worth of cost just
        // to correctly conclude "no match" on a huge table (paid twice — once for the
        // primary attempt, once here) since the stricter word-boundary/initial checks
        // added 2026-07-16 make genuine no-match outcomes on common names common, not
        // rare. User chose speed over the rare extra match beyond the first ~1000-row
        // sample for this type (2026-07-16: 1.5s -> ~700ms on customers_tamil_nadu).
        if (empty($rows) && $ftInfo !== null && count($ftInfo['tokens']) >= 2 && $type !== 'name_father') {
            $swapWhere  = "MATCH({$ftInfo['matchCols']}) AGAINST(:{$ftInfo['paramName']} IN BOOLEAN MODE)";
            $swapParams = [$ftInfo['paramName'] => '+' . $ftInfo['tokens'][1]];
            $swapExtra  = array_map('strtolower', array_merge([$ftInfo['tokens'][0]], array_slice($ftInfo['tokens'], 2)));
            try {
                $swapStmt = $pdo->prepare("SELECT $cols FROM `$tbl` WHERE $swapWhere LIMIT 100");
                $swapStmt->execute($swapParams);
                $rows = rowsContainAllTerms($swapStmt->fetchAll(PDO::FETCH_ASSOC), $swapExtra, $ftInfo['filterFields']);
                if ($nameFilterPrefix !== null) {
                    $rows = array_values(array_filter($rows, fn($r) => nameStartsWith((string) ($r['name'] ?? ''), $nameFilterPrefix)));
                }
                if ($pincodeFilterPrefix !== null) {
                    $rows = array_values(array_filter($rows, fn($r) => strncmp((string) ($r['pincode'] ?? ''), $pincodeFilterPrefix, strlen($pincodeFilterPrefix)) === 0));
                }
            } catch (PDOException $e) {
                // leave $rows as the (empty) primary-attempt result
            }
        }

        // Extra fallback specific to 'address' (full_address already uses this
        // multi-term AND strategy as its primary approach) — when even the
        // swap-retry sample comes up empty, both of the two selected terms
        // independently matched too many rows for single-term 100-row sampling
        // to ever land on the true match by chance. Only costs an extra query
        // in that already-failing case (found 2026-07-19: a real address
        // search where the two picked terms were a common surname/street name
        // AND a common locality name — neither alone was selective, but
        // ANDing them together narrowed straight to the 1 true record).
        $usedAddressFallback = false;
        if ($type === 'address' && empty($rows) && isset($tablesWithFtAddress[$tbl])) {
            $multiFt = ftTermsList($addr, 5, 4);
            if (empty($multiFt)) $multiFt = ftTermsList($addr, 3, 2);
            if (count($multiFt) >= 2) {
                try {
                    $multiWhere  = "MATCH(address, permanent_address) AGAINST(:maddr IN BOOLEAN MODE)";
                    $multiParams = ['maddr' => implode(' ', array_map(fn($t) => '+' . $t, $multiFt))];
                    $multiStmt = $pdo->prepare("SELECT $cols FROM `$tbl` WHERE $multiWhere LIMIT 1000");
                    $multiStmt->execute($multiParams);
                    $rows = $multiStmt->fetchAll(PDO::FETCH_ASSOC);
                    $usedAddressFallback = true;
                } catch (PDOException $e) {
                    // leave rows empty — this table just won't contribute a result
                }
            }
        }

        // "Full Address" means find the specific record this address belongs to,
        // not every resident of the same street — the AND'd MATCH above narrows
        // to a plausible neighborhood (still dozens/hundreds of rows on a shared
        // street), so rank each candidate by what fraction of the pasted address's
        // own words it actually contains and keep only the closest match(es).
        // A leading "S/O:/D/O:/W/O:/C/O: <name>," is stripped first — that's a
        // relationship annotation on the source Aadhaar record, not part of the
        // postal address, and full_address has no father-name field to check it
        // against anyway (found 2026-07-16: a real search included "S/O: Raja"
        // ahead of the actual street address).
        if (($type === 'full_address' || $usedAddressFallback) && !empty($rows)) {
            $searchForScoring = preg_replace('/^\s*[SDWC]\/?O\s*:?\s*[^,]*,\s*/i', '', $addr);
            $searchTokens = array_values(array_unique(array_filter(
                preg_split('/[^a-zA-Z0-9]+/', strtolower($searchForScoring), -1, PREG_SPLIT_NO_EMPTY),
                fn($t) => strlen($t) >= 2
            )));
            if (!empty($searchTokens)) {
                $scored = [];
                foreach ($rows as $r) {
                    $candText = strtolower(($r['address'] ?? '') . ' ' . ($r['permanent_address'] ?? ''));
                    $hits = 0;
                    foreach ($searchTokens as $t) { if (stripos($candText, $t) !== false) $hits++; }
                    $scored[] = ['row' => $r, 'score' => $hits / count($searchTokens)];
                }
                usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
                $maxScore = $scored[0]['score'];
                // Only tighten to "closest match" when there IS a genuinely close
                // match — otherwise keep the wider candidate list rather than
                // arbitrarily picking a "best of a bad bunch" result.
                if ($maxScore >= 0.6) {
                    $rows = array_values(array_map(
                        fn($s) => $s['row'],
                        array_filter($scored, fn($s) => $s['score'] >= $maxScore - 0.001)
                    ));
                    $rows = array_slice($rows, 0, 10);
                }
            }
        }

        // A "last resort" combined-query fallback (FULLTEXT MATCH + the actual
        // name/pincode condition ANDed into one SQL statement) used to run here
        // when both the primary FULLTEXT window and the swap-retry came up empty.
        // Removed 2026-07-16: InnoDB can't intersect a FULLTEXT index with a
        // BTREE index, so that combined query paid the full ~25-60s "Using
        // where" scan it exists elsewhere in this file to avoid — and for a
        // CRM, "specific person not in the first sample window" is the common
        // case, not a rare one, so this was firing on a large fraction of
        // real searches, not just edge cases. The wider 1000-row FULLTEXT
        // window above (fetchLimit) now does the same job for the vast
        // majority of cases without ever paying that cost; the rare remaining
        // miss is a better trade than an unpredictable 50s+ page load.
    }

    foreach ($rows as &$row) { $row['state'] = $label; }
    unset($row);

    $allRows = array_merge($allRows, $rows);
    if (count($allRows) >= $limit) break;
}

$allRows = array_slice($allRows, 0, $limit);

// Captured here, before the audit-log write below - that INSERT is
// unrelated overhead the badge shouldn't be blamed for.
$queryMs = (int) round((microtime(true) - $queryStartedAt) * 1000);

/* ── Log ─────────────────────────────────────────────────────────────────── */
try {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $pdo->prepare('INSERT INTO search_logs (user_id, search_type, search_query, result_count, ip_address)
                   VALUES (:uid,:type,:q,:cnt,:ip)')
        ->execute(['uid' => $_SESSION['user_id'], 'type' => $type, 'q' => $logQuery,
                   'cnt' => count($allRows), 'ip' => substr($ip, 0, 45)]);
} catch (PDOException $e) {}

echo json_encode(['ok' => true, 'rows' => $allRows, 'queryMs' => $queryMs]);


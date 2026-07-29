<?php
// Public endpoint — deliberately reached with NO CRM session (it's loaded
// cross-origin by a <script src> that an agent's bookmarklet injects while
// sitting on the SDMS login page, so our session cookie is never sent).
// Auth is entirely via the ?k= per-agent capability key from
// lpgEnsureBookmarkletKey(), re-checked against the DB on every request —
// disabling an account, revoking LPG access, or letting it expire kills this
// immediately, same as every other permission check in this app.
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/lpg_crypto.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store');

// Never throws a JS error into the SDMS page — a stale/invalid/revoked
// bookmarklet should fail quietly (console warning only), not visibly break
// whatever the agent is doing on SDMS's own site.
function lpgAutofillNoop(string $reason): void {
    echo 'console.warn(' . json_encode('LPG autofill: ' . $reason) . ');';
    exit;
}

$key = $_GET['k'] ?? '';
if (!preg_match('/^[0-9a-f]{64}$/', $key)) {
    lpgAutofillNoop('invalid key');
}

$stmt = $pdo->prepare(
    'SELECT id, is_active, lpg_search_access, expires_at FROM users WHERE lpg_bookmarklet_key = :key'
);
$stmt->execute(['key' => $key]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !$user['is_active'] || !$user['lpg_search_access']) {
    lpgAutofillNoop('access not granted');
}
if ($user['expires_at'] !== null && strtotime($user['expires_at']) <= time()) {
    lpgAutofillNoop('account expired');
}

$cred = $pdo->query(
    'SELECT sdms_username, password_ciphertext, password_iv, password_tag FROM lpg_credentials WHERE id = 1'
)->fetch(PDO::FETCH_ASSOC);
if (!$cred) {
    lpgAutofillNoop('SDMS credentials not configured yet');
}

$sdmsPassword = lpgDecrypt($cred['password_ciphertext'], $cred['password_iv'], $cred['password_tag']);
if ($sdmsPassword === null) {
    lpgAutofillNoop('could not decrypt stored credentials');
}

// Audit log — same table every other search/launch action in this app uses.
try {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $pdo->prepare('INSERT INTO search_logs (user_id, search_type, search_query, result_count, ip_address)
                   VALUES (:uid, :type, :q, :cnt, :ip)')
        ->execute([
            'uid' => $user['id'], 'type' => 'lpg_autofill_used',
            'q' => 'SDMS login autofilled', 'cnt' => 0, 'ip' => substr($ip, 0, 45),
        ]);
} catch (PDOException $e) {
    // Not fatal.
}

// Targets the real field IDs confirmed from SDMS's actual rendered login
// form (2026-07-20): username input#s_swepi_1 (name=SWEUserName), password
// input#s_swepi_2 (name=SWEPassword). Falls back to name-based lookup in
// case a Siebel skin update ever renumbers the ids.
//
// Fills the fields only — deliberately does NOT call SWEExecuteLogin() to
// auto-submit. The agent clicks SDMS's own Login link themselves once the
// fields are filled; keeping that final action a deliberate human click
// (not a script submitting on their behalf) is intentional, not a missing
// feature (changed 2026-07-20).
?>
(function(){
  function findField(id, name) {
    return document.getElementById(id) || document.getElementsByName(name)[0] || null;
  }
  var u = findField('s_swepi_1', 'SWEUserName');
  var p = findField('s_swepi_2', 'SWEPassword');
  if (!u || !p) {
    console.warn('LPG autofill: SDMS login fields not found on this page.');
    return;
  }
  u.value = <?= json_encode($cred['sdms_username']) ?>;
  p.value = <?= json_encode($sdmsPassword) ?>;
})();

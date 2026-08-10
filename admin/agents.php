<?php
require __DIR__ . '/../includes/auth.php';
requireAdmin('../login.php');
require_once __DIR__ . '/../config/db.php';

$message     = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username  = trim($_POST['username']  ?? '');
        $fullName  = trim($_POST['full_name'] ?? '');
        $mobileNo  = trim($_POST['mobile_no'] ?? '');
        $password  = $_POST['password']       ?? '';
        $role      = ($_POST['role'] ?? 'agent') === 'admin' ? 'admin' : 'agent';
        $lpgAccess = isset($_POST['lpg_search_access']) ? 1 : 0;
        $rcPrintAccess = isset($_POST['rc_print_access']) ? 1 : 0;
        $rcPrintMonthlyLimit = min(65535, max(0, (int) ($_POST['rc_print_monthly_limit'] ?? 5)));
        $hpGasAccess = isset($_POST['hp_gas_access']) ? 1 : 0;
        $hpGasMonthlyLimit = min(65535, max(0, (int) ($_POST['hp_gas_monthly_limit'] ?? 5)));
        $eagleEyeAccess = isset($_POST['eagle_eye_access']) ? 1 : 0;
        $eagleEyeMonthlyLimit = min(65535, max(0, (int) ($_POST['eagle_eye_monthly_limit'] ?? 5)));
        $panIndiaAccess = isset($_POST['pan_india_access']) ? 1 : 0;
        $panIndiaProAccess = isset($_POST['pan_india_pro_access']) ? 1 : 0;
        $panIndiaProMonthlyLimit = min(65535, max(0, (int) ($_POST['pan_india_pro_monthly_limit'] ?? 5)));
        $advancedSearchAccess = isset($_POST['advanced_search_access']) ? 1 : 0;
        $advancedSearchMonthlyLimit = min(65535, max(0, (int) ($_POST['advanced_search_monthly_limit'] ?? 5)));
        $maxSessions = max(1, (int) ($_POST['max_concurrent_sessions'] ?? 1));
        $expiresDate  = trim($_POST['expires_date'] ?? '');
        $expiresTime  = trim($_POST['expires_time'] ?? '') ?: '00:00';
        $expiresAtSql = $expiresDate !== '' ? "$expiresDate $expiresTime:00" : null;

        if ($username === '' || $fullName === '' || strlen($password) < 6) {
            $message     = 'Username, full name, and a password of at least 6 characters are required.';
            $messageType = 'danger';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO users (username, password_hash, full_name, mobile_no, role, lpg_search_access, rc_print_access, rc_print_monthly_limit, hp_gas_access, hp_gas_monthly_limit, eagle_eye_access, eagle_eye_monthly_limit, pan_india_access, pan_india_pro_access, pan_india_pro_monthly_limit, advanced_search_access, advanced_search_monthly_limit, max_concurrent_sessions, expires_at)
                 VALUES (:username, :hash, :full_name, :mobile_no, :role, :lpg_access, :rc_print_access, :rc_print_monthly_limit, :hp_gas_access, :hp_gas_monthly_limit, :eagle_eye_access, :eagle_eye_monthly_limit, :pan_india_access, :pan_india_pro_access, :pan_india_pro_monthly_limit, :advanced_search_access, :advanced_search_monthly_limit, :max_sessions, :expires_at)'
            );
            try {
                $stmt->execute([
                    'username'  => $username,
                    'hash'      => password_hash($password, PASSWORD_DEFAULT),
                    'full_name' => $fullName,
                    'mobile_no' => $mobileNo !== '' ? $mobileNo : null,
                    'role'      => $role,
                    'lpg_access'=> $lpgAccess,
                    'rc_print_access' => $rcPrintAccess,
                    'rc_print_monthly_limit' => $rcPrintMonthlyLimit,
                    'hp_gas_access' => $hpGasAccess,
                    'hp_gas_monthly_limit' => $hpGasMonthlyLimit,
                    'eagle_eye_access' => $eagleEyeAccess,
                    'eagle_eye_monthly_limit' => $eagleEyeMonthlyLimit,
                    'pan_india_access' => $panIndiaAccess,
                    'pan_india_pro_access' => $panIndiaProAccess,
                    'pan_india_pro_monthly_limit' => $panIndiaProMonthlyLimit,
                    'advanced_search_access' => $advancedSearchAccess,
                    'advanced_search_monthly_limit' => $advancedSearchMonthlyLimit,
                    'max_sessions' => $maxSessions,
                    'expires_at'=> $expiresAtSql,
                ]);
                $message = "Account <strong>" . htmlspecialchars($username) . "</strong> created successfully.";
            } catch (PDOException $e) {
                // The banner stays generic (real DB errors shouldn't leak to
                // agents), but the real message - which duplicate-key
                // violation, a schema mismatch, etc. - is logged here since
                // php.ini's own error_log path isn't readable outside the
                // web server's own account (found 2026-08-09 debugging a
                // "username may already exist" that turned out not to be one).
                error_log('[agents.php create] ' . $e->getMessage(), 3, __DIR__ . '/../agents_debug.log');
                $message     = 'Could not create account — username may already exist.';
                $messageType = 'danger';
            }
        }
    } elseif ($action === 'edit') {
        $id       = (int) ($_POST['id'] ?? 0);
        $username = trim($_POST['username']  ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $mobileNo = trim($_POST['mobile_no'] ?? '');
        $role     = ($_POST['role'] ?? 'agent') === 'admin' ? 'admin' : 'agent';
        $lpgAccess = isset($_POST['lpg_search_access']) ? 1 : 0;
        $rcPrintAccess = isset($_POST['rc_print_access']) ? 1 : 0;
        $rcPrintMonthlyLimit = min(65535, max(0, (int) ($_POST['rc_print_monthly_limit'] ?? 5)));
        $hpGasAccess = isset($_POST['hp_gas_access']) ? 1 : 0;
        $hpGasMonthlyLimit = min(65535, max(0, (int) ($_POST['hp_gas_monthly_limit'] ?? 5)));
        $eagleEyeAccess = isset($_POST['eagle_eye_access']) ? 1 : 0;
        $eagleEyeMonthlyLimit = min(65535, max(0, (int) ($_POST['eagle_eye_monthly_limit'] ?? 5)));
        $panIndiaAccess = isset($_POST['pan_india_access']) ? 1 : 0;
        $panIndiaProAccess = isset($_POST['pan_india_pro_access']) ? 1 : 0;
        $panIndiaProMonthlyLimit = min(65535, max(0, (int) ($_POST['pan_india_pro_monthly_limit'] ?? 5)));
        $advancedSearchAccess = isset($_POST['advanced_search_access']) ? 1 : 0;
        $advancedSearchMonthlyLimit = min(65535, max(0, (int) ($_POST['advanced_search_monthly_limit'] ?? 5)));
        $maxSessions = max(1, (int) ($_POST['max_concurrent_sessions'] ?? 1));
        $newPassword  = $_POST['new_password'] ?? '';
        $expiresDate  = trim($_POST['expires_date'] ?? '');
        $expiresTime  = trim($_POST['expires_time'] ?? '') ?: '00:00';
        $expiresAtSql = $expiresDate !== '' ? "$expiresDate $expiresTime:00" : null;

        if ($username === '' || $fullName === '') {
            $message     = 'Username and full name are required.';
            $messageType = 'danger';
        } elseif ($newPassword !== '' && strlen($newPassword) < 6) {
            $message     = 'New password must be at least 6 characters (or leave it blank to keep the current one).';
            $messageType = 'danger';
        } else {
            $sql = 'UPDATE users SET username = :username, full_name = :full_name, mobile_no = :mobile_no, role = :role, lpg_search_access = :lpg_access, rc_print_access = :rc_print_access, rc_print_monthly_limit = :rc_print_monthly_limit, hp_gas_access = :hp_gas_access, hp_gas_monthly_limit = :hp_gas_monthly_limit, eagle_eye_access = :eagle_eye_access, eagle_eye_monthly_limit = :eagle_eye_monthly_limit, pan_india_access = :pan_india_access, pan_india_pro_access = :pan_india_pro_access, pan_india_pro_monthly_limit = :pan_india_pro_monthly_limit, advanced_search_access = :advanced_search_access, advanced_search_monthly_limit = :advanced_search_monthly_limit, max_concurrent_sessions = :max_sessions, expires_at = :expires_at';
            $params = [
                'username'  => $username,
                'full_name' => $fullName,
                'mobile_no' => $mobileNo !== '' ? $mobileNo : null,
                'role'      => $role,
                'lpg_access'=> $lpgAccess,
                'rc_print_access' => $rcPrintAccess,
                'rc_print_monthly_limit' => $rcPrintMonthlyLimit,
                'hp_gas_access' => $hpGasAccess,
                'hp_gas_monthly_limit' => $hpGasMonthlyLimit,
                'eagle_eye_access' => $eagleEyeAccess,
                'eagle_eye_monthly_limit' => $eagleEyeMonthlyLimit,
                'pan_india_access' => $panIndiaAccess,
                'pan_india_pro_access' => $panIndiaProAccess,
                'pan_india_pro_monthly_limit' => $panIndiaProMonthlyLimit,
                'advanced_search_access' => $advancedSearchAccess,
                'advanced_search_monthly_limit' => $advancedSearchMonthlyLimit,
                'max_sessions' => $maxSessions,
                'expires_at'=> $expiresAtSql,
                'id'        => $id,
            ];
            // Changing the password invalidates every one of that account's
            // current sessions - if the password was changed because it
            // leaked, none of the old sessions (not just one) should survive it.
            if ($newPassword !== '') {
                $sql .= ', password_hash = :hash';
                $params['hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
            }
            $sql .= ' WHERE id = :id';
            try {
                $pdo->prepare($sql)->execute($params);
                if ($newPassword !== '') {
                    $pdo->prepare('DELETE FROM user_sessions WHERE user_id = :id')->execute(['id' => $id]);
                }
                $message = "Account <strong>" . htmlspecialchars($username) . "</strong> updated successfully.";
            } catch (PDOException $e) {
                error_log('[agents.php edit] ' . $e->getMessage(), 3, __DIR__ . '/../agents_debug.log');
                $message     = 'Could not update account — username may already be taken.';
                $messageType = 'danger';
            }
        }
    } elseif ($action === 'toggle_lpg') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE users SET lpg_search_access = 1 - lpg_search_access WHERE id = :id');
        $stmt->execute(['id' => $id]);
    } elseif ($action === 'toggle_rc_print') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE users SET rc_print_access = 1 - rc_print_access WHERE id = :id');
        $stmt->execute(['id' => $id]);
    } elseif ($action === 'toggle_hp_gas') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE users SET hp_gas_access = 1 - hp_gas_access WHERE id = :id');
        $stmt->execute(['id' => $id]);
    } elseif ($action === 'toggle_eagle_eye') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE users SET eagle_eye_access = 1 - eagle_eye_access WHERE id = :id');
        $stmt->execute(['id' => $id]);
    } elseif ($action === 'toggle_pan_india') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE users SET pan_india_access = 1 - pan_india_access WHERE id = :id');
        $stmt->execute(['id' => $id]);
    } elseif ($action === 'toggle_pan_india_pro') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE users SET pan_india_pro_access = 1 - pan_india_pro_access WHERE id = :id');
        $stmt->execute(['id' => $id]);
    } elseif ($action === 'toggle_advanced_search') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE users SET advanced_search_access = 1 - advanced_search_access WHERE id = :id');
        $stmt->execute(['id' => $id]);
    } elseif ($action === 'regenerate_lpg_key') {
        // Invalidates that agent's current bookmarklet immediately — the next
        // visit to lpg_search.php lazily generates a fresh key (see
        // lpgEnsureBookmarkletKey). Use this if a bookmarklet is ever
        // suspected to have leaked, without having to rotate the shared SDMS
        // password for every other agent.
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE users SET lpg_bookmarklet_key = NULL WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $message = 'LPG bookmarklet key reset — the agent must revisit LPG Search to get a new one.';
    } elseif ($action === 'set_expiry') {
        $id        = (int) ($_POST['id'] ?? 0);
        $expiresDate  = trim($_POST['expires_date'] ?? '');
        $expiresTime  = trim($_POST['expires_time'] ?? '') ?: '00:00';
        $expiresAtSql = $expiresDate !== '' ? "$expiresDate $expiresTime:00" : null;
        $stmt = $pdo->prepare('UPDATE users SET expires_at = :expires_at WHERE id = :id');
        $stmt->execute(['expires_at' => $expiresAtSql, 'id' => $id]);
        $message = $expiresAtSql ? 'Expiry date updated.' : 'Expiry removed.';
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id !== (int) $_SESSION['user_id']) {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM search_logs WHERE user_id = :id')->execute(['id' => $id]);
            $pdo->prepare('DELETE FROM user_sessions WHERE user_id = :id')->execute(['id' => $id]);
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
            $pdo->commit();
            $message     = 'Account deleted successfully.';
            $messageType = 'warning';
        } else {
            $message     = 'You cannot delete your own account.';
            $messageType = 'danger';
        }
    }
}

$users = $pdo->query(
    'SELECT id, username, full_name, mobile_no, role, is_active, lpg_search_access, lpg_bookmarklet_key, rc_print_access, rc_print_monthly_limit, hp_gas_access, hp_gas_monthly_limit, eagle_eye_access, eagle_eye_monthly_limit, pan_india_access, pan_india_pro_access, pan_india_pro_monthly_limit, advanced_search_access, advanced_search_monthly_limit, max_concurrent_sessions, expires_at, created_at, last_login_at FROM users ORDER BY created_at DESC'
)->fetchAll();

// Summary stats for the admin view. "Logged In" counts users who have ever
// signed in at least once (last_login_at is set) — the users table only
// stores the MOST RECENT login timestamp per user, not a running count of
// every login event, so this is "how many accounts have been used", not a
// cumulative login-event total (that data was never recorded).
$totalUsers   = count($users);
$totalAdmins  = 0;
$totalAgents  = 0;
$loggedInCount = 0;
$expiredCount  = 0;
foreach ($users as $u) {
    if ($u['role'] === 'admin') $totalAdmins++; else $totalAgents++;
    if ($u['last_login_at'] !== null) $loggedInCount++;
    if ($u['expires_at'] !== null && strtotime($u['expires_at']) <= time()) $expiredCount++;
}

$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<style>
  /* Compact create-account layout (2026-08-08) - the flat inline-form flex
     row this used to be stopped fitting once RC Print/HP Gas/Advance Pan
     India each added their own access checkbox + monthly-limit number
     input on top of the original LPG Search checkbox, so everything's
     grouped into rows/a grid here instead of one long wrap. */
  .agent-create-form{padding:16px 20px;background:var(--c-surface-2);border-bottom:1px solid var(--c-border);}
  .acf-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px;}
  .acf-row:last-child{margin-bottom:0;}
  .acf-row input[type=text],.acf-row input[type=tel],.acf-row input[type=password],
  .acf-row input[type=time],.acf-row select{
    padding:8px 12px;border:1px solid var(--c-border);border-radius:var(--r-md);
    font-family:var(--font);font-size:13px;outline:none;
    background:var(--c-surface);transition:border-color var(--t),box-shadow var(--t);color:var(--c-text);
  }
  .acf-row input:focus,.acf-row select:focus{border-color:var(--c-accent);box-shadow:0 0 0 3px var(--c-accent-glow);}
  .acf-section-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;
    color:var(--c-text-soft);margin-bottom:8px;display:flex;align-items:center;gap:6px;}
  .acf-section-label i{color:var(--c-accent);font-size:12px;}
  .acf-feature-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:10px;margin-bottom:14px;}
  .acf-feature{position:relative;display:flex;align-items:center;gap:10px;padding:10px 12px 10px 14px;
    border:1px solid var(--c-border);border-radius:var(--r-md);background:var(--c-surface-2);
    font-size:12.5px;font-weight:600;color:var(--c-text);cursor:pointer;
    transition:border-color 150ms,background 150ms,box-shadow 150ms,transform 150ms;}
  .acf-feature:hover{border-color:var(--c-accent);transform:translateY(-1px);}
  .acf-feature:has(input:checked){background:var(--c-accent-light);border-color:var(--c-accent);
    box-shadow:0 2px 10px rgba(79,70,229,.2);color:var(--c-accent-hover);}
  .acf-feature input[type=checkbox]{width:16px;height:16px;flex-shrink:0;accent-color:var(--c-accent);cursor:pointer;}
  .acf-feature span{flex:1;}
  .acf-feature .acf-limit{display:flex;align-items:center;gap:4px;flex:0 0 auto;font-size:11px;font-weight:500;color:var(--c-text-soft);}
  .acf-feature:has(input:checked) .acf-limit{color:var(--c-accent-hover);}
  .acf-feature .acf-limit input{width:44px;padding:3px 5px;border:1px solid var(--c-border);border-radius:6px;
    background:var(--c-surface);color:var(--c-text);font-size:11.5px;text-align:center;font-weight:600;}
  .acf-inline-field{display:flex;align-items:center;gap:6px;font-size:13px;color:var(--c-text);white-space:nowrap;}
  .acf-inline-field input{width:56px;padding:8px 10px;border:1px solid var(--c-border);border-radius:var(--r-md);
    background:var(--c-surface);color:var(--c-text);font-size:13px;outline:none;}

  /* Edit modal widened (2026-08-08, widened further same day) - the shared
     .modal-box max-width (420px, used by every modal in the app) left
     almost no room once Feature Access grew to 5 checkboxes + 3
     monthly-limit inputs; scoped to just this modal via its own ID rather
     than raising the shared default, which other (genuinely small) modals
     elsewhere still want. Wide enough that basic info fits one row of 4
     and Max Logins/Expiry one row of 3 on a normal desktop window.
     Feature Access itself auto-wraps (minmax(190px,1fr)) rather than a
     fixed column count - a fixed repeat(N,1fr) has to be re-tuned by hand
     every time a feature is added and, worse, doesn't wrap at all on a
     narrower/non-maximized window - it just runs the last item(s) off the
     edge instead (found 2026-08-10 with 7 features + number inputs). */
  #edit-modal-overlay .modal-box{max-width:960px;width:96vw;}
  #edit-modal-overlay .edit-basic-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:0 16px;}
  #edit-modal-overlay .edit-basic-grid .form-group{margin-bottom:14px;}
  #edit-modal-overlay .acf-feature-grid{grid-template-columns:repeat(auto-fill,minmax(190px,1fr));}
  #edit-modal-overlay .edit-settings-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:0 16px;align-items:end;}
  #edit-modal-overlay .edit-settings-grid .form-group{margin-bottom:14px;}
  @media (max-width:700px){
    #edit-modal-overlay .edit-basic-grid{grid-template-columns:1fr 1fr;}
    #edit-modal-overlay .edit-settings-grid{grid-template-columns:1fr 1fr;}
  }
  @media (max-width:560px){
    #edit-modal-overlay .edit-basic-grid{grid-template-columns:1fr;}
    #edit-modal-overlay .edit-settings-grid{grid-template-columns:1fr;}
  }
</style>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-people-fill"></i> Manage Agents &amp; Admins</h1>
  <p class="page-subtitle">Create, enable/disable, or remove CRM portal accounts.</p>
</div>

<div class="sp-stats mb-4">
  <div class="sp-stat sp-stat--accent">
    <span><?= $totalUsers ?></span>
    <label>Total Users</label>
  </div>
  <div class="sp-stat">
    <span><?= $totalAdmins ?></span>
    <label>Admins</label>
  </div>
  <div class="sp-stat">
    <span><?= $totalAgents ?></span>
    <label>Agents</label>
  </div>
  <div class="sp-stat" title="Accounts that have signed in at least once">
    <span><?= $loggedInCount ?></span>
    <label>Logged In</label>
  </div>
  <div class="sp-stat">
    <span><?= $expiredCount ?></span>
    <label>Expired</label>
  </div>
</div>

<?php if ($message): ?>
  <div class="notice notice-<?= htmlspecialchars($messageType) ?>">
    <i class="bi bi-<?= $messageType === 'danger' ? 'exclamation-triangle-fill' : ($messageType === 'warning' ? 'exclamation-circle-fill' : 'check-circle-fill') ?>"></i>
    <?= $message ?>
  </div>
<?php endif; ?>

<!-- Create account form -->
<div class="card mb-4">
  <div class="card-header">
    <i class="bi bi-person-plus-fill" style="color:var(--c-accent)"></i>
    <span class="card-title">Create New Account</span>
  </div>
  <form method="post" class="agent-create-form">
    <input type="hidden" name="action" value="create">

    <div class="acf-row">
      <input type="text"     name="username"   placeholder="Username"          required style="min-width:130px">
      <input type="text"     name="full_name"  placeholder="Full Name"         required style="min-width:160px">
      <input type="tel"      name="mobile_no"  placeholder="Mobile Number (optional)" style="min-width:170px">
      <div class="password-field-wrap" style="min-width:160px">
        <input type="password" name="password" id="create-password" placeholder="Password (min 6)" required minlength="6" style="width:100%">
        <button type="button" class="password-toggle-btn" id="create-password-toggle-btn" aria-label="Show password">
          <i class="bi bi-eye-fill" id="create-password-toggle-icon"></i>
        </button>
      </div>
      <select name="role" style="min-width:110px">
        <option value="agent">Agent</option>
        <option value="admin">Admin</option>
      </select>
    </div>

    <div class="acf-section-label"><i class="bi bi-shield-lock-fill"></i> Feature Access</div>
    <div class="acf-feature-grid">
      <label class="acf-feature">
        <input type="checkbox" name="lpg_search_access" value="1">
        <span>LPG Search</span>
      </label>
      <label class="acf-feature">
        <input type="checkbox" name="pan_india_access" value="1">
        <span>Pan India</span>
      </label>
      <label class="acf-feature">
        <input type="checkbox" name="rc_print_access" value="1">
        <span>RC Print</span>
        <span class="acf-limit" title="How many RC Print searches this agent can run per calendar month - each one spends real credits on the shared locateme.services account. Ignored for admins.">
          <input type="number" name="rc_print_monthly_limit" value="5" min="0" max="65535" onclick="event.stopPropagation()">/mo
        </span>
      </label>
      <label class="acf-feature">
        <input type="checkbox" name="hp_gas_access" value="1">
        <span>HP LPG Search</span>
        <span class="acf-limit" title="How many HP LPG searches this agent can run per calendar month - each one spends real credits (150/search) on the shared locateme.services account. Ignored for admins.">
          <input type="number" name="hp_gas_monthly_limit" value="5" min="0" max="65535" onclick="event.stopPropagation()">/mo
        </span>
      </label>
      <label class="acf-feature">
        <input type="checkbox" name="eagle_eye_access" value="1">
        <span>Advance Pan India</span>
        <span class="acf-limit" title="How many Advance Pan India searches this agent can run per calendar month - shares a single monthly plan pool on theeagleeye.biz. Ignored for admins.">
          <input type="number" name="eagle_eye_monthly_limit" value="5" min="0" max="65535" onclick="event.stopPropagation()">/mo
        </span>
      </label>
      <label class="acf-feature">
        <input type="checkbox" name="pan_india_pro_access" value="1">
        <span>Night Out</span>
        <span class="acf-limit" title="How many Night Out searches this agent can run per calendar month - shares a single daily quota on the vendor's side. Ignored for admins.">
          <input type="number" name="pan_india_pro_monthly_limit" value="5" min="0" max="65535" onclick="event.stopPropagation()">/mo
        </span>
      </label>
      <label class="acf-feature">
        <input type="checkbox" name="advanced_search_access" value="1">
        <span>Advanced Search</span>
        <span class="acf-limit" title="How many Advanced Search searches this agent can run per calendar month - shares a single account's own daily/IP quota on tracekart.in. Ignored for admins.">
          <input type="number" name="advanced_search_monthly_limit" value="5" min="0" max="65535" onclick="event.stopPropagation()">/mo
        </span>
      </label>
    </div>

    <div class="acf-row">
      <label class="acf-inline-field"
             title="How many devices can be signed into this account at the same time. Logging in beyond this limit signs out whichever device has been idle longest.">
        Max Logins
        <input type="number" name="max_concurrent_sessions" value="1" min="1" max="50">
      </label>
      <input type="text" name="expires_date" placeholder="DD/MM/YYYY" pattern="\d{2}/\d{2}/\d{4}" maxlength="10"
             title="Expiry date, DD/MM/YYYY (leave blank for no expiry)" style="min-width:140px">
      <input type="time" name="expires_time" title="Expiry time (defaults to 00:00)" style="min-width:110px">
      <button type="submit" class="btn btn-primary btn-sm">
        <i class="bi bi-person-plus"></i> Create Account
      </button>
    </div>
  </form>
</div>

<!-- Users table -->
<div class="card">
  <div class="card-header">
    <i class="bi bi-list-ul" style="color:var(--c-accent)"></i>
    <span class="card-title">All Accounts</span>
    <span class="badge badge-neutral" id="accounts-count"><?= count($users) ?> total</span>
    <div style="margin-left:auto;display:flex;align-items:center;gap:8px">
      <button type="button" class="btn btn-sm btn-success" id="export-accounts-btn">
        <i class="bi bi-file-earmark-excel"></i> Download Excel
      </button>
      <select id="account-role-filter" class="dt-select">
        <option value="">All Roles</option>
        <option value="admin">Admin</option>
        <option value="agent">Agent</option>
      </select>
      <div class="dt-search-box">
        <i class="bi bi-search"></i>
        <input type="text" id="account-search-input" placeholder="Search username, name, or mobile…">
      </div>
    </div>
  </div>
  <div class="card-body p-0">
    <table class="results-table agents-table" id="accounts-table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Username</th>
          <th>Mobile Number</th>
          <th>Role</th>
          <th>Status</th>
          <th>LPG</th>
          <th>Pan India</th>
          <th>RC Print</th>
          <th>HP Gas</th>
          <th>Adv. Pan India</th>
          <th>Night Out</th>
          <th>Advanced Search</th>
          <th>Set Expiry</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u):
        $isExpired   = $u['expires_at'] !== null && strtotime($u['expires_at']) <= time();
        $expiryDateValue = $u['expires_at'] ? date('d/m/Y', strtotime($u['expires_at'])) : '';
        $expiryTimeValue = $u['expires_at'] ? date('H:i', strtotime($u['expires_at'])) : '';
        $isSelf      = (int) $u['id'] === (int) $_SESSION['user_id'];

        if ($isExpired) { $statusClass = 'badge-danger';   $statusLabel = 'Expired'; }
        elseif ($u['is_active']) { $statusClass = 'badge-success'; $statusLabel = 'Active'; }
        else                     { $statusClass = 'badge-neutral'; $statusLabel = 'Disabled'; }
      ?>
        <tr data-role="<?= htmlspecialchars($u['role']) ?>"
            data-search="<?= htmlspecialchars(strtolower($u['username'] . ' ' . $u['full_name'] . ' ' . ($u['mobile_no'] ?? ''))) ?>">
          <td class="text-sm text-muted">#<?= (int) $u['id'] ?></td>
          <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
          <td class="text-sm text-muted"><?= htmlspecialchars($u['mobile_no'] ?? '') ?: '<span class="na">—</span>' ?></td>
          <td>
            <span class="badge <?= $u['role'] === 'admin' ? 'badge-warning' : 'badge-info' ?>">
              <?= ucfirst($u['role']) ?>
            </span>
          </td>
          <td><span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span></td>
          <td>
            <span class="badge <?= $u['lpg_search_access'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $u['lpg_search_access'] ? 'Granted' : 'Not Granted' ?>
            </span>
          </td>
          <td>
            <span class="badge <?= $u['pan_india_access'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $u['pan_india_access'] ? 'Granted' : 'Not Granted' ?>
            </span>
          </td>
          <td>
            <span class="badge <?= $u['rc_print_access'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $u['rc_print_access'] ? 'Granted' : 'Not Granted' ?>
            </span>
            <?php if ($u['rc_print_access'] && $u['role'] !== 'admin'): ?>
              <div class="text-sm text-muted" style="margin-top:2px"><?= (int) $u['rc_print_monthly_limit'] ?>/month</div>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $u['hp_gas_access'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $u['hp_gas_access'] ? 'Granted' : 'Not Granted' ?>
            </span>
            <?php if ($u['hp_gas_access'] && $u['role'] !== 'admin'): ?>
              <div class="text-sm text-muted" style="margin-top:2px"><?= (int) $u['hp_gas_monthly_limit'] ?>/month</div>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $u['eagle_eye_access'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $u['eagle_eye_access'] ? 'Granted' : 'Not Granted' ?>
            </span>
            <?php if ($u['eagle_eye_access'] && $u['role'] !== 'admin'): ?>
              <div class="text-sm text-muted" style="margin-top:2px"><?= (int) $u['eagle_eye_monthly_limit'] ?>/month</div>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $u['pan_india_pro_access'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $u['pan_india_pro_access'] ? 'Granted' : 'Not Granted' ?>
            </span>
            <?php if ($u['pan_india_pro_access'] && $u['role'] !== 'admin'): ?>
              <div class="text-sm text-muted" style="margin-top:2px"><?= (int) $u['pan_india_pro_monthly_limit'] ?>/month</div>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $u['advanced_search_access'] ? 'badge-success' : 'badge-neutral' ?>">
              <?= $u['advanced_search_access'] ? 'Granted' : 'Not Granted' ?>
            </span>
            <?php if ($u['advanced_search_access'] && $u['role'] !== 'admin'): ?>
              <div class="text-sm text-muted" style="margin-top:2px"><?= (int) $u['advanced_search_monthly_limit'] ?>/month</div>
            <?php endif; ?>
          </td>
          <td>
            <form method="post" class="expiry-form">
              <input type="hidden" name="action" value="set_expiry">
              <input type="hidden" name="id"     value="<?= (int) $u['id'] ?>">
              <input type="text" name="expires_date" value="<?= htmlspecialchars($expiryDateValue) ?>"
                     placeholder="DD/MM/YYYY" pattern="\d{2}/\d{2}/\d{4}" maxlength="10"
                     title="<?= $u['expires_at'] ? 'Current: '.$u['expires_at'] : 'No expiry set' ?>">
              <input type="time" name="expires_time" value="<?= htmlspecialchars($expiryTimeValue) ?>"
                     title="<?= $u['expires_at'] ? 'Current: '.$u['expires_at'] : 'No expiry set' ?>">
              <button type="submit" class="btn btn-sm btn-secondary">
                <i class="bi bi-clock"></i> Save
              </button>
            </form>
            <?php if ($u['expires_at']): ?>
              <div class="text-sm" style="margin-top:4px;color:<?= $isExpired ? 'var(--c-danger)' : 'var(--c-text-muted)' ?>">
                <i class="bi bi-<?= $isExpired ? 'exclamation-circle' : 'calendar-check' ?>"></i>
                <?= htmlspecialchars($u['expires_at']) ?>
              </div>
            <?php endif; ?>
          </td>
          <td class="action-cell">
            <button type="button" class="btn btn-sm btn-secondary"
                    onclick="openEditModal(<?= (int) $u['id'] ?>, <?= htmlspecialchars(json_encode($u['username']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($u['full_name']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($u['mobile_no'] ?? ''), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($u['role']), ENT_QUOTES) ?>, <?= (int) $u['lpg_search_access'] ?>, <?= (int) $u['rc_print_access'] ?>, <?= (int) $u['rc_print_monthly_limit'] ?>, <?= (int) $u['hp_gas_access'] ?>, <?= (int) $u['hp_gas_monthly_limit'] ?>, <?= (int) $u['eagle_eye_access'] ?>, <?= (int) $u['eagle_eye_monthly_limit'] ?>, <?= (int) $u['pan_india_access'] ?>, <?= (int) $u['pan_india_pro_access'] ?>, <?= (int) $u['pan_india_pro_monthly_limit'] ?>, <?= (int) $u['advanced_search_access'] ?>, <?= (int) $u['advanced_search_monthly_limit'] ?>, <?= (int) $u['max_concurrent_sessions'] ?>, <?= htmlspecialchars(json_encode($expiryDateValue), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($expiryTimeValue), ENT_QUOTES) ?>)">
              <i class="bi bi-pencil-square"></i> Edit
            </button>
            <?php if ($u['lpg_search_access'] && $u['lpg_bookmarklet_key']): ?>
              <form method="post" style="display:inline"
                    onsubmit="return confirm('Reset <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>\'s LPG bookmarklet? Their current one will stop working until they revisit LPG Search.')">
                <input type="hidden" name="action" value="regenerate_lpg_key">
                <input type="hidden" name="id"     value="<?= (int) $u['id'] ?>">
                <button type="submit" class="btn btn-sm btn-secondary" title="Reset this agent's LPG bookmarklet key">
                  <i class="bi bi-arrow-repeat"></i> Reset Key
                </button>
              </form>
            <?php endif; ?>
            <?php if (!$isSelf): ?>
              <form method="post" style="display:inline"
                    onsubmit="return confirm('Delete account \'<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>\'?\nSearch history will also be deleted. This cannot be undone.')">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id"     value="<?= (int) $u['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger">
                  <i class="bi bi-trash3"></i> Delete
                </button>
              </form>
            <?php else: ?>
              <span class="badge badge-neutral"><i class="bi bi-person-check"></i> You</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Edit account modal (shared, populated via JS) -->
<div class="modal-overlay" id="edit-modal-overlay" style="display:none" onclick="if(event.target===this) closeEditModal()">
  <div class="modal-box">
    <div class="modal-header">
      <span><i class="bi bi-pencil-square"></i> Edit Account</span>
      <button type="button" class="modal-close-btn" onclick="closeEditModal()" aria-label="Close">&times;</button>
    </div>
    <form method="post" id="edit-form">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="edit-id">
      <div class="edit-basic-grid">
        <div class="form-group">
          <label class="form-label" for="edit-username">Username</label>
          <input type="text" class="form-control" name="username" id="edit-username" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="edit-full_name">Full Name</label>
          <input type="text" class="form-control" name="full_name" id="edit-full_name" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="edit-mobile_no">Mobile Number</label>
          <input type="tel" class="form-control" name="mobile_no" id="edit-mobile_no" placeholder="Optional">
        </div>
        <div class="form-group">
          <label class="form-label" for="edit-role">Role</label>
          <select class="form-control" name="role" id="edit-role">
            <option value="agent">Agent</option>
            <option value="admin">Admin</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label"><i class="bi bi-shield-lock-fill"></i> Feature Access</label>
        <div class="acf-feature-grid">
          <label class="acf-feature">
            <input type="checkbox" name="lpg_search_access" id="edit-lpg_search_access" value="1">
            <span>LPG Search</span>
          </label>
          <label class="acf-feature">
            <input type="checkbox" name="pan_india_access" id="edit-pan_india_access" value="1">
            <span>Pan India</span>
          </label>
          <label class="acf-feature">
            <input type="checkbox" name="rc_print_access" id="edit-rc_print_access" value="1">
            <span>RC Print</span>
            <span class="acf-limit" title="How many RC Print searches this agent can run per calendar month - each one spends real credits on the shared locateme.services account. Ignored for admins.">
              <input type="number" name="rc_print_monthly_limit" id="edit-rc_print_monthly_limit" value="5" min="0" max="65535" onclick="event.stopPropagation()">/mo
            </span>
          </label>
          <label class="acf-feature">
            <input type="checkbox" name="hp_gas_access" id="edit-hp_gas_access" value="1">
            <span>HP LPG Search</span>
            <span class="acf-limit" title="How many HP LPG searches this agent can run per calendar month - each one spends real credits (150/search) on the shared locateme.services account. Ignored for admins.">
              <input type="number" name="hp_gas_monthly_limit" id="edit-hp_gas_monthly_limit" value="5" min="0" max="65535" onclick="event.stopPropagation()">/mo
            </span>
          </label>
          <label class="acf-feature">
            <input type="checkbox" name="eagle_eye_access" id="edit-eagle_eye_access" value="1">
            <span>Advance Pan India</span>
            <span class="acf-limit" title="How many Advance Pan India searches this agent can run per calendar month - shares a single monthly plan pool on theeagleeye.biz. Ignored for admins.">
              <input type="number" name="eagle_eye_monthly_limit" id="edit-eagle_eye_monthly_limit" value="5" min="0" max="65535" onclick="event.stopPropagation()">/mo
            </span>
          </label>
          <label class="acf-feature">
            <input type="checkbox" name="pan_india_pro_access" id="edit-pan_india_pro_access" value="1">
            <span>Night Out</span>
            <span class="acf-limit" title="How many Night Out searches this agent can run per calendar month - shares a single daily quota on the vendor's side. Ignored for admins.">
              <input type="number" name="pan_india_pro_monthly_limit" id="edit-pan_india_pro_monthly_limit" value="5" min="0" max="65535" onclick="event.stopPropagation()">/mo
            </span>
          </label>
          <label class="acf-feature">
            <input type="checkbox" name="advanced_search_access" id="edit-advanced_search_access" value="1">
            <span>Advanced Search</span>
            <span class="acf-limit" title="How many Advanced Search searches this agent can run per calendar month - shares a single account's own daily/IP quota on tracekart.in. Ignored for admins.">
              <input type="number" name="advanced_search_monthly_limit" id="edit-advanced_search_monthly_limit" value="5" min="0" max="65535" onclick="event.stopPropagation()">/mo
            </span>
          </label>
        </div>
      </div>
      <div class="edit-settings-grid">
        <div class="form-group">
          <label class="form-label" for="edit-max_concurrent_sessions"
                 title="How many devices can be signed into this account at the same time. Logging in beyond this limit signs out whichever device has been idle longest.">
            Max Simultaneous Logins
          </label>
          <input type="number" class="form-control" name="max_concurrent_sessions" id="edit-max_concurrent_sessions"
                 value="1" min="1" max="50">
        </div>
        <div class="form-group">
          <label class="form-label" for="edit-expires_date">Expiry Date</label>
          <input type="text" class="form-control" name="expires_date" id="edit-expires_date"
                 placeholder="DD/MM/YYYY" pattern="\d{2}/\d{2}/\d{4}" maxlength="10" title="DD/MM/YYYY — leave blank for no expiry">
        </div>
        <div class="form-group">
          <label class="form-label" for="edit-expires_time">Expiry Time</label>
          <input type="time" class="form-control" name="expires_time" id="edit-expires_time" title="Defaults to 00:00">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label" for="edit-new_password">New Password</label>
        <div class="password-field-wrap">
          <input type="password" class="form-control" name="new_password" id="edit-new_password"
                 minlength="6" placeholder="Leave blank to keep current password" autocomplete="new-password">
          <button type="button" class="password-toggle-btn" id="edit-password-toggle-btn" aria-label="Show password">
            <i class="bi bi-eye-fill" id="edit-password-toggle-icon"></i>
          </button>
        </div>
        <div class="text-sm text-muted" style="margin-top:4px">Leave blank to keep the current password unchanged. Setting a new one signs the account out everywhere.</div>
      </div>
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-circle"></i> Save Changes
      </button>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
function openEditModal(id, username, fullName, mobileNo, role, lpgAccess, rcPrintAccess, rcPrintMonthlyLimit, hpGasAccess, hpGasMonthlyLimit, eagleEyeAccess, eagleEyeMonthlyLimit, panIndiaAccess, panIndiaProAccess, panIndiaProMonthlyLimit, advancedSearchAccess, advancedSearchMonthlyLimit, maxSessions, expiresDate, expiresTime) {
  document.getElementById('edit-id').value = id;
  document.getElementById('edit-username').value = username;
  document.getElementById('edit-full_name').value = fullName;
  document.getElementById('edit-mobile_no').value = mobileNo;
  document.getElementById('edit-role').value = role;
  document.getElementById('edit-lpg_search_access').checked = !!lpgAccess;
  document.getElementById('edit-rc_print_access').checked = !!rcPrintAccess;
  document.getElementById('edit-rc_print_monthly_limit').value = rcPrintMonthlyLimit;
  document.getElementById('edit-hp_gas_access').checked = !!hpGasAccess;
  document.getElementById('edit-hp_gas_monthly_limit').value = hpGasMonthlyLimit;
  document.getElementById('edit-eagle_eye_access').checked = !!eagleEyeAccess;
  document.getElementById('edit-eagle_eye_monthly_limit').value = eagleEyeMonthlyLimit;
  document.getElementById('edit-pan_india_access').checked = !!panIndiaAccess;
  document.getElementById('edit-pan_india_pro_access').checked = !!panIndiaProAccess;
  document.getElementById('edit-pan_india_pro_monthly_limit').value = panIndiaProMonthlyLimit;
  document.getElementById('edit-advanced_search_access').checked = !!advancedSearchAccess;
  document.getElementById('edit-advanced_search_monthly_limit').value = advancedSearchMonthlyLimit;
  document.getElementById('edit-max_concurrent_sessions').value = maxSessions;
  document.getElementById('edit-expires_date').value = expiresDate;
  document.getElementById('edit-expires_time').value = expiresTime;
  document.getElementById('edit-new_password').value = '';
  document.getElementById('edit-modal-overlay').style.display = 'flex';
}
function closeEditModal() {
  document.getElementById('edit-modal-overlay').style.display = 'none';
}
document.getElementById('edit-password-toggle-btn').addEventListener('click', () => {
  const input = document.getElementById('edit-new_password');
  const icon  = document.getElementById('edit-password-toggle-icon');
  const showing = input.type === 'text';
  input.type = showing ? 'password' : 'text';
  icon.className = showing ? 'bi bi-eye-fill' : 'bi bi-eye-slash-fill';
});
document.getElementById('create-password-toggle-btn').addEventListener('click', () => {
  const input = document.getElementById('create-password');
  const icon  = document.getElementById('create-password-toggle-icon');
  const showing = input.type === 'text';
  input.type = showing ? 'password' : 'text';
  icon.className = showing ? 'bi bi-eye-fill' : 'bi bi-eye-slash-fill';
});

/* Expiry date fields are plain text (DD/MM/YYYY) rather than native
   <input type="date"> — a native date input's displayed format follows the
   browser/OS locale (e.g. shows mm/dd/yyyy on an en-US browser regardless of
   this page), which can't be forced to DD/MM/YYYY from the page itself.
   Convert to the ISO format the backend/DB expect right before each form
   submits, so expires_date still posts as YYYY-MM-DD like before. */
function expiryDatePad(n) { return String(n).padStart(2, '0'); }
function expiryDateValid(y, mo, d) {
  if (mo < 1 || mo > 12 || d < 1) return false;
  return d <= new Date(y, mo, 0).getDate();
}
function parseExpiryDate(s) {
  s = (s || '').trim();
  if (!s) return '';
  const m = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(s);
  if (!m) return null;
  const d = +m[1], mo = +m[2], y = +m[3];
  return expiryDateValid(y, mo, d) ? `${y}-${expiryDatePad(mo)}-${expiryDatePad(d)}` : null;
}
document.querySelectorAll('form.agent-create-form, form.expiry-form, #edit-form').forEach(form => {
  form.addEventListener('submit', e => {
    const input = form.querySelector('input[name="expires_date"]');
    if (!input) return;
    const iso = parseExpiryDate(input.value);
    if (iso === null) {
      e.preventDefault();
      alert('Expiry date must be a valid DD/MM/YYYY date, or left blank.');
      input.focus();
      return;
    }
    input.value = iso;
  });
});

/* Guard against double-submitting Create Account (found 2026-08-09 - a slow
   response or an accidental double-click could send the same "create" POST
   twice; the first request succeeds and the second then fails on the now-real
   duplicate username, showing a confusing "already exists" error for an
   account that was in fact just created). Runs after the expiry-date
   validation above, so a rejected (preventDefault'd) submit leaves the
   button alone for the agent to fix and resubmit. */
document.querySelector('form.agent-create-form').addEventListener('submit', e => {
  if (e.defaultPrevented) return;
  const btn = e.target.querySelector('button[type="submit"]');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Creating…';
  }
});

/* Account search/filter — plain client-side row show/hide (this table tops
   out around a few hundred rows, so no need for DataTables here). Matches
   against username, full name, and mobile number together (see data-search
   on each <tr>), combined with the role dropdown. */
(function () {
  const searchInput = document.getElementById('account-search-input');
  const roleFilter   = document.getElementById('account-role-filter');
  const rows          = Array.from(document.querySelectorAll('#accounts-table tbody tr'));
  const countBadge    = document.getElementById('accounts-count');

  function applyFilter() {
    const q    = searchInput.value.trim().toLowerCase();
    const role = roleFilter.value;
    let visible = 0;
    rows.forEach(row => {
      const matchesSearch = q === '' || row.dataset.search.includes(q);
      const matchesRole   = role === '' || row.dataset.role === role;
      const show = matchesSearch && matchesRole;
      row.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    countBadge.textContent = visible === rows.length ? `${rows.length} total` : `${visible} of ${rows.length}`;
  }
  searchInput.addEventListener('input', applyFilter);
  roleFilter.addEventListener('change', applyFilter);
})();

/* Download Excel - the full account list (not just whatever the search/role
   filter above currently shows), same tokens/columns as the table itself.
   Built from server-emitted data rather than scraping the DOM, since the
   table's own cells are badges/forms, not plain text. */
const AGENTS_EXPORT_DATA = <?= json_encode(array_map(function ($u) {
    $isExpired = $u['expires_at'] !== null && strtotime($u['expires_at']) <= time();
    return [
        'id' => $u['id'],
        'username' => $u['username'],
        'full_name' => $u['full_name'],
        'mobile_no' => $u['mobile_no'] ?? '',
        'role' => ucfirst($u['role']),
        'status' => $isExpired ? 'Expired' : ($u['is_active'] ? 'Active' : 'Paused'),
        'lpg' => $u['lpg_search_access'] ? 'Granted' : 'Not Granted',
        'pan_india' => $u['pan_india_access'] ? 'Granted' : 'Not Granted',
        'rc_print' => $u['rc_print_access'] ? "Granted ({$u['rc_print_monthly_limit']}/mo)" : 'Not Granted',
        'hp_gas' => $u['hp_gas_access'] ? "Granted ({$u['hp_gas_monthly_limit']}/mo)" : 'Not Granted',
        'adv_pan_india' => $u['eagle_eye_access'] ? "Granted ({$u['eagle_eye_monthly_limit']}/mo)" : 'Not Granted',
        'pan_india_pro' => $u['pan_india_pro_access'] ? "Granted ({$u['pan_india_pro_monthly_limit']}/mo)" : 'Not Granted',
        'advanced_search' => $u['advanced_search_access'] ? "Granted ({$u['advanced_search_monthly_limit']}/mo)" : 'Not Granted',
        'max_logins' => $u['max_concurrent_sessions'],
        'expires_at' => $u['expires_at'] ? date('d/m/Y H:i', strtotime($u['expires_at'])) : 'No expiry',
        'created_at' => $u['created_at'] ? date('d/m/Y H:i', strtotime($u['created_at'])) : '',
        'last_login_at' => $u['last_login_at'] ? date('d/m/Y H:i', strtotime($u['last_login_at'])) : 'Never',
    ];
}, $users), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

document.getElementById('export-accounts-btn').addEventListener('click', () => {
  const headers = ['ID', 'Username', 'Full Name', 'Mobile Number', 'Role', 'Status', 'LPG', 'Pan India', 'RC Print', 'HP Gas', 'Adv. Pan India', 'Night Out', 'Advanced Search', 'Max Logins', 'Expiry', 'Created At', 'Last Login'];
  const aoa = [headers, ...AGENTS_EXPORT_DATA.map(u => [
    u.id, u.username, u.full_name, u.mobile_no, u.role, u.status, u.lpg, u.pan_india,
    u.rc_print, u.hp_gas, u.adv_pan_india, u.pan_india_pro, u.advanced_search, u.max_logins, u.expires_at, u.created_at, u.last_login_at,
  ])];
  const ws = XLSX.utils.aoa_to_sheet(aoa);
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Accounts');
  const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-');
  XLSX.writeFile(wb, `crm-agents-export-${stamp}.xlsx`);
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>

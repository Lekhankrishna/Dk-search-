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
        $panIndiaAccess = isset($_POST['pan_india_access']) ? 1 : 0;
        $maxSessions = max(1, (int) ($_POST['max_concurrent_sessions'] ?? 1));
        $expiresDate  = trim($_POST['expires_date'] ?? '');
        $expiresTime  = trim($_POST['expires_time'] ?? '') ?: '00:00';
        $expiresAtSql = $expiresDate !== '' ? "$expiresDate $expiresTime:00" : null;

        if ($username === '' || $fullName === '' || strlen($password) < 6) {
            $message     = 'Username, full name, and a password of at least 6 characters are required.';
            $messageType = 'danger';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO users (username, password_hash, full_name, mobile_no, role, lpg_search_access, pan_india_access, max_concurrent_sessions, expires_at)
                 VALUES (:username, :hash, :full_name, :mobile_no, :role, :lpg_access, :pan_india_access, :max_sessions, :expires_at)'
            );
            try {
                $stmt->execute([
                    'username'  => $username,
                    'hash'      => password_hash($password, PASSWORD_DEFAULT),
                    'full_name' => $fullName,
                    'mobile_no' => $mobileNo !== '' ? $mobileNo : null,
                    'role'      => $role,
                    'lpg_access'=> $lpgAccess,
                    'pan_india_access' => $panIndiaAccess,
                    'max_sessions' => $maxSessions,
                    'expires_at'=> $expiresAtSql,
                ]);
                $message = "Account <strong>" . htmlspecialchars($username) . "</strong> created successfully.";
            } catch (PDOException $e) {
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
        $panIndiaAccess = isset($_POST['pan_india_access']) ? 1 : 0;
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
            $sql = 'UPDATE users SET username = :username, full_name = :full_name, mobile_no = :mobile_no, role = :role, lpg_search_access = :lpg_access, pan_india_access = :pan_india_access, max_concurrent_sessions = :max_sessions, expires_at = :expires_at';
            $params = [
                'username'  => $username,
                'full_name' => $fullName,
                'mobile_no' => $mobileNo !== '' ? $mobileNo : null,
                'role'      => $role,
                'lpg_access'=> $lpgAccess,
                'pan_india_access' => $panIndiaAccess,
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
                $message     = 'Could not update account — username may already be taken.';
                $messageType = 'danger';
            }
        }
    } elseif ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id !== (int) $_SESSION['user_id']) {
            $stmt = $pdo->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = :id');
            $stmt->execute(['id' => $id]);
        }
    } elseif ($action === 'toggle_lpg') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE users SET lpg_search_access = 1 - lpg_search_access WHERE id = :id');
        $stmt->execute(['id' => $id]);
    } elseif ($action === 'toggle_pan_india') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE users SET pan_india_access = 1 - pan_india_access WHERE id = :id');
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
    'SELECT id, username, full_name, mobile_no, role, is_active, lpg_search_access, lpg_bookmarklet_key, pan_india_access, max_concurrent_sessions, expires_at, created_at, last_login_at FROM users ORDER BY created_at DESC'
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
  <form method="post" class="inline-form">
    <input type="hidden" name="action" value="create">
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
    <label style="display:flex;align-items:center;gap:6px;font-size:13px;white-space:nowrap;color:var(--c-text)">
      <input type="checkbox" name="lpg_search_access" value="1" style="width:auto"> LPG Search Access
    </label>
    <label style="display:flex;align-items:center;gap:6px;font-size:13px;white-space:nowrap;color:var(--c-text)">
      <input type="checkbox" name="pan_india_access" value="1" style="width:auto"> Pan India Access
    </label>
    <label style="display:flex;align-items:center;gap:6px;font-size:13px;white-space:nowrap;color:var(--c-text)"
           title="How many devices can be signed into this account at the same time. Logging in beyond this limit signs out whichever device has been idle longest.">
      Max Simultaneous Logins
      <input type="number" name="max_concurrent_sessions" value="1" min="1" max="50" style="width:60px">
    </label>
    <input type="text" name="expires_date" placeholder="DD/MM/YYYY" pattern="\d{2}/\d{2}/\d{4}" maxlength="10"
           title="Expiry date, DD/MM/YYYY (leave blank for no expiry)" style="min-width:140px">
    <input type="time" name="expires_time" title="Expiry time (defaults to 00:00)" style="min-width:110px">
    <button type="submit" class="btn btn-primary btn-sm">
      <i class="bi bi-person-plus"></i> Create Account
    </button>
  </form>
</div>

<!-- Users table -->
<div class="card">
  <div class="card-header">
    <i class="bi bi-list-ul" style="color:var(--c-accent)"></i>
    <span class="card-title">All Accounts</span>
    <span class="badge badge-neutral" id="accounts-count"><?= count($users) ?> total</span>
    <div style="margin-left:auto;display:flex;align-items:center;gap:8px">
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
                    onclick="openEditModal(<?= (int) $u['id'] ?>, <?= htmlspecialchars(json_encode($u['username']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($u['full_name']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($u['mobile_no'] ?? ''), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($u['role']), ENT_QUOTES) ?>, <?= (int) $u['lpg_search_access'] ?>, <?= (int) $u['pan_india_access'] ?>, <?= (int) $u['max_concurrent_sessions'] ?>, <?= htmlspecialchars(json_encode($expiryDateValue), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($expiryTimeValue), ENT_QUOTES) ?>)">
              <i class="bi bi-pencil-square"></i> Edit
            </button>
            <form method="post" style="display:inline">
              <input type="hidden" name="action" value="toggle_lpg">
              <input type="hidden" name="id"     value="<?= (int) $u['id'] ?>">
              <button type="submit" class="btn btn-sm <?= $u['lpg_search_access'] ? 'btn-warning' : 'btn-secondary' ?>"
                      title="<?= $u['lpg_search_access'] ? 'Revoke LPG Search access' : 'Grant LPG Search access' ?>">
                <i class="bi bi-fuel-pump-fill"></i> LPG
              </button>
            </form>
            <form method="post" style="display:inline">
              <input type="hidden" name="action" value="toggle_pan_india">
              <input type="hidden" name="id"     value="<?= (int) $u['id'] ?>">
              <button type="submit" class="btn btn-sm <?= $u['pan_india_access'] ? 'btn-warning' : 'btn-secondary' ?>"
                      title="<?= $u['pan_india_access'] ? 'Revoke Pan India access' : 'Grant Pan India access' ?>">
                <i class="bi bi-globe-asia-australia"></i> Pan India
              </button>
            </form>
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
      <div class="form-group">
        <label class="form-label" style="display:flex;align-items:center;gap:8px">
          <input type="checkbox" name="lpg_search_access" id="edit-lpg_search_access" value="1" style="width:auto">
          LPG Search Access
        </label>
      </div>
      <div class="form-group">
        <label class="form-label" style="display:flex;align-items:center;gap:8px">
          <input type="checkbox" name="pan_india_access" id="edit-pan_india_access" value="1" style="width:auto">
          Pan India Access
        </label>
      </div>
      <div class="form-group">
        <label class="form-label" for="edit-max_concurrent_sessions"
               title="How many devices can be signed into this account at the same time. Logging in beyond this limit signs out whichever device has been idle longest.">
          Max Simultaneous Logins
        </label>
        <input type="number" class="form-control" name="max_concurrent_sessions" id="edit-max_concurrent_sessions"
               value="1" min="1" max="50" style="width:100px">
      </div>
      <div class="form-group">
        <label class="form-label">Expiry Date &amp; Time</label>
        <div style="display:flex;gap:8px">
          <input type="text" class="form-control" name="expires_date" id="edit-expires_date"
                 placeholder="DD/MM/YYYY" pattern="\d{2}/\d{2}/\d{4}" maxlength="10" title="DD/MM/YYYY — leave blank for no expiry">
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

<script>
function openEditModal(id, username, fullName, mobileNo, role, lpgAccess, panIndiaAccess, maxSessions, expiresDate, expiresTime) {
  document.getElementById('edit-id').value = id;
  document.getElementById('edit-username').value = username;
  document.getElementById('edit-full_name').value = fullName;
  document.getElementById('edit-mobile_no').value = mobileNo;
  document.getElementById('edit-role').value = role;
  document.getElementById('edit-lpg_search_access').checked = !!lpgAccess;
  document.getElementById('edit-pan_india_access').checked = !!panIndiaAccess;
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
document.querySelectorAll('form.inline-form, form.expiry-form, #edit-form').forEach(form => {
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
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>

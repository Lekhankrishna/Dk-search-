<?php
require __DIR__ . '/../includes/auth.php';
requireAdmin('../login.php');
require_once __DIR__ . '/../config/db.php';

$message     = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_global') {
        $isEnabled      = isset($_POST['is_enabled']) ? 1 : 0;
        $phoneNumber    = preg_replace('/\D+/', '', $_POST['phone_number'] ?? '');
        $defaultMessage = trim($_POST['default_message'] ?? '');

        if ($phoneNumber === '' || strlen($phoneNumber) < 10) {
            $message     = 'Enter a valid WhatsApp number with country code (digits only, e.g. 919901431238).';
            $messageType = 'danger';
        } elseif ($defaultMessage === '') {
            $message     = 'Default message is required.';
            $messageType = 'danger';
        } else {
            $pdo->prepare(
                'UPDATE whatsapp_settings SET is_enabled = :en, phone_number = :ph, default_message = :msg, updated_by = :by WHERE id = 1'
            )->execute([
                'en'  => $isEnabled,
                'ph'  => $phoneNumber,
                'msg' => $defaultMessage,
                'by'  => $_SESSION['user_id'],
            ]);
            $message = $isEnabled
                ? 'WhatsApp button enabled and settings saved.'
                : 'WhatsApp button disabled. It is now hidden for every user.';
        }
    } elseif ($action === 'save_users') {
        $selectedIds = array_map('intval', $_POST['selected_users'] ?? []);
        // Applied as one transaction so the allowlist is replaced atomically
        // (no window where it's briefly all-off or a stale mix of old+new).
        $pdo->beginTransaction();
        $pdo->exec('UPDATE users SET whatsapp_button_access = 0');
        if ($selectedIds) {
            $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
            $pdo->prepare("UPDATE users SET whatsapp_button_access = 1 WHERE id IN ($placeholders)")
                ->execute($selectedIds);
        }
        $pdo->commit();
        $message = 'WhatsApp access list updated — takes effect immediately for every selected user.';
    }
}

$settings = $pdo->query('SELECT is_enabled, phone_number, default_message FROM whatsapp_settings WHERE id = 1')->fetch();
$users = $pdo->query(
    'SELECT id, username, full_name, role, whatsapp_button_access FROM users ORDER BY full_name'
)->fetchAll();

$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-whatsapp"></i> WhatsApp Button Settings</h1>
  <p class="page-subtitle">Control the floating WhatsApp contact button shown across the portal.</p>
</div>

<?php if ($message): ?>
  <div class="notice notice-<?= htmlspecialchars($messageType) ?>">
    <i class="bi bi-<?= $messageType === 'danger' ? 'exclamation-triangle-fill' : 'check-circle-fill' ?>"></i>
    <?= htmlspecialchars($message) ?>
  </div>
<?php endif; ?>

<div class="card mb-4" style="max-width:520px">
  <div class="card-header">
    <i class="bi bi-toggle-on" style="color:var(--c-accent)"></i>
    <span class="card-title">Global Switch</span>
  </div>
  <div class="card-body">
    <p class="text-sm text-muted" style="margin-top:0">
      When disabled, the button is hidden for <strong>every</strong> user regardless of the access list below.
      When enabled, it is shown only to the users selected below.
    </p>
    <form method="post">
      <input type="hidden" name="action" value="save_global">
      <div class="form-group">
        <label class="form-label" style="display:flex;align-items:center;gap:8px">
          <input type="checkbox" name="is_enabled" value="1" style="width:auto" <?= $settings['is_enabled'] ? 'checked' : '' ?>>
          Enable WhatsApp button
        </label>
      </div>
      <div class="form-group">
        <label class="form-label" for="phone_number">WhatsApp Number (with country code, digits only)</label>
        <input type="text" class="form-control" name="phone_number" id="phone_number"
               value="<?= htmlspecialchars($settings['phone_number']) ?>" placeholder="919901431238" required>
      </div>
      <div class="form-group">
        <label class="form-label" for="default_message">Default Message</label>
        <input type="text" class="form-control" name="default_message" id="default_message"
               value="<?= htmlspecialchars($settings['default_message']) ?>" required>
      </div>
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-circle"></i> Save
      </button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <i class="bi bi-people-fill" style="color:var(--c-accent)"></i>
    <span class="card-title">Users Allowed to See the Button</span>
    <span class="badge badge-neutral" id="wa-users-count"><?= count($users) ?> total</span>
    <div style="margin-left:auto">
      <div class="dt-search-box">
        <i class="bi bi-search"></i>
        <input type="text" id="wa-user-search-input" placeholder="Search username or name…">
      </div>
    </div>
  </div>
  <div class="card-body p-0">
    <form method="post" id="wa-users-form">
      <input type="hidden" name="action" value="save_users">
      <table class="results-table agents-table" id="wa-users-table">
        <thead>
          <tr>
            <th style="width:44px"><input type="checkbox" id="wa-select-all"></th>
            <th style="width:150px">Username</th>
            <th>Full Name</th>
            <th style="width:90px">Role</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
          <tr data-search="<?= htmlspecialchars(strtolower($u['username'] . ' ' . $u['full_name'])) ?>">
            <td>
              <input type="checkbox" name="selected_users[]" value="<?= (int) $u['id'] ?>"
                     class="wa-user-checkbox" <?= $u['whatsapp_button_access'] ? 'checked' : '' ?>>
            </td>
            <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
            <td><?= htmlspecialchars($u['full_name']) ?></td>
            <td><span class="badge <?= $u['role'] === 'admin' ? 'badge-warning' : 'badge-info' ?>"><?= ucfirst($u['role']) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div style="padding:16px">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-check-circle"></i> Save Selected Users
        </button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var searchInput = document.getElementById('wa-user-search-input');
  var rows         = Array.from(document.querySelectorAll('#wa-users-table tbody tr'));
  var countBadge   = document.getElementById('wa-users-count');
  var selectAll    = document.getElementById('wa-select-all');

  function applyFilter() {
    var q = searchInput.value.trim().toLowerCase();
    var visible = 0;
    rows.forEach(function (row) {
      var show = q === '' || row.dataset.search.includes(q);
      row.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    countBadge.textContent = visible === rows.length ? (rows.length + ' total') : (visible + ' of ' + rows.length);
  }
  searchInput.addEventListener('input', applyFilter);

  selectAll.addEventListener('change', function () {
    rows.forEach(function (row) {
      if (row.style.display === 'none') return;
      var box = row.querySelector('.wa-user-checkbox');
      if (box) box.checked = selectAll.checked;
    });
  });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>

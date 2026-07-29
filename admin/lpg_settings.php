<?php
require __DIR__ . '/../includes/auth.php';
requireAdmin('../login.php');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/lpg_crypto.php';

$message     = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sdmsUsername = trim($_POST['sdms_username'] ?? '');
    $sdmsPassword = $_POST['sdms_password'] ?? '';

    if ($sdmsUsername === '') {
        $message     = 'SDMS User ID is required.';
        $messageType = 'danger';
    } elseif ($sdmsPassword === '' && !$pdo->query('SELECT id FROM lpg_credentials WHERE id = 1')->fetch()) {
        $message     = 'A password is required the first time credentials are set.';
        $messageType = 'danger';
    } else {
        if ($sdmsPassword !== '') {
            $enc = lpgEncrypt($sdmsPassword);
            $pdo->prepare(
                'INSERT INTO lpg_credentials (id, sdms_username, password_ciphertext, password_iv, password_tag, updated_by)
                 VALUES (1, :u, :ct, :iv, :tag, :by)
                 ON DUPLICATE KEY UPDATE sdms_username = :u2, password_ciphertext = :ct2, password_iv = :iv2, password_tag = :tag2, updated_by = :by2'
            )->execute([
                'u' => $sdmsUsername, 'ct' => $enc['ciphertext'], 'iv' => $enc['iv'], 'tag' => $enc['tag'], 'by' => $_SESSION['user_id'],
                'u2' => $sdmsUsername, 'ct2' => $enc['ciphertext'], 'iv2' => $enc['iv'], 'tag2' => $enc['tag'], 'by2' => $_SESSION['user_id'],
            ]);
        } else {
            // Username-only update — keep the existing encrypted password untouched.
            $pdo->prepare('UPDATE lpg_credentials SET sdms_username = :u, updated_by = :by WHERE id = 1')
                ->execute(['u' => $sdmsUsername, 'by' => $_SESSION['user_id']]);
        }
        $message = 'SDMS credentials saved.';
    }
}

$current = $pdo->query('SELECT sdms_username, updated_at FROM lpg_credentials WHERE id = 1')->fetch();

$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-key-fill"></i> LPG Portal Credentials</h1>
  <p class="page-subtitle">The single shared IndianOil SDMS login used to autofill authorized agents' bookmarklets.</p>
</div>

<?php if ($message): ?>
  <div class="notice notice-<?= htmlspecialchars($messageType) ?>">
    <i class="bi bi-<?= $messageType === 'danger' ? 'exclamation-triangle-fill' : 'check-circle-fill' ?>"></i>
    <?= htmlspecialchars($message) ?>
  </div>
<?php endif; ?>

<div class="card" style="max-width:480px">
  <div class="card-header">
    <i class="bi bi-shield-lock-fill" style="color:var(--c-accent)"></i>
    <span class="card-title">SDMS Login</span>
  </div>
  <div class="card-body">
    <?php if ($current): ?>
      <p class="text-sm text-muted" style="margin-top:0">
        Currently set for User ID <strong><?= htmlspecialchars($current['sdms_username']) ?></strong>
        (updated <?= htmlspecialchars($current['updated_at']) ?>). The password is encrypted at rest and never
        shown here again — leave it blank below to keep it unchanged.
      </p>
    <?php else: ?>
      <p class="text-sm text-muted" style="margin-top:0">Not configured yet — the LPG Search bookmarklet won't work until this is set.</p>
    <?php endif; ?>
    <form method="post">
      <div class="form-group">
        <label class="form-label" for="sdms_username">SDMS User ID</label>
        <input type="text" class="form-control" name="sdms_username" id="sdms_username"
               value="<?= htmlspecialchars($current['sdms_username'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label" for="sdms_password">SDMS Password</label>
        <div class="password-field-wrap">
          <input type="password" class="form-control" name="sdms_password" id="sdms_password"
                 placeholder="<?= $current ? 'Leave blank to keep current password' : 'Required' ?>" autocomplete="new-password">
          <button type="button" class="password-toggle-btn" id="sdms-password-toggle-btn" aria-label="Show password">
            <i class="bi bi-eye-fill" id="sdms-password-toggle-icon"></i>
          </button>
        </div>
      </div>
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-circle"></i> Save Credentials
      </button>
    </form>
  </div>
</div>

<script>
document.getElementById('sdms-password-toggle-btn').addEventListener('click', () => {
  const input = document.getElementById('sdms_password');
  const icon  = document.getElementById('sdms-password-toggle-icon');
  const showing = input.type === 'text';
  input.type = showing ? 'password' : 'text';
  icon.className = showing ? 'bi bi-eye-fill' : 'bi bi-eye-slash-fill';
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>

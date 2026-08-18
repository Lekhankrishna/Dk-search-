<?php
require __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :username AND is_active = 1');
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        if ($user['expires_at'] !== null && strtotime($user['expires_at']) <= time()) {
            $error = 'This account has expired. Please contact an administrator.';
        } else {
            // Each account gets up to max_concurrent_sessions active device
            // slots (Admin > Agents > "Max Simultaneous Logins", default 1 -
            // same as the old single-session behaviour). A login beyond that
            // limit evicts the least-recently-used session rather than being
            // refused - same "a new login always wins" spirit the old
            // single-token version had, just extended past one slot instead
            // of always kicking the only other session.
            $maxSessions = max(1, (int) $user['max_concurrent_sessions']);
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM user_sessions WHERE user_id = :id');
            $countStmt->execute(['id' => $user['id']]);
            $currentCount = (int) $countStmt->fetchColumn();
            $toEvict = max(0, $currentCount - $maxSessions + 1);
            if ($toEvict > 0) {
                // $toEvict is derived from a COUNT(), not user input - safe to
                // interpolate; PDO can't bind LIMIT as a parameter.
                $pdo->prepare("DELETE FROM user_sessions WHERE user_id = :id ORDER BY last_seen_at ASC LIMIT {$toEvict}")
                    ->execute(['id' => $user['id']]);
            }

            $token = bin2hex(random_bytes(32));
            $pdo->prepare('INSERT INTO user_sessions (user_id, session_token) VALUES (:id, :token)')
                ->execute(['id' => $user['id'], 'token' => $token]);
            $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')
                ->execute(['id' => $user['id']]);

            session_regenerate_id(true);
            $_SESSION['user_id']       = $user['id'];
            $_SESSION['username']      = $user['username'];
            $_SESSION['full_name']     = $user['full_name'];
            $_SESSION['role']          = $user['role'];
            $_SESSION['expires_at']    = $user['expires_at'];
            $_SESSION['session_token'] = $token;
            header('Location: index.php');
            exit;
        }
    } else {
        $error = 'Invalid username or password.';
    }
}

if ($error === '' && ($_GET['reason'] ?? '') === 'session_replaced') {
    $error = 'You have been signed out because this account was signed in from another device.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <script>document.documentElement.setAttribute('data-theme', localStorage.getItem('crm-theme') || 'light');</script>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>DK Search — Login</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: time() ?>">
</head>
<body class="login-page">
  <div class="login-wrap">
    <div class="login-box">

      <div class="login-logo">
        <div class="login-logo__icon">
          <i class="bi bi-search"></i>
        </div>
        <h1 class="login-logo__title">DK Search</h1>
        <p class="login-logo__sub">Secure sign-in to your account</p>
      </div>

      <?php if ($error): ?>
        <div class="login-error">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <form method="post" autocomplete="on">
        <div class="form-group">
          <label class="form-label" for="username">Username</label>
          <input id="username" class="form-control" type="text" name="username"
                 required autofocus autocomplete="username"
                 placeholder="Enter your username">
        </div>
        <div class="form-group">
          <label class="form-label" for="password">Password</label>
          <div class="password-field-wrap">
            <input id="password" class="form-control" type="password" name="password"
                   required autocomplete="current-password"
                   placeholder="Enter your password">
            <button type="button" class="password-toggle-btn" id="password-toggle-btn"
                    aria-label="Show password" aria-pressed="false">
              <i class="bi bi-eye-fill" id="password-toggle-icon"></i>
            </button>
          </div>
        </div>
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-box-arrow-in-right"></i> Sign In
        </button>
      </form>

    </div>
  </div>
  <script>
    const pwInput = document.getElementById('password');
    const pwBtn   = document.getElementById('password-toggle-btn');
    const pwIcon  = document.getElementById('password-toggle-icon');
    pwBtn.addEventListener('click', () => {
      const showing = pwInput.type === 'text';
      pwInput.type = showing ? 'password' : 'text';
      pwIcon.className = showing ? 'bi bi-eye-fill' : 'bi bi-eye-slash-fill';
      pwBtn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
      pwBtn.setAttribute('aria-pressed', showing ? 'false' : 'true');
    });
  </script>
</body>
</html>

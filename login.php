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
            // A fresh token here invalidates any session already open elsewhere for
            // this user — the next request on that older session will see a
            // mismatch against this new DB value and get signed out automatically.
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('UPDATE users SET session_token = :token, last_login_at = NOW() WHERE id = :id')
                ->execute(['token' => $token, 'id' => $user['id']]);

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
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>lookup — Login</title>
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
        <h1 class="login-logo__title">lookup</h1>
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

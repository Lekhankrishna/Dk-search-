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
        } elseif (!isIpAllowed($user['allowed_ips'], clientIp())) {
            $error = 'This account cannot sign in from this network. Contact your administrator.';
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
            // Next page shows ACCESS GRANTED once (includes/footer.php).
            $_SESSION['login_granted'] = 1;
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
if ($error === '' && ($_GET['reason'] ?? '') === 'ip_restricted') {
    $error = 'You have been signed out because this account cannot be used from this network.';
}

$notice = '';
if ($error === '' && ($_GET['reason'] ?? '') === 'password_changed') {
    $notice = 'Your password was changed. Please sign in again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <script>document.documentElement.setAttribute('data-theme', localStorage.getItem('crm-theme') || 'dark');</script>
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
          <i class="bi bi-diagram-3-fill"></i>
        </div>
        <h1 class="login-logo__title">DK Search</h1>
        <p class="login-logo__sub">Secure sign-in to your account</p>
      </div>

      <?php // "Secure channel" boot log (2026-10-05, per explicit instruction) -
            // crm-app-v3's login terminal in DK's own green. Decorative; the
            // fixed lines in the script below are all it writes, plus ACCESS
            // DENIED when the sign-in just failed. ?>
      <div class="login-term" id="loginTerm" aria-hidden="true"
           data-denied="<?= $error !== '' ? '1' : '' ?>"></div>

      <?php if ($error): ?>
        <div class="login-error">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <?= htmlspecialchars($error) ?>
        </div>
      <?php elseif ($notice): ?>
        <div class="login-notice">
          <i class="bi bi-check-circle-fill"></i>
          <?= htmlspecialchars($notice) ?>
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
          <input id="password" class="form-control" type="password" name="password"
                 required autocomplete="current-password"
                 placeholder="Enter your password">
        </div>
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-box-arrow-in-right"></i> Sign In
        </button>
      </form>

    </div>
  </div>
<style>
  .login-term{margin:-12px 0 22px;padding:11px 14px;min-height:92px;border-radius:10px;
    background:var(--c-accent-light,#e5f5e8);border:1px solid rgba(46,158,63,.25);
    font:12px/1.65 Consolas,ui-monospace,monospace;color:var(--c-accent-hover,#257e32);white-space:pre-wrap;text-align:left;}
  .login-term .dim{opacity:.55;}
  .login-term .ok{color:var(--c-accent,#2e9e3f);font-weight:700;}
  .login-term .bad{color:#d6334f;font-weight:700;}
  .login-term .caret{display:inline-block;width:7px;height:12px;margin-left:2px;vertical-align:-1px;
    background:var(--c-accent,#2e9e3f);animation:loginCaret 1s steps(1) infinite;}
  @keyframes loginCaret{50%{opacity:0}}
  @media (prefers-reduced-motion: reduce){.login-term .caret{animation:none;}}
</style>
<script>
(function () {
  var box = document.getElementById('loginTerm');
  if (!box) return;
  var denied = box.getAttribute('data-denied') === '1';
  var lines = [
    ['$ ', 'init secure_channel --tls1.3', ''],
    ['', '[ OK ] handshake complete', 'ok'],
    ['', '[ OK ] firewall rules loaded', 'ok'],
    denied ? ['', '[ !! ] ACCESS DENIED - check your details', 'bad'] : ['$ ', 'awaiting operator credentials', '']
  ];
  function esc(t) { return t.replace(/&/g, '&amp;').replace(/</g, '&lt;'); }
  function lineHtml(l, text) {
    return '<span class="dim">' + l[0] + '</span>' + (l[2] ? '<span class="' + l[2] + '">' + esc(text) + '</span>' : esc(text));
  }
  var li = 0, ci = 0, done = '';
  (function type() {
    if (li >= lines.length) { box.innerHTML = done + ' <span class="caret"></span>'; return; }
    var l = lines[li];
    ci++;
    box.innerHTML = done + lineHtml(l, l[1].slice(0, ci)) + '<span class="caret"></span>';
    if (ci >= l[1].length) {
      done += lineHtml(l, l[1]) + (li < lines.length - 1 ? '\n' : '');
      li++; ci = 0;
      setTimeout(type, 240);
    } else {
      setTimeout(type, 20);
    }
  })();
  // On Sign In: one more line while the form goes through.
  var form = document.querySelector('.login-box form');
  if (form) form.addEventListener('submit', function () {
    box.innerHTML = done.replace(/\n?<span class="dim">\$ <\/span>awaiting operator credentials$/, '').replace(/\n?<span class="bad">.*<\/span>$/, '') +
      '\n<span class="dim">$ </span>verifying credentials ... <span class="caret"></span>';
  });
})();
</script>
</body>
</html>

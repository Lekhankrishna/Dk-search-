    </main>
  </div>
</div>

<?php if (hasWhatsAppButtonAccess()):
  $wa = whatsappSettings();
  $waHref = 'https://wa.me/' . rawurlencode($wa['phone_number']) . '?text=' . rawurlencode($wa['default_message']);
?>
<a href="<?= htmlspecialchars($waHref) ?>" class="wa-float-btn" target="_blank" rel="noopener noreferrer" aria-label="Chat on WhatsApp" title="Chat on WhatsApp">
  <i class="bi bi-whatsapp"></i>
</a>
<style>
/* Self-contained styles for the floating WhatsApp button - kept isolated
   here (like lpg_search.php's inline CSS) rather than in the shared
   stylesheet, since it's a single small site-wide widget. */
.wa-float-btn {
  position: fixed;
  right: 24px;
  bottom: 24px;
  width: 58px;
  height: 58px;
  border-radius: 50%;
  background: #25D366;
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 30px;
  box-shadow: 0 4px 14px rgba(0,0,0,.3);
  z-index: 9999;
  animation: wa-pulse 2s infinite;
  transition: transform .15s ease;
}
.wa-float-btn:hover { transform: scale(1.08); color: #fff; }
@keyframes wa-pulse {
  0%   { box-shadow: 0 4px 14px rgba(0,0,0,.3), 0 0 0 0 rgba(37,211,102,.55); }
  70%  { box-shadow: 0 4px 14px rgba(0,0,0,.3), 0 0 0 14px rgba(37,211,102,0); }
  100% { box-shadow: 0 4px 14px rgba(0,0,0,.3), 0 0 0 0 rgba(37,211,102,0); }
}
@media (max-width: 480px) {
  .wa-float-btn { right: 16px; bottom: 16px; width: 52px; height: 52px; font-size: 26px; }
}
</style>
<?php endif; ?>

<script>
(function(){
  var sidebar = document.getElementById('sidebar');
  // Two separate buttons toggle the same sidebar: #sidebar-toggle lives
  // inside <aside> itself (desktop collapse), #mobile-sidebar-toggle lives
  // in .app-topbar (the only way to open the sidebar on narrow viewports,
  // since <aside> - and the button inside it - is translated off-screen
  // there; see .app-topbar__menu-btn in assets/style.css).
  ['sidebar-toggle', 'mobile-sidebar-toggle'].forEach(function(id){
    var toggle = document.getElementById(id);
    if (toggle && sidebar) {
      toggle.addEventListener('click', function(){
        sidebar.classList.toggle('sidebar--open');
      });
    }
  });
})();
(function(){
  // The Dark Mode toggle button is gone (removed 2026-08-11, per explicit
  // instruction), but this still applies whatever theme was already saved
  // to localStorage - from before the button existed, or the 'dark' default
  // - so the choice a user already made keeps rendering correctly rather
  // than silently reverting everyone to 'dark'.
  document.documentElement.setAttribute('data-theme', localStorage.getItem('crm-theme') || 'dark');
})();
</script>
<!-- Change Password - reachable from the key icon next to Logout in the
     sidebar footer (see includes/header.php). Site-wide (this file loads on
     every authenticated page), reuses the same .modal-overlay/.modal-box
     shell as admin/agents.php's own edit modal. Submits to
     change_password.php via fetch rather than a normal form POST so a
     wrong-current-password error can be shown right here without a page
     reload/redirect dance across however many different pages this modal
     might be opened from. -->
<div class="modal-overlay" id="change-password-overlay" style="display:none" onclick="if(event.target===this) closeChangePasswordModal()">
  <div class="modal-box" style="max-width:420px">
    <div class="modal-header">
      <span><i class="bi bi-lock-fill"></i> Change Password</span>
      <button type="button" class="modal-close-btn" onclick="closeChangePasswordModal()" aria-label="Close">&times;</button>
    </div>
    <form id="change-password-form">
      <div id="change-password-error" class="login-error" style="display:none;margin:0 20px 14px"></div>
      <div class="form-group">
        <label class="form-label" for="cp-current">Current Password</label>
        <div class="password-field-wrap">
          <input type="password" class="form-control" id="cp-current" autocomplete="current-password" required>
          <button type="button" class="password-toggle-btn" data-target="cp-current" aria-label="Show password">
            <i class="bi bi-eye-fill"></i>
          </button>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label" for="cp-new">New Password</label>
        <div class="password-field-wrap">
          <input type="password" class="form-control" id="cp-new" minlength="6" autocomplete="new-password" required>
          <button type="button" class="password-toggle-btn" data-target="cp-new" aria-label="Show password">
            <i class="bi bi-eye-fill"></i>
          </button>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label" for="cp-confirm">Confirm New Password</label>
        <div class="password-field-wrap">
          <input type="password" class="form-control" id="cp-confirm" minlength="6" autocomplete="new-password" required>
          <button type="button" class="password-toggle-btn" data-target="cp-confirm" aria-label="Show password">
            <i class="bi bi-eye-fill"></i>
          </button>
        </div>
      </div>
      <div class="text-sm text-muted" style="margin:0 20px 14px">Changing your password signs you out everywhere, including this device - you'll need to sign in again.</div>
      <button type="submit" class="btn btn-primary" id="change-password-submit-btn">Change Password</button>
    </form>
  </div>
</div>
<script>
(function(){
  var overlay  = document.getElementById('change-password-overlay');
  var form     = document.getElementById('change-password-form');
  var errorBox = document.getElementById('change-password-error');
  var submitBtn = document.getElementById('change-password-submit-btn');
  if (!overlay || !form) return;

  window.closeChangePasswordModal = function(){
    overlay.style.display = 'none';
    form.reset();
    errorBox.style.display = 'none';
  };

  // Opened from the "Change Password" sidebar nav item (includes/header.php,
  // below TATA SKY DTH) via onclick="openChangePasswordModal()" - a global
  // function rather than a click listener bound to one button, since that
  // nav item is plain PHP-rendered markup sharing the generic .sidebar__item
  // loop's structure, not a dedicated element with its own id to bind to.
  window.openChangePasswordModal = function(){
    overlay.style.display = 'flex';
    document.getElementById('cp-current').focus();
  };

  form.querySelectorAll('.password-toggle-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var input = document.getElementById(btn.dataset.target);
      var icon  = btn.querySelector('i');
      var showing = input.type === 'text';
      input.type = showing ? 'password' : 'text';
      icon.className = showing ? 'bi bi-eye-fill' : 'bi bi-eye-slash-fill';
    });
  });

  form.addEventListener('submit', async function(event){
    event.preventDefault();
    var current = document.getElementById('cp-current').value;
    var next    = document.getElementById('cp-new').value;
    var confirm = document.getElementById('cp-confirm').value;
    errorBox.style.display = 'none';

    if (next !== confirm) {
      errorBox.textContent = 'New password and confirmation do not match.';
      errorBox.style.display = 'block';
      return;
    }

    submitBtn.disabled = true;
    submitBtn.textContent = 'Changing…';
    try {
      var body = new URLSearchParams();
      body.set('current_password', current);
      body.set('new_password', next);
      body.set('confirm_password', confirm);
      var res = await fetch('<?= $bp ?>change_password.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
        body: body
      });
      var data = await res.json();
      if (!res.ok || !data.ok) {
        errorBox.textContent = data.error || 'Could not change your password.';
        errorBox.style.display = 'block';
        submitBtn.disabled = false;
        submitBtn.textContent = 'Change Password';
        return;
      }
      window.location.href = '<?= $bp ?>login.php?reason=password_changed';
    } catch (err) {
      errorBox.textContent = 'Could not reach the server: ' + err.message;
      errorBox.style.display = 'block';
      submitBtn.disabled = false;
      submitBtn.textContent = 'Change Password';
    }
  });
})();
</script>
<!-- Loaded on every page so any search page can call startConfetti()/
     stopConfetti() - see assets/confetti.js. Needs its own
     #confetti-container div and success/clear hookup per page. -->
<script src="<?= $bp ?>assets/confetti.js?v=<?= @filemtime(__DIR__ . '/../assets/confetti.js') ?: time() ?>"></script>
<!-- "Hacking" console while any search loads, then ACCESS GRANTED /
     ACCESS DENIED (2026-10-05) - see assets/hack.js. After confetti.js. -->
<script src="<?= $bp ?>assets/hack.js?v=<?= @filemtime(__DIR__ . '/../assets/hack.js') ?: time() ?>"></script>
<?php if (!empty($_SESSION['login_granted'])): unset($_SESSION['login_granted']); ?>
<!-- Just signed in: ACCESS GRANTED once (2026-10-05) - set by login.php. -->
<script>
  if (typeof showAccessGranted === 'function') {
    showAccessGranted(<?= json_encode('Welcome, ' . (($_SESSION['full_name'] ?? '') !== '' ? $_SESSION['full_name'] : ($_SESSION['username'] ?? '')), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
  }
</script>
<?php endif; ?>
</body>
</html>

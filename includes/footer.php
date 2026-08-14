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
  // to localStorage - so a user who toggled to dark before the button was
  // removed keeps seeing it. Falls back to 'light' (not 'dark') for
  // everyone else (2026-08-14) - html[data-theme="dark"] only just grew
  // real colors of its own (assets/style.css), and with no toggle left in
  // the UI to reach it, defaulting new/no-preference visitors into it would
  // have silently changed the whole site's look with no way back.
  document.documentElement.setAttribute('data-theme', localStorage.getItem('crm-theme') || 'light');
})();
</script>
<!-- Loaded on every page so any search page can call startConfetti()/
     stopConfetti() - see assets/confetti.js. Needs its own
     #confetti-container div and success/clear hookup per page. -->
<script src="<?= $bp ?>assets/confetti.js?v=<?= @filemtime(__DIR__ . '/../assets/confetti.js') ?: time() ?>"></script>
</body>
</html>

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
  var toggle = document.getElementById('sidebar-toggle');
  var sidebar = document.getElementById('sidebar');
  if (toggle && sidebar) {
    toggle.addEventListener('click', function(){
      sidebar.classList.toggle('sidebar--open');
    });
  }
})();
(function(){
  var btn   = document.getElementById('theme-toggle-btn');
  var icon  = document.getElementById('theme-toggle-icon');
  var label = document.getElementById('theme-toggle-label');
  function apply(theme){
    document.documentElement.setAttribute('data-theme', theme);
    if (icon)  icon.className  = theme === 'light' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
    if (label) label.textContent = theme === 'light' ? 'Light Mode' : 'Dark Mode';
  }
  var current = localStorage.getItem('crm-theme') || 'dark';
  apply(current);
  if (btn) {
    btn.addEventListener('click', function(){
      current = current === 'light' ? 'dark' : 'light';
      localStorage.setItem('crm-theme', current);
      apply(current);
    });
  }
})();
</script>
</body>
</html>

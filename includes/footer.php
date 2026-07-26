    </main>
  </div>
</div>
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

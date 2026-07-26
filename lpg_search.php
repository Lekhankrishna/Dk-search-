<?php
require __DIR__ . '/includes/auth.php';
requireLpgSearchAccess(); // requireLogin() + a 403 for logged-in users without the "LPG Search Access" permission (Admin > Agents)

$basePath = '';
require __DIR__ . '/includes/header.php';
?>

<div class="page-header" style="display:flex;align-items:center;flex-wrap:wrap;gap:12px">
  <h1 class="page-title" style="margin:0"><i class="bi bi-fuel-pump-fill"></i> LPG Search</h1>
</div>

<div class="card" id="lpg-frame-card" style="height:calc(100vh - 140px)">
  <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;text-align:center;gap:14px;padding:20px">
    <i class="bi bi-fuel-pump-fill" style="font-size:32px;color:var(--c-accent)"></i>
    <div style="font-weight:700;color:var(--c-text);font-size:15px">LPG Search runs locally on your own computer</div>
    <div style="font-size:13px;color:var(--c-text-muted);max-width:460px">
      It opens in its own window — this tab stays right here.
    </div>
    <button type="button" id="lpgOpenBtn" class="btn btn-primary">
      <i class="bi bi-box-arrow-up-right"></i> Open LPG Search
    </button>
    <div style="font-size:12.5px;color:var(--c-text-soft);margin-top:6px">
      First time on this computer?
      <a href="#" id="lpgInstallLink">Download the one-time setup file</a> and run it, then click the button above.
    </div>
  </div>
</div>

<script>
// lpgtool_launcher.bat (registered once per computer via
// install_lpgtool_protocol.bat) starts the local Flask server if it isn't
// already running, then opens it in a Chrome "app mode" window - a clean,
// borderless window with no address bar UI at all (found 2026-07-26, after
// confirming a page loaded over plain HTTP can't embed the tool via
// iframe/fetch either - Chrome blocks that outright as a Private Network
// Access violation, "the request client is not a secure context", no matter
// what headers the local server sends back). Using the OS to launch Chrome
// directly like this sidesteps that restriction entirely, and - unlike the
// earlier "just navigate this tab to the raw URL" fallback - means this
// CRM tab's own address bar never has to change at all.
//
// No "?target=" query string here (unlike lpg_bulk_search.php) - the
// launcher defaults to the single-search page when none is given.
document.getElementById('lpgOpenBtn').addEventListener('click', () => {
  window.location.href = 'lpgtool://open?target=single';
});

// NOT fired automatically on page load (removed 2026-07-26): Chrome tracks
// automatic (non-user-gesture) redirects to external protocol handlers and
// silently throttles/blocks the whole scheme after enough of them in a
// short window - no dialog, no error, nothing visibly happens, which is
// exactly what made this so hard to diagnose. Confirmed by checking Chrome's
// own profile Preferences file: a
// safe_browsing.external_app_redirect_timestamps entry for "lpgtool" had
// just been recorded, timed to match repeated auto-reloads during testing.
// A real click is a genuine user gesture and isn't subject to the same
// throttle, so the button alone (which already shows the one-time "Open LPG
// Tool?" permission prompt on first use) is enough - no need to also fire
// this on every page load.

document.getElementById('lpgInstallLink').addEventListener('click', (e) => {
  e.preventDefault();
  const a = document.createElement('a');
  // ?target=single is the installer's own default anyway, but explicit
  // here for symmetry with lpg_bulk_search.php's equivalent (found
  // 2026-07-26).
  a.href = 'lpg_tool_bootstrap.php?target=single';
  a.download = 'LPG-Tool-Install.bat';
  document.body.appendChild(a);
  a.click();
  a.remove();
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

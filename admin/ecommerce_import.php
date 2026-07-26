<?php
require __DIR__ . '/../includes/auth.php';
requireAdmin('../login.php');

$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-cart-fill"></i> Import E-Commerce Data</h1>
  <p class="page-subtitle">
    Upload a <strong>.csv</strong> or <strong>.xlsx</strong> file of delivery records.
  </p>
</div>

<div id="import-result"></div>

<div class="card">
  <div class="card-header">
    <i class="bi bi-file-earmark-arrow-up" style="color:var(--c-accent)"></i>
    <span class="card-title">Upload File</span>
    <a href="ecommerce_import_sample.php" class="btn btn-secondary btn-sm" style="margin-left:auto">
      <i class="bi bi-download"></i> Sample CSV
    </a>
  </div>
  <div class="card-body">
    <p class="text-muted text-sm" style="margin:0 0 16px">
      Recognised columns:
      <strong>Mobile Number</strong> (required), Name, Address, Delivery Date,
      Location (combined "lat,long", e.g. 12.9716,77.5946 — or separate Latitude/Longitude columns).
    </p>
    <form id="ecom-import-form" enctype="multipart/form-data">
      <div class="import-zone" onclick="document.getElementById('import_file').click()">
        <i class="bi bi-cloud-arrow-up"></i>
        <p><strong>Click to browse</strong> or drag &amp; drop your file here</p>
        <p>.csv or .xlsx · Header row required</p>
        <input type="file" id="import_file" name="import_file"
               accept=".csv,.xlsx" required style="display:none"
               onchange="document.getElementById('file-name').textContent = this.files[0]?.name ?? ''">
        <p id="file-name" style="margin-top:8px;font-weight:600;color:var(--c-accent)"></p>
      </div>

      <div class="progress-wrap" id="import-progress-wrap" style="display:none">
        <div class="progress-track">
          <div class="progress-fill" id="import-progress-fill"></div>
        </div>
        <div class="progress-meta">
          <span id="import-progress-counts"></span>
          <span id="import-progress-eta"></span>
        </div>
      </div>

      <div style="margin-top:16px">
        <button type="submit" class="btn btn-primary" id="import-submit-btn">
          <i class="bi bi-upload"></i> Upload &amp; Import
        </button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  const form          = document.getElementById('ecom-import-form');
  const fileInput      = document.getElementById('import_file');
  const submitBtn      = document.getElementById('import-submit-btn');
  const resultBox      = document.getElementById('import-result');
  const progressWrap   = document.getElementById('import-progress-wrap');
  const progressFill   = document.getElementById('import-progress-fill');
  const progressCounts = document.getElementById('import-progress-counts');
  const progressEta    = document.getElementById('import-progress-eta');

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }
  function formatDuration(sec) {
    if (!isFinite(sec) || sec < 0) return '…';
    if (sec < 60) return `${Math.ceil(sec)}s`;
    const m = Math.floor(sec / 60), s = Math.round(sec % 60);
    return `${m}m ${s}s`;
  }
  function resetButton() {
    submitBtn.disabled = false;
    submitBtn.innerHTML = '<i class="bi bi-upload"></i> Upload &amp; Import';
  }
  function showError(msg) {
    progressWrap.style.display = 'none';
    resultBox.innerHTML = `<div class="notice notice-danger"><i class="bi bi-exclamation-triangle-fill"></i> ${escapeHtml(msg)}</div>`;
    resetButton();
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const file = fileInput.files[0];
    if (!file) { alert('Please choose a file.'); return; }

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Uploading…';
    resultBox.innerHTML = '';
    progressWrap.style.display = '';
    progressFill.style.width = '0%';
    progressCounts.textContent = 'Uploading file…';
    progressEta.textContent = '';

    let initData;
    try {
      const fd = new FormData();
      fd.append('action', 'init');
      fd.append('import_file', file);
      const res = await fetch('ecommerce_import_chunk.php', { method: 'POST', body: fd });
      initData = await res.json();
    } catch (err) {
      showError('Could not reach the server. Please check your connection and try again.');
      return;
    }
    if (!initData.ok) { showError(initData.error); return; }

    const { token, totalRows } = initData;
    if (totalRows === 0) { showError('The file has no data rows.'); return; }

    let skip = 0, totalProcessed = 0, totalInserted = 0, totalSkipped = 0;
    const allSkippedReasons = [];
    const startTime = performance.now();
    submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Importing…';

    while (true) {
      let chunkData;
      try {
        const fd = new FormData();
        fd.append('action', 'chunk');
        fd.append('token', token);
        fd.append('skip', skip);
        const res = await fetch('ecommerce_import_chunk.php', { method: 'POST', body: fd });
        chunkData = await res.json();
      } catch (err) {
        showError('Connection lost during import — some rows may not have been processed. You can re-upload the same file; duplicates are accepted, not skipped.');
        return;
      }
      if (!chunkData.ok) { showError(chunkData.error); return; }

      skip           += chunkData.processed;
      totalProcessed += chunkData.processed;
      totalInserted  += chunkData.inserted;
      totalSkipped   += chunkData.skipped;
      allSkippedReasons.push(...chunkData.skippedReasons);

      const pct = Math.min(100, Math.round((totalProcessed / totalRows) * 100));
      progressFill.style.width = pct + '%';
      progressCounts.textContent =
        `${pct}% — ${totalProcessed.toLocaleString()} / ${totalRows.toLocaleString()} rows `
        + `(${totalInserted.toLocaleString()} inserted, ${totalSkipped.toLocaleString()} skipped)`;

      const elapsedSec = (performance.now() - startTime) / 1000;
      const rate       = totalProcessed / Math.max(elapsedSec, 0.001);
      const remaining  = totalRows - totalProcessed;
      progressEta.textContent = chunkData.done
        ? 'Finishing…'
        : `~${formatDuration(remaining / Math.max(rate, 0.001))} remaining (${Math.round(rate)} rows/sec)`;

      if (chunkData.done) break;
    }

    const totalElapsed = (performance.now() - startTime) / 1000;
    progressEta.textContent = `Done in ${formatDuration(totalElapsed)}`;
    resetButton();

    const hasSkipped = totalSkipped > 0;
    let html = `<div class="notice notice-${hasSkipped ? 'warning' : 'success'}">
      <i class="bi bi-${hasSkipped ? 'exclamation-circle-fill' : 'check-circle-fill'}"></i>
      <div>
        Processed <strong>${totalProcessed.toLocaleString()}</strong> row(s),
        <strong>${totalInserted.toLocaleString()}</strong> inserted,
        <strong>${totalSkipped.toLocaleString()}</strong> skipped.`;
    if (hasSkipped) {
      // Group identical reasons (e.g. thousands of "blank row" or "missing
      // mobile number") into one counted line instead of repeating the same
      // text hundreds of times — a flat list of 6,000 duplicate lines told
      // you nothing a count doesn't.
      const counts = new Map();
      allSkippedReasons.forEach(r => counts.set(r, (counts.get(r) || 0) + 1));
      const grouped = [...counts.entries()].sort((a, b) => b[1] - a[1]);

      html += '<ul class="skipped-list">';
      grouped.slice(0, 20).forEach(([reason, count]) => {
        html += `<li>${escapeHtml(reason)}${count > 1 ? ` <strong>(${count.toLocaleString()}×)</strong>` : ''}</li>`;
      });
      if (grouped.length > 20) {
        const remaining = grouped.slice(20).reduce((sum, [, c]) => sum + c, 0);
        html += `<li>… and ${remaining.toLocaleString()} more across ${(grouped.length - 20).toLocaleString()} other reason(s).</li>`;
      }
      html += '</ul>';
    }
    html += '</div></div>';
    resultBox.innerHTML = html;
  });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>

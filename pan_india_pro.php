<?php
require __DIR__ . '/includes/auth.php';
requirePanIndiaProAccess();
require_once __DIR__ . '/config/db.php';

$basePath = '';
// Bulk Search is admin-only (and unlimited) - agents get Single Search only.
$canBulk = isMainAdmin();
require __DIR__ . '/includes/header.php';
?>

<!-- Confetti overlay - populated/cleared by startConfetti()/stopConfetti()
     (assets/confetti.js), only while a search has actually succeeded. -->
<div class="confetti-container" id="confetti-container"></div>

<div class="page-header" style="display:flex;align-items:center;flex-wrap:wrap;gap:12px">
  <h1 class="page-title" style="margin:0"><i class="bi bi-globe-asia-australia"></i> Night Out</h1>
  <span class="badge badge-neutral" style="margin-left:auto">Unlimited</span>
</div>

<style>
  /* Same tokens/shape as rc_print.php/hp_gas.php/advance_pan_india.php's
     cards - matches this app's usual white-card indigo tool styling rather
     than a one-off vendor-matched dark palette. */
  .pip-card{background:var(--c-surface,#fff);border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;}
  .pip-card-body{padding:16px 18px;}
  .pip-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;}
  .pip-field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;color:#777;margin-bottom:4px;}
  .pip-field input{width:100%;padding:10px 12px;font-size:13px;color:#333;border:1px solid #e0e0e0;border-radius:8px;
    background:#fff;outline:none;transition:border-color 150ms,box-shadow 150ms;}
  .pip-field input:focus{border-color:#2e9e3f;box-shadow:0 0 0 3px rgba(46,158,63,.15);}
  .pip-row{display:flex;align-items:center;gap:12px;margin-top:14px;flex-wrap:wrap;}
  .pip-btn{padding:11px 26px;border-radius:9px;border:none;background:#2e9e3f;color:#fff;
    font-size:12.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;cursor:pointer;
    transition:all 150ms;box-shadow:0 4px 18px rgba(46,158,63,.3);}
  .pip-btn:hover:not(:disabled){background:#257e32;transform:translateY(-1px);box-shadow:0 6px 20px rgba(46,158,63,.45);}
  .pip-btn:disabled{opacity:.65;cursor:wait;transform:none;}
  .pip-btn-secondary{background:#fff;color:#333;border:1px solid #e0e0e0;box-shadow:none;}
  .pip-btn-secondary:hover:not(:disabled){background:#eeeef6;border-color:#2e9e3f;transform:none;box-shadow:none;}
  .pip-btn-excel{background:#10b981;color:#fff;box-shadow:0 4px 18px rgba(16,185,129,.3);}
  .pip-btn-excel:hover:not(:disabled){background:#0d9668;transform:translateY(-1px);box-shadow:0 6px 20px rgba(16,185,129,.45);}
  #pipStatus{font-size:12.5px;color:#555;white-space:pre-wrap;word-break:break-word;font-weight:500;}
  .pip-progress-wrap{margin-top:12px;display:none;}
  .pip-progress-track{height:8px;border-radius:6px;background:#eeeef6;overflow:hidden;border:1px solid #e0e0e0;}
  .pip-progress-fill{height:100%;border-radius:6px;background:#2e9e3f;width:100%;
    background-image:repeating-linear-gradient(45deg,#2e9e3f 0 12px,#257e32 12px 24px);
    background-size:34px 100%;animation:pip-progress-stripes 1s linear infinite;}
  @keyframes pip-progress-stripes{from{background-position:0 0;}to{background-position:-34px 0;}}
  .pip-progress-meta{display:flex;justify-content:space-between;margin-top:6px;font-size:11.5px;color:#999;}
  .pip-result-wrap{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);margin-top:16px;overflow-x:auto;display:none;}
  .pip-result-toolbar{padding:10px 16px;background:#2e9e3f;color:#fff;font-size:11.5px;font-weight:700;
    text-transform:uppercase;letter-spacing:.3px;}
  .pip-table{width:100%;border-collapse:collapse;font-size:11.5px;}
  .pip-table th{background:#eeeef6;color:#555;font-size:10px;font-weight:700;text-transform:uppercase;
    letter-spacing:.3px;padding:6px 8px;text-align:left;white-space:nowrap;border-bottom:1px solid #e0e0e0;}
  .pip-table td{padding:6px 8px;border-bottom:1px solid #eee;vertical-align:top;color:#333;max-width:260px;word-break:break-word;}
  .pip-table tr:nth-child(even) td{background:#f8f8fc;}
  .pip-no-results{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);padding:16px;color:#777;}

  /* Per-column colour coding (Name/Father's Name plain, Mobile=amber,
     Alt. Mobile=pink, Address=green, Alt. Address=teal, Email=orange,
     Reg. Year=cyan, Identity=blue) - same palette/nth-child convention and
     light-theme header+tint+left-border treatment as lpg_search.php's
     .lpg-table and pan_india.php's per-field highlighting, so this table
     reads as "one of this app's tables" instead of a one-off dark theme. */
  .pip-table th:nth-child(2){background:rgb(217,119,6);color:#fff;}
  .pip-table th:nth-child(3){background:rgb(219,39,119);color:#fff;}
  .pip-table th:nth-child(5){background:rgb(5,150,105);color:#fff;}
  .pip-table th:nth-child(6){background:rgb(13,148,136);color:#fff;}
  .pip-table th:nth-child(7){background:rgb(234,88,12);color:#fff;}
  .pip-table th:nth-child(8){background:rgb(2,132,199);color:#fff;}
  .pip-table th:nth-child(9){background:rgb(37,99,235);color:#fff;}
  .pip-table td:nth-child(2){background:rgba(217,119,6,.08);border-left:3px solid rgba(217,119,6,.5);}
  .pip-table td:nth-child(3){background:rgba(219,39,119,.08);border-left:3px solid rgba(219,39,119,.5);}
  .pip-table td:nth-child(5){background:rgba(5,150,105,.08);border-left:3px solid rgba(5,150,105,.5);}
  .pip-table td:nth-child(6){background:rgba(13,148,136,.08);border-left:3px solid rgba(13,148,136,.5);}
  .pip-table td:nth-child(7){background:rgba(234,88,12,.08);border-left:3px solid rgba(234,88,12,.5);}
  .pip-table td:nth-child(8){background:rgba(2,132,199,.08);border-left:3px solid rgba(2,132,199,.5);}
  .pip-table td:nth-child(9){background:rgba(37,99,235,.08);border-left:3px solid rgba(37,99,235,.5);}
  .pip-cell-badge{font-family:'Consolas','Cascadia Code','Courier New',monospace;font-size:11.5px;font-weight:700;
    padding:1px 7px;border-radius:5px;display:inline-block;letter-spacing:.2px;
    background:rgba(46,158,63,.1);border:1px solid rgba(46,158,63,.3);color:#257e32;}
  /* Single/Bulk tabs (2026-10-03) - same look as indane_gas_pro.php's. */
  .pip-tabs{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;}
  .pip-tab{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:10px;
    border:1px solid #e2e2ea;background:#fff;font-size:12.5px;font-weight:600;color:#555;cursor:pointer;transition:all 150ms;}
  .pip-tab:hover{border-color:#2e9e3f;color:#2e9e3f;}
  .pip-tab.active{background:#2e9e3f;border-color:#2e9e3f;color:#fff;box-shadow:0 4px 14px rgba(46,158,63,.35);}
  .pip-textarea{width:100%;min-height:110px;padding:11px 14px;font-size:13px;color:#333;border:1px solid #e0e0e0;
    border-radius:8px;background:#fff;outline:none;resize:vertical;font-family:inherit;}
  .pip-textarea:focus{border-color:#2e9e3f;box-shadow:0 0 0 3px rgba(46,158,63,.15);}
  .pip-hint{font-size:11.5px;color:#999;margin-top:6px;}
  /* Bulk: one heading row per searched number inside the same table. */
  .pip-table tr.pip-group td{background:#1f2937 !important;color:#fff;font-weight:700;font-size:12px;
    border-left:none !important;padding:7px 10px;}
</style>

<div class="pip-tabs">
  <button type="button" class="pip-tab active" id="pipTabSingle"><i class="bi bi-search"></i> Single Search</button>
  <?php if ($canBulk): ?>
  <button type="button" class="pip-tab" id="pipTabBulk"><i class="bi bi-list-ol"></i> Bulk Search</button>
  <?php endif; ?>
</div>

<div class="pip-card">
  <div class="pip-card-body">
    <?php if ($canBulk): ?>
    <div id="pipBulkInput" style="display:none">
      <div class="pip-field"><label>Mobile Numbers</label></div>
      <textarea id="pipBulkBox" class="pip-textarea" placeholder="9876543210, 9876543211, ... (one per line or comma-separated)"></textarea>
      <div class="pip-hint">No limit - each number is searched one after another.</div>
    </div>
    <?php endif; ?>
    <div class="pip-grid" id="pipSingleInput">
      <div class="pip-field"><label>Mobile</label><input type="text" id="pipMobile" placeholder="Enter mobile number"></div>
      <div class="pip-field"><label>Name</label><input type="text" id="pipName" placeholder="Enter name"></div>
      <div class="pip-field"><label>Father's Name</label><input type="text" id="pipFname" placeholder="Enter father's name"></div>
      <div class="pip-field"><label>Email</label><input type="email" id="pipEmail" placeholder="Enter email"></div>
      <div class="pip-field"><label>Address</label><input type="text" id="pipAddress" placeholder="Enter address"></div>
      <div class="pip-field"><label>Identity</label><input type="text" id="pipMasterId" placeholder="Enter identity"></div>
    </div>
    <div class="pip-row">
      <button id="pipSearchBtn" class="pip-btn">Search</button>
      <button id="pipClearBtn" class="pip-btn pip-btn-secondary" type="button">Clear</button>
      <button id="pipExportBtn" class="pip-btn pip-btn-excel" type="button" disabled>
        <i class="bi bi-file-earmark-excel"></i> Download Excel
      </button>
      <span id="pipStatus"></span>
    </div>
    <div class="pip-progress-wrap" id="pipProgressWrap">
      <div class="pip-progress-track"><div class="pip-progress-fill" id="pipProgressFill"></div></div>
      <div class="pip-progress-meta">
        <span id="pipProgressLabel">Searching Night Out…</span>
        <span id="pipProgressElapsed"></span>
      </div>
    </div>
  </div>
</div>

<div class="pip-result-wrap" id="pipResultWrap">
  <div class="pip-result-toolbar" id="pipResultToolbar"></div>
  <table class="pip-table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Mobile</th>
        <th>Alt. Mobile</th>
        <th>Father's Name</th>
        <th>Address</th>
        <th>Alt. Address</th>
        <th>Email</th>
        <th>Reg. Year</th>
        <th>Identity</th>
      </tr>
    </thead>
    <tbody id="pipResultBody"></tbody>
  </table>
</div>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
const searchBtn  = document.getElementById("pipSearchBtn");
const clearBtn   = document.getElementById("pipClearBtn");
const exportBtn  = document.getElementById("pipExportBtn");
const statusEl   = document.getElementById("pipStatus");
const resultWrap = document.getElementById("pipResultWrap");
const resultToolbar = document.getElementById("pipResultToolbar");
const resultBody = document.getElementById("pipResultBody");
let lastPipRows = [];
// Bulk Search (2026-10-03): rows carry the number they were found for in
// _searched, used for the group rows and the export's first column.
let bulkMode = false;
let lastWasBulk = false;
const bulkBox = document.getElementById("pipBulkBox");
const tabSingle = document.getElementById("pipTabSingle");
const tabBulk = document.getElementById("pipTabBulk");
const progressWrap    = document.getElementById("pipProgressWrap");
const progressLabel   = document.getElementById("pipProgressLabel");
const progressElapsed = document.getElementById("pipProgressElapsed");

let progressTimer = null;
let searchStartedAt = null;

function formatDuration(seconds) {
  seconds = Math.max(0, Math.round(seconds));
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

// No per-step progress to report (unlike LPG's job-based polling) - this is
// one blocking fetch for the whole login(if needed)+search sequence in
// includes/pan_india_pro_client.php, so the bar itself is always
// indeterminate (striped, animating). The countdown is a fixed estimate
// (this is a plain JSON API, no browser automation, so typically just a
// couple seconds) - same idea as LPG's own "~Xs remaining", just without
// real done/total numbers to base it on.
const ESTIMATED_SECONDS = 5;

function startProgress() {
  searchStartedAt = Date.now();
  progressLabel.textContent = "Searching Night Out…";
  progressElapsed.textContent = `~${formatDuration(ESTIMATED_SECONDS)} estimated`;
  progressWrap.style.display = "block";
  clearInterval(progressTimer);
  progressTimer = setInterval(() => {
    const remaining = ESTIMATED_SECONDS - (Date.now() - searchStartedAt) / 1000;
    progressElapsed.textContent = remaining > 0
      ? `~${formatDuration(remaining)} remaining`
      : "Finishing up…";
  }, 1000);
}

function stopProgress(finalLabel) {
  clearInterval(progressTimer);
  if (finalLabel && searchStartedAt) {
    progressLabel.textContent = finalLabel;
    progressElapsed.textContent = `Done in ${formatDuration((Date.now() - searchStartedAt) / 1000)}`;
  } else {
    progressWrap.style.display = "none";
  }
}

const fields = {
  mobile: document.getElementById("pipMobile"),
  name: document.getElementById("pipName"),
  fname: document.getElementById("pipFname"),
  email: document.getElementById("pipEmail"),
  address: document.getElementById("pipAddress"),
  master_id: document.getElementById("pipMasterId"),
};

function cell(value) {
  return value ? String(value) : "—";
}

// Mobile/Alt. Mobile/Reg. Year render as small dark pill badges (see
// .pip-cell-badge) so they stand out on top of their column's own solid
// background colour (see .pip-table td:nth-child(N) above) - every other
// column just needs plain text, since the column colour already does the
// work the vendor's own table uses tinted text for.
function badgeCell(value) {
  const td = document.createElement("td");
  if (value) {
    const span = document.createElement("span");
    span.className = "pip-cell-badge";
    span.textContent = value;
    td.appendChild(span);
  } else {
    td.textContent = "—";
  }
  return td;
}

function textCell(value) {
  const td = document.createElement("td");
  td.textContent = cell(value);
  return td;
}

// Rows come from the vendor's own fixed JSON schema (id [called "Master ID"
// on the vendor's own site, shown here as "Identity"], name, mobile, alt,
// fname, address, alt_address, email, year_of_registration) - see
// includes/pan_india_pro_client.php - so this renders known columns
// directly instead of Advance Pan India's generic discovered-headers table.
function appendRow(row) {
  const tr = document.createElement("tr");
  tr.appendChild(textCell(row.name));
  tr.appendChild(badgeCell(row.mobile));
  tr.appendChild(badgeCell(row.alt));
  tr.appendChild(textCell(row.fname));
  tr.appendChild(textCell(row.address));
  tr.appendChild(textCell(row.alt_address));
  tr.appendChild(textCell(row.email));
  tr.appendChild(badgeCell(row.year_of_registration));
  tr.appendChild(textCell(row.id));
  resultBody.appendChild(tr);
}

function renderResult(data) {
  resultBody.innerHTML = "";
  lastWasBulk = false;
  lastPipRows = (data.totalResults && data.rows) ? data.rows : [];
  exportBtn.disabled = !lastPipRows.length;

  if (!lastPipRows.length) {
    resultWrap.style.display = "none";
  } else {
    resultToolbar.textContent = `${lastPipRows.length} result${lastPipRows.length === 1 ? "" : "s"}`;
    lastPipRows.forEach(appendRow);
    resultWrap.style.display = "block";
  }

  if (lastPipRows.length) startConfetti(); else stopConfetti();
}

function showMode(bulk) {
  bulkMode = bulk;
  if (tabBulk) tabBulk.classList.toggle("active", bulk);
  tabSingle.classList.toggle("active", !bulk);
  const bulkInput = document.getElementById("pipBulkInput");
  if (bulkInput) bulkInput.style.display = bulk ? "" : "none";
  document.getElementById("pipSingleInput").style.display = bulk ? "none" : "";
  searchBtn.textContent = bulk ? "Bulk Search" : "Search";
  statusEl.textContent = "";
}
tabSingle.addEventListener("click", () => showMode(false));
if (tabBulk) tabBulk.addEventListener("click", () => showMode(true));

// Any 10-digit mobile numbers in the box (comma/space/newline separated;
// a +91/91/0 prefix is dropped), de-duplicated.
// Split on whitespace/commas/semicolons (so one number per line works -
// 2026-10-03 fix: the first version's regex let a line break count as a
// space inside a number, gluing a whole list into one invalid string), then
// rejoin "98765 43210"-style halves like lpg_search.php's parser does.
function parseBulkNumbers(raw) {
  const tokens = raw.split(/[\s,;]+/).map(t => t.replace(/\D+/g, "")).filter(Boolean);
  const seen = new Set();
  for (let i = 0; i < tokens.length; i++) {
    let d = tokens[i];
    if (d.length === 5 && tokens[i + 1] && tokens[i + 1].length === 5) { d += tokens[i + 1]; i++; }
    if (d.length === 12 && d.startsWith("91")) d = d.slice(2);
    if (d.length === 11 && d.startsWith("0")) d = d.slice(1);
    if (d.length === 10) seen.add(d);
  }
  return [...seen];
}

function appendGroupRow(text) {
  const tr = document.createElement("tr");
  tr.className = "pip-group";
  const td = document.createElement("td");
  td.colSpan = 9;
  td.textContent = text;
  tr.appendChild(td);
  resultBody.appendChild(tr);
}

async function runBulkSearch() {
  const all = parseBulkNumbers(bulkBox.value);
  if (!all.length) {
    statusEl.textContent = "Enter at least one valid 10-digit mobile number.";
    return;
  }
  const numbers = all;

  searchBtn.disabled = true;
  exportBtn.disabled = true;
  statusEl.textContent = "";
  resultBody.innerHTML = "";
  resultToolbar.textContent = "";
  resultWrap.style.display = "block";
  lastPipRows = [];
  lastWasBulk = true;
  progressWrap.style.display = "block";
  const startedAt = Date.now();
  let foundNumbers = 0;

  try {
    for (let i = 0; i < numbers.length; i++) {
      const number = numbers[i];
      const elapsed = (Date.now() - startedAt) / 1000;
      progressLabel.textContent = `Searching ${number} (${i + 1}/${numbers.length})…`;
      progressElapsed.textContent = i === 0 ? "Estimating time…"
        : `~${formatDuration((elapsed / i) * (numbers.length - i))} remaining`;
      let data;
      try {
        const res = await fetch("pan_india_pro_api.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ mobile: number, name: "", fname: "", email: "", address: "", master_id: "" })
        });
        data = await res.json();
        if (res.status === 401) { window.location.href = data.loginUrl || "login.php"; return; }
        if (!res.ok) data = { error: data.error || "could not complete search" };
      } catch (err) {
        data = { error: "could not reach the server" };
      }
      const rows = (!data.error && data.totalResults && data.rows) ? data.rows : [];
      appendGroupRow(data.error ? `${number} — Error: ${data.error}`
        : rows.length ? `${number} — ${rows.length} result${rows.length === 1 ? "" : "s"}` : `${number} — no results`);
      rows.forEach(row => { appendRow(row); lastPipRows.push({ ...row, _searched: number }); });
      if (rows.length) foundNumbers++;
      resultToolbar.textContent = `${lastPipRows.length} result${lastPipRows.length === 1 ? "" : "s"} across ${i + 1} number${i === 0 ? "" : "s"}`;
      exportBtn.disabled = !lastPipRows.length;
    }
    progressLabel.textContent = `${foundNumbers} of ${numbers.length} number${numbers.length === 1 ? "" : "s"} had results`;
    progressElapsed.textContent = `Done in ${formatDuration((Date.now() - startedAt) / 1000)}`;
    if (foundNumbers) startConfetti(); else stopConfetti();
  } finally {
    searchBtn.disabled = false;
  }
}

async function runSearch() {
  if (bulkMode) return runBulkSearch();
  const params = {};
  let hasAny = false;
  for (const [key, input] of Object.entries(fields)) {
    params[key] = input.value.trim();
    if (params[key]) hasAny = true;
  }
  if (!hasAny) {
    statusEl.textContent = "Enter at least one search field.";
    return;
  }

  searchBtn.disabled = true;
  statusEl.textContent = "";
  resultWrap.style.display = "none";
  startProgress();

  try {
    const res = await fetch("pan_india_pro_api.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(params)
    });
    const data = await res.json();

    if (!res.ok) {
      stopProgress(null);
      statusEl.textContent = `Error: ${data.error || "could not complete search"}`;
      return;
    }

    stopProgress(data.totalResults ? `${data.totalResults} result${data.totalResults === 1 ? "" : "s"} found` : "No results found");
    renderResult(data);
  } catch (err) {
    stopProgress(null);
    statusEl.textContent = `Could not reach the server: ${err.message}`;
  } finally {
    searchBtn.disabled = false;
  }
}

searchBtn.addEventListener("click", runSearch);
clearBtn.addEventListener("click", () => {
  Object.values(fields).forEach(input => input.value = "");
  if (bulkBox) bulkBox.value = "";
  statusEl.textContent = "";
  resultWrap.style.display = "none";
  stopProgress(null);
  lastPipRows = [];
  lastWasBulk = false;
  exportBtn.disabled = true;
  stopConfetti();
});

/* Download the current result set as a single .xlsx workbook. */
exportBtn.addEventListener("click", () => {
  if (!lastPipRows.length) { alert("No records to export."); return; }
  const headers = ["Name", "Mobile", "Alt. Mobile", "Father's Name", "Address", "Alt. Address", "Email", "Reg. Year", "Identity"];
  const aoa = [lastWasBulk ? ["Searched Number", ...headers] : headers, ...lastPipRows.map(row => [
    ...(lastWasBulk ? [row._searched || ""] : []),
    row.name || "", row.mobile || "", row.alt || "", row.fname || "",
    row.address || "", row.alt_address || "", row.email || "", row.year_of_registration || "", row.id || "",
  ])];
  const ws = XLSX.utils.aoa_to_sheet(aoa);
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, "Results");
  const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, "-");
  XLSX.writeFile(wb, `night-out-export-${stamp}.xlsx`);
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

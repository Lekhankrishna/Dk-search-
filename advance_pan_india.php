<?php
require __DIR__ . '/includes/auth.php';
requireEagleEyeAccess();
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
  <h1 class="page-title" style="margin:0"><i class="bi bi-globe-asia-australia"></i> Advance Pan India</h1>
  <span class="badge badge-neutral" style="margin-left:auto">Unlimited</span>
</div>

<style>
  /* Same tokens/shape as rc_print.php/hp_gas.php's cards. */
  .ee-card{background:var(--c-surface,#fff);border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;}
  .ee-card-body{padding:16px 18px;}
  .ee-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;}
  .ee-field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;color:#777;margin-bottom:4px;}
  .ee-field input{width:100%;padding:10px 12px;font-size:13px;color:#333;border:1px solid #e0e0e0;border-radius:8px;background:#fff;outline:none;}
  .ee-row{display:flex;align-items:center;gap:12px;margin-top:14px;flex-wrap:wrap;}
  .ee-btn{padding:11px 26px;border-radius:9px;border:none;background:#2e9e3f;color:#fff;
    font-size:12.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;cursor:pointer;
    transition:all 150ms;box-shadow:0 4px 18px rgba(46,158,63,.3);}
  .ee-btn:hover:not(:disabled){background:#257e32;transform:translateY(-1px);box-shadow:0 6px 20px rgba(46,158,63,.45);}
  .ee-btn:disabled{opacity:.65;cursor:wait;transform:none;}
  .ee-btn-secondary{background:#fff;color:#333;border:1px solid #e0e0e0;box-shadow:none;}
  .ee-btn-secondary:hover:not(:disabled){background:#eeeef6;border-color:#2e9e3f;transform:none;box-shadow:none;}
  .ee-btn-excel{background:#10b981;color:#fff;box-shadow:0 4px 18px rgba(16,185,129,.3);}
  .ee-btn-excel:hover:not(:disabled){background:#0d9668;transform:translateY(-1px);box-shadow:0 6px 20px rgba(16,185,129,.45);}
  #eeStatus{font-size:12.5px;color:#555;white-space:pre-wrap;word-break:break-word;font-weight:500;}
  .ee-progress-wrap{margin-top:12px;display:none;}
  .ee-progress-track{height:8px;border-radius:6px;background:#eeeef6;overflow:hidden;border:1px solid #e0e0e0;}
  .ee-progress-fill{height:100%;border-radius:6px;background:#2e9e3f;width:100%;
    background-image:repeating-linear-gradient(45deg,#2e9e3f 0 12px,#257e32 12px 24px);
    background-size:34px 100%;animation:ee-progress-stripes 1s linear infinite;}
  @keyframes ee-progress-stripes{from{background-position:0 0;}to{background-position:-34px 0;}}
  .ee-progress-meta{display:flex;justify-content:space-between;margin-top:6px;font-size:11.5px;color:#999;}
  .ee-result-wrap{margin-top:16px;display:none;}
  .ee-table-wrap{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);margin-bottom:14px;overflow-x:auto;}
  .ee-table-toolbar{padding:10px 16px;background:#2e9e3f;color:#fff;font-size:11.5px;font-weight:700;
    text-transform:uppercase;letter-spacing:.3px;}
  .ee-table{width:100%;border-collapse:collapse;font-size:11.5px;}
  .ee-table th{background:#eeeef6;color:#555;font-size:10px;font-weight:700;text-transform:uppercase;
    letter-spacing:.3px;padding:6px 8px;text-align:left;white-space:nowrap;border-bottom:1px solid #e0e0e0;}
  .ee-table td{padding:6px 8px;border-bottom:1px solid #eee;vertical-align:top;color:#333;max-width:260px;word-break:break-word;}
  .ee-table tr:nth-child(even) td{background:#f8f8fc;}
  .ee-no-results{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);padding:16px;color:#777;}
  /* Single/Bulk tabs (2026-10-03) - same look as indane_gas_pro.php's. */
  .ee-tabs{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;}
  .ee-tab{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:10px;
    border:1px solid #e2e2ea;background:#fff;font-size:12.5px;font-weight:600;color:#555;cursor:pointer;transition:all 150ms;}
  .ee-tab:hover{border-color:#2e9e3f;color:#2e9e3f;}
  .ee-tab.active{background:#2e9e3f;border-color:#2e9e3f;color:#fff;box-shadow:0 4px 14px rgba(46,158,63,.35);}
  .ee-textarea{width:100%;min-height:110px;padding:11px 14px;font-size:13px;color:#333;border:1px solid #e0e0e0;
    border-radius:8px;background:#fff;outline:none;resize:vertical;font-family:inherit;}
  .ee-textarea:focus{border-color:#2e9e3f;}
  .ee-hint{font-size:11.5px;color:#999;margin-top:6px;}
  .ee-number-heading{margin:18px 0 8px;font-size:13px;font-weight:700;color:#1f2937;}
</style>

<div class="ee-tabs">
  <button type="button" class="ee-tab active" id="eeTabSingle"><i class="bi bi-search"></i> Single Search</button>
  <?php if ($canBulk): ?>
  <button type="button" class="ee-tab" id="eeTabBulk"><i class="bi bi-list-ol"></i> Bulk Search</button>
  <?php endif; ?>
</div>

<div class="ee-card">
  <div class="ee-card-body">
    <?php if ($canBulk): ?>
    <div id="eeBulkInput" style="display:none">
      <div class="ee-field"><label>Mobile Numbers</label></div>
      <textarea id="eeBulkBox" class="ee-textarea" placeholder="9876543210, 9876543211, ... (one per line or comma-separated)"></textarea>
      <div class="ee-hint">No limit - each number is searched one after another.</div>
    </div>
    <?php endif; ?>
    <div class="ee-grid" id="eeSingleInput">
      <div class="ee-field"><label>Mobile</label><input type="text" id="eeMobile" placeholder="Enter mobile number"></div>
      <div class="ee-field"><label>Name</label><input type="text" id="eeName" placeholder="Enter name"></div>
      <div class="ee-field"><label>Father's Name</label><input type="text" id="eeFname" placeholder="Enter father's name"></div>
      <div class="ee-field"><label>Email</label><input type="email" id="eeEmail" placeholder="Enter email"></div>
      <div class="ee-field"><label>Address</label><input type="text" id="eeAddress" placeholder="Enter address"></div>
      <div class="ee-field"><label>Identity</label><input type="text" id="eeMasterId" placeholder="Enter identity"></div>
    </div>
    <div class="ee-row">
      <button id="eeSearchBtn" class="ee-btn">Search</button>
      <button id="eeClearBtn" class="ee-btn ee-btn-secondary" type="button">Clear</button>
      <button id="eeExportBtn" class="ee-btn ee-btn-excel" type="button" disabled>
        <i class="bi bi-file-earmark-excel"></i> Download Excel
      </button>
      <span id="eeStatus"></span>
    </div>
    <div class="ee-progress-wrap" id="eeProgressWrap">
      <div class="ee-progress-track"><div class="ee-progress-fill" id="eeProgressFill"></div></div>
      <div class="ee-progress-meta">
        <span id="eeProgressLabel">Searching Advance Pan India…</span>
        <span id="eeProgressElapsed"></span>
      </div>
    </div>
  </div>
</div>

<div class="ee-result-wrap" id="eeResultWrap"></div>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
const searchBtn  = document.getElementById("eeSearchBtn");
const clearBtn   = document.getElementById("eeClearBtn");
const exportBtn  = document.getElementById("eeExportBtn");
const statusEl   = document.getElementById("eeStatus");
const resultWrap = document.getElementById("eeResultWrap");
let lastEeTables = [];
// Bulk Search (2026-10-03): [{ number, tables }] for the last batch.
let lastBulk = [];
let bulkMode = false;
const bulkBox = document.getElementById("eeBulkBox");
const tabSingle = document.getElementById("eeTabSingle");
const tabBulk = document.getElementById("eeTabBulk");
const progressWrap    = document.getElementById("eeProgressWrap");
const progressLabel   = document.getElementById("eeProgressLabel");
const progressElapsed = document.getElementById("eeProgressElapsed");

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
// includes/eagleeye_client.php, so the bar itself is always indeterminate
// (striped, animating). The countdown is a fixed estimate (this is a plain
// server-rendered site, no browser automation, so typically just a couple
// seconds - longer only on the rare request that also has to clear
// theeagleeye.biz's session-limit panel first) - same idea as LPG's own
// "~Xs remaining", just without real done/total numbers to base it on.
const ESTIMATED_SECONDS = 5;

function startProgress() {
  searchStartedAt = Date.now();
  progressLabel.textContent = "Searching Advance Pan India…";
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
  name: document.getElementById("eeName"),
  fname: document.getElementById("eeFname"),
  mobile: document.getElementById("eeMobile"),
  email: document.getElementById("eeEmail"),
  address: document.getElementById("eeAddress"),
  master_id: document.getElementById("eeMasterId"),
};

// Tables come from theeagleeye.biz's own result tables, read generically
// (whatever headers/columns it renders) - see includes/eagleeye_client.php.
function renderResult(data) {
  resultWrap.innerHTML = "";
  lastEeTables = (data.totalResults && data.tables) ? data.tables : [];
  exportBtn.disabled = !lastEeTables.length;
  appendTables(data, null);
  resultWrap.style.display = "block";
  if (lastEeTables.length) startConfetti(); else stopConfetti();
}

// Appends one search's tables (or its "no results" / error note). In bulk
// mode a heading names the searched number first.
function appendTables(data, number) {
  if (number !== null) {
    const heading = document.createElement("div");
    heading.className = "ee-number-heading";
    heading.textContent = number;
    resultWrap.appendChild(heading);
  }
  if (data.error) {
    const err = document.createElement("div");
    err.className = "ee-no-results";
    err.textContent = `Error: ${data.error}`;
    resultWrap.appendChild(err);
  } else if (!data.totalResults || !data.tables || !data.tables.length) {
    const none = document.createElement("div");
    none.className = "ee-no-results";
    none.textContent = "No results found.";
    resultWrap.appendChild(none);
  } else {
    data.tables.forEach((table, tableIndex) => {
      const box = document.createElement("div");
      box.className = "ee-table-wrap";
      const toolbar = document.createElement("div");
      toolbar.className = "ee-table-toolbar";
      toolbar.textContent = `Source ${tableIndex + 1} — ${table.rows.length} result${table.rows.length === 1 ? "" : "s"}`;
      box.appendChild(toolbar);

      const tableEl = document.createElement("table");
      tableEl.className = "ee-table";
      const thead = document.createElement("thead");
      const headRow = document.createElement("tr");
      table.headers.forEach(h => {
        const th = document.createElement("th");
        th.textContent = h;
        headRow.appendChild(th);
      });
      thead.appendChild(headRow);
      tableEl.appendChild(thead);

      const tbody = document.createElement("tbody");
      table.rows.forEach(row => {
        const tr = document.createElement("tr");
        table.headers.forEach(h => {
          const td = document.createElement("td");
          td.textContent = row[h] || "—";
          tr.appendChild(td);
        });
        tbody.appendChild(tr);
      });
      tableEl.appendChild(tbody);
      box.appendChild(tableEl);
      resultWrap.appendChild(box);
    });
  }
}

function showMode(bulk) {
  bulkMode = bulk;
  if (tabBulk) tabBulk.classList.toggle("active", bulk);
  tabSingle.classList.toggle("active", !bulk);
  const bulkInput = document.getElementById("eeBulkInput");
  if (bulkInput) bulkInput.style.display = bulk ? "" : "none";
  document.getElementById("eeSingleInput").style.display = bulk ? "none" : "";
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
  resultWrap.innerHTML = "";
  resultWrap.style.display = "block";
  lastEeTables = [];
  lastBulk = [];
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
        const res = await fetch("advance_pan_india_api.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ name: "", fname: "", mobile: number, email: "", address: "", master_id: "" })
        });
        data = await res.json();
        if (res.status === 401) { window.location.href = data.loginUrl || "login.php"; return; }
        if (!res.ok) data = { error: data.error || "could not complete search" };
      } catch (err) {
        data = { error: "could not reach the server" };
      }
      appendTables(data, number);
      const tables = (!data.error && data.totalResults && data.tables) ? data.tables : [];
      if (tables.length) { foundNumbers++; lastBulk.push({ number, tables }); }
      exportBtn.disabled = !lastBulk.length;
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
  lastBulk = [];
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
    const res = await fetch("advance_pan_india_api.php", {
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
  lastEeTables = [];
  lastBulk = [];
  exportBtn.disabled = true;
  stopConfetti();
});

/* Download all currently rendered result tables as a single .xlsx workbook -
   one sheet per source table, matching how theeagleeye.biz itself groups
   results (see "Source N" toolbar labels in renderResult()). */
exportBtn.addEventListener("click", () => {
  const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, "-");
  // Bulk: one combined sheet - Searched Number + Source, then the union of
  // every source table's own columns, so the whole batch filters in one place.
  if (lastBulk.length) {
    const cols = [];
    lastBulk.forEach(b => b.tables.forEach(t => t.headers.forEach(h => { if (!cols.includes(h)) cols.push(h); })));
    const aoa = [["Searched Number", "Source", ...cols]];
    lastBulk.forEach(b => b.tables.forEach((t, i) => t.rows.forEach(row => {
      aoa.push([b.number, `Source ${i + 1}`, ...cols.map(h => row[h] || "")]);
    })));
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(aoa), "Bulk Results");
    XLSX.writeFile(wb, `advance-pan-india-bulk-${stamp}.xlsx`);
    return;
  }
  if (!lastEeTables.length) { alert("No records to export."); return; }
  const wb = XLSX.utils.book_new();
  lastEeTables.forEach((table, i) => {
    const aoa = [table.headers, ...table.rows.map(row => table.headers.map(h => row[h] || ""))];
    const ws = XLSX.utils.aoa_to_sheet(aoa);
    XLSX.utils.book_append_sheet(wb, ws, `Source ${i + 1}`.slice(0, 31));
  });
  XLSX.writeFile(wb, `advance-pan-india-export-${stamp}.xlsx`);
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

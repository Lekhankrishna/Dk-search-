<?php
require __DIR__ . '/includes/auth.php';
requireIndaneGasProAccess();
require_once __DIR__ . '/config/db.php';

// Unlimited for everyone (explicit instruction, 2026-09-03) - no more
// monthly quota badge, same as every other unmetered tool here.
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

$basePath = '';
require __DIR__ . '/includes/header.php';
?>

<div class="page-header" style="display:flex;align-items:center;flex-wrap:wrap;gap:12px">
  <h1 class="page-title" style="margin:0"><i class="bi bi-fire"></i> Indane Gas Pro</h1>
</div>

<style>
  .igp-card{background:var(--c-surface,#fff);border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;}
  .igp-card-body{padding:16px 18px;}
  .igp-row{display:flex;align-items:center;gap:12px;margin-top:12px;flex-wrap:wrap;}
  .igp-btn{padding:11px 26px;border-radius:9px;border:none;background:#2e9e3f;color:#fff;
    font-size:12.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;cursor:pointer;
    transition:all 150ms;box-shadow:0 4px 18px rgba(46,158,63,.3);}
  .igp-btn:hover:not(:disabled){background:#257e32;transform:translateY(-1px);box-shadow:0 6px 20px rgba(46,158,63,.45);}
  .igp-btn:disabled{opacity:.65;cursor:wait;transform:none;}
  .igp-btn-secondary{background:#fff;color:#333;border:1px solid #e0e0e0;box-shadow:none;}
  .igp-btn-secondary:hover:not(:disabled){background:#eeeef6;border-color:#2e9e3f;transform:none;box-shadow:none;}
  .igp-btn-export{background:#10b981;box-shadow:0 4px 18px rgba(16,185,129,.3);}
  .igp-btn-export:hover:not(:disabled){background:#0d9668;box-shadow:0 6px 20px rgba(16,185,129,.45);}
  .igp-btn-export:disabled{opacity:.5;cursor:not-allowed;transform:none;box-shadow:none;}
  #igpStatus{font-size:12.5px;color:#555;white-space:pre-wrap;word-break:break-word;font-weight:500;}
  .igp-progress-wrap{margin-top:12px;display:none;}
  .igp-progress-track{height:8px;border-radius:6px;background:#eeeef6;overflow:hidden;border:1px solid #e0e0e0;}
  .igp-progress-fill{height:100%;border-radius:6px;background:#2e9e3f;width:100%;
    background-image:repeating-linear-gradient(45deg,#2e9e3f 0 12px,#257e32 12px 24px);
    background-size:34px 100%;animation:igp-progress-stripes 1s linear infinite;}
  @keyframes igp-progress-stripes{from{background-position:0 0;}to{background-position:-34px 0;}}
  .igp-progress-meta{display:flex;justify-content:space-between;margin-top:6px;font-size:11.5px;color:#999;}
  .igp-section{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;margin-top:16px;}
  .igp-section-title{padding:10px 16px;background:#2e9e3f;color:#fff;font-size:11.5px;font-weight:700;
    text-transform:uppercase;letter-spacing:.4px;}
  .igp-section-table{width:100%;border-collapse:collapse;}
  .igp-section-table tr:nth-child(odd){background:#fff;}
  .igp-section-table tr:nth-child(even){background:#f8f8fc;}
  .igp-section-table td{padding:8px 16px;font-size:12.5px;border-bottom:1px solid #eee;vertical-align:top;}
  .igp-section-table tr:last-child td{border-bottom:none;}
  .igp-field-label{width:38%;color:#777;font-weight:600;}
  .igp-field-value{color:#222;font-weight:500;word-break:break-word;}
  .igp-not-found{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);padding:16px;color:#f87171;font-weight:600;margin-top:16px;}
  /* Single/Bulk tabs (2026-10-03) - same look as indane_gas_info.php's. */
  .igp-tabs{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;}
  .igp-tab{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:10px;
    border:1px solid #e2e2ea;background:#fff;font-size:12.5px;font-weight:600;color:#555;cursor:pointer;transition:all 150ms;}
  .igp-tab:hover{border-color:#2e9e3f;color:#2e9e3f;}
  .igp-tab.active{background:#2e9e3f;border-color:#2e9e3f;color:#fff;box-shadow:0 4px 14px rgba(46,158,63,.35);}
  .igp-textarea{width:100%;min-height:110px;padding:11px 16px;font-size:13px;color:#333;border:1px solid #e0e0e0;
    border-radius:9px;background:#fff;outline:none;resize:vertical;font-family:inherit;}
  .igp-textarea:focus{border-color:#2e9e3f;}
  .igp-hint{font-size:11.5px;color:#999;margin-top:6px;}
  /* Bulk results: one row per searched number (2026-10-03, per explicit
     instruction - all numbers in one table rather than a card each). */
  .igp-bulk-wrap{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);margin-top:16px;overflow-x:auto;}
  .igp-bulk-toolbar{padding:10px 16px;background:#2e9e3f;color:#fff;font-size:11.5px;font-weight:700;
    text-transform:uppercase;letter-spacing:.3px;}
  .igp-bulk-table{width:100%;border-collapse:collapse;font-size:11.5px;}
  .igp-bulk-table th{background:#eeeef6;color:#555;font-size:10px;font-weight:700;text-transform:uppercase;
    letter-spacing:.3px;padding:7px 9px;text-align:left;white-space:nowrap;border-bottom:1px solid #e0e0e0;}
  .igp-bulk-table td{padding:7px 9px;border-bottom:1px solid #eee;vertical-align:top;color:#333;
    max-width:280px;word-break:break-word;}
  .igp-bulk-table tr:nth-child(even) td{background:#f8f8fc;}
  .igp-bulk-table td.igp-num{font-weight:700;white-space:nowrap;}
  .igp-status-found{color:#257e32;font-weight:700;}
  .igp-status-miss{color:#f87171;font-weight:700;}
</style>

<div class="igp-tabs">
  <button type="button" class="igp-tab active" id="igpTabSingle"><i class="bi bi-search"></i> Single Search</button>
  <button type="button" class="igp-tab" id="igpTabBulk"><i class="bi bi-list-ol"></i> Bulk Search</button>
</div>

<div class="igp-card">
  <div class="igp-card-body">
    <div id="igpSingleInput">
      <input type="text" id="igpNumberBox" placeholder="9876543210" maxlength="10"
             style="width:100%;padding:11px 16px;font-size:13px;color:#333;border:1px solid #e0e0e0;border-radius:9px;background:#fff;outline:none;">
    </div>
    <div id="igpBulkInput" style="display:none">
      <textarea id="igpBulkBox" class="igp-textarea" placeholder="9876543210, 9876543211, ... (one per line or comma-separated)"></textarea>
    </div>
    <div class="igp-row">
      <button id="igpSearchBtn" class="igp-btn">Search</button>
      <button id="igpClearBtn" class="igp-btn igp-btn-secondary" type="button">Clear</button>
      <button id="igpExportBtn" class="igp-btn igp-btn-export" type="button" disabled>
        <i class="bi bi-file-earmark-<?= $isAdmin ? 'excel' : 'pdf' ?>"></i>
        Download <?= $isAdmin ? 'Excel' : 'PDF' ?>
      </button>
      <span id="igpStatus"></span>
    </div>
    <div class="igp-progress-wrap" id="igpProgressWrap">
      <div class="igp-progress-track"><div class="igp-progress-fill"></div></div>
      <div class="igp-progress-meta">
        <span id="igpProgressLabel">Checking IOCL…</span>
        <span id="igpProgressElapsed"></span>
      </div>
    </div>
  </div>
</div>

<div id="igpResultWrap"></div>

<?php if ($isAdmin): ?>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<?php else: ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/4.2.1/jspdf.umd.min.js"></script>
<?php endif; ?>
<script>
const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;

const searchBtn      = document.getElementById("igpSearchBtn");
const clearBtn       = document.getElementById("igpClearBtn");
const exportBtn      = document.getElementById("igpExportBtn");
const numberBox      = document.getElementById("igpNumberBox");
const statusEl       = document.getElementById("igpStatus");
const resultWrap     = document.getElementById("igpResultWrap");
const progressWrap   = document.getElementById("igpProgressWrap");
const progressLabel  = document.getElementById("igpProgressLabel");
const progressElapsed= document.getElementById("igpProgressElapsed");

let progressTimer = null;
let searchStartedAt = null;
// Every result currently shown, single or bulk - [{ mobileNumber, found, fields }].
let lastResults = [];
let bulkMode = false;
// Admins: no per-batch cap (2026-10-03, per explicit instruction); agents: 25.
const BULK_NUMBER_LIMIT = IS_ADMIN ? Infinity : 25;
const bulkBox       = document.getElementById("igpBulkBox");
const tabSingle     = document.getElementById("igpTabSingle");
const tabBulk       = document.getElementById("igpTabBulk");
const singleInputEl = document.getElementById("igpSingleInput");
const bulkInputEl   = document.getElementById("igpBulkInput");

function formatDuration(seconds) {
  seconds = Math.max(0, Math.round(seconds));
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

// Checks IOCL server-side (one real page load with its own consumer-lookup
// AJAX round trip) - typically ~4-8s after trimming indane_gas_pro.py's own
// unnecessary fixed waits (2026-09-06, confirmed live: repeated runs landed
// at 4.3-5.6s under normal site load). Kept as an estimate, not a hard cap -
// app.cyfuture.co.in's own AJAX response has been observed taking much
// longer under real site load (up to ~22s in one live test), and the
// backend is intentionally not cut off at 8s, since that would turn a
// slow-but-real answer into a false "not found" - this just sets what the
// countdown displays, same reasoning as tataplay.php's own indeterminate bar.
const ESTIMATED_SECONDS = 8;

function startProgress() {
  searchStartedAt = Date.now();
  progressLabel.textContent = "Checking IOCL…";
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

function buildFieldTable(fields) {
  const table = document.createElement("table");
  table.className = "igp-section-table";
  const tbody = document.createElement("tbody");
  fields.forEach(field => {
    const tr = document.createElement("tr");
    tr.innerHTML = `<td class="igp-field-label"></td><td class="igp-field-value"></td>`;
    tr.querySelector(".igp-field-label").textContent = field.label;
    tr.querySelector(".igp-field-value").textContent = field.value || "—";
    tbody.appendChild(tr);
  });
  table.appendChild(tbody);
  return table;
}

// Appends one number's result. In bulk mode the heading names the number,
// since several are listed together.
function appendResult(data, showNumber) {
  if (!data.found) {
    const miss = document.createElement("div");
    miss.className = "igp-not-found";
    miss.textContent = data.error ? `${data.mobileNumber}: ${data.error}` : `Not found for ${data.mobileNumber}.`;
    resultWrap.appendChild(miss);
    return;
  }
  const box = document.createElement("div");
  box.className = "igp-section";
  const title = document.createElement("div");
  title.className = "igp-section-title";
  title.textContent = showNumber ? `Consumer Details - ${data.mobileNumber}` : "Consumer Details";
  box.appendChild(title);
  box.appendChild(buildFieldTable(data.fields || []));
  resultWrap.appendChild(box);
}

function updateExportButton() {
  exportBtn.disabled = !lastResults.some(r => r.found);
}

function showMode(bulk) {
  bulkMode = bulk;
  tabBulk.classList.toggle("active", bulk);
  tabSingle.classList.toggle("active", !bulk);
  bulkInputEl.style.display = bulk ? "" : "none";
  singleInputEl.style.display = bulk ? "none" : "";
  searchBtn.textContent = bulk ? "Bulk Search" : "Search";
  statusEl.textContent = "";
}
tabSingle.addEventListener("click", () => showMode(false));
tabBulk.addEventListener("click", () => showMode(true));

// Any 10-digit mobile numbers in the box (comma/space/newline separated;
// a +91/91/0 prefix is dropped), de-duplicated, capped at BULK_NUMBER_LIMIT.
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

// One number through indane_gas_pro_api.php. Returns the result object, or
// { mobileNumber, found:false, error } when the request itself failed.
async function lookupNumber(mobileNumber) {
  const res = await fetch("indane_gas_pro_api.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ mobileNumber })
  });
  const data = await res.json();
  if (res.status === 401) {
    window.location.href = data.loginUrl || "login.php";
    throw new Error("signed out");
  }
  if (!res.ok) return { mobileNumber, found: false, error: data.error || "could not complete search" };
  return { ...data, mobileNumber: data.mobileNumber || mobileNumber };
}

async function runBulkSearch() {
  const all = parseBulkNumbers(bulkBox.value);
  if (!all.length) {
    statusEl.textContent = "Enter at least one valid 10-digit mobile number.";
    return;
  }
  const numbers = all.slice(0, BULK_NUMBER_LIMIT);

  searchBtn.disabled = true;
  exportBtn.disabled = true;
  statusEl.textContent = all.length > BULK_NUMBER_LIMIT
    ? `Only the first ${BULK_NUMBER_LIMIT} of ${all.length} numbers will be searched.` : "";
  resultWrap.innerHTML = "";
  lastResults = [];
  progressWrap.style.display = "block";
  const startedAt = Date.now();
  let found = 0;

  try {
    for (let i = 0; i < numbers.length; i++) {
      const elapsed = (Date.now() - startedAt) / 1000;
      progressLabel.textContent = `Checking ${numbers[i]} (${i + 1}/${numbers.length})…`;
      progressElapsed.textContent = i === 0 ? "Estimating time…"
        : `~${formatDuration((elapsed / i) * (numbers.length - i))} remaining`;
      let r;
      try {
        r = await lookupNumber(numbers[i]);
      } catch (err) {
        if (err.message === "signed out") return;
        r = { mobileNumber: numbers[i], found: false, error: "could not reach the server" };
      }
      lastResults.push(r);
      if (r.found) { found++; if (typeof showAccessGranted === "function") showAccessGranted(); }   // ACCESS GRANTED stamp (assets/confetti.js)
      renderBulkTable();
      updateExportButton();
    }
    progressLabel.textContent = `${found} found out of ${numbers.length} number${numbers.length === 1 ? "" : "s"}`;
    progressElapsed.textContent = `Done in ${formatDuration((Date.now() - startedAt) / 1000)}`;
  } finally {
    searchBtn.disabled = false;
  }
}

// Every field label any result has, in first-seen order - the bulk table's
// (and bulk Excel's) columns after Mobile Number and Status.
function bulkColumns() {
  const cols = [];
  lastResults.forEach(r => (r.fields || []).forEach(f => { if (!cols.includes(f.label)) cols.push(f.label); }));
  return cols;
}

function bulkStatus(r) {
  return r.found ? "Found" : (r.error ? `Error: ${r.error}` : "Not found");
}

// All searched numbers in one table, one row each - redrawn as each number
// finishes so rows appear live during the batch.
function renderBulkTable() {
  const cols = bulkColumns();
  resultWrap.innerHTML = "";
  const wrap = document.createElement("div");
  wrap.className = "igp-bulk-wrap";
  const toolbar = document.createElement("div");
  toolbar.className = "igp-bulk-toolbar";
  const foundCount = lastResults.filter(r => r.found).length;
  toolbar.textContent = `${foundCount} found out of ${lastResults.length} number${lastResults.length === 1 ? "" : "s"}`;
  wrap.appendChild(toolbar);

  const table = document.createElement("table");
  table.className = "igp-bulk-table";
  const headRow = document.createElement("tr");
  ["Mobile Number", "Status", ...cols].forEach(h => {
    const th = document.createElement("th");
    th.textContent = h;
    headRow.appendChild(th);
  });
  const thead = document.createElement("thead");
  thead.appendChild(headRow);
  table.appendChild(thead);

  const tbody = document.createElement("tbody");
  lastResults.forEach(r => {
    const tr = document.createElement("tr");
    const num = document.createElement("td");
    num.className = "igp-num";
    num.textContent = r.mobileNumber;
    tr.appendChild(num);
    const status = document.createElement("td");
    status.className = r.found ? "igp-status-found" : "igp-status-miss";
    status.textContent = bulkStatus(r);
    tr.appendChild(status);
    const byLabel = {};
    (r.fields || []).forEach(f => { byLabel[f.label] = f.value; });
    cols.forEach(c => {
      const td = document.createElement("td");
      td.textContent = byLabel[c] || "—";
      tr.appendChild(td);
    });
    tbody.appendChild(tr);
  });
  table.appendChild(tbody);
  wrap.appendChild(table);
  resultWrap.appendChild(wrap);
}

async function runSearch() {
  if (bulkMode) return runBulkSearch();
  const mobileNumber = numberBox.value.replace(/\D/g, "");
  if (mobileNumber.length !== 10) {
    statusEl.textContent = "Enter a valid 10-digit mobile number.";
    return;
  }

  searchBtn.disabled = true;
  exportBtn.disabled = true;
  statusEl.textContent = "";
  resultWrap.innerHTML = "";
  lastResults = [];
  startProgress();

  try {
    const res = await fetch("indane_gas_pro_api.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ mobileNumber })
    });
    const data = await res.json();

    if (res.status === 401) {
      window.location.href = data.loginUrl || "login.php";
      return;
    }

    if (!res.ok) {
      stopProgress(null);
      statusEl.textContent = `Error: ${data.error || "could not complete search"}`;
      return;
    }

    stopProgress(data.found ? "Result found" : "No result found");
    if (data.found) if (typeof showAccessGranted === "function") showAccessGranted();   // ACCESS GRANTED stamp (assets/confetti.js)
    lastResults = [data];
    appendResult(data, false);
    updateExportButton();
  } catch (err) {
    stopProgress(null);
    statusEl.textContent = `Could not reach the server: ${err.message}`;
  } finally {
    searchBtn.disabled = false;
  }
}

// Agents get a PDF (jsPDF, built client-side and saved directly - same
// approach as pan_india.php's own "Download as PDF", chosen there over the
// browser's print dialog since print-to-PDF isn't a direct download).
// Admins get an Excel file instead (SheetJS, same pattern as
// indane_gas_info.php's own export) - per explicit instruction, 2026-09-03.
function downloadPdf() {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF({ unit: 'pt', format: 'a4' });
  const pageWidth = doc.internal.pageSize.getWidth();
  const pageHeight = doc.internal.pageSize.getHeight();
  const margin = 40;
  let y = margin;

  function ensureSpace(lineHeight) {
    if (y + lineHeight > pageHeight - margin) { doc.addPage(); y = margin; }
  }

  const found = lastResults.filter(r => r.found);
  doc.setFontSize(16);
  doc.text('Indane Gas Pro - Consumer Details', margin, y);
  y += 22;
  doc.setFontSize(10);
  doc.setTextColor(90);
  doc.text(new Date().toLocaleString(), margin, y);
  y += 24;
  doc.setTextColor(0);

  found.forEach(result => {
    ensureSpace(40);
    doc.setFont(undefined, 'bold');
    doc.setFontSize(12);
    doc.text(`Mobile Number: ${result.mobileNumber}`, margin, y);
    y += 20;
    (result.fields || []).forEach(field => {
      ensureSpace(18);
      doc.setFont(undefined, 'bold');
      doc.setFontSize(10);
      doc.text(`${field.label}:`, margin, y);
      doc.setFont(undefined, 'normal');
      doc.text(field.value || '—', margin + 160, y);
      y += 18;
    });
    y += 12;
  });

  const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, "-");
  const name = found.length === 1 ? found[0].mobileNumber : `bulk-${found.length}`;
  doc.save(`indane-gas-pro-${name}-${stamp}.pdf`);
}

function downloadExcel() {
  const found = lastResults.filter(r => r.found);
  let aoa;
  if (lastResults.length > 1) {
    // Bulk: same one-row-per-number layout as the on-screen table,
    // including the numbers that weren't found.
    const cols = bulkColumns();
    aoa = [["Mobile Number", "Status", ...cols]];
    lastResults.forEach(r => {
      const byLabel = {};
      (r.fields || []).forEach(f => { byLabel[f.label] = f.value; });
      aoa.push([r.mobileNumber, bulkStatus(r), ...cols.map(c => byLabel[c] || "")]);
    });
  } else {
    aoa = [["Field", "Value"]];
    (found[0].fields || []).forEach(field => aoa.push([field.label, field.value || ""]));
  }

  const ws = XLSX.utils.aoa_to_sheet(aoa);
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, "Result");
  const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, "-");
  const name = found.length === 1 ? found[0].mobileNumber : `bulk-${found.length}`;
  XLSX.writeFile(wb, `indane-gas-pro-${name}-${stamp}.xlsx`);
}

searchBtn.addEventListener("click", runSearch);
numberBox.addEventListener("keydown", (e) => {
  if (e.key === "Enter" && !searchBtn.disabled) runSearch();
});
clearBtn.addEventListener("click", () => {
  numberBox.value = "";
  bulkBox.value = "";
  statusEl.textContent = "";
  resultWrap.innerHTML = "";
  lastResults = [];
  exportBtn.disabled = true;
  stopProgress(null);
});
exportBtn.addEventListener("click", () => {
  if (!lastResults.some(r => r.found)) return;
  if (IS_ADMIN) downloadExcel(); else downloadPdf();
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

<?php
require __DIR__ . '/includes/auth.php';
requireIndaneGasAccess(); // requireLogin() + a 403 for logged-in users without the dedicated "Indane Gas Info" permission (Admin > Agents) - promoted out of the generic Tracing 2.0 bucket 2026-08-18
require_once __DIR__ . '/config/db.php';

// Same quota-badge pattern as hp_gas.php/rc_print.php - Indane Gas Info now
// has its own count-based monthly quota (indane_gas_monthly_limit,
// search_type = 'indane_gas'), not the shared Tracing 2.0 credit bucket.
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
// Bulk Search for every account with Indane Gas access (2026-10-03, per
// explicit instruction - was admin-only). Each bulk number still goes
// through tracing2_api.php individually, so an agent's monthly limit is
// enforced per number exactly as for single searches; the batch stops at
// the limit (see runBulkSearch()).
$canBulk = true;
$quota = null;
if (!$isAdmin) {
    $stmt = $pdo->prepare('SELECT indane_gas_monthly_limit FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $monthlyLimit = (int) $stmt->fetchColumn();

    // result_count > 0 (2026-08-28) - matches tracing2_api.php's own quota
    // query for this same search_type: a clean not-found result doesn't
    // cost the agent quota, so this badge must count the same rows the
    // live gate actually enforces against, not every logged attempt.
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM search_logs WHERE user_id = :id AND search_type = 'indane_gas' AND result_count > 0 AND searched_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
    );
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $usedThisMonth = (int) $stmt->fetchColumn();

    $quota = ['used' => $usedThisMonth, 'limit' => $monthlyLimit];
}

$basePath = '';
require __DIR__ . '/includes/header.php';
?>

<!-- Confetti overlay - populated/cleared by startConfetti()/stopConfetti()
     (assets/confetti.js), only while a search has actually succeeded. -->
<div class="confetti-container" id="confetti-container"></div>

<div class="page-header" style="display:flex;align-items:center;flex-wrap:wrap;gap:12px">
  <h1 class="page-title" style="margin:0"><i class="bi bi-fuel-pump-fill"></i> Indane Gas</h1>
  <?php if ($isAdmin): ?>
    <span id="igQuotaBadge" class="badge badge-neutral" style="margin-left:auto">Unlimited (Admin)</span>
  <?php elseif ($quota !== null): ?>
    <span id="igQuotaBadge" class="badge <?= $quota['used'] >= $quota['limit'] ? 'badge-danger' : 'badge-neutral' ?>"
          style="margin-left:auto">
      <?= $quota['limit'] - $quota['used'] > 0 ? $quota['limit'] - $quota['used'] : 0 ?> of <?= $quota['limit'] ?> left this month
    </span>
  <?php endif; ?>
</div>

<style>
  /* Same tokens/shape as lpg_search.php's own card - a single, focused
     search box rather than tracing2.php's 24-tool tab picker, since this
     page only ever calls the one "indane-gas-info" tool. */
  .ig-card{background:var(--c-surface,#fff);border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;}
  .ig-card-body{padding:16px 18px;}
  .ig-row{display:flex;align-items:center;gap:12px;margin-top:12px;flex-wrap:wrap;}
  .ig-btn{padding:11px 26px;border-radius:9px;border:none;background:#2e9e3f;color:#fff;
    font-size:12.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;cursor:pointer;
    transition:all 150ms;box-shadow:0 4px 18px rgba(46,158,63,.3);}
  .ig-btn:hover:not(:disabled){background:#257e32;transform:translateY(-1px);box-shadow:0 6px 20px rgba(46,158,63,.45);}
  .ig-btn:disabled{opacity:.65;cursor:wait;transform:none;}
  .ig-btn-secondary{background:#fff;color:#333;border:1px solid #e0e0e0;box-shadow:none;}
  .ig-btn-secondary:hover:not(:disabled){background:#eeeef6;border-color:#2e9e3f;transform:none;box-shadow:none;}
  #igStatus{font-size:12.5px;color:#555;white-space:pre-wrap;word-break:break-word;font-weight:500;}
  .ig-progress-wrap{margin-top:12px;display:none;}
  .ig-progress-track{height:8px;border-radius:6px;background:#eeeef6;overflow:hidden;border:1px solid #e0e0e0;}
  .ig-progress-fill{height:100%;border-radius:6px;background:#2e9e3f;width:100%;
    background-image:repeating-linear-gradient(45deg,#2e9e3f 0 12px,#257e32 12px 24px);
    background-size:34px 100%;animation:ig-progress-stripes 1s linear infinite;}
  @keyframes ig-progress-stripes{from{background-position:0 0;}to{background-position:-34px 0;}}
  .ig-progress-meta{display:flex;justify-content:space-between;margin-top:6px;font-size:11.5px;color:#999;}

  /* Result cards - identical shape/classes to tracing2.php's own .t2-*
     rules so a record looks the same wherever it's shown in this app. */
  .t2-record{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;margin-bottom:14px;}
  .t2-record-header{padding:12px 16px;background:#2e9e3f;color:#fff;display:flex;align-items:center;gap:10px;}
  .t2-record-header i{font-size:16px;}
  .t2-record-name{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;}
  .t2-record-status{margin-left:auto;font-size:10.5px;padding:2px 10px;border-radius:999px;
    font-weight:700;text-transform:uppercase;letter-spacing:.3px;background:rgba(255,255,255,.2);}
  .t2-field-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;padding:16px;}
  .t2-field-item{display:flex;align-items:flex-start;gap:10px;}
  .t2-field-item i{font-size:15px;color:#2e9e3f;margin-top:2px;flex-shrink:0;}
  .t2-field-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;color:#999;margin-bottom:2px;}
  .t2-field-value{font-size:13px;font-weight:600;color:#222;word-break:break-word;}
  /* Section cards - same look as hp_gas.php's result (per explicit
     instruction, 2026-10-04): one card per section, green title bar, a
     label/value list inside. */
  .ig-section{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;margin-bottom:14px;}
  .ig-section-title{padding:10px 16px;background:#2e9e3f;color:#fff;font-size:11.5px;font-weight:700;
    text-transform:uppercase;letter-spacing:.4px;}
  .ig-section-table{width:100%;border-collapse:collapse;}
  .ig-section-table tr:nth-child(odd){background:#fff;}
  .ig-section-table tr:nth-child(even){background:#f8f8fc;}
  .ig-section-table td{padding:8px 16px;font-size:12.5px;border-bottom:1px solid #eee;vertical-align:top;}
  .ig-section-table tr:last-child td{border-bottom:none;}
  .ig-field-label{width:38%;color:#777;font-weight:600;text-transform:uppercase;}
  .ig-field-value{color:#222;font-weight:500;word-break:break-word;}
  .ig-result-wrap{margin-top:16px;display:none;}
  .ig-result-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:12px;}
  .ig-result-count{font-size:12px;color:#777;font-weight:600;}
  .ig-no-results{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;
    padding:56px 16px;color:#999;margin-top:16px;}
  .ig-no-results i{font-size:38px;color:#ccc;width:74px;height:74px;display:flex;align-items:center;justify-content:center;
    border-radius:50%;border:1.5px solid #e5e5e5;}
  .ig-no-results span{font-size:14px;color:#888;}

  <?php if ($canBulk): ?>
  /* Single/Bulk mode tabs (see $canBulk above). Each bulk number is its
     own blocking locateme.services search run sequentially client-side
     (this tool has no job-queue backend like lpg_search.py's), so bulk is
     capped low (BULK_NUMBER_LIMIT below). */
  .ig-tabs{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;}
  .ig-tab{display:inline-flex;align-items:center;gap:8px;padding:11px 18px;border-radius:999px;
    border:1px solid #e2e2ea;background:#fff;font-size:12.5px;font-weight:600;color:#555;
    cursor:pointer;transition:all 150ms;white-space:nowrap;}
  .ig-tab i{font-size:14px;}
  .ig-tab:hover{border-color:#2e9e3f;color:#2e9e3f;}
  .ig-tab.active{background:#2e9e3f;border-color:#2e9e3f;color:#fff;box-shadow:0 4px 14px rgba(46,158,63,.35);}
  .ig-textarea{width:100%;height:110px;padding:9px 14px;font-size:13px;color:#333;
    border:1px solid #e0e0e0;border-radius:9px;background:#fff;resize:vertical;outline:none;}
  .ig-textarea:focus{border-color:#2e9e3f;box-shadow:0 0 0 3px rgba(46,158,63,.25);}
  <?php endif; ?>
  .ig-export-btn{background:#10b981;color:#fff;border:none;box-shadow:0 4px 18px rgba(16,185,129,.3);padding:9px 18px;
    border-radius:9px;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;cursor:pointer;
    display:inline-flex;align-items:center;gap:8px;transition:all 150ms;}
  .ig-export-btn:hover:not(:disabled){background:#0d9668;transform:translateY(-1px);box-shadow:0 6px 20px rgba(16,185,129,.45);}
  .ig-export-btn:disabled{opacity:.5;cursor:not-allowed;transform:none;box-shadow:none;}
  .t2-group-heading{font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;
    color:#2e9e3f;margin:20px 0 8px;padding-bottom:6px;border-bottom:2px solid #e2e2ea;}
  .t2-group-heading:first-child{margin-top:0;}
</style>

<?php if ($canBulk): ?>
<div class="ig-tabs">
  <button type="button" class="ig-tab active" id="igTabSingle"><i class="bi bi-search"></i> Single Search</button>
  <button type="button" class="ig-tab" id="igTabBulk"><i class="bi bi-list-ol"></i> Bulk Search</button>
</div>
<?php endif; ?>

<div id="igSingleMode">
  <div class="ig-card">
    <div class="ig-card-body">
      <input type="text" id="igNumberBox" placeholder="Enter 10-digit Number" maxlength="100"
             style="width:100%;padding:11px 16px;font-size:13px;color:#333;border:1px solid #e0e0e0;border-radius:9px;background:#fff;outline:none;">
      <div class="ig-row">
        <button id="igSearchBtn" class="ig-btn">Search</button>
        <button id="igClearBtn" class="ig-btn ig-btn-secondary" type="button">Clear</button>
        <span id="igStatus"></span>
      </div>
      <div class="ig-progress-wrap" id="igProgressWrap">
        <div class="ig-progress-track"><div class="ig-progress-fill"></div></div>
        <div class="ig-progress-meta">
          <span id="igProgressLabel">Running the search…</span>
          <span id="igProgressElapsed"></span>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if ($canBulk): ?>
<div id="igBulkMode" style="display:none">
  <div class="ig-card">
    <div class="ig-card-body">
      <textarea id="igBulkNumbersBox" class="ig-textarea" placeholder="9876543210, 9876543211, ..."></textarea>
      <div class="ig-row">
        <button id="igBulkSearchBtn" class="ig-btn">Bulk Search</button>
        <button id="igBulkClearBtn" class="ig-btn ig-btn-secondary" type="button">Clear</button>
        <span id="igBulkStatus"></span>
      </div>
      <div class="ig-progress-wrap" id="igBulkProgressWrap">
        <div class="ig-progress-track"><div class="ig-progress-fill"></div></div>
        <div class="ig-progress-meta">
          <span id="igBulkProgressLabel"></span>
          <span id="igBulkProgressElapsed"></span>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="ig-result-wrap" id="igResultWrap">
  <div class="ig-result-toolbar">
    <span class="ig-result-count" id="igResultCountText"></span>
    <button id="igExportBtn" class="ig-export-btn" type="button" disabled>
      <i class="bi bi-file-earmark-excel"></i> Export as Excel (CSV)
    </button>
  </div>
  <div id="igRecordsWrap"></div>
</div>
<div class="ig-no-results" id="igNoResults" style="display:none">
  <i class="bi bi-search"></i>
  <span>Data not found</span>
</div>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
// Per explicit instruction (same rule as tracing2.php): every search
// failure - bad input, quota reached, access not granted, a Selenium
// crash, an unreachable backend - shows this same generic message rather
// than whatever real error text the server returned.
const SERVER_DOWN_MESSAGE = "Server is down. Please try again later.";

const searchBtn       = document.getElementById("igSearchBtn");
const clearBtn        = document.getElementById("igClearBtn");
const numberBox       = document.getElementById("igNumberBox");
const statusEl        = document.getElementById("igStatus");
const resultWrap      = document.getElementById("igResultWrap");
const resultCountText = document.getElementById("igResultCountText");
const recordsWrap     = document.getElementById("igRecordsWrap");
const noResultsEl     = document.getElementById("igNoResults");
const quotaBadge      = document.getElementById("igQuotaBadge");
const progressWrap    = document.getElementById("igProgressWrap");
const progressFill    = progressWrap.querySelector(".ig-progress-fill");
const progressLabel   = document.getElementById("igProgressLabel");
const progressElapsed = document.getElementById("igProgressElapsed");

let progressTimer = null;
let searchStartedAt = null;

function formatDuration(seconds) {
  seconds = Math.max(0, Math.round(seconds));
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

// One blocking fetch for the whole login+search+scrape sequence (same
// backend call as tracing2.php's own tool picker) - no per-step progress
// to report, so the bar is always indeterminate with a fixed estimate.
const ESTIMATED_SECONDS = 20;

function startProgress() {
  searchStartedAt = Date.now();
  progressLabel.textContent = "Running the search…";
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

function updateQuotaBadge(used, limit, unit) {
  if (!quotaBadge) return;
  const remaining = Math.max(0, limit - used);
  quotaBadge.textContent = `${remaining} of ${limit} ${unit || "searches"} left this month`;
  quotaBadge.classList.toggle("badge-danger", used >= limit);
  quotaBadge.classList.toggle("badge-neutral", used < limit);
}

// Only these columns are shown, in this order - the rest of whatever
// locateme.services returns for this tool is hidden, per explicit
// instruction (2026-08-23). Matched against each record's fields
// case-insensitively, since the source site renders its own labels in
// all caps but the scraped text case isn't guaranteed.
const IG_COLUMNS = [
  "Registered Mobile", "Relationship Id", "Consumer Id", "Full Name",
  "Physical Address", "Agency Name", "Agency Contact", "Agency Address",
  "Main Product", "Category",
];

// locateme.services splits ONE person's data across several separate
// cards (confirmed live 2026-08-23: a single search came back as 5
// records - Consumer Detail fields in one, Agency Detail fields in
// another, empty placeholders in the rest) rather than one card with
// everything - merges every record's fields into one flat list first, so
// columns that came from a different card still end up in the same row
// as the rest of that person's data.
function mergeIndaneGasFields(records) {
  const merged = [];
  records.forEach(record => {
    (record.fields || []).forEach(f => {
      const label = (f.label || "").trim();
      const value = (f.value || "").trim();
      if (value && !merged.some(m => m.label.toLowerCase() === label.toLowerCase())) {
        merged.push({ label, value });
      }
    });
  });
  return merged;
}

// Substring match, not exact - confirmed live 2026-08-23: the real field
// is labelled "UCM RELATIONSHIP ID" on the source site, not "RELATIONSHIP
// ID" as it reads once summarized/typed out, so an exact-equality lookup
// silently missed it (showed "—" despite the value being right there in
// the response). Matching on "does this label CONTAIN the column name"
// finds it regardless of whatever prefix the site puts in front.
function lookupIndaneGasField(merged, col) {
  const target = col.toLowerCase();
  const hit = merged.find(m => m.label.toLowerCase().includes(target));
  return hit ? hit.value : "—";
}

function buildIndaneGasTable(records) {
  const merged = mergeIndaneGasFields(records);
  const row = IG_COLUMNS.map(col => lookupIndaneGasField(merged, col));

  // Same weighted colgroup sizing as pan_india.php's buildResultsTable()
  // (reusing its .pan-results-table class, which is what actually turns on
  // table-layout:fixed + cell wrapping instead of the base .results-table's
  // nowrap/ellipsis) - percentage widths driven by each column's own
  // content length, capped so one very long field (an address) can't
  // squeeze the rest down to nothing, so all 10 columns fit the page width
  // with long values wrapping onto multiple lines instead of forcing a
  // horizontal scroll.
  const longestToken = value => value.split(/[\s,;]+/).reduce((max, tok) => Math.max(max, tok.length), 0);
  const rawWeights = IG_COLUMNS.map((col, i) => {
    const v = row[i] === "—" ? "" : row[i];
    const maxLen = Math.max(col.length, v.length);
    const maxToken = Math.max(col.length, longestToken(v));
    return Math.max(Math.sqrt(maxLen) * 5, col.length * 1.5, maxToken * 3.2, 16);
  });
  const rawTotal = rawWeights.reduce((a, b) => a + b, 0);
  const cap = rawTotal * 0.22;
  const weights = rawWeights.map(w => Math.min(w, cap));
  const totalWeight = weights.reduce((a, b) => a + b, 0);

  const table = document.createElement("table");
  table.className = "results-table pan-results-table";

  const colgroup = document.createElement("colgroup");
  weights.forEach(w => {
    const col = document.createElement("col");
    col.style.width = (w / totalWeight * 100).toFixed(2) + "%";
    colgroup.appendChild(col);
  });
  table.appendChild(colgroup);

  const thead = document.createElement("thead");
  const headRow = document.createElement("tr");
  IG_COLUMNS.forEach(col => {
    const th = document.createElement("th");
    th.textContent = col;
    headRow.appendChild(th);
  });
  thead.appendChild(headRow);
  table.appendChild(thead);

  const tbody = document.createElement("tbody");
  const tr = document.createElement("tr");
  row.forEach(value => {
    const td = document.createElement("td");
    td.textContent = value;
    tr.appendChild(td);
  });
  tbody.appendChild(tr);
  table.appendChild(tbody);
  return table;
}

let lastRecords = [];
const exportBtn = document.getElementById("igExportBtn");

// HP Gas-style section cards (per explicit instruction, 2026-10-04). The
// site returns one summary record (the person - name/status + every field)
// plus one record per section ("CONSUMER DETAILS", "DISTRIBUTOR
// INTELLIGENCE", ...). The section records are shown as cards; if none
// came back, each record becomes its own card instead.
function buildIndaneGasCards(records) {
  const holder = document.createElement("div");
  const isSection = r => /details|intelligence|specs|compliance|profile/i.test(r.name || "");
  // "Product & Asset Specs" and "Eligibility & Compliance" not shown (per
  // explicit instruction, 2026-10-04).
  const isHidden = r => /specs|compliance/i.test(r.name || "");
  const sections = (records.some(isSection) ? records.filter(isSection) : records).filter(r => !isHidden(r));
  sections.forEach(rec => {
    const fields = (rec.fields || []).filter(f => String(f.value || "").trim());
    if (!fields.length) return;
    const box = document.createElement("div");
    box.className = "ig-section";
    const title = document.createElement("div");
    title.className = "ig-section-title";
    title.textContent = rec.name || "Consumer Details";
    box.appendChild(title);
    const table = document.createElement("table");
    table.className = "ig-section-table";
    const tbody = document.createElement("tbody");
    fields.forEach(f => {
      const tr = document.createElement("tr");
      tr.innerHTML = `<td class="ig-field-label"></td><td class="ig-field-value"></td>`;
      tr.querySelector(".ig-field-label").textContent = f.label;
      tr.querySelector(".ig-field-value").textContent = f.value;
      tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    box.appendChild(table);
    holder.appendChild(box);
  });
  return holder;
}

function renderResult(data) {
  recordsWrap.innerHTML = "";
  resultWrap.style.display = "none";
  noResultsEl.style.display = "none";
  lastRecords = [];

  const records = (data.found && Array.isArray(data.records)) ? data.records : [];

  if (records.length) {
    lastRecords = records;
    // One merged row regardless of how many fragments the backend
    // returned - see buildIndaneGasTable()'s own comment.
    resultCountText.textContent = "1 result found";
    recordsWrap.appendChild(buildIndaneGasCards(records));
    resultWrap.style.display = "block";
    exportBtn.disabled = false;
    startConfetti();
  } else {
    noResultsEl.style.display = "flex";
    exportBtn.disabled = true;
    stopConfetti();
  }

  if (typeof data.used === "number" && typeof data.limit === "number") {
    updateQuotaBadge(data.used, data.limit, data.unit);
  }
}

// Export matches what's actually shown - just IG_COLUMNS, not every field
// locateme.services returns, same "hide the rest" instruction as the table.
// Grouped by section (one group per searched number in bulk mode; a single
// implicit group for a single search) and merged the same way
// buildIndaneGasTable() does, so this has exactly one row per search - not
// one per raw fragment locateme.services returned for that person.
exportBtn.addEventListener("click", () => {
  if (!lastRecords.length) return;
  const hasSection = lastRecords.some(r => r.section);
  const columns = hasSection ? ["Number", ...IG_COLUMNS] : [...IG_COLUMNS];

  const groups = new Map();
  lastRecords.forEach(r => {
    const key = r.section || "";
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key).push(r);
  });

  const aoa = [columns];
  groups.forEach((groupRecords, section) => {
    const merged = mergeIndaneGasFields(groupRecords);
    const row = [];
    if (hasSection) row.push(section);
    IG_COLUMNS.forEach(col => {
      const value = lookupIndaneGasField(merged, col);
      row.push(value === "—" ? "" : value);
    });
    aoa.push(row);
  });

  const ws = XLSX.utils.aoa_to_sheet(aoa);
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, "Result");
  const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, "-");
  XLSX.writeFile(wb, `indane-gas-${stamp}.xlsx`);
});

async function runSearch() {
  const query = numberBox.value.replace(/\D+/g, "");
  if (!query) {
    statusEl.textContent = SERVER_DOWN_MESSAGE;
    return;
  }

  searchBtn.disabled = true;
  statusEl.textContent = "";
  resultWrap.style.display = "none";
  noResultsEl.style.display = "none";
  startProgress();

  try {
    const res = await fetch("tracing2_api.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ tool: "indane-gas-info", query })
    });
    const data = await res.json();

    if (res.status === 401) {
      window.location.href = data.loginUrl || "login.php";
      return;
    }

    if (!res.ok) {
      stopProgress(null);
      // The real reason when there is one (e.g. "Indane Gas source is not
      // responding right now", or the monthly-limit message).
      statusEl.textContent = data.error || SERVER_DOWN_MESSAGE;
      if (typeof data.used === "number" && typeof data.limit === "number") {
        updateQuotaBadge(data.used, data.limit, data.unit);
      }
      return;
    }

    stopProgress(data.found ? "Result found" : "Data not found");
    renderResult(data);
  } catch (err) {
    stopProgress(null);
    statusEl.textContent = SERVER_DOWN_MESSAGE;
  } finally {
    searchBtn.disabled = false;
  }
}

searchBtn.addEventListener("click", runSearch);
numberBox.addEventListener("keydown", (e) => {
  if (e.key === "Enter" && !searchBtn.disabled) runSearch();
});
clearBtn.addEventListener("click", () => {
  numberBox.value = "";
  statusEl.textContent = "";
  resultWrap.style.display = "none";
  noResultsEl.style.display = "none";
  stopProgress(null);
  stopConfetti();
});

// --- Bulk Search (see $canBulk; when it's off these elements don't exist
// in the DOM, so this entire block is a no-op). Each number is its own blocking
// locateme.services search, run one at a time (no job-queue backend like
// lpg_search.py's to batch them) - capped well below lpg_search.php's own
// bulk limit for that reason.
const tabSingle = document.getElementById("igTabSingle");
if (tabSingle) {
  const tabBulk           = document.getElementById("igTabBulk");
  const singleMode        = document.getElementById("igSingleMode");
  const bulkMode          = document.getElementById("igBulkMode");
  const bulkNumbersBox    = document.getElementById("igBulkNumbersBox");
  const bulkSearchBtn     = document.getElementById("igBulkSearchBtn");
  const bulkClearBtn      = document.getElementById("igBulkClearBtn");
  const bulkStatusEl      = document.getElementById("igBulkStatus");
  const bulkProgressWrap  = document.getElementById("igBulkProgressWrap");
  const bulkProgressLabel = document.getElementById("igBulkProgressLabel");
  const bulkProgressEta   = document.getElementById("igBulkProgressElapsed");

  const BULK_NUMBER_LIMIT = 25;

  function showMode(mode) {
    const isBulk = mode === "bulk";
    tabBulk.classList.toggle("active", isBulk);
    tabSingle.classList.toggle("active", !isBulk);
    bulkMode.style.display = isBulk ? "" : "none";
    singleMode.style.display = isBulk ? "none" : "";
  }
  tabSingle.addEventListener("click", () => showMode("single"));
  tabBulk.addEventListener("click", () => showMode("bulk"));

  // Same number-parsing/merging as lpg_search.php's bulk mode (dash/space-
  // formatted numbers, "XXXXX XXXXX" split-in-two paste artifacts).
  function parseBulkNumbers(raw) {
    const tokens = raw.split(/[\s,]+/).map(s => s.trim()).filter(s => s.length > 0);
    const merged = [];
    for (let i = 0; i < tokens.length; i++) {
      const cur = tokens[i].replace(/\D+/g, "");
      const next = tokens[i + 1] ? tokens[i + 1].replace(/\D+/g, "") : "";
      if (cur.length === 5 && next.length === 5) {
        merged.push(cur + next);
        i++;
      } else if (cur.length > 0) {
        merged.push(cur);
      }
    }
    return merged.slice(0, BULK_NUMBER_LIMIT);
  }

  function setBulkSearching(isSearching) {
    bulkSearchBtn.disabled = isSearching;
    searchBtn.disabled = isSearching;
  }

  async function runBulkSearch(numbers) {
    if (numbers.length === 0) {
      bulkStatusEl.textContent = "Enter at least one valid mobile number.";
      return;
    }

    setBulkSearching(true);
    recordsWrap.innerHTML = "";
    resultWrap.style.display = "none";
    noResultsEl.style.display = "none";
    resultCountText.textContent = "";
    lastRecords = [];
    exportBtn.disabled = true;

    bulkProgressWrap.style.display = "block";
    const startedAt = Date.now();
    let foundAny = false;
    let foundCount = 0;

    for (let i = 0; i < numbers.length; i++) {
      const number = numbers[i];
      bulkStatusEl.textContent = "";
      bulkProgressLabel.textContent = `Searching ${number} (${i + 1}/${numbers.length})…`;
      const elapsed = (Date.now() - startedAt) / 1000;
      bulkProgressEta.textContent = i === 0
        ? "Estimating time…"
        : `~${formatDuration((elapsed / i) * (numbers.length - i))} remaining`;

      try {
        const res = await fetch("tracing2_api.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ tool: "indane-gas-info", query: number })
        });
        const data = await res.json();

        if (res.status === 401) {
          window.location.href = data.loginUrl || "login.php";
          return;
        }
        if (typeof data.used === "number" && typeof data.limit === "number") {
          updateQuotaBadge(data.used, data.limit, data.unit);
        }

        // Monthly limit reached - every remaining number would be refused
        // the same way, so stop the batch here with the real message.
        if (res.status === 429) {
          bulkStatusEl.textContent = data.error || "Monthly limit reached.";
          const remaining = numbers.length - i;
          const stopped = document.createElement("div");
          stopped.className = "ig-result-count";
          stopped.style.cssText = "color:var(--c-danger,#dc2626);margin:4px 0 14px;";
          stopped.textContent = `${data.error || "Monthly limit reached."} ${remaining} number${remaining === 1 ? "" : "s"} not searched.`;
          recordsWrap.appendChild(stopped);
          numbers = numbers.slice(0, i);
          break;
        }

        const records = (res.ok && data.found && Array.isArray(data.records)) ? data.records : [];
        const heading = document.createElement("div");
        heading.className = "t2-group-heading";
        heading.textContent = number;
        recordsWrap.appendChild(heading);

        if (records.length) {
          // Heading already added above (the number itself), so the table
          // goes straight in rather than repeating a per-record heading.
          // The export copy still gets section = number so the "Number"
          // column works.
          recordsWrap.appendChild(buildIndaneGasCards(records));
          lastRecords = lastRecords.concat(records.map(r => ({ ...r, section: number })));
          foundAny = true;
          foundCount++;
        } else {
          const empty = document.createElement("div");
          empty.className = "ig-result-count";
          empty.style.cssText = "color:#999;margin:-4px 0 14px;";
          empty.textContent = res.ok ? "Data not found" : (data.error || "Server is down. Please try again later.");
          recordsWrap.appendChild(empty);
        }
      } catch (err) {
        const heading = document.createElement("div");
        heading.className = "t2-group-heading";
        heading.textContent = number;
        recordsWrap.appendChild(heading);
        const empty = document.createElement("div");
        empty.className = "ig-result-count";
        empty.style.cssText = "color:#999;margin:-4px 0 14px;";
        empty.textContent = "Server is down. Please try again later.";
        recordsWrap.appendChild(empty);
      }
    }

    bulkProgressLabel.textContent = `${numbers.length} number${numbers.length === 1 ? "" : "s"} searched`;
    bulkProgressEta.textContent = `Done in ${formatDuration((Date.now() - startedAt) / 1000)}`;
    // foundCount (one merged result per searched number that found
    // anything), not lastRecords.length - that still holds every raw
    // fragment locateme.services returned per number (see
    // buildIndaneGasTable()'s own comment on why those get merged).
    resultCountText.textContent = `${foundCount} result${foundCount === 1 ? "" : "s"} found across ${numbers.length} number${numbers.length === 1 ? "" : "s"}`;
    resultWrap.style.display = "block";
    exportBtn.disabled = lastRecords.length === 0;
    setBulkSearching(false);
    if (foundAny) startConfetti(); else stopConfetti();
  }

  bulkSearchBtn.addEventListener("click", () => runBulkSearch(parseBulkNumbers(bulkNumbersBox.value)));
  bulkClearBtn.addEventListener("click", () => {
    bulkNumbersBox.value = "";
    bulkStatusEl.textContent = "";
    bulkProgressWrap.style.display = "none";
    recordsWrap.innerHTML = "";
    resultWrap.style.display = "none";
    noResultsEl.style.display = "none";
    lastRecords = [];
    exportBtn.disabled = true;
    stopConfetti();
  });
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

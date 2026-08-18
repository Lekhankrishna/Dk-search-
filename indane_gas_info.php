<?php
require __DIR__ . '/includes/auth.php';
requireIndaneGasAccess(); // requireLogin() + a 403 for logged-in users without the dedicated "Indane Gas Info" permission (Admin > Agents) - promoted out of the generic Tracing 2.0 bucket 2026-08-18
require_once __DIR__ . '/config/db.php';

// Same quota-badge pattern as hp_gas.php/rc_print.php - Indane Gas Info now
// has its own count-based monthly quota (indane_gas_monthly_limit,
// search_type = 'indane_gas'), not the shared Tracing 2.0 credit bucket.
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$quota = null;
if (!$isAdmin) {
    $stmt = $pdo->prepare('SELECT indane_gas_monthly_limit FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $monthlyLimit = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM search_logs WHERE user_id = :id AND search_type = 'indane_gas' AND searched_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
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

  /* Result table - same shape as hp_gas.php's .hp-section/.hp-section-table
     (colored title bar + plain label:value rows) rather than tracing2.php's
     icon-grid cards, per explicit instruction ("colour and tables theme
     like other app"). One consumer per search here, same as HP Gas/RC
     Print, so a single stacked table reads better than a multi-column grid. */
  .ig-section{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;margin-bottom:14px;}
  .ig-section-title{padding:10px 16px;background:#2e9e3f;color:#fff;font-size:11.5px;font-weight:700;
    text-transform:uppercase;letter-spacing:.4px;}
  .ig-section-table{width:100%;border-collapse:collapse;}
  .ig-section-table tr:nth-child(odd){background:#fff;}
  .ig-section-table tr:nth-child(even){background:#f8f8fc;}
  .ig-section-table td{padding:8px 16px;font-size:12.5px;border-bottom:1px solid #eee;vertical-align:top;}
  .ig-section-table tr:last-child td{border-bottom:none;}
  .ig-field-label{width:38%;color:#777;font-weight:600;}
  .ig-field-value{color:#222;font-weight:500;word-break:break-word;}
  .ig-result-wrap{margin-top:16px;display:none;}
  .ig-result-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:12px;}
  .ig-result-count{font-size:12px;color:#777;font-weight:600;}
  .ig-no-results{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;
    padding:56px 16px;color:#999;margin-top:16px;}
  .ig-no-results i{font-size:38px;color:#ccc;width:74px;height:74px;display:flex;align-items:center;justify-content:center;
    border-radius:50%;border:1.5px solid #e5e5e5;}
  .ig-no-results span{font-size:14px;color:#888;}

  <?php if ($isAdmin): ?>
  /* Single/Bulk mode tabs - admin-only (see the PHP gate below). Each
     bulk number is its own ~20s blocking locateme.services search run
     sequentially client-side (this tool has no job-queue backend like
     lpg_search.py's), so bulk is capped low (BULK_NUMBER_LIMIT below) and
     kept off agents' quota-limited accounts entirely rather than letting
     them queue up a batch that instantly exhausts a 5/month limit. */
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

<?php if ($isAdmin): ?>
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

<?php if ($isAdmin): ?>
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

// Only these fields are shown, in this order, per explicit instruction -
// everything else locateme.services returns for this tool (eKYC details,
// bank linkage, geo-intelligence, etc.) is hidden. Matched case-
// insensitively against whatever label text the generic scraper picked up,
// not a fixed key, since tracing2_tools.py doesn't normalize label casing.
const IG_VISIBLE_FIELDS = [
  "registered mobile", "consumer id", "full name", "physical address", "agency name", "agency contact",
];

// The backend returns one "master" record holding every field for the
// consumer, plus several more records that just re-group a SUBSET of that
// same master's fields under section names (Consumer Details, Distributor
// Intelligence, ...) - not separate people (confirmed live 2026-08-19:
// searching one number returned 5 records, all for the same consumer).
// Rendering each of those separately would repeat the same 2-3 fields
// across multiple cards and leave the section-only records that happen to
// have none of the 6 whitelisted fields as empty boxes. Merging every
// record's fields into one map first (first-seen label wins) and building
// ONE card from that avoids both problems. Returns null if nothing in the
// whitelist was present at all, so the caller can treat that as not found.
function buildConsolidatedCard(records) {
  const fieldsByLabel = new Map();
  records.forEach(r => (r.fields || []).forEach(f => {
    const key = f.label.trim().toLowerCase();
    if (!fieldsByLabel.has(key)) fieldsByLabel.set(key, f);
  }));

  const box = document.createElement("div");
  box.className = "ig-section";

  const title = document.createElement("div");
  title.className = "ig-section-title";
  title.textContent = (records.find(r => r.name)?.name) || "Result";
  box.appendChild(title);

  const table = document.createElement("table");
  table.className = "ig-section-table";
  const tbody = document.createElement("tbody");
  let matched = 0;
  IG_VISIBLE_FIELDS.forEach(key => {
    const field = fieldsByLabel.get(key);
    if (!field) return;
    matched++;
    const tr = document.createElement("tr");
    tr.innerHTML = `<td class="ig-field-label"></td><td class="ig-field-value"></td>`;
    tr.querySelector(".ig-field-label").textContent = field.label;
    tr.querySelector(".ig-field-value").textContent = field.value || "—";
    tbody.appendChild(tr);
  });
  if (matched === 0) return null;
  table.appendChild(tbody);
  box.appendChild(table);
  return box;
}

let lastRecords = [];
const exportBtn = document.getElementById("igExportBtn");

function renderResult(data) {
  recordsWrap.innerHTML = "";
  resultWrap.style.display = "none";
  noResultsEl.style.display = "none";
  lastRecords = [];

  const records = (data.found && Array.isArray(data.records)) ? data.records : [];
  const card = records.length ? buildConsolidatedCard(records) : null;

  if (card) {
    lastRecords = [records[0]];
    resultCountText.textContent = "1 result found";
    recordsWrap.appendChild(card);
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

// Same IG_VISIBLE_FIELDS whitelist/order as buildConsolidatedCard(), so the
// export matches what's actually shown on screen instead of dumping every
// field locateme.services returned.
exportBtn.addEventListener("click", () => {
  if (!lastRecords.length) return;
  const hasSection = lastRecords.some(r => r.section);
  const columns = hasSection ? ["Number"] : [];
  const presentFields = IG_VISIBLE_FIELDS.filter(key =>
    lastRecords.some(r => (r.fields || []).some(f => f.label.trim().toLowerCase() === key))
  );
  const labelByKey = new Map();
  lastRecords.forEach(r => (r.fields || []).forEach(f => labelByKey.set(f.label.trim().toLowerCase(), f.label)));
  presentFields.forEach(key => columns.push(labelByKey.get(key)));

  const aoa = [columns, ...lastRecords.map(r => {
    const valueMap = {};
    if (hasSection) valueMap["Number"] = r.section || "";
    (r.fields || []).forEach(f => { valueMap[f.label] = f.value || ""; });
    return columns.map(c => valueMap[c] || "");
  })];
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
      statusEl.textContent = SERVER_DOWN_MESSAGE;
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

// --- Bulk Search - admin-only (see the PHP gate around #igBulkMode above;
// these elements simply don't exist in the DOM for a non-admin, so this
// entire block is a no-op for them). Each number is its own ~20s blocking
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

        const records = (res.ok && data.found && Array.isArray(data.records)) ? data.records : [];
        const card = records.length ? buildConsolidatedCard(records) : null;
        const heading = document.createElement("div");
        heading.className = "t2-group-heading";
        heading.textContent = number;
        recordsWrap.appendChild(heading);

        if (card) {
          // Heading already added above (the number itself). The export
          // copy carries section = number so the "Number" column works;
          // just the master record (index 0) is kept per number, same as
          // renderResult() - buildConsolidatedCard() already merged
          // whatever fields it needed out of the rest.
          recordsWrap.appendChild(card);
          lastRecords.push({ ...records[0], section: number });
          foundAny = true;
        } else {
          const empty = document.createElement("div");
          empty.className = "ig-result-count";
          empty.style.cssText = "color:#999;margin:-4px 0 14px;";
          empty.textContent = res.ok ? "Data not found" : "Server is down. Please try again later.";
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
    resultCountText.textContent = `${lastRecords.length} result${lastRecords.length === 1 ? "" : "s"} found across ${numbers.length} number${numbers.length === 1 ? "" : "s"}`;
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

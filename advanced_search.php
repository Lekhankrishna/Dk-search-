<?php
require __DIR__ . '/includes/auth.php';
requireAdvancedSearchAccess();
require_once __DIR__ . '/config/db.php';

// Same quota-badge pattern as rc_print.php/hp_gas.php/advance_pan_india.php.
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$quota = null;
if (!$isAdmin) {
    $stmt = $pdo->prepare('SELECT advanced_search_monthly_limit FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $monthlyLimit = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM search_logs WHERE user_id = :id AND search_type = 'advanced_search' AND searched_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
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
  <h1 class="page-title" style="margin:0"><i class="bi bi-search"></i> Advanced Search</h1>
  <?php if ($isAdmin): ?>
    <span id="asQuotaBadge" class="badge badge-neutral" style="margin-left:auto">Unlimited (Admin)</span>
  <?php elseif ($quota !== null): ?>
    <span id="asQuotaBadge" class="badge <?= $quota['used'] >= $quota['limit'] ? 'badge-danger' : 'badge-neutral' ?>"
          style="margin-left:auto">
      <?= $quota['limit'] - $quota['used'] > 0 ? $quota['limit'] - $quota['used'] : 0 ?> of <?= $quota['limit'] ?> left this month
    </span>
  <?php endif; ?>
</div>

<style>
  /* Same tokens/shape as rc_print.php/hp_gas.php/advance_pan_india.php's cards. */
  .as-card{background:var(--c-surface,#fff);border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;}
  .as-card-body{padding:20px 22px;}
  /* State selector - square-box buttons, same style as the mode tabs below.
     tracekart.in itself is 5 separate per-state pages/endpoints under the
     hood (see includes/tracekart_client.php), not one combined search, so
     this determines which of those a search actually hits rather than
     being cosmetic. */
  .as-state-row{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;}
  /* Square-box tabs with icons (both the state row above and the mode row
     below share this .as-tab class, so one change covers both) - active =
     solid indigo fill, inactive = light outline. Radius matches this
     page's own .as-btn/input fields (10px) rather than the fully pill-
     shaped 999px used before, per explicit instruction. */
  .as-tabs{display:flex;gap:10px;flex-wrap:wrap;padding-bottom:18px;margin-bottom:18px;border-bottom:1px solid #eee;}
  .as-tab[hidden]{display:none;}
  .as-tab{display:inline-flex;align-items:center;gap:8px;padding:11px 18px;border-radius:10px;
    border:1px solid #e2e2ea;background:#fff;font-size:12.5px;font-weight:600;color:#555;
    cursor:pointer;transition:all 150ms;white-space:nowrap;}
  .as-tab i{font-size:14px;}
  .as-tab:hover{border-color:#2e9e3f;color:#2e9e3f;}
  .as-tab.active{background:#2e9e3f;border-color:#2e9e3f;color:#fff;box-shadow:0 4px 14px rgba(46,158,63,.35);}
  .as-search-row{display:flex;align-items:stretch;gap:10px;flex-wrap:wrap;}
  .as-input-group{display:none;flex:1 1 320px;gap:10px;min-width:0;}
  .as-input-group.active{display:flex;}
  .as-input-group input{flex:1;min-width:0;padding:13px 16px;font-size:14px;color:#333;
    border:1px solid #e0e0e0;border-radius:10px;background:#fff;outline:none;transition:border-color 150ms;}
  .as-input-group input:focus{border-color:#2e9e3f;}
  .as-btn{padding:0 26px;border-radius:10px;border:none;background:#2e9e3f;color:#fff;
    font-size:12.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;cursor:pointer;
    transition:all 150ms;box-shadow:0 4px 18px rgba(46,158,63,.3);display:inline-flex;align-items:center;gap:8px;
    white-space:nowrap;flex-shrink:0;}
  .as-btn:hover:not(:disabled){background:#257e32;transform:translateY(-1px);box-shadow:0 6px 20px rgba(46,158,63,.45);}
  .as-btn:disabled{opacity:.65;cursor:wait;transform:none;}
  .as-row{display:flex;align-items:center;gap:12px;margin-top:14px;flex-wrap:wrap;}
  .as-btn-secondary{background:#fff;color:#333;border:1px solid #e0e0e0;box-shadow:none;padding:9px 18px;}
  .as-btn-secondary:hover:not(:disabled){background:#eeeef6;border-color:#2e9e3f;transform:none;box-shadow:none;}
  .as-btn-excel{background:#10b981;color:#fff;box-shadow:0 4px 18px rgba(16,185,129,.3);padding:9px 18px;}
  .as-btn-excel:hover:not(:disabled){background:#0d9668;transform:translateY(-1px);box-shadow:0 6px 20px rgba(16,185,129,.45);}
  #asStatus{font-size:12.5px;color:#555;white-space:pre-wrap;word-break:break-word;font-weight:500;}
  .as-progress-wrap{margin-top:12px;display:none;}
  .as-progress-track{height:8px;border-radius:6px;background:#eeeef6;overflow:hidden;border:1px solid #e0e0e0;}
  .as-progress-fill{height:100%;border-radius:6px;background:#2e9e3f;width:100%;
    background-image:repeating-linear-gradient(45deg,#2e9e3f 0 12px,#257e32 12px 24px);
    background-size:34px 100%;animation:as-progress-stripes 1s linear infinite;}
  @keyframes as-progress-stripes{from{background-position:0 0;}to{background-position:-34px 0;}}
  .as-progress-meta{display:flex;justify-content:space-between;margin-top:6px;font-size:11.5px;color:#999;}
  .as-result-wrap{margin-top:16px;display:none;background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow-x:auto;}
  .as-table-toolbar{padding:10px 16px;background:#2e9e3f;color:#fff;font-size:11.5px;font-weight:700;
    text-transform:uppercase;letter-spacing:.3px;}
  .as-table{width:100%;border-collapse:collapse;font-size:11.5px;}
  .as-table th{background:#eeeef6;color:#555;font-size:10px;font-weight:700;text-transform:uppercase;
    letter-spacing:.3px;padding:6px 8px;text-align:left;white-space:nowrap;border-bottom:1px solid #e0e0e0;}
  .as-table td{padding:6px 8px;border-bottom:1px solid #eee;vertical-align:top;color:#333;max-width:260px;word-break:break-word;}
  .as-table tr:nth-child(even) td{background:#f8f8fc;}
  .as-no-results{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;
    padding:56px 16px;color:#999;margin-top:16px;}
  .as-no-results i{font-size:38px;color:#ccc;width:74px;height:74px;display:flex;align-items:center;justify-content:center;
    border-radius:50%;border:1.5px solid #e5e5e5;}
  .as-no-results span{font-size:14px;color:#888;}
</style>

<div class="as-card">
  <div class="as-card-body">
    <div class="as-state-row" role="tablist">
      <button type="button" class="as-tab as-state-btn active" data-state="tn">Tamil Nadu</button>
      <button type="button" class="as-tab as-state-btn" data-state="ap">Andhra Pradesh</button>
      <button type="button" class="as-tab as-state-btn" data-state="ka">Karnataka</button>
      <button type="button" class="as-tab as-state-btn" data-state="mh">Maharashtra</button>
      <button type="button" class="as-tab as-state-btn" data-state="kl">Kerala</button>
    </div>
    <div class="as-tabs" role="tablist">
      <button type="button" class="as-tab active" data-mode="mobile" data-target="asMobileGroup"><i class="bi bi-telephone"></i> Mobile Number</button>
      <button type="button" class="as-tab" data-mode="father" data-target="asFatherGroup"><i class="bi bi-person"></i> Name &amp; Father Name</button>
      <button type="button" class="as-tab" data-mode="address" data-target="asAddressGroup"><i class="bi bi-geo-alt"></i> Name &amp; Address</button>
      <button type="button" class="as-tab" data-mode="fulladdress" data-target="asFullAddressGroup"><i class="bi bi-house-door"></i> Full Address</button>
      <button type="button" class="as-tab" data-mode="dob" data-target="asDobGroup"><i class="bi bi-calendar3"></i> Name &amp; D.O.B</button>
    </div>
    <div class="as-search-row">
      <div class="as-input-group active" id="asMobileGroup">
        <input type="text" id="asMobile" placeholder="Enter mobile number...">
      </div>
      <div class="as-input-group" id="asFatherGroup">
        <input type="text" id="asFatherName" placeholder="Enter name...">
        <input type="text" id="asFatherFname" placeholder="Enter father's name...">
      </div>
      <div class="as-input-group" id="asAddressGroup">
        <input type="text" id="asAddrName" placeholder="Enter name...">
        <input type="text" id="asAddrAddress" placeholder="Enter address...">
      </div>
      <div class="as-input-group" id="asFullAddressGroup">
        <input type="text" id="asFullAddress" placeholder="Enter full address...">
      </div>
      <div class="as-input-group" id="asDobGroup">
        <input type="text" id="asDobName" placeholder="Enter name...">
        <input type="text" id="asDob" placeholder="Date of Birth (dd/mm/yyyy)">
      </div>
    </div>
    <div class="as-row">
      <button id="asSearchBtn" class="as-btn" style="padding:9px 18px"><i class="bi bi-search"></i> SEARCH</button>
      <button id="asClearBtn" class="as-btn as-btn-secondary" type="button">Clear</button>
      <button id="asExportBtn" class="as-btn as-btn-excel" type="button" disabled>
        <i class="bi bi-file-earmark-excel"></i> Download Excel
      </button>
      <span id="asStatus"></span>
    </div>
    <div class="as-progress-wrap" id="asProgressWrap">
      <div class="as-progress-track"><div class="as-progress-fill" id="asProgressFill"></div></div>
      <div class="as-progress-meta">
        <span id="asProgressLabel">Searching…</span>
        <span id="asProgressElapsed"></span>
      </div>
    </div>
  </div>
</div>

<div class="as-result-wrap" id="asResultWrap">
  <div class="as-table-toolbar" id="asResultToolbar"></div>
  <table class="as-table" id="asResultTable">
    <thead><tr id="asResultHeadRow"></tr></thead>
    <tbody id="asResultBody"></tbody>
  </table>
</div>
<div class="as-no-results" id="asNoResults">
  <i class="bi bi-search"></i>
  <span>No records found</span>
</div>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
const searchBtn  = document.getElementById("asSearchBtn");
const clearBtn   = document.getElementById("asClearBtn");
const exportBtn  = document.getElementById("asExportBtn");
const statusEl   = document.getElementById("asStatus");
const resultWrap = document.getElementById("asResultWrap");
const resultToolbar = document.getElementById("asResultToolbar");
const resultHeadRow = document.getElementById("asResultHeadRow");
const resultBody = document.getElementById("asResultBody");
const noResultsEl = document.getElementById("asNoResults");
const quotaBadge = document.getElementById("asQuotaBadge");
let lastHeaders = [];
let lastRows = [];
const progressWrap    = document.getElementById("asProgressWrap");
const progressLabel   = document.getElementById("asProgressLabel");
const progressElapsed = document.getElementById("asProgressElapsed");

let progressTimer = null;
let searchStartedAt = null;
let activeMode = "mobile";
let activeState = "tn";
const dobTab = document.querySelector('.as-tabs .as-tab[data-mode="dob"]');

// Kerala's own page on tracekart.in has no Name & D.O.B search at all (see
// includes/tracekart_client.php's TRACEKART_STATES) - hide that tab rather
// than let it be picked and fail server-side, and bump off it automatically
// if it was already selected when Kerala gets picked.
function updateTabsForState() {
  const isKerala = activeState === "kl";
  if (dobTab) dobTab.hidden = isKerala;
  if (isKerala && activeMode === "dob") {
    const mobileTab = document.querySelector('.as-tabs .as-tab[data-mode="mobile"]');
    if (mobileTab) mobileTab.click();
  }
}

document.querySelectorAll(".as-state-btn").forEach(btn => btn.addEventListener("click", () => {
  document.querySelectorAll(".as-state-btn").forEach(b => b.classList.remove("active"));
  btn.classList.add("active");
  activeState = btn.dataset.state;
  statusEl.textContent = "";
  updateTabsForState();
}));
updateTabsForState();

function formatDuration(seconds) {
  seconds = Math.max(0, Math.round(seconds));
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

// One blocking fetch for the whole login(if needed)+search sequence in
// includes/tracekart_client.php - same indeterminate-bar-with-estimate
// approach as pan_india_pro.php/advance_pan_india.php.
const ESTIMATED_SECONDS = 6;

function startProgress() {
  searchStartedAt = Date.now();
  progressLabel.textContent = "Searching…";
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

document.querySelectorAll(".as-tabs .as-tab").forEach(tab => tab.addEventListener("click", () => {
  document.querySelectorAll(".as-tabs .as-tab").forEach(t => t.classList.remove("active"));
  document.querySelectorAll(".as-input-group").forEach(g => g.classList.remove("active"));
  tab.classList.add("active");
  document.getElementById(tab.dataset.target).classList.add("active");
  activeMode = tab.dataset.mode;
  statusEl.textContent = "";
}));

function updateQuotaBadge(used, limit) {
  if (!quotaBadge) return;
  const remaining = Math.max(0, limit - used);
  quotaBadge.textContent = `${remaining} of ${limit} left this month`;
  quotaBadge.classList.toggle("badge-danger", used >= limit);
  quotaBadge.classList.toggle("badge-neutral", used < limit);
}

function collectFields() {
  switch (activeMode) {
    case "mobile": return { mobile: document.getElementById("asMobile").value.trim() };
    case "father": return {
      name: document.getElementById("asFatherName").value.trim(),
      fathername: document.getElementById("asFatherFname").value.trim(),
    };
    case "dob": return {
      name: document.getElementById("asDobName").value.trim(),
      dob: document.getElementById("asDob").value.trim(),
    };
    case "address": return {
      name: document.getElementById("asAddrName").value.trim(),
      address: document.getElementById("asAddrAddress").value.trim(),
    };
    case "fulladdress": return { address: document.getElementById("asFullAddress").value.trim() };
    default: return {};
  }
}

// Results come from tracekart.in's own result table, read generically
// (whatever headers/columns it renders) - see includes/tracekart_client.php -
// rather than a fixed column list, since that table's real shape was never
// observed live (every test query came back "No records found").
function renderResult(data) {
  resultHeadRow.innerHTML = "";
  resultBody.innerHTML = "";
  lastHeaders = data.headers || [];
  lastRows = (data.totalResults && data.rows) ? data.rows : [];
  exportBtn.disabled = !lastRows.length;

  if (!lastRows.length) {
    resultWrap.style.display = "none";
    noResultsEl.style.display = "block";
  } else {
    noResultsEl.style.display = "none";
    resultToolbar.textContent = `${lastRows.length} result${lastRows.length === 1 ? "" : "s"}`;
    lastHeaders.forEach(h => {
      const th = document.createElement("th");
      th.textContent = h;
      resultHeadRow.appendChild(th);
    });
    lastRows.forEach(row => {
      const tr = document.createElement("tr");
      lastHeaders.forEach(h => {
        const td = document.createElement("td");
        td.textContent = row[h] || "—";
        tr.appendChild(td);
      });
      resultBody.appendChild(tr);
    });
    resultWrap.style.display = "block";
  }

  if (lastRows.length) startConfetti(); else stopConfetti();
  if (typeof data.used === "number" && typeof data.limit === "number") {
    updateQuotaBadge(data.used, data.limit);
  }
}

async function runSearch() {
  const fields = collectFields();
  if (!Object.values(fields).some(v => v)) {
    statusEl.textContent = "Enter at least one search field.";
    return;
  }

  searchBtn.disabled = true;
  statusEl.textContent = "";
  resultWrap.style.display = "none";
  noResultsEl.style.display = "none";
  startProgress();

  try {
    const res = await fetch("advanced_search_api.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ state: activeState, mode: activeMode, fields })
    });
    const data = await res.json();

    if (!res.ok) {
      stopProgress(null);
      statusEl.textContent = `Error: ${data.error || "could not complete search"}`;
      if (typeof data.used === "number" && typeof data.limit === "number") {
        updateQuotaBadge(data.used, data.limit);
      }
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
  document.querySelectorAll(".as-input-group input").forEach(input => input.value = "");
  statusEl.textContent = "";
  resultWrap.style.display = "none";
  noResultsEl.style.display = "none";
  stopProgress(null);
  lastHeaders = [];
  lastRows = [];
  exportBtn.disabled = true;
  stopConfetti();
});

/* Download the current result set as a single .xlsx workbook. */
exportBtn.addEventListener("click", () => {
  if (!lastRows.length) { alert("No records to export."); return; }
  const aoa = [lastHeaders, ...lastRows.map(row => lastHeaders.map(h => row[h] || ""))];
  const ws = XLSX.utils.aoa_to_sheet(aoa);
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, "Results");
  const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, "-");
  XLSX.writeFile(wb, `advanced-search-export-${stamp}.xlsx`);
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

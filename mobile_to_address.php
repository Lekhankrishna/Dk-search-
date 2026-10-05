<?php
// "Mobile to Delivery Address" (2026-10-04) - known addresses for a mobile number
// through the Nexora API v3 (crm-app-v3's tool of the same name), via
// mobile_to_address_search.php. Same look as all_gas.php's cards.
require __DIR__ . '/includes/auth.php';
requireMobileToAddressAccess();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/nexora_client.php';

$quota = nexoraUsage($pdo, 'mobile_to_address');   // null = admin (unlimited)

$basePath = '';
require __DIR__ . '/includes/header.php';
?>

<div class="confetti-container" id="confetti-container"></div>

<div class="page-header" style="display:flex;align-items:center;flex-wrap:wrap;gap:12px">
  <h1 class="page-title" style="margin:0"><i class="bi bi-geo-alt-fill"></i> Mobile to Delivery Address</h1>
  <?php if ($quota === null): ?>
    <span id="quotaBadge" class="badge badge-neutral" style="margin-left:auto">Unlimited (Admin)</span>
  <?php else: ?>
    <span id="quotaBadge" class="badge <?= $quota['used'] >= $quota['limit'] ? 'badge-danger' : 'badge-neutral' ?>" style="margin-left:auto"><?= max(0, $quota['limit'] - $quota['used']) ?> of <?= (int) $quota['limit'] ?> searches left this month</span>
  <?php endif; ?>
</div>

<style>
  /* Same tokens/shape as all_gas.php. */
  .ig-card{background:var(--c-surface,#fff);border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;}
  .ig-card-body{padding:20px 22px;}
  .ig-search-row{display:flex;align-items:stretch;gap:10px;flex-wrap:wrap;}
  .ig-search-row input{flex:1 1 320px;min-width:0;padding:13px 16px;font-size:14px;color:#333;
    border:1px solid #e0e0e0;border-radius:10px;background:#fff;outline:none;transition:border-color 150ms;}
  .ig-search-row input:focus{border-color:#2e9e3f;}
  .ig-btn{padding:9px 18px;border-radius:10px;border:none;background:#2e9e3f;color:#fff;
    font-size:12.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;cursor:pointer;
    transition:all 150ms;box-shadow:0 4px 18px rgba(46,158,63,.3);display:inline-flex;align-items:center;gap:8px;
    white-space:nowrap;flex-shrink:0;}
  .ig-btn:hover:not(:disabled){background:#257e32;transform:translateY(-1px);box-shadow:0 6px 20px rgba(46,158,63,.45);}
  .ig-btn:disabled{opacity:.65;cursor:wait;transform:none;}
  .ig-row{display:flex;align-items:center;gap:12px;margin-top:14px;flex-wrap:wrap;}
  .ig-btn-secondary{background:#fff;color:#333;border:1px solid #e0e0e0;box-shadow:none;}
  .ig-btn-secondary:hover:not(:disabled){background:#eeeef6;border-color:#2e9e3f;transform:none;box-shadow:none;}
  .ig-btn-excel{background:#10b981;color:#fff;box-shadow:0 4px 18px rgba(16,185,129,.3);}
  .ig-btn-excel:hover:not(:disabled){background:#0d9668;transform:translateY(-1px);box-shadow:0 6px 20px rgba(16,185,129,.45);}
  #igStatus{font-size:12.5px;color:#555;white-space:pre-wrap;word-break:break-word;font-weight:500;}
  .ig-progress-wrap{margin-top:12px;display:none;}
  .ig-progress-track{height:8px;border-radius:6px;background:#eeeef6;overflow:hidden;border:1px solid #e0e0e0;}
  .ig-progress-fill{height:100%;border-radius:6px;width:100%;
    background-image:repeating-linear-gradient(45deg,#2e9e3f 0 12px,#257e32 12px 24px);
    background-size:34px 100%;animation:ig-progress-stripes 1s linear infinite;}
  @keyframes ig-progress-stripes{from{background-position:0 0;}to{background-position:-34px 0;}}
  .ig-progress-meta{display:flex;justify-content:space-between;margin-top:6px;font-size:11.5px;color:#999;}
  .ig-sections{margin-top:16px;}
  .ig-section{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;margin-bottom:14px;}
  .ig-section-title{padding:10px 16px;background:#2e9e3f;color:#fff;font-size:11.5px;font-weight:700;
    text-transform:uppercase;letter-spacing:.4px;}
  .ig-section-table{width:100%;border-collapse:collapse;}
  .ig-section-table tr:nth-child(even){background:#f8f8fc;}
  .ig-section-table td{padding:8px 16px;font-size:12.5px;border-bottom:1px solid #eee;vertical-align:top;}
  .ig-section-table tr:last-child td{border-bottom:none;}
  .ig-field-label{width:38%;color:#777;font-weight:600;text-transform:uppercase;}
  .ig-field-value{color:#222;font-weight:500;word-break:break-word;}
  .ig-no-results{display:none;flex-direction:column;align-items:center;justify-content:center;gap:14px;
    padding:56px 16px;color:#999;margin-top:16px;text-align:center;}
  .ig-no-results i{font-size:38px;color:#ccc;width:74px;height:74px;display:flex;align-items:center;justify-content:center;
    border-radius:50%;border:1.5px solid #e5e5e5;}
  .ig-no-results span{font-size:14px;color:#888;}
</style>

<div class="ig-card">
  <div class="ig-card-body">
    <div class="ig-search-row">
      <input type="text" id="igMobile" placeholder="Enter 10-digit mobile number..." maxlength="10" inputmode="numeric" autocomplete="off">
    </div>
    <div class="ig-row">
      <button id="igSearchBtn" class="ig-btn"><i class="bi bi-search"></i> SEARCH</button>
      <button id="igClearBtn" class="ig-btn ig-btn-secondary" type="button">Clear</button>
      <button id="igExportBtn" class="ig-btn ig-btn-excel" type="button" disabled>
        <i class="bi bi-file-earmark-excel"></i> Download Excel
      </button>
      <span id="igStatus"></span>
    </div>
    <div class="ig-progress-wrap" id="igProgressWrap">
      <div class="ig-progress-track"><div class="ig-progress-fill"></div></div>
      <div class="ig-progress-meta">
        <span id="igProgressLabel">Searching…</span>
        <span id="igProgressElapsed"></span>
      </div>
    </div>
  </div>
</div>

<div class="ig-sections" id="igSections"></div>
<div class="ig-no-results" id="igNoResults">
  <i class="bi bi-search"></i>
  <span id="igNoResultsText">No records found</span>
</div>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
const searchBtn = document.getElementById("igSearchBtn");
const clearBtn = document.getElementById("igClearBtn");
const exportBtn = document.getElementById("igExportBtn");
const mobileEl = document.getElementById("igMobile");
const statusEl = document.getElementById("igStatus");
const sectionsEl = document.getElementById("igSections");
const noResultsEl = document.getElementById("igNoResults");
const noResultsText = document.getElementById("igNoResultsText");
const progressWrap = document.getElementById("igProgressWrap");
const progressLabel = document.getElementById("igProgressLabel");
const progressElapsed = document.getElementById("igProgressElapsed");

// "X of N searches left this month" badge (2026-10-05) - same wording as
// indane_gas_info.php's; refreshed from each answer's usage / a 429's used+limit.
const quotaBadge = document.getElementById("quotaBadge");
function updateQuotaBadge(used, limit) {
  if (!quotaBadge || typeof used !== "number" || typeof limit !== "number") return;
  quotaBadge.textContent = `${Math.max(0, limit - used)} of ${limit} searches left this month`;
  quotaBadge.classList.toggle("badge-danger", used >= limit);
  quotaBadge.classList.toggle("badge-neutral", used < limit);
}

let lastRows = [];   // [Section, Field, Value] for the Excel export
let progressTimer = null;
let searchStartedAt = null;
// Live calls took 7-30s (2026-10-04).
const ESTIMATED_SECONDS = 25;

mobileEl.addEventListener("input", () => {
  const digits = mobileEl.value.replace(/\D/g, "").slice(0, 10);
  if (digits !== mobileEl.value) mobileEl.value = digits;
});
mobileEl.addEventListener("keydown", e => { if (e.key === "Enter") runSearch(); });

function formatDuration(seconds) {
  seconds = Math.max(0, Math.round(seconds));
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

function startProgress() {
  searchStartedAt = Date.now();
  progressLabel.textContent = "Searching…";
  progressElapsed.textContent = `~${formatDuration(ESTIMATED_SECONDS)} estimated`;
  progressWrap.style.display = "block";
  clearInterval(progressTimer);
  progressTimer = setInterval(() => {
    const remaining = ESTIMATED_SECONDS - (Date.now() - searchStartedAt) / 1000;
    progressElapsed.textContent = remaining > 0 ? `~${formatDuration(remaining)} remaining` : "Finishing up…";
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

function clearResult() {
  sectionsEl.innerHTML = "";
  noResultsEl.style.display = "none";
  lastRows = [];
  exportBtn.disabled = true;
}

// One card per section (Consumer Details / Distributor Details), same look
// as all_gas.php.
function renderResult(data) {
  clearResult();
  const sections = (data.found && data.sections) ? data.sections : {};
  Object.keys(sections).forEach(title => {
    const box = document.createElement("div");
    box.className = "ig-section";
    const head = document.createElement("div");
    head.className = "ig-section-title";
    head.textContent = title;
    box.appendChild(head);
    const table = document.createElement("table");
    table.className = "ig-section-table";
    const tbody = document.createElement("tbody");
    Object.entries(sections[title]).forEach(([field, value]) => {
      const tr = document.createElement("tr");
      const label = document.createElement("td");
      label.className = "ig-field-label";
      label.textContent = field;
      const val = document.createElement("td");
      val.className = "ig-field-value";
      val.textContent = value || "—";
      tr.appendChild(label);
      tr.appendChild(val);
      tbody.appendChild(tr);
      lastRows.push([title, field, value || ""]);
    });
    table.appendChild(tbody);
    box.appendChild(table);
    sectionsEl.appendChild(box);
  });
  exportBtn.disabled = !lastRows.length;
  if (!lastRows.length) {
    noResultsText.textContent = data.message || "No records found";
    noResultsEl.style.display = "flex";
  }
  if (typeof startConfetti === "function") {
    if (lastRows.length) startConfetti(); else stopConfetti();
  }
}

async function runSearch() {
  const mobile = mobileEl.value.trim();
  if (!/^\d{10}$/.test(mobile)) {
    statusEl.textContent = "Enter a valid 10-digit mobile number.";
    return;
  }
  searchBtn.disabled = true;
  statusEl.textContent = "";
  clearResult();
  startProgress();
  try {
    const res = await fetch("mobile_to_address_search.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ mobile })
    });
    const data = await res.json();
    if (data.usage) updateQuotaBadge(data.usage.used, data.usage.limit);
    if (typeof data.used === "number") updateQuotaBadge(data.used, data.limit);
    if (!res.ok) {
      stopProgress(null);
      statusEl.textContent = `Error: ${data.error || "could not complete search"}`;
      return;
    }
    renderResult(data);
    stopProgress(lastRows.length ? "Result found" : "No result found");
  } catch (err) {
    stopProgress(null);
    statusEl.textContent = `Could not reach the server: ${err.message}`;
  } finally {
    searchBtn.disabled = false;
  }
}

searchBtn.addEventListener("click", runSearch);
clearBtn.addEventListener("click", () => {
  mobileEl.value = "";
  statusEl.textContent = "";
  stopProgress(null);
  clearResult();
  if (typeof stopConfetti === "function") stopConfetti();
});

exportBtn.addEventListener("click", () => {
  if (!lastRows.length) { alert("No records to export."); return; }
  const ws = XLSX.utils.aoa_to_sheet([["Section", "Field", "Value"], ...lastRows]);
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, "Results");
  const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, "-");
  XLSX.writeFile(wb, `mobile-to-address-${mobileEl.value || "export"}-${stamp}.xlsx`);
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

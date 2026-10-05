<?php
// "All Gas Advanced" (2026-10-04) - one page with a tab per Indane lookup,
// placed below All Gas in the sidebar per explicit instruction:
//   Indian Gas      - the locateme.services Indane Gas lookup
//                     (tracing2_api.php, tool "indane-gas-info" - same backend,
//                     access flag and monthly limit as indane_gas_info.php)
//   Indian Gas Advanced  - the Nexora API lookup (indian_gas_api_search.php, same
//                     as indian_gas_api.php)
// A tab only shows for an account that has that tool's own access.
require __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/nexora_client.php';

$tabs = [];
if (hasIndaneGasAccess())    $tabs['indian_gas'] = 'Indian Gas';
if (hasIndianGasApiAccess()) $tabs['indian_gas_api'] = 'Indian Gas Advanced';
// HP Gas and HP Gas Advanced tabs (per explicit instruction, 2026-10-04):
//   HP Gas          - the locateme.services HP lookup (hp_gas_api.php - same
//                     backend, access flag and monthly limit as hp_gas.php)
//   HP Gas Advanced - the Nexora API's HP lookup (hp_gas_api_search.php, same
//                     as hp_gas_advanced.php)
if (hasHpGasAccess())        $tabs['hp_gas'] = 'HP Gas';
if (hasHpGasApiAccess())     $tabs['hp_gas_api'] = 'HP Gas Advanced';
// Bharat Gas Advanced - the Nexora API's Bharat lookup (bharat_gas_api_search.php;
// the endpoint crm-app-v3's "Bharat Gas Advance" uses), 2026-10-04.
if (hasBharatGasApiAccess())  $tabs['bharat_gas_api'] = 'Bharat Gas Advanced';
if (!$tabs) {
    http_response_code(403);
    die('Access denied: All Gas Advanced has not been granted for this account.');
}

// This month's usage per tab for the "X of N searches left" badge (2026-10-05);
// null = admin. Indian Gas / HP Gas count against the agent's Indane Gas /
// HP Gas limits (search_types indane_gas / hp_gas), the others their own.
$tabUsage = [];
foreach ($tabs as $key => $label) {
    $tabUsage[$key] = [
        'indian_gas'     => fn() => nexoraUsage($pdo, 'indane_gas', 'indane_gas_monthly_limit'),
        'indian_gas_api' => fn() => nexoraUsage($pdo, 'indian_gas_api'),
        'hp_gas'         => fn() => nexoraUsage($pdo, 'hp_gas', 'hp_gas_monthly_limit'),
        'hp_gas_api'     => fn() => nexoraUsage($pdo, 'hp_gas_api'),
        'bharat_gas_api' => fn() => nexoraUsage($pdo, 'bharat_gas_api'),
    ][$key]();
}

$basePath = '';
require __DIR__ . '/includes/header.php';
?>

<div class="confetti-container" id="confetti-container"></div>

<div class="page-header" style="display:flex;align-items:center;flex-wrap:wrap;gap:12px">
  <h1 class="page-title" style="margin:0"><i class="bi bi-fire"></i> All Gas Advanced</h1>
  <span id="quotaBadge" class="badge badge-neutral" style="margin-left:auto"></span>
</div>

<style>
  /* Same tokens/shape as all_gas.php. */
  .ga-card{background:var(--c-surface,#fff);border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;}
  .ga-card-body{padding:20px 22px;}
  .ga-tabs{display:flex;gap:10px;flex-wrap:wrap;padding-bottom:18px;margin-bottom:18px;border-bottom:1px solid #eee;}
  .ga-tab{display:inline-flex;align-items:center;gap:8px;padding:11px 18px;border-radius:10px;
    border:1px solid #e2e2ea;background:#fff;font-size:12.5px;font-weight:600;color:#555;
    cursor:pointer;transition:all 150ms;white-space:nowrap;}
  .ga-tab i{font-size:14px;}
  .ga-tab:hover{border-color:#2e9e3f;color:#2e9e3f;}
  .ga-tab.active{background:#2e9e3f;border-color:#2e9e3f;color:#fff;box-shadow:0 4px 14px rgba(46,158,63,.35);}
  .ga-search-row{display:flex;align-items:stretch;gap:10px;flex-wrap:wrap;}
  .ga-search-row input{flex:1 1 320px;min-width:0;padding:13px 16px;font-size:14px;color:#333;
    border:1px solid #e0e0e0;border-radius:10px;background:#fff;outline:none;transition:border-color 150ms;}
  .ga-search-row input:focus{border-color:#2e9e3f;}
  .ga-btn{padding:9px 18px;border-radius:10px;border:none;background:#2e9e3f;color:#fff;
    font-size:12.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;cursor:pointer;
    transition:all 150ms;box-shadow:0 4px 18px rgba(46,158,63,.3);display:inline-flex;align-items:center;gap:8px;
    white-space:nowrap;flex-shrink:0;}
  .ga-btn:hover:not(:disabled){background:#257e32;transform:translateY(-1px);box-shadow:0 6px 20px rgba(46,158,63,.45);}
  .ga-btn:disabled{opacity:.65;cursor:wait;transform:none;}
  .ga-row{display:flex;align-items:center;gap:12px;margin-top:14px;flex-wrap:wrap;}
  .ga-btn-secondary{background:#fff;color:#333;border:1px solid #e0e0e0;box-shadow:none;}
  .ga-btn-secondary:hover:not(:disabled){background:#eeeef6;border-color:#2e9e3f;transform:none;box-shadow:none;}
  .ga-btn-excel{background:#10b981;color:#fff;box-shadow:0 4px 18px rgba(16,185,129,.3);}
  .ga-btn-excel:hover:not(:disabled){background:#0d9668;transform:translateY(-1px);box-shadow:0 6px 20px rgba(16,185,129,.45);}
  #gaStatus{font-size:12.5px;color:#555;white-space:pre-wrap;word-break:break-word;font-weight:500;}
  .ga-progress-wrap{margin-top:12px;display:none;}
  .ga-progress-track{height:8px;border-radius:6px;background:#eeeef6;overflow:hidden;border:1px solid #e0e0e0;}
  .ga-progress-fill{height:100%;border-radius:6px;width:100%;
    background-image:repeating-linear-gradient(45deg,#2e9e3f 0 12px,#257e32 12px 24px);
    background-size:34px 100%;animation:ga-progress-stripes 1s linear infinite;}
  @keyframes ga-progress-stripes{from{background-position:0 0;}to{background-position:-34px 0;}}
  .ga-progress-meta{display:flex;justify-content:space-between;margin-top:6px;font-size:11.5px;color:#999;}
  .ga-sections{margin-top:16px;}
  .ga-section{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;margin-bottom:14px;}
  .ga-section-title{padding:10px 16px;background:#2e9e3f;color:#fff;font-size:11.5px;font-weight:700;
    text-transform:uppercase;letter-spacing:.4px;}
  .ga-section-table{width:100%;border-collapse:collapse;}
  .ga-section-table tr:nth-child(even){background:#f8f8fc;}
  .ga-section-table td{padding:8px 16px;font-size:12.5px;border-bottom:1px solid #eee;vertical-align:top;}
  .ga-section-table tr:last-child td{border-bottom:none;}
  .ga-field-label{width:38%;color:#777;font-weight:600;text-transform:uppercase;}
  .ga-field-value{color:#222;font-weight:500;word-break:break-word;}
  .ga-no-results{display:none;flex-direction:column;align-items:center;justify-content:center;gap:14px;
    padding:56px 16px;color:#999;margin-top:16px;text-align:center;}
  .ga-no-results i{font-size:38px;color:#ccc;width:74px;height:74px;display:flex;align-items:center;justify-content:center;
    border-radius:50%;border:1.5px solid #e5e5e5;}
  .ga-no-results span{font-size:14px;color:#888;}
</style>

<div class="ga-card">
  <div class="ga-card-body">
    <div class="ga-tabs" role="tablist">
      <?php $first = true; foreach ($tabs as $key => $label): ?>
        <button type="button" class="ga-tab<?= $first ? ' active' : '' ?>" data-tool="<?= $key ?>"><i class="bi bi-fire"></i> <?= htmlspecialchars($label) ?></button>
      <?php $first = false; endforeach; ?>
    </div>
    <div class="ga-search-row">
      <input type="text" id="gaMobile" placeholder="Enter 10-digit mobile number..." maxlength="10" inputmode="numeric" autocomplete="off">
    </div>
    <div class="ga-row">
      <button id="gaSearchBtn" class="ga-btn"><i class="bi bi-search"></i> SEARCH</button>
      <button id="gaClearBtn" class="ga-btn ga-btn-secondary" type="button">Clear</button>
      <button id="gaExportBtn" class="ga-btn ga-btn-excel" type="button" disabled>
        <i class="bi bi-file-earmark-excel"></i> Download Excel
      </button>
      <span id="gaStatus"></span>
    </div>
    <div class="ga-progress-wrap" id="gaProgressWrap">
      <div class="ga-progress-track"><div class="ga-progress-fill"></div></div>
      <div class="ga-progress-meta">
        <span id="gaProgressLabel">Searching…</span>
        <span id="gaProgressElapsed"></span>
      </div>
    </div>
  </div>
</div>

<div class="ga-sections" id="gaSections"></div>
<div class="ga-no-results" id="gaNoResults">
  <i class="bi bi-search"></i>
  <span id="gaNoResultsText">No records found</span>
</div>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
const searchBtn = document.getElementById("gaSearchBtn");
const clearBtn = document.getElementById("gaClearBtn");
const exportBtn = document.getElementById("gaExportBtn");
const mobileEl = document.getElementById("gaMobile");
const statusEl = document.getElementById("gaStatus");
const sectionsEl = document.getElementById("gaSections");
const noResultsEl = document.getElementById("gaNoResults");
const noResultsText = document.getElementById("gaNoResultsText");
const progressWrap = document.getElementById("gaProgressWrap");
const progressLabel = document.getElementById("gaProgressLabel");
const progressElapsed = document.getElementById("gaProgressElapsed");
const SERVER_DOWN_MESSAGE = "Server Down. Please try again later.";

// Each tab: its endpoint, request body, rough duration, and how its answer
// becomes { found, message, sections: { title: { label: value } } }.
const TOOLS = {
  indian_gas: {
    url: "tracing2_api.php",
    body: mobile => ({ tool: "indane-gas-info", query: mobile }),
    seconds: 30,
    // Same cards as indane_gas_info.php: only the section records, without
    // "Product & Asset Specs" / "Eligibility & Compliance".
    normalize: data => {
      const records = (data.found && Array.isArray(data.records)) ? data.records : [];
      const isSection = r => /details|intelligence|specs|compliance|profile/i.test(r.name || "");
      const isHidden = r => /specs|compliance/i.test(r.name || "");
      const picked = (records.some(isSection) ? records.filter(isSection) : records).filter(r => !isHidden(r));
      const sections = {};
      picked.forEach(rec => {
        const fields = (rec.fields || []).filter(f => String(f.value || "").trim());
        if (!fields.length) return;
        const title = rec.name || "Consumer Details";
        sections[title] = sections[title] || {};
        fields.forEach(f => { sections[title][f.label] = f.value; });
      });
      return { found: Object.keys(sections).length > 0, message: data.message || "Data not found", sections };
    },
  },
  indian_gas_api: {
    url: "indian_gas_api_search.php",
    body: mobile => ({ mobile }),
    seconds: 10,
    normalize: data => ({ found: !!data.found, message: data.message || "No records found", sections: data.sections || {} }),
  },
  hp_gas: {
    url: "hp_gas_api.php",
    body: mobile => ({ mobileNumber: mobile }),
    seconds: 40,
    // Same sections as hp_gas.php, without "Bank & LPG Linkage" and
    // "Geo-Intelligence" (hidden there too).
    normalize: data => {
      const sections = {};
      if (data.found && Array.isArray(data.sections)) {
        data.sections.filter(s => !/bank|geo/i.test(s.title || "")).forEach(s => {
          const fields = (s.fields || []).filter(f => String(f.value || "").trim());
          if (!fields.length) return;
          const title = s.title || "Result";
          sections[title] = sections[title] || {};
          fields.forEach(f => { sections[title][f.label] = f.value; });
        });
      }
      return { found: Object.keys(sections).length > 0, message: "No result found", sections };
    },
  },
  hp_gas_api: {
    url: "hp_gas_api_search.php",
    body: mobile => ({ mobile }),
    seconds: 5,
    normalize: data => ({ found: !!data.found, message: data.message || "No records found", sections: data.sections || {} }),
  },
  bharat_gas_api: {
    url: "bharat_gas_api_search.php",
    body: mobile => ({ mobile }),
    seconds: 5,
    normalize: data => ({ found: !!data.found, message: data.message || "No records found", sections: data.sections || {} }),
  },
};

let activeTool = document.querySelector(".ga-tab.active").dataset.tool;

// "X of N searches left this month" for the selected tab (2026-10-05).
const TAB_USAGE = <?= json_encode($tabUsage) ?>;
const quotaBadge = document.getElementById("quotaBadge");
function showQuota() {
  const u = TAB_USAGE[activeTool];
  if (!u) { quotaBadge.textContent = "Unlimited (Admin)"; quotaBadge.className = "badge badge-neutral"; return; }
  quotaBadge.textContent = `${Math.max(0, u.limit - u.used)} of ${u.limit} searches left this month`;
  quotaBadge.className = "badge " + (u.used >= u.limit ? "badge-danger" : "badge-neutral");
}
function noteUsage(data) {
  if (!TAB_USAGE[activeTool]) return;
  if (data && data.usage) TAB_USAGE[activeTool] = data.usage;
  else if (data && typeof data.used === "number" && typeof data.limit === "number") TAB_USAGE[activeTool] = { used: data.used, limit: data.limit };
  showQuota();
}
showQuota();
let lastRows = [];   // [Section, Field, Value] for the Excel export
let progressTimer = null;
let searchStartedAt = null;

document.querySelectorAll(".ga-tab").forEach(tab => tab.addEventListener("click", () => {
  document.querySelectorAll(".ga-tab").forEach(t => t.classList.remove("active"));
  tab.classList.add("active");
  activeTool = tab.dataset.tool;
  showQuota();
  statusEl.textContent = "";
  stopProgress(null);
  clearResult();
}));

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

function startProgress(estimate) {
  searchStartedAt = Date.now();
  progressLabel.textContent = "Searching…";
  progressElapsed.textContent = `~${formatDuration(estimate)} estimated`;
  progressWrap.style.display = "block";
  clearInterval(progressTimer);
  progressTimer = setInterval(() => {
    const remaining = estimate - (Date.now() - searchStartedAt) / 1000;
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

function renderResult(result) {
  clearResult();
  Object.keys(result.sections).forEach(title => {
    const box = document.createElement("div");
    box.className = "ga-section";
    if (title !== "") {   // an empty title = a plain card with no title bar
      const head = document.createElement("div");
      head.className = "ga-section-title";
      head.textContent = title;
      box.appendChild(head);
    }
    const table = document.createElement("table");
    table.className = "ga-section-table";
    const tbody = document.createElement("tbody");
    Object.entries(result.sections[title]).forEach(([field, value]) => {
      const tr = document.createElement("tr");
      const label = document.createElement("td");
      label.className = "ga-field-label";
      label.textContent = field;
      const val = document.createElement("td");
      val.className = "ga-field-value";
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
    noResultsText.textContent = result.message;
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
  const tool = TOOLS[activeTool];
  searchBtn.disabled = true;
  statusEl.textContent = "";
  clearResult();
  startProgress(tool.seconds);
  try {
    const res = await fetch(tool.url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(tool.body(mobile))
    });
    const data = await res.json();
    noteUsage(data);
    if (res.status === 401) { window.location.href = data.loginUrl || "login.php"; return; }
    if (!res.ok) {
      stopProgress(null);
      // The real reason when there is one (e.g. the monthly-limit message).
      statusEl.textContent = data.error || SERVER_DOWN_MESSAGE;
      return;
    }
    const result = tool.normalize(data);
    renderResult(result);
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
  XLSX.writeFile(wb, `${activeTool.replace(/_/g, "-")}-${mobileEl.value || "export"}-${stamp}.xlsx`);
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

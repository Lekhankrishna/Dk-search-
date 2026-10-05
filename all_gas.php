<?php
require __DIR__ . '/includes/auth.php';
requireAllGasAccess();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/tracekart_client.php';

// This month's usage per provider for the header badge (agents only -
// admins are never limited).
$allGasUsage = null;
if (($_SESSION['role'] ?? '') !== 'admin') {
    $allGasUsage = [];
    foreach (array_keys(ALL_GAS_LIMIT_COLUMNS) as $prov) {
        $allGasUsage[$prov] = allGasUsage($pdo, (int) $_SESSION['user_id'], $prov);
    }
}

$basePath = '';
require __DIR__ . '/includes/header.php';
?>

<!-- Confetti overlay - populated/cleared by startConfetti()/stopConfetti()
     (assets/confetti.js), only while a search has actually succeeded. -->
<div class="confetti-container" id="confetti-container"></div>

<div class="page-header" style="display:flex;align-items:center;flex-wrap:wrap;gap:12px">
  <h1 class="page-title" style="margin:0"><i class="bi bi-fire"></i> All Gas</h1>
  <span class="badge badge-neutral" style="margin-left:auto" id="agQuota" title="Found searches used this month / monthly limit">
    <?php if ($allGasUsage === null): ?>Unlimited<?php else: ?>
      <?php foreach ($allGasUsage as $prov => $u): ?>
        <span data-prov="<?= $prov ?>"><?= htmlspecialchars(TRACEKART_GAS_PROVIDERS[$prov]) ?> <b><?= $u['used'] ?>/<?= $u['limit'] ?></b></span><?= $prov !== 'hp' ? ' &middot; ' : '' ?>
      <?php endforeach; ?>
    <?php endif; ?>
  </span>
</div>

<style>
  /* Same tokens/shape as advanced_search.php's cards and tabs. */
  .ag-card{background:var(--c-surface,#fff);border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;}
  .ag-card-body{padding:20px 22px;}
  .ag-tabs{display:flex;gap:10px;flex-wrap:wrap;padding-bottom:18px;margin-bottom:18px;border-bottom:1px solid #eee;}
  .ag-tab{display:inline-flex;align-items:center;gap:8px;padding:11px 18px;border-radius:10px;
    border:1px solid #e2e2ea;background:#fff;font-size:12.5px;font-weight:600;color:#555;
    cursor:pointer;transition:all 150ms;white-space:nowrap;}
  .ag-tab i{font-size:14px;}
  .ag-tab:hover{border-color:#2e9e3f;color:#2e9e3f;}
  .ag-tab.active{background:#2e9e3f;border-color:#2e9e3f;color:#fff;box-shadow:0 4px 14px rgba(46,158,63,.35);}
  .ag-search-row{display:flex;align-items:stretch;gap:10px;flex-wrap:wrap;}
  .ag-search-row input{flex:1 1 320px;min-width:0;padding:13px 16px;font-size:14px;color:#333;
    border:1px solid #e0e0e0;border-radius:10px;background:#fff;outline:none;transition:border-color 150ms;}
  .ag-search-row input:focus{border-color:#2e9e3f;}
  .ag-btn{padding:9px 18px;border-radius:10px;border:none;background:#2e9e3f;color:#fff;
    font-size:12.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;cursor:pointer;
    transition:all 150ms;box-shadow:0 4px 18px rgba(46,158,63,.3);display:inline-flex;align-items:center;gap:8px;
    white-space:nowrap;flex-shrink:0;}
  .ag-btn:hover:not(:disabled){background:#257e32;transform:translateY(-1px);box-shadow:0 6px 20px rgba(46,158,63,.45);}
  .ag-btn:disabled{opacity:.65;cursor:wait;transform:none;}
  .ag-row{display:flex;align-items:center;gap:12px;margin-top:14px;flex-wrap:wrap;}
  .ag-btn-secondary{background:#fff;color:#333;border:1px solid #e0e0e0;box-shadow:none;}
  .ag-btn-secondary:hover:not(:disabled){background:#eeeef6;border-color:#2e9e3f;transform:none;box-shadow:none;}
  .ag-btn-excel{background:#10b981;color:#fff;box-shadow:0 4px 18px rgba(16,185,129,.3);}
  .ag-btn-excel:hover:not(:disabled){background:#0d9668;transform:translateY(-1px);box-shadow:0 6px 20px rgba(16,185,129,.45);}
  #agStatus{font-size:12.5px;color:#555;white-space:pre-wrap;word-break:break-word;font-weight:500;}
  .ag-progress-wrap{margin-top:12px;display:none;}
  .ag-progress-track{height:8px;border-radius:6px;background:#eeeef6;overflow:hidden;border:1px solid #e0e0e0;}
  .ag-progress-fill{height:100%;border-radius:6px;width:100%;
    background-image:repeating-linear-gradient(45deg,#2e9e3f 0 12px,#257e32 12px 24px);
    background-size:34px 100%;animation:ag-progress-stripes 1s linear infinite;}
  @keyframes ag-progress-stripes{from{background-position:0 0;}to{background-position:-34px 0;}}
  .ag-progress-meta{display:flex;justify-content:space-between;margin-top:6px;font-size:11.5px;color:#999;}
  .ag-result-wrap{margin-top:16px;display:none;background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow-x:auto;}
  .ag-table-toolbar{padding:10px 16px;background:#2e9e3f;color:#fff;font-size:11.5px;font-weight:700;
    text-transform:uppercase;letter-spacing:.3px;}
  .ag-table{width:100%;border-collapse:collapse;font-size:11.5px;}
  .ag-table th{background:#eeeef6;color:#555;font-size:10px;font-weight:700;text-transform:uppercase;
    letter-spacing:.3px;padding:6px 8px;text-align:left;white-space:nowrap;border-bottom:1px solid #e0e0e0;}
  .ag-table td{padding:6px 8px;border-bottom:1px solid #eee;vertical-align:top;color:#333;max-width:260px;word-break:break-word;}
  /* Key consumer rows only (Consumer Id/Number/Name, Sub Status, Consumer
     Address) in dark, semi-bold text, value a step bolder - per explicit
     instruction, 2026-10-04; see AG_KEY_ROWS. */
  .ag-table tr.ag-key td{color:#1f2937;font-weight:600;}
  .ag-table tr.ag-key td:last-child{font-weight:700;color:#111827;}
  /* Section cards - same look as hp_gas.php's result (per explicit
     instruction, 2026-10-04): one card per Section, green title bar, a
     label/value list inside. */
  .ag-sections{margin-top:16px;}
  .ag-section{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;margin-bottom:14px;}
  .ag-section-title{padding:10px 16px;background:#2e9e3f;color:#fff;font-size:11.5px;font-weight:700;
    text-transform:uppercase;letter-spacing:.4px;}
  .ag-section-table{width:100%;border-collapse:collapse;}
  .ag-section-table tr:nth-child(odd){background:#fff;}
  .ag-section-table tr:nth-child(even){background:#f8f8fc;}
  .ag-section-table td{padding:8px 16px;font-size:12.5px;border-bottom:1px solid #eee;vertical-align:top;}
  .ag-section-table tr:last-child td{border-bottom:none;}
  .ag-field-label{width:38%;color:#777;font-weight:600;text-transform:uppercase;}
  .ag-field-value{color:#222;font-weight:500;word-break:break-word;}
  .ag-table tr:nth-child(even) td{background:#f8f8fc;}
  .ag-no-results{display:none;flex-direction:column;align-items:center;justify-content:center;gap:14px;
    padding:56px 16px;color:#999;margin-top:16px;text-align:center;}
  .ag-no-results i{font-size:38px;color:#ccc;width:74px;height:74px;display:flex;align-items:center;justify-content:center;
    border-radius:50%;border:1.5px solid #e5e5e5;}
  .ag-no-results span{font-size:14px;color:#888;}
</style>

<div class="ag-card">
  <div class="ag-card-body">
    <div class="ag-tabs" role="tablist">
      <button type="button" class="ag-tab active" data-provider="indane"><i class="bi bi-fire"></i> Indane</button>
      <button type="button" class="ag-tab" data-provider="bharat"><i class="bi bi-fire"></i> Bharat Gas</button>
      <button type="button" class="ag-tab" data-provider="hp"><i class="bi bi-fire"></i> HP Gas</button>
    </div>
    <div class="ag-search-row">
      <input type="text" id="agMobile" placeholder="Enter 10-digit mobile number..." maxlength="10" inputmode="numeric" autocomplete="off">
    </div>
    <div class="ag-row">
      <button id="agSearchBtn" class="ag-btn"><i class="bi bi-search"></i> SEARCH</button>
      <button id="agClearBtn" class="ag-btn ag-btn-secondary" type="button">Clear</button>
      <button id="agExportBtn" class="ag-btn ag-btn-excel" type="button" disabled>
        <i class="bi bi-file-earmark-excel"></i> Download Excel
      </button>
      <span id="agStatus"></span>
    </div>
    <div class="ag-progress-wrap" id="agProgressWrap">
      <div class="ag-progress-track"><div class="ag-progress-fill"></div></div>
      <div class="ag-progress-meta">
        <span id="agProgressLabel">Searching…</span>
        <span id="agProgressElapsed"></span>
      </div>
    </div>
  </div>
</div>

<div class="ag-sections" id="agSections"></div>
<div class="ag-result-wrap" id="agResultWrap">
  <table class="ag-table">
    <thead><tr id="agResultHeadRow"></tr></thead>
    <tbody id="agResultBody"></tbody>
  </table>
</div>
<div class="ag-no-results" id="agNoResults">
  <i class="bi bi-search"></i>
  <span id="agNoResultsText">No records found</span>
</div>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
const searchBtn = document.getElementById("agSearchBtn");
const clearBtn = document.getElementById("agClearBtn");
const exportBtn = document.getElementById("agExportBtn");
const mobileEl = document.getElementById("agMobile");
const statusEl = document.getElementById("agStatus");
const resultWrap = document.getElementById("agResultWrap");
const sectionsEl = document.getElementById("agSections");
const resultHeadRow = document.getElementById("agResultHeadRow");
const resultBody = document.getElementById("agResultBody");
const noResultsEl = document.getElementById("agNoResults");
const noResultsText = document.getElementById("agNoResultsText");
const progressWrap = document.getElementById("agProgressWrap");
const progressLabel = document.getElementById("agProgressLabel");
const progressElapsed = document.getElementById("agProgressElapsed");

let activeProvider = "indane";
let lastHeaders = [];
let lastRows = [];
let progressTimer = null;
let searchStartedAt = null;
// A live tracekart.in Gas Connection search took ~13s when measured.
const ESTIMATED_SECONDS = 15;

document.querySelectorAll(".ag-tab").forEach(tab => tab.addEventListener("click", () => {
  document.querySelectorAll(".ag-tab").forEach(t => t.classList.remove("active"));
  tab.classList.add("active");
  activeProvider = tab.dataset.provider;
  statusEl.textContent = "";
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

// Fields not shown (or exported) per explicit instruction (2026-10-04) -
// matched case-insensitively against the "Field" column. The archive still
// keeps the full record.
const HIDDEN_FIELDS = [
  "consumer category", "mi due date", "relationship uc mid",
  "consumer relation type", "consumer status", "tube change date", "consumer type",
  "tube change due date", "distributor code", "distributor backlog days",
  // HP's leading status block (Message / Status Code / Success).
  "message", "status code", "success",
  // HP Data rows not needed.
  "error code", "existing hppaycustomer", "has additional connection", "lpgcodless than five min",
  "query ref no", "svquantity",
  "is refill portability enabled", "is valid for payment", "is valid for refill", "online payment discount amount",
  "cylinder type",
];

// An address section's separate Line 1/2/3, State, City and Pin Code rows
// are shown as ONE comma-joined "Address" row, in this order (per explicit
// instruction, 2026-10-04 - e.g. "NO 10/12 4TH MAIN,9TH CROSS BSK 3RD
// STAGE,SRINIVASANAGAR,Karnataka,BANGALORE,560085"); its other parts
// (State Code, District, District Code) aren't shown.
// Distributor Address uses "Address Line 1/2/3" and keeps its pin inside
// line 3, so both naming styles are listed; a part already contained in the
// joined text so far (e.g. the City repeated at the end of line 3) is skipped.
const ADDRESS_PARTS = ["line 1", "address line 1", "line 2", "address line 2", "line 3", "address line 3", "state", "city", "pin code"];

function mergeAddressRows(rows) {
  const out = [];
  const bySection = {};
  const isFirstLine = f => f === "line 1" || f === "address line 1";
  rows.forEach(row => {
    const section = String(row.Section || "");
    const field = String(row.Field || "").trim().toLowerCase();
    if (!/address/i.test(section) || !rows.some(r => r.Section === row.Section && isFirstLine(String(r.Field || "").trim().toLowerCase()))) {
      out.push(row);
      return;
    }
    if (!bySection[section]) {
      bySection[section] = { Section: section, Field: "Address", Value: "", parts: {} };
      out.push(bySection[section]); // keeps the address where its first row was
    }
    if (ADDRESS_PARTS.includes(field)) bySection[section].parts[field] = String(row.Value || "").trim();
  });
  Object.values(bySection).forEach(a => {
    const kept = [];
    ADDRESS_PARTS.forEach(p => {
      const v = a.parts[p];
      if (!v || v === "—") return;
      if (kept.join(",").toLowerCase().includes(v.toLowerCase())) return;
      kept.push(v);
    });
    a.Value = kept.join(",");
    delete a.parts;
  });
  return out;
}

// Every provider's result is shown in the same two-card layout as Indane's
// (per explicit instruction, 2026-10-04): Consumer Details (Id, Number,
// Name, Sub Status, Address) and Distributor Details (Name, Contact,
// Address), mapping each provider's own field names onto those rows - e.g.
// HP's "Unique Consumer ID" -> Consumer Id, "Status" -> Consumer Sub
// Status, "Consumer Address" + "Pin Code" -> Address. Everything else is
// left out. Returns null when none of the consumer fields are present, so
// an unfamiliar layout still falls back to the generic view.
function joinParts(...parts) {
  const kept = [];
  parts.forEach(p => {
    const v = String(p || "").trim();
    if (!v || v === "—") return;
    if (kept.join(",").toLowerCase().includes(v.toLowerCase())) return;
    kept.push(v);
  });
  return kept.join(",");
}

function standardizeRows(rawRows) {
  const rows = mergeAddressRows(rawRows);
  const norm = r => ({ s: String(r.Section || "").trim().toLowerCase(), f: String(r.Field || "").trim().toLowerCase(), v: String(r.Value || "").trim() });
  const get = pred => { const hit = rows.map(norm).find(r => r.v && pred(r)); return hit ? hit.v : ""; };
  const notDistributor = r => !/distributor/.test(r.s) && !/distributor/.test(r.f);

  const consumerId = get(r => r.f === "consumer id" || r.f === "unique consumer id");
  const number = get(r => r.f === "consumer number");
  const name = get(r => r.f === "consumer name");
  if (!consumerId && !number && !name) return null;
  const subStatus = get(r => r.f === "consumer sub status") || get(r => r.f === "status" && notDistributor(r));
  const cAddress = get(r => r.s === "consumer address" && r.f === "address")
    || joinParts(get(r => r.f === "consumer address"), get(r => r.f === "pin code" && notDistributor(r)));
  const dName = get(r => r.f === "distributor name");
  const dContact = get(r => r.f === "distributor contact");
  const dAddress = get(r => r.s === "distributor address" && r.f === "address")
    || joinParts(get(r => r.f === "distributor address"), get(r => r.f === "distributor city"));

  const out = [];
  const add = (section, field, value) => { if (value) out.push({ Section: section, Field: field, Value: value }); };
  add("Consumer Details", "Consumer Id", consumerId);
  add("Consumer Details", "Consumer Number", number);
  add("Consumer Details", "Consumer Name", name);
  add("Consumer Details", "Consumer Sub Status", subStatus);
  add("Consumer Details", "Address", cAddress);
  add("Distributor Details", "Distributor Name", dName);
  add("Distributor Details", "Distributor Contact", dContact);
  add("Distributor Details", "Address", dAddress);
  return out;
}

// Card titles shown under a different name (per explicit instruction,
// 2026-10-04). Indane's first section ("Indane Gas") holds the consumer's
// id/number/name, so it's shown as "Consumer Details" - and, sharing that
// title, it merges into the same card as the real Consumer Details rows.
// The consumer's address joins the same card too (per explicit instruction,
// 2026-10-04 - "all need single").
const SECTION_RENAMES = {
  "indane gas": "Consumer Details", "consumer address": "Consumer Details",
  // Distributor address joins the Distributor Details card the same way.
  "distributor address": "Distributor Details",
};
// Cards shown without their green title bar.
// (Emptied 2026-10-04 to match hp_gas.php's look - every card shows its
// title again.)
const UNTITLED_SECTIONS = ["data"];
function displaySection(section) {
  const s = String(section || "Result").trim();
  return SECTION_RENAMES[s.toLowerCase()] || s;
}

// The rows shown in dark text (see .ag-table tr.ag-key).
const AG_KEY_ROWS = ["consumer id", "consumer number", "consumer name", "consumer sub status"];
function isKeyRow(row) {
  const field = String(row.Field || "").trim().toLowerCase();
  return AG_KEY_ROWS.includes(field) || (/^consumer address$/i.test(String(row.Section || "").trim()) && field === "address");
}

function renderResult(data) {
  resultHeadRow.innerHTML = "";
  resultBody.innerHTML = "";
  lastHeaders = data.headers || [];
  const rawRows = (data.totalResults && data.rows) ? data.rows : [];
  // Bharat Gas: the source's own "Details" section as one card - Consumer Id,
  // Consumer Number, Current Mobile Number, Address, Distributor, Name - same
  // as crm-app-v3's result (2026-10-05, per explicit instruction).
  const BHARAT_FIELDS = ["consumer id", "consumer number", "current mobile number", "address", "distributor", "name"];
  const bharatRows = activeProvider === "bharat"
    ? rawRows.filter(r => String(r.Section || "").trim().toLowerCase() === "details" && String(r.Value || "").trim())
        .sort((x, y) => BHARAT_FIELDS.indexOf(String(x.Field).trim().toLowerCase()) - BHARAT_FIELDS.indexOf(String(y.Field).trim().toLowerCase()))
        .map(r => ({ Section: "Details", Field: r.Field, Value: r.Value }))
    : [];
  const standard = bharatRows.length ? bharatRows
    : (lastHeaders.join("|") === "Section|Field|Value" ? standardizeRows(rawRows) : null);
  lastRows = standard || mergeAddressRows(rawRows
    .filter(row => !HIDDEN_FIELDS.includes(String(row.Field || "").trim().toLowerCase())));
  exportBtn.disabled = !lastRows.length;
  sectionsEl.innerHTML = "";

  if (!lastRows.length) {
    resultWrap.style.display = "none";
    noResultsText.textContent = data.message || "No records found";
    noResultsEl.style.display = "flex";
  } else if (lastHeaders.join("|") === "Section|Field|Value") {
    // Card view: one card per Section, in the order the sections arrive.
    noResultsEl.style.display = "none";
    resultWrap.style.display = "none";
    const cards = {};
    lastRows.forEach(row => {
      const section = displaySection(row.Section);
      if (!cards[section]) {
        const box = document.createElement("div");
        box.className = "ag-section";
        const table = document.createElement("table");
        table.className = "ag-section-table";
        const tbody = document.createElement("tbody");
        table.appendChild(tbody);
        // No title bar on the Consumer Details card (per explicit
        // instruction, 2026-10-04) - every other card keeps its title.
        if (!UNTITLED_SECTIONS.includes(section.toLowerCase())) {
          const title = document.createElement("div");
          title.className = "ag-section-title";
          title.textContent = section;
          box.appendChild(title);
        }
        box.appendChild(table);
        sectionsEl.appendChild(box);
        cards[section] = tbody;
      }
      const tr = document.createElement("tr");
      if (isKeyRow(row)) tr.className = "ag-key";
      const label = document.createElement("td");
      label.className = "ag-field-label";
      label.textContent = row.Field || "";
      const value = document.createElement("td");
      value.className = "ag-field-value";
      value.textContent = row.Value || "—";
      tr.appendChild(label);
      tr.appendChild(value);
      cards[section].appendChild(tr);
    });
  } else {
    noResultsEl.style.display = "none";
    lastHeaders.forEach(h => {
      const th = document.createElement("th");
      th.textContent = h;
      resultHeadRow.appendChild(th);
    });
    lastRows.forEach(row => {
      const tr = document.createElement("tr");
      if (isKeyRow(row)) tr.className = "ag-key";
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
}

async function runSearch() {
  const mobile = mobileEl.value.trim();
  if (!/^\d{10}$/.test(mobile)) {
    statusEl.textContent = "Enter a valid 10-digit mobile number.";
    return;
  }

  searchBtn.disabled = true;
  statusEl.textContent = "";
  resultWrap.style.display = "none";
  sectionsEl.innerHTML = "";
  noResultsEl.style.display = "none";
  startProgress();

  try {
    const res = await fetch("all_gas_api.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ mobile, provider: activeProvider })
    });
    const data = await res.json();

    if (!res.ok) {
      stopProgress(null);
      statusEl.textContent = `Error: ${data.error || "could not complete search"}`;
      return;
    }

    renderResult(data);
    // Same "Result found" / "No result found" line as hp_gas.php.
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
  resultWrap.style.display = "none";
  sectionsEl.innerHTML = "";
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
  XLSX.writeFile(wb, `all-gas-export-${stamp}.xlsx`);
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

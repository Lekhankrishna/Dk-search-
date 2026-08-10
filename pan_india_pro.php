<?php
require __DIR__ . '/includes/auth.php';
requirePanIndiaProAccess();
require_once __DIR__ . '/config/db.php';

// Same quota-badge pattern as advance_pan_india.php/rc_print.php/hp_gas.php.
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$quota = null;
if (!$isAdmin) {
    $stmt = $pdo->prepare('SELECT pan_india_pro_monthly_limit FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $monthlyLimit = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM search_logs WHERE user_id = :id AND search_type = 'pan_india_pro' AND searched_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
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
  <h1 class="page-title" style="margin:0"><i class="bi bi-globe-asia-australia"></i> Night Out</h1>
  <?php if ($isAdmin): ?>
    <span id="pipQuotaBadge" class="badge badge-neutral" style="margin-left:auto">Unlimited (Admin)</span>
  <?php elseif ($quota !== null): ?>
    <span id="pipQuotaBadge" class="badge <?= $quota['used'] >= $quota['limit'] ? 'badge-danger' : 'badge-neutral' ?>"
          style="margin-left:auto">
      <?= $quota['limit'] - $quota['used'] > 0 ? $quota['limit'] - $quota['used'] : 0 ?> of <?= $quota['limit'] ?> left this month
    </span>
  <?php endif; ?>
</div>

<style>
  /* Dark purple/pink/cyan palette (2026-08-09) matching the vendor's own
     site design, instead of this app's usual white-card indigo tool
     styling (rc_print.php/hp_gas.php/advance_pan_india.php) - deliberately
     distinct so this page still doesn't NAME the vendor anywhere, but reads
     as visually "from" that tool. */
  .pip-card{background:#1a0f2e;border-radius:12px;border:1px solid rgba(168,85,247,.25);
    box-shadow:0 0 30px rgba(168,85,247,.12);overflow:hidden;}
  .pip-card-body{padding:16px 18px;}
  .pip-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;}
  .pip-field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;color:#9ca3af;margin-bottom:4px;}
  .pip-field input{width:100%;padding:10px 12px;font-size:13px;color:#fff;border:1px solid #4b5563;border-radius:8px;
    background:#2D1B4E;outline:none;transition:border-color 150ms,box-shadow 150ms;}
  .pip-field input::placeholder{color:#6b7280;}
  .pip-field input:focus{border-color:#ec4899;box-shadow:0 0 0 3px rgba(236,72,153,.2);}
  .pip-row{display:flex;align-items:center;gap:12px;margin-top:14px;flex-wrap:wrap;}
  .pip-btn{padding:11px 26px;border-radius:9px;border:none;
    background:linear-gradient(90deg,#ec4899,#ef4444);color:#fff;
    font-size:12.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;cursor:pointer;
    transition:all 150ms;box-shadow:0 4px 18px rgba(236,72,153,.35);}
  .pip-btn:hover:not(:disabled){background:linear-gradient(90deg,#db2777,#dc2626);transform:translateY(-1px);box-shadow:0 6px 20px rgba(236,72,153,.5);}
  .pip-btn:disabled{opacity:.65;cursor:wait;transform:none;}
  .pip-btn-secondary{background:#2D1B4E;color:#e5e7eb;border:1px solid #4b5563;box-shadow:none;}
  .pip-btn-secondary:hover:not(:disabled){background:#3b2a5e;border-color:#ec4899;transform:none;box-shadow:none;}
  .pip-btn-excel{background:#10b981;color:#fff;box-shadow:0 4px 18px rgba(16,185,129,.3);}
  .pip-btn-excel:hover:not(:disabled){background:#0d9668;transform:translateY(-1px);box-shadow:0 6px 20px rgba(16,185,129,.45);}
  #pipStatus{font-size:12.5px;color:#9ca3af;white-space:pre-wrap;word-break:break-word;font-weight:500;}
  .pip-progress-wrap{margin-top:12px;display:none;}
  .pip-progress-track{height:8px;border-radius:6px;background:#2D1B4E;overflow:hidden;border:1px solid #4b5563;}
  .pip-progress-fill{height:100%;border-radius:6px;background:#ec4899;width:100%;
    background-image:repeating-linear-gradient(45deg,#ec4899 0 12px,#ef4444 12px 24px);
    background-size:34px 100%;animation:pip-progress-stripes 1s linear infinite;}
  @keyframes pip-progress-stripes{from{background-position:0 0;}to{background-position:-34px 0;}}
  .pip-progress-meta{display:flex;justify-content:space-between;margin-top:6px;font-size:11.5px;color:#6b7280;}
  .pip-result-wrap{background:#1a0f2e;border-radius:12px;border:1px solid rgba(168,85,247,.25);
    box-shadow:0 0 30px rgba(168,85,247,.12);margin-top:16px;overflow-x:auto;display:none;}
  .pip-result-toolbar{padding:10px 16px;background:#a855f7;color:#fff;font-size:11.5px;font-weight:700;
    text-transform:uppercase;letter-spacing:.3px;}
  .pip-table{width:100%;border-collapse:collapse;font-size:11.5px;}
  .pip-table th{background:#2D1B4E;color:#fff;font-size:10px;font-weight:700;text-transform:uppercase;
    letter-spacing:.3px;padding:8px;text-align:left;white-space:nowrap;border-bottom:1px solid #4b5563;}
  .pip-table td{padding:8px;border-bottom:1px solid rgba(255,255,255,.06);vertical-align:top;color:#e5e7eb;max-width:260px;word-break:break-word;}
  .pip-no-results{background:#1a0f2e;border-radius:12px;border:1px solid rgba(168,85,247,.25);padding:16px;color:#9ca3af;}

  /* Per-column colour coding (2026-08-09) matching the vendor's own results
     table exactly (Name/Father's Name/Identity=slate, Mobile=pink,
     Alt. Mobile=violet, Address=green, Alt. Address=coral, Email=orange,
     Reg. Year=cyan) - same nth-child convention already used for this in
     lpg_search.php/index.php, just re-tuned to sit on this page's dark
     cards instead of light ones. */
  .pip-table th:nth-child(1),.pip-table th:nth-child(4),.pip-table th:nth-child(9){background:#475569;}
  .pip-table th:nth-child(2){background:#ec4899;}
  .pip-table th:nth-child(3){background:#8b5cf6;}
  .pip-table th:nth-child(5){background:#34d399;}
  .pip-table th:nth-child(6){background:#f87171;}
  .pip-table th:nth-child(7){background:#f97316;}
  .pip-table th:nth-child(8){background:#38bdf8;}
  /* Same solid colour as the header, carried down the full column - not
     just a subtle tint (per explicit instruction: "give same as header"). */
  .pip-table td:nth-child(1),.pip-table td:nth-child(4),.pip-table td:nth-child(9){background:#475569;color:#fff;}
  .pip-table td:nth-child(2){background:#ec4899;color:#fff;}
  .pip-table td:nth-child(3){background:#8b5cf6;color:#fff;}
  .pip-table td:nth-child(5){background:#34d399;color:#0a2e22;}
  .pip-table td:nth-child(6){background:#f87171;color:#3a0a0a;}
  .pip-table td:nth-child(7){background:#f97316;color:#3a1c00;}
  .pip-table td:nth-child(8){background:#38bdf8;color:#04283a;}
  /* A dark chip rather than a colour-matched one - the whole cell is
     already that column's colour now, so the badge just needs to read
     clearly on top of any of them. */
  .pip-cell-badge{font-family:'Consolas','Cascadia Code','Courier New',monospace;font-size:11.5px;font-weight:700;
    padding:2px 8px;border-radius:5px;display:inline-block;letter-spacing:.2px;
    background:rgba(0,0,0,.28);border:1px solid rgba(255,255,255,.35);color:#fff;}
</style>

<div class="pip-card">
  <div class="pip-card-body">
    <div class="pip-grid">
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
const quotaBadge = document.getElementById("pipQuotaBadge");
let lastPipRows = [];
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

function updateQuotaBadge(used, limit) {
  if (!quotaBadge) return;
  const remaining = Math.max(0, limit - used);
  quotaBadge.textContent = `${remaining} of ${limit} left this month`;
  quotaBadge.classList.toggle("badge-danger", used >= limit);
  quotaBadge.classList.toggle("badge-neutral", used < limit);
}

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
function renderResult(data) {
  resultBody.innerHTML = "";
  lastPipRows = (data.totalResults && data.rows) ? data.rows : [];
  exportBtn.disabled = !lastPipRows.length;

  if (!lastPipRows.length) {
    resultWrap.style.display = "none";
  } else {
    resultToolbar.textContent = `${lastPipRows.length} result${lastPipRows.length === 1 ? "" : "s"}`;
    lastPipRows.forEach(row => {
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
    });
    resultWrap.style.display = "block";
  }

  if (lastPipRows.length) startConfetti(); else stopConfetti();
  if (typeof data.used === "number" && typeof data.limit === "number") {
    updateQuotaBadge(data.used, data.limit);
  }
}

async function runSearch() {
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
  Object.values(fields).forEach(input => input.value = "");
  statusEl.textContent = "";
  resultWrap.style.display = "none";
  stopProgress(null);
  lastPipRows = [];
  exportBtn.disabled = true;
  stopConfetti();
});

/* Download the current result set as a single .xlsx workbook. */
exportBtn.addEventListener("click", () => {
  if (!lastPipRows.length) { alert("No records to export."); return; }
  const headers = ["Name", "Mobile", "Alt. Mobile", "Father's Name", "Address", "Alt. Address", "Email", "Reg. Year", "Identity"];
  const aoa = [headers, ...lastPipRows.map(row => [
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

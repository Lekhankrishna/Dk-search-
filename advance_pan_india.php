<?php
require __DIR__ . '/includes/auth.php';
requireEagleEyeAccess();
require_once __DIR__ . '/config/db.php';

// Same quota-badge pattern as rc_print.php/hp_gas.php.
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$quota = null;
if (!$isAdmin) {
    $stmt = $pdo->prepare('SELECT eagle_eye_monthly_limit FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $monthlyLimit = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM search_logs WHERE user_id = :id AND search_type = 'eagle_eye' AND searched_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
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
  <h1 class="page-title" style="margin:0"><i class="bi bi-globe-asia-australia"></i> Advance Pan India</h1>
  <?php if ($isAdmin): ?>
    <span id="eeQuotaBadge" class="badge badge-neutral" style="margin-left:auto">Unlimited (Admin)</span>
  <?php elseif ($quota !== null): ?>
    <span id="eeQuotaBadge" class="badge <?= $quota['used'] >= $quota['limit'] ? 'badge-danger' : 'badge-neutral' ?>"
          style="margin-left:auto">
      <?= $quota['limit'] - $quota['used'] > 0 ? $quota['limit'] - $quota['used'] : 0 ?> of <?= $quota['limit'] ?> left this month
    </span>
  <?php endif; ?>
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
</style>

<div class="ee-card">
  <div class="ee-card-body">
    <div class="ee-grid">
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
const quotaBadge = document.getElementById("eeQuotaBadge");
let lastEeTables = [];
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

function updateQuotaBadge(used, limit) {
  if (!quotaBadge) return;
  const remaining = Math.max(0, limit - used);
  quotaBadge.textContent = `${remaining} of ${limit} left this month`;
  quotaBadge.classList.toggle("badge-danger", used >= limit);
  quotaBadge.classList.toggle("badge-neutral", used < limit);
}

// Tables come from theeagleeye.biz's own result tables, read generically
// (whatever headers/columns it renders) - see includes/eagleeye_client.php.
function renderResult(data) {
  resultWrap.innerHTML = "";
  lastEeTables = (data.totalResults && data.tables) ? data.tables : [];
  exportBtn.disabled = !lastEeTables.length;

  if (!data.totalResults || !data.tables || !data.tables.length) {
    resultWrap.innerHTML = `<div class="ee-no-results">No results found.</div>`;
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

  resultWrap.style.display = "block";
  if (lastEeTables.length) startConfetti(); else stopConfetti();
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
    const res = await fetch("advance_pan_india_api.php", {
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
  lastEeTables = [];
  exportBtn.disabled = true;
  stopConfetti();
});

/* Download all currently rendered result tables as a single .xlsx workbook -
   one sheet per source table, matching how theeagleeye.biz itself groups
   results (see "Source N" toolbar labels in renderResult()). */
exportBtn.addEventListener("click", () => {
  if (!lastEeTables.length) { alert("No records to export."); return; }
  const wb = XLSX.utils.book_new();
  lastEeTables.forEach((table, i) => {
    const aoa = [table.headers, ...table.rows.map(row => table.headers.map(h => row[h] || ""))];
    const ws = XLSX.utils.aoa_to_sheet(aoa);
    XLSX.utils.book_append_sheet(wb, ws, `Source ${i + 1}`.slice(0, 31));
  });
  const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, "-");
  XLSX.writeFile(wb, `advance-pan-india-export-${stamp}.xlsx`);
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

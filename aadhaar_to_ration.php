<?php
require __DIR__ . '/includes/auth.php';
requireAadhaarToRationAccess();
require_once __DIR__ . '/config/db.php';

// Same quota-badge pattern as rc_print.php/hp_gas.php.
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$quota = null;
if (!$isAdmin) {
    $stmt = $pdo->prepare('SELECT aadhaar_to_ration_monthly_limit FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $monthlyLimit = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM search_logs WHERE user_id = :id AND search_type = 'aadhaar_to_ration' AND result_count > 0 AND searched_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
    );
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $usedThisMonth = (int) $stmt->fetchColumn();

    $quota = ['used' => $usedThisMonth, 'limit' => $monthlyLimit];
}

$basePath = '';
require __DIR__ . '/includes/header.php';
?>

<div class="page-header" style="display:flex;align-items:center;flex-wrap:wrap;gap:12px">
  <h1 class="page-title" style="margin:0"><i class="bi bi-person-vcard"></i> Aadhaar to Family Members</h1>
  <?php if ($isAdmin): ?>
    <span id="a2rQuotaBadge" class="badge badge-neutral" style="margin-left:auto">Unlimited (Admin)</span>
  <?php elseif ($quota !== null): ?>
    <span id="a2rQuotaBadge" class="badge <?= $quota['used'] >= $quota['limit'] ? 'badge-danger' : 'badge-neutral' ?>"
          style="margin-left:auto">
      <?= $quota['limit'] - $quota['used'] > 0 ? $quota['limit'] - $quota['used'] : 0 ?> of <?= $quota['limit'] ?> left this month
    </span>
  <?php endif; ?>
</div>

<style>
  .a2r-card{background:var(--c-surface,#fff);border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;}
  .a2r-card-body{padding:16px 18px;}
  .a2r-row{display:flex;align-items:center;gap:12px;margin-top:12px;flex-wrap:wrap;}
  .a2r-btn{padding:11px 26px;border-radius:9px;border:none;background:#2e9e3f;color:#fff;
    font-size:12.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;cursor:pointer;
    transition:all 150ms;box-shadow:0 4px 18px rgba(46,158,63,.3);}
  .a2r-btn:hover:not(:disabled){background:#257e32;transform:translateY(-1px);box-shadow:0 6px 20px rgba(46,158,63,.45);}
  .a2r-btn:disabled{opacity:.65;cursor:wait;transform:none;}
  .a2r-btn-secondary{background:#fff;color:#333;border:1px solid #e0e0e0;box-shadow:none;}
  .a2r-btn-secondary:hover:not(:disabled){background:#eeeef6;border-color:#2e9e3f;transform:none;box-shadow:none;}
  #a2rStatus{font-size:12.5px;color:#555;white-space:pre-wrap;word-break:break-word;font-weight:500;}
  .a2r-progress-wrap{margin-top:12px;display:none;}
  .a2r-progress-track{height:8px;border-radius:6px;background:#eeeef6;overflow:hidden;border:1px solid #e0e0e0;}
  .a2r-progress-fill{height:100%;border-radius:6px;background:#2e9e3f;width:100%;
    background-image:repeating-linear-gradient(45deg,#2e9e3f 0 12px,#257e32 12px 24px);
    background-size:34px 100%;animation:a2r-progress-stripes 1s linear infinite;}
  @keyframes a2r-progress-stripes{from{background-position:0 0;}to{background-position:-34px 0;}}
  .a2r-progress-meta{display:flex;justify-content:space-between;margin-top:6px;font-size:11.5px;color:#999;}
  .a2r-section{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;margin-top:16px;}
  .a2r-section-title{padding:10px 16px;background:#2e9e3f;color:#fff;font-size:11.5px;font-weight:700;
    text-transform:uppercase;letter-spacing:.4px;}
  .a2r-section-table{width:100%;border-collapse:collapse;}
  .a2r-section-table tr:nth-child(odd){background:#fff;}
  .a2r-section-table tr:nth-child(even){background:#f8f8fc;}
  .a2r-section-table td{padding:8px 16px;font-size:12.5px;border-bottom:1px solid #eee;vertical-align:top;}
  .a2r-section-table tr:last-child td{border-bottom:none;}
  .a2r-field-label{width:38%;color:#777;font-weight:600;}
  .a2r-field-value{color:#222;font-weight:500;word-break:break-word;}
  .a2r-not-found{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);padding:16px;color:#f87171;font-weight:600;margin-top:16px;}
</style>

<div class="a2r-card">
  <div class="a2r-card-body">
    <input type="text" id="a2rNumberBox" placeholder="Enter 12-digit Aadhaar number" maxlength="12"
           style="width:100%;padding:11px 16px;font-size:13px;color:#333;border:1px solid #e0e0e0;border-radius:9px;background:#fff;outline:none;">
    <div class="a2r-row">
      <button id="a2rSearchBtn" class="a2r-btn">Search</button>
      <button id="a2rClearBtn" class="a2r-btn a2r-btn-secondary" type="button">Clear</button>
      <span id="a2rStatus"></span>
    </div>
    <div class="a2r-progress-wrap" id="a2rProgressWrap">
      <div class="a2r-progress-track"><div class="a2r-progress-fill"></div></div>
      <div class="a2r-progress-meta">
        <span id="a2rProgressLabel">Logging in and running the search…</span>
        <span id="a2rProgressElapsed"></span>
      </div>
    </div>
  </div>
</div>

<div id="a2rResultWrap"></div>

<script>
const searchBtn      = document.getElementById("a2rSearchBtn");
const clearBtn       = document.getElementById("a2rClearBtn");
const numberBox      = document.getElementById("a2rNumberBox");
const statusEl       = document.getElementById("a2rStatus");
const resultWrap     = document.getElementById("a2rResultWrap");
const quotaBadge     = document.getElementById("a2rQuotaBadge");
const progressWrap   = document.getElementById("a2rProgressWrap");
const progressLabel  = document.getElementById("a2rProgressLabel");
const progressElapsed= document.getElementById("a2rProgressElapsed");

let progressTimer = null;
let searchStartedAt = null;

function formatDuration(seconds) {
  seconds = Math.max(0, Math.round(seconds));
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

const ESTIMATED_SECONDS = 25;

function startProgress() {
  searchStartedAt = Date.now();
  progressLabel.textContent = "Logging in and running the search…";
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

function updateQuotaBadge(used, limit) {
  if (!quotaBadge) return;
  const remaining = Math.max(0, limit - used);
  quotaBadge.textContent = `${remaining} of ${limit} left this month`;
  quotaBadge.classList.toggle("badge-danger", used >= limit);
  quotaBadge.classList.toggle("badge-neutral", used < limit);
}

// Fields not shown, per explicit instruction (2026-10-04) - matched
// case-insensitively against the field label.
const HIDDEN_FIELDS = ["aadhaar uid", "member id"];

function buildFieldTable(fields) {
  const table = document.createElement("table");
  table.className = "a2r-section-table";
  const tbody = document.createElement("tbody");
  fields.filter(field => !HIDDEN_FIELDS.includes(String(field.label || "").trim().toLowerCase())).forEach(field => {
    const tr = document.createElement("tr");
    tr.innerHTML = `<td class="a2r-field-label"></td><td class="a2r-field-value"></td>`;
    tr.querySelector(".a2r-field-label").textContent = field.label;
    tr.querySelector(".a2r-field-value").textContent = field.value || "—";
    tbody.appendChild(tr);
  });
  table.appendChild(tbody);
  return table;
}

// Records come from Gas/lpg_web/aadhaar_to_ration.py (itself reusing
// tracing2_tools.py's generic scraper) as label/value field pairs grouped
// under an optional name/status header - rendered as-is here rather than
// assuming fixed field names, same reasoning as tracing2.php's own
// buildRecordCard().
function renderResult(data) {
  resultWrap.innerHTML = "";

  const records = (data.found && Array.isArray(data.records)) ? data.records : [];

  if (!records.length) {
    resultWrap.innerHTML = `<div class="a2r-not-found">Not found for ${data.aadhaarNumber}.</div>`;
  } else {
    // Family members (records whose only visible field is NAME, once the
    // hidden fields are dropped) are listed together in ONE plain card -
    // one name per row, no green title bar and no "NAME" label (per
    // explicit instruction, 2026-10-04). Other records keep their card.
    const visible = r => (r.fields || []).filter(f => !HIDDEN_FIELDS.includes(String(f.label || "").trim().toLowerCase()));
    const isMember = r => { const v = visible(r); return v.length === 1 && String(v[0].label || "").trim().toLowerCase() === "name"; };
    const members = records.filter(isMember);
    records.filter(r => !isMember(r)).forEach(record => {
      const box = document.createElement("div");
      box.className = "a2r-section";
      const title = document.createElement("div");
      title.className = "a2r-section-title";
      title.textContent = record.name ? (record.status ? `${record.name} (${record.status})` : record.name) : "Ration Card Details";
      box.appendChild(title);
      box.appendChild(buildFieldTable(record.fields || []));
      resultWrap.appendChild(box);
    });
    // ...shown as ONE "NAME" row at the bottom of the Ration Card Details
    // card, every member's name on its own line in the value column (per
    // explicit instruction, 2026-10-04).
    if (members.length) {
      let tbody = resultWrap.querySelector(".a2r-section .a2r-section-table tbody");
      if (!tbody) {
        const box = document.createElement("div");
        box.className = "a2r-section";
        const title = document.createElement("div");
        title.className = "a2r-section-title";
        title.textContent = "Ration Card Details";
        box.appendChild(title);
        box.appendChild(buildFieldTable([]));
        resultWrap.appendChild(box);
        tbody = box.querySelector("tbody");
      }
      const tr = document.createElement("tr");
      tr.innerHTML = `<td class="a2r-field-label"></td><td class="a2r-field-value" style="white-space:pre-line"></td>`;
      tr.querySelector(".a2r-field-label").textContent = "NAME";
      tr.querySelector(".a2r-field-value").textContent = members.map(m => visible(m)[0].value || m.name || "—").join("\n");
      tbody.appendChild(tr);
    }
  }

  if (typeof data.used === "number" && typeof data.limit === "number") {
    updateQuotaBadge(data.used, data.limit);
  }
}

async function runSearch() {
  const aadhaarNumber = numberBox.value.replace(/\D/g, "");
  if (aadhaarNumber.length !== 12) {
    statusEl.textContent = "Enter a valid 12-digit Aadhaar number.";
    return;
  }

  searchBtn.disabled = true;
  statusEl.textContent = "";
  resultWrap.innerHTML = "";
  startProgress();

  try {
    const res = await fetch("aadhaar_to_ration_api.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ aadhaarNumber })
    });
    const data = await res.json();

    if (res.status === 401) {
      window.location.href = data.loginUrl || "login.php";
      return;
    }

    if (!res.ok) {
      stopProgress(null);
      statusEl.textContent = `Error: ${data.error || "could not complete search"}`;
      if (typeof data.used === "number" && typeof data.limit === "number") {
        updateQuotaBadge(data.used, data.limit);
      }
      return;
    }

    stopProgress(data.found ? "Result found" : "No result found");
    renderResult(data);
    if (data.found) if (typeof showAccessGranted === "function") showAccessGranted();   // ACCESS GRANTED stamp (assets/confetti.js)
  } catch (err) {
    stopProgress(null);
    statusEl.textContent = `Could not reach the server: ${err.message}`;
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
  resultWrap.innerHTML = "";
  stopProgress(null);
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

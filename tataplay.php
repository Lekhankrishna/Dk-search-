<?php
require __DIR__ . '/includes/auth.php';
requireTataPlayAccess();
require_once __DIR__ . '/config/db.php';

$basePath = '';
require __DIR__ . '/includes/header.php';
?>

<!-- Confetti overlay - populated/cleared by startConfetti()/stopConfetti()
     (assets/confetti.js), only while a search has actually succeeded. -->
<div class="confetti-container" id="confetti-container"></div>

<div class="page-header" style="display:flex;align-items:center;flex-wrap:wrap;gap:12px">
  <h1 class="page-title" style="margin:0"><i class="bi bi-tv"></i> TATA SKY DTH</h1>
</div>

<style>
  /* Same tokens/shape as rc_print.php/hp_gas.php's cards. */
  .tp-card{background:var(--c-surface,#fff);border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;}
  .tp-card-body{padding:16px 18px;}
  .tp-row{display:flex;align-items:center;gap:12px;margin-top:12px;flex-wrap:wrap;}
  .tp-btn{padding:11px 26px;border-radius:9px;border:none;background:#2e9e3f;color:#fff;
    font-size:12.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;cursor:pointer;
    transition:all 150ms;box-shadow:0 4px 18px rgba(46,158,63,.3);}
  .tp-btn:hover:not(:disabled){background:#257e32;transform:translateY(-1px);box-shadow:0 6px 20px rgba(46,158,63,.45);}
  .tp-btn:disabled{opacity:.65;cursor:wait;transform:none;}
  .tp-btn-secondary{background:#fff;color:#333;border:1px solid #e0e0e0;box-shadow:none;}
  .tp-btn-secondary:hover:not(:disabled){background:#eeeef6;border-color:#2e9e3f;transform:none;box-shadow:none;}
  #tpStatus{font-size:12.5px;color:#555;white-space:pre-wrap;word-break:break-word;font-weight:500;}
  .tp-progress-wrap{margin-top:12px;display:none;}
  .tp-progress-track{height:8px;border-radius:6px;background:#eeeef6;overflow:hidden;border:1px solid #e0e0e0;}
  .tp-progress-fill{height:100%;border-radius:6px;background:#2e9e3f;width:100%;
    background-image:repeating-linear-gradient(45deg,#2e9e3f 0 12px,#257e32 12px 24px);
    background-size:34px 100%;animation:tp-progress-stripes 1s linear infinite;}
  @keyframes tp-progress-stripes{from{background-position:0 0;}to{background-position:-34px 0;}}
  .tp-progress-meta{display:flex;justify-content:space-between;margin-top:6px;font-size:11.5px;color:#999;}
  .tp-result-wrap{margin-top:16px;display:none;}
  .tp-not-found{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);padding:16px;color:#f87171;font-weight:600;}
</style>

<div class="tp-card">
  <div class="tp-card-body">
    <input type="text" id="tpNumberBox" placeholder="9876543210" maxlength="10"
           style="width:100%;padding:11px 16px;font-size:13px;color:#333;border:1px solid #e0e0e0;border-radius:9px;background:#fff;outline:none;">
    <div class="tp-row">
      <button id="tpSearchBtn" class="tp-btn">Search</button>
      <button id="tpClearBtn" class="tp-btn tp-btn-secondary" type="button">Clear</button>
      <span id="tpStatus"></span>
    </div>
    <div class="tp-progress-wrap" id="tpProgressWrap">
      <div class="tp-progress-track"><div class="tp-progress-fill" id="tpProgressFill"></div></div>
      <div class="tp-progress-meta">
        <span id="tpProgressLabel">Logging in and running the search…</span>
        <span id="tpProgressElapsed"></span>
      </div>
    </div>
  </div>
</div>

<div class="tp-result-wrap" id="tpResultWrap"></div>

<script>
const searchBtn      = document.getElementById("tpSearchBtn");
const clearBtn       = document.getElementById("tpClearBtn");
const numberBox      = document.getElementById("tpNumberBox");
const statusEl       = document.getElementById("tpStatus");
const resultWrap     = document.getElementById("tpResultWrap");
const progressWrap   = document.getElementById("tpProgressWrap");
const progressLabel  = document.getElementById("tpProgressLabel");
const progressElapsed= document.getElementById("tpProgressElapsed");

let progressTimer = null;
let searchStartedAt = null;

function formatDuration(seconds) {
  seconds = Math.max(0, Math.round(seconds));
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

// No per-step progress to report - this is one blocking fetch for the whole
// login+search sequence in Gas/lpg_web/tataplay.py, same reasoning as
// hp_gas.php's own indeterminate bar. Typical observed run: ~15-25s (SSO
// login + Siebel PRM tab switch + quick-find).
const ESTIMATED_SECONDS = 20;

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

// Only these fields are shown, in this order - Digicard/set-top-box
// details (Product, Digicard #, Digicomp #, etc.) are dropped entirely per
// explicit instruction (2026-08-23). Each entry pairs the display column
// with the matching key on the account object (see Gas/lpg_web/
// tataplay.py's run_tataplay_single() for the full shape).
const TP_COLUMNS = [
  ["Subscriber Name", "accountName"],
  ["Subscriber Id", "subscriberId"],
  ["Status", "accountStatus"],
  ["Account Type", "accountType"],
  ["Account Category", "accountCategory"],
  ["Account Sub-Category", "accountSubCategory"],
  ["Sales Segment", "salesSegment"],
  ["Address Line 1", "addressLine1"],
  ["Address Line 2", "addressLine2"],
  ["Village/Town/City", "villageTownCity"],
  ["Town", "town"],
  ["District", "district"],
  ["Tahsil", "tahsil"],
  ["State", "state"],
  ["Pin Code", "pinCode"],
  ["Last Recharge Date", "lastRechargeDate"],
];

// A search can genuinely match more than one account for the same mobile
// number (confirmed live 2026-08-19 - a deactivated account and a pending
// one for the same person) - each is a real, distinct account (different
// Subscriber Id/Status), not a fragment of one record, so each gets its
// own row rather than being merged together the way Indane Gas's
// same-person fragments are. Same weighted colgroup sizing as pan_india.php/
// indane_gas_info.php's tables (via .pan-results-table) so long address
// values wrap within their cell instead of forcing a horizontal scroll.
function buildTataPlayTable(accounts) {
  const rows = accounts.map(account => TP_COLUMNS.map(([, key]) => account[key] || "—"));

  const longestToken = value => value.split(/[\s,;]+/).reduce((max, tok) => Math.max(max, tok.length), 0);
  const rawWeights = TP_COLUMNS.map(([label], i) => {
    let maxLen = label.length;
    let maxToken = label.length;
    rows.forEach(r => {
      const v = r[i] === "—" ? "" : r[i];
      maxLen = Math.max(maxLen, v.length);
      maxToken = Math.max(maxToken, longestToken(v));
    });
    return Math.max(Math.sqrt(maxLen) * 5, label.length * 1.5, maxToken * 3.2, 16);
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
  TP_COLUMNS.forEach(([label]) => {
    const th = document.createElement("th");
    th.textContent = label;
    headRow.appendChild(th);
  });
  thead.appendChild(headRow);
  table.appendChild(thead);

  const tbody = document.createElement("tbody");
  rows.forEach(r => {
    const tr = document.createElement("tr");
    r.forEach(value => {
      const td = document.createElement("td");
      td.textContent = value;
      tr.appendChild(td);
    });
    tbody.appendChild(tr);
  });
  table.appendChild(tbody);
  return table;
}

function renderResult(data) {
  resultWrap.innerHTML = "";

  const accounts = (data.found && Array.isArray(data.accounts)) ? data.accounts : [];

  if (!accounts.length) {
    resultWrap.innerHTML = `<div class="tp-not-found">Not found for ${data.mobileNumber}.</div>`;
  } else {
    const wrap = document.createElement("div");
    wrap.className = "results-table-wrap";
    wrap.appendChild(buildTataPlayTable(accounts));
    resultWrap.appendChild(wrap);
  }

  resultWrap.style.display = "block";
  if (accounts.length) startConfetti(); else stopConfetti();
}

async function runSearch() {
  const mobileNumber = numberBox.value.replace(/\D/g, "");
  if (mobileNumber.length !== 10) {
    statusEl.textContent = "Enter a valid 10-digit mobile number.";
    return;
  }

  searchBtn.disabled = true;
  statusEl.textContent = "";
  resultWrap.style.display = "none";
  startProgress();

  try {
    const res = await fetch("tataplay_api.php", {
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
    renderResult(data);
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
  resultWrap.style.display = "none";
  stopProgress(null);
  stopConfetti();
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

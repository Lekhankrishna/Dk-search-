<?php
require __DIR__ . '/includes/auth.php';
requireTataPlayAccess();
require_once __DIR__ . '/config/db.php';

// Same quota-badge pattern as hp_gas.php/rc_print.php.
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$quota = null;
if (!$isAdmin) {
    $stmt = $pdo->prepare('SELECT tata_play_monthly_limit FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $monthlyLimit = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM search_logs WHERE user_id = :id AND search_type = 'tata_play' AND searched_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
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
  <h1 class="page-title" style="margin:0"><i class="bi bi-tv"></i> TATA SKY DTH</h1>
  <?php if ($isAdmin): ?>
    <span id="tpQuotaBadge" class="badge badge-neutral" style="margin-left:auto">Unlimited (Admin)</span>
  <?php elseif ($quota !== null): ?>
    <span id="tpQuotaBadge" class="badge <?= $quota['used'] >= $quota['limit'] ? 'badge-danger' : 'badge-neutral' ?>"
          style="margin-left:auto">
      <?= $quota['limit'] - $quota['used'] > 0 ? $quota['limit'] - $quota['used'] : 0 ?> of <?= $quota['limit'] ?> left this month
    </span>
  <?php endif; ?>
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
  .tp-section{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;}
  .tp-section-title{padding:10px 16px;background:#2e9e3f;color:#fff;font-size:11.5px;font-weight:700;
    text-transform:uppercase;letter-spacing:.4px;}
  .tp-section-table{width:100%;border-collapse:collapse;}
  .tp-section-table tr:nth-child(odd){background:#fff;}
  .tp-section-table tr:nth-child(even){background:#f8f8fc;}
  .tp-section-table td{padding:8px 16px;font-size:12.5px;border-bottom:1px solid #eee;vertical-align:top;}
  .tp-section-table tr:last-child td{border-bottom:none;}
  .tp-field-label{width:38%;color:#777;font-weight:600;}
  .tp-field-value{color:#222;font-weight:500;word-break:break-word;}
  .tp-status-chip{display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;
    text-transform:uppercase;letter-spacing:.3px;background:#eeeef6;color:#555;}
  .tp-status-chip.tp-status-active{background:rgba(16,185,129,.15);color:#0d9668;}
  .tp-status-chip.tp-status-bad{background:rgba(248,113,113,.15);color:#dc2626;}
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
const quotaBadge     = document.getElementById("tpQuotaBadge");
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

function updateQuotaBadge(used, limit) {
  if (!quotaBadge) return;
  const remaining = Math.max(0, limit - used);
  quotaBadge.textContent = `${remaining} of ${limit} left this month`;
  quotaBadge.classList.toggle("badge-danger", used >= limit);
  quotaBadge.classList.toggle("badge-neutral", used < limit);
}

// Account Status values seen live (2026-08-13): Pending, Deactivated,
// Cancelled, Cancel-Pending, WrittenOff - "Pending"/anything containing
// "active" reads as a live-ish account, "cancel"/"written off"/"deactivat"
// as a dead one; anything else stays a neutral grey chip rather than
// guessing at a colour for a status this hasn't seen yet.
function statusChipClass(status) {
  const s = (status || "").toLowerCase();
  if (s.includes("cancel") || s.includes("deactivat") || s.includes("writtenoff") || s.includes("written off")) {
    return "tp-status-bad";
  }
  if (s === "pending" || s.includes("active")) return "tp-status-active";
  return "";
}

function renderResult(data) {
  resultWrap.innerHTML = "";

  if (!data.found) {
    resultWrap.innerHTML = `<div class="tp-not-found">Not found for ${data.mobileNumber}.</div>`;
  } else {
    const box = document.createElement("div");
    box.className = "tp-section";
    const title = document.createElement("div");
    title.className = "tp-section-title";
    title.textContent = "Account";
    box.appendChild(title);

    const table = document.createElement("table");
    table.className = "tp-section-table";
    const tbody = document.createElement("tbody");

    const rows = [
      ["Account Name", data.accountName],
      ["Subscriber Id", data.subscriberId],
      ["Account Status", data.accountStatus],
      ["Address", data.address],
      ["Last Recharge Date", data.lastRechargeDate],
    ];
    rows.forEach(([label, value]) => {
      const tr = document.createElement("tr");
      const labelTd = document.createElement("td");
      labelTd.className = "tp-field-label";
      labelTd.textContent = label;
      const valueTd = document.createElement("td");
      valueTd.className = "tp-field-value";
      if (label === "Account Status") {
        const chip = document.createElement("span");
        chip.className = "tp-status-chip " + statusChipClass(value);
        chip.textContent = value || "—";
        valueTd.appendChild(chip);
      } else {
        valueTd.textContent = value || "—";
      }
      tr.append(labelTd, valueTd);
      tbody.appendChild(tr);
    });

    table.appendChild(tbody);
    box.appendChild(table);
    resultWrap.appendChild(box);
  }

  resultWrap.style.display = "block";
  if (data.found) startConfetti(); else stopConfetti();
  if (typeof data.used === "number" && typeof data.limit === "number") {
    updateQuotaBadge(data.used, data.limit);
  }
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
      if (typeof data.used === "number" && typeof data.limit === "number") {
        updateQuotaBadge(data.used, data.limit);
      }
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

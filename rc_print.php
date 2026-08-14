<?php
require __DIR__ . '/includes/auth.php';
requireRcPrintAccess();
require_once __DIR__ . '/config/db.php';

// Admins bypass the monthly cap entirely (see rc_print_api.php) - shown as
// an explicit "Unlimited" badge below rather than hiding the badge outright,
// so an admin testing the page can tell it's working as intended rather
// than wondering why nothing shows up.
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$quota = null;
if (!$isAdmin) {
    $stmt = $pdo->prepare('SELECT rc_print_monthly_limit FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $monthlyLimit = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM search_logs WHERE user_id = :id AND search_type = 'rc_print' AND searched_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
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
  <h1 class="page-title" style="margin:0"><i class="bi bi-car-front-fill"></i> RC Print</h1>
  <?php if ($isAdmin): ?>
    <span id="rcQuotaBadge" class="badge badge-neutral" style="margin-left:auto">Unlimited (Admin)</span>
  <?php elseif ($quota !== null): ?>
    <span id="rcQuotaBadge" class="badge <?= $quota['used'] >= $quota['limit'] ? 'badge-danger' : 'badge-neutral' ?>"
          style="margin-left:auto"
          data-used="<?= $quota['used'] ?>" data-limit="<?= $quota['limit'] ?>">
      <?= $quota['limit'] - $quota['used'] > 0 ? $quota['limit'] - $quota['used'] : 0 ?> of <?= $quota['limit'] ?> left this month
    </span>
  <?php endif; ?>
</div>

<style>
  /* Same tokens/shape as lpg_search.php's card - a single vehicle lookup
     is one result, not a results table, so no lpg-table styling needed here. */
  .rc-card{background:var(--c-surface,#fff);border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;}
  .rc-card-body{padding:16px 18px;}
  .rc-row{display:flex;align-items:center;gap:12px;margin-top:12px;flex-wrap:wrap;}
  .rc-btn{padding:11px 26px;border-radius:9px;border:none;background:#2e9e3f;color:#fff;
    font-size:12.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;cursor:pointer;
    transition:all 150ms;box-shadow:0 4px 18px rgba(46,158,63,.3);}
  .rc-btn:hover:not(:disabled){background:#257e32;transform:translateY(-1px);box-shadow:0 6px 20px rgba(46,158,63,.45);}
  .rc-btn:disabled{opacity:.65;cursor:wait;transform:none;}
  .rc-btn-secondary{background:#fff;color:#333;border:1px solid #e0e0e0;box-shadow:none;}
  .rc-btn-secondary:hover:not(:disabled){background:#eeeef6;border-color:#2e9e3f;transform:none;box-shadow:none;}
  #rcStatus{font-size:12.5px;color:#555;white-space:pre-wrap;word-break:break-word;font-weight:500;}
  .rc-progress-wrap{margin-top:12px;display:none;}
  .rc-progress-track{height:8px;border-radius:6px;background:#eeeef6;overflow:hidden;border:1px solid #e0e0e0;}
  .rc-progress-fill{height:100%;border-radius:6px;background:#2e9e3f;width:100%;
    background-image:repeating-linear-gradient(45deg,#2e9e3f 0 12px,#257e32 12px 24px);
    background-size:34px 100%;animation:rc-progress-stripes 1s linear infinite;}
  @keyframes rc-progress-stripes{from{background-position:0 0;}to{background-position:-34px 0;}}
  .rc-progress-meta{display:flex;justify-content:space-between;margin-top:6px;font-size:11.5px;color:#999;}
  .rc-result-wrap{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);margin-top:16px;overflow:hidden;display:none;}
  .rc-result-toolbar{display:flex;align-items:center;justify-content:space-between;padding:10px 16px;border-bottom:1px solid #e0e0e0;background:#eeeef6;}
  .rc-result-toolbar .count{font-size:12px;color:#555;font-weight:600;}
  .rc-pdf-frame{width:100%;height:80vh;border:none;display:block;}
</style>

<div class="rc-card">
  <div class="rc-card-body">
    <input type="text" id="rcNumberBox" placeholder="KA01AB1234"
           style="width:100%;padding:11px 16px;font-size:13px;color:#333;border:1px solid #e0e0e0;border-radius:9px;background:#fff;outline:none;text-transform:uppercase;">
    <div class="rc-row">
      <button id="rcSearchBtn" class="rc-btn">Search</button>
      <button id="rcClearBtn" class="rc-btn rc-btn-secondary" type="button">Clear</button>
      <span id="rcStatus"></span>
    </div>
    <div class="rc-progress-wrap" id="rcProgressWrap">
      <div class="rc-progress-track"><div class="rc-progress-fill" id="rcProgressFill"></div></div>
      <div class="rc-progress-meta">
        <span id="rcProgressLabel">Logging in and generating the PDF…</span>
        <span id="rcProgressElapsed"></span>
      </div>
    </div>
  </div>
</div>

<div class="rc-result-wrap" id="rcResultWrap">
  <div class="rc-result-toolbar">
    <span class="count" id="rcResultCount"></span>
    <button id="rcDownloadBtn" class="rc-btn rc-btn-secondary" style="padding:7px 16px;font-size:11px" type="button">⬇ Download PDF</button>
  </div>
  <iframe class="rc-pdf-frame" id="rcPdfFrame" title="RC PDF Preview"></iframe>
</div>

<script>
const searchBtn    = document.getElementById("rcSearchBtn");
const clearBtn     = document.getElementById("rcClearBtn");
const numberBox    = document.getElementById("rcNumberBox");
const statusEl     = document.getElementById("rcStatus");
const resultWrap   = document.getElementById("rcResultWrap");
const resultCount  = document.getElementById("rcResultCount");
const pdfFrame     = document.getElementById("rcPdfFrame");
const downloadBtn  = document.getElementById("rcDownloadBtn");
const quotaBadge   = document.getElementById("rcQuotaBadge");
const progressWrap    = document.getElementById("rcProgressWrap");
const progressLabel   = document.getElementById("rcProgressLabel");
const progressElapsed = document.getElementById("rcProgressElapsed");

let lastPdfDataUri = null;
let lastVehicleNumber = null;
let progressTimer = null;
let searchStartedAt = null;

function formatDuration(seconds) {
  seconds = Math.max(0, Math.round(seconds));
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

// No per-step progress to report (unlike LPG's job-based polling) - this is
// one blocking fetch for the whole login+generate sequence in
// Gas/lpg_web/rc_print.py, so the bar itself is always indeterminate
// (striped, animating). The countdown is a fixed estimate (typical
// observed run: ~25-30s - browser launch + locateme.services login +
// their own PDF generation), not anything server-reported, so it's a
// rough "about this long" rather than a real per-step progress figure -
// same idea as LPG's own "~Xs remaining", just without real done/total
// numbers to base it on for a single one-shot search.
const ESTIMATED_SECONDS = 30;

function startProgress() {
  searchStartedAt = Date.now();
  progressLabel.textContent = "Logging in and generating the PDF…";
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

// Absent for admins (no cap to show) - guard every call site rather than
// assuming the element exists.
function updateQuotaBadge(used, limit) {
  if (!quotaBadge) return;
  const remaining = Math.max(0, limit - used);
  quotaBadge.textContent = `${remaining} of ${limit} left this month`;
  quotaBadge.classList.toggle("badge-danger", used >= limit);
  quotaBadge.classList.toggle("badge-neutral", used < limit);
}

function renderResult(data) {
  lastPdfDataUri = data.pdfDataUri;
  lastVehicleNumber = data.vehicleNumber || numberBox.value;
  pdfFrame.src = data.pdfDataUri + "#toolbar=0&navpanes=0";
  resultCount.textContent = lastVehicleNumber;
  resultWrap.style.display = "block";
  startConfetti();
  if (typeof data.used === "number" && typeof data.limit === "number") {
    updateQuotaBadge(data.used, data.limit);
  }
}

downloadBtn.addEventListener("click", () => {
  if (!lastPdfDataUri) return;
  const a = document.createElement("a");
  a.href = lastPdfDataUri;
  a.download = `RC_${lastVehicleNumber}.pdf`;
  document.body.appendChild(a);
  a.click();
  a.remove();
});

async function runSearch() {
  const vehicleNumber = numberBox.value.replace(/[^A-Za-z0-9]/g, "").toUpperCase();
  if (!vehicleNumber) {
    statusEl.textContent = "Enter a vehicle registration number.";
    return;
  }

  searchBtn.disabled = true;
  statusEl.textContent = "";
  resultWrap.style.display = "none";
  startProgress();

  try {
    const res = await fetch("rc_print_api.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ vehicleNumber })
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

    stopProgress("PDF ready");
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
  pdfFrame.src = "";
  stopProgress(null);
  stopConfetti();
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

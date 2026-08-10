<?php
require __DIR__ . '/includes/auth.php';
requireHpGasAccess();
require_once __DIR__ . '/config/db.php';

// Same quota-badge pattern as rc_print.php.
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$quota = null;
if (!$isAdmin) {
    $stmt = $pdo->prepare('SELECT hp_gas_monthly_limit FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $monthlyLimit = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM search_logs WHERE user_id = :id AND search_type = 'hp_gas' AND searched_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
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
  <h1 class="page-title" style="margin:0"><i class="bi bi-fire"></i> HP LPG Search</h1>
  <?php if ($isAdmin): ?>
    <span id="hpGasQuotaBadge" class="badge badge-neutral" style="margin-left:auto">Unlimited (Admin)</span>
  <?php elseif ($quota !== null): ?>
    <span id="hpGasQuotaBadge" class="badge <?= $quota['used'] >= $quota['limit'] ? 'badge-danger' : 'badge-neutral' ?>"
          style="margin-left:auto">
      <?= $quota['limit'] - $quota['used'] > 0 ? $quota['limit'] - $quota['used'] : 0 ?> of <?= $quota['limit'] ?> left this month
    </span>
  <?php endif; ?>
</div>

<style>
  /* Same tokens/shape as rc_print.php's card. */
  .hp-card{background:var(--c-surface,#fff);border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;}
  .hp-card-body{padding:16px 18px;}
  .hp-row{display:flex;align-items:center;gap:12px;margin-top:12px;flex-wrap:wrap;}
  .hp-btn{padding:11px 26px;border-radius:9px;border:none;background:#4f46e5;color:#fff;
    font-size:12.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;cursor:pointer;
    transition:all 150ms;box-shadow:0 4px 18px rgba(79,70,229,.3);}
  .hp-btn:hover:not(:disabled){background:#4338ca;transform:translateY(-1px);box-shadow:0 6px 20px rgba(79,70,229,.45);}
  .hp-btn:disabled{opacity:.65;cursor:wait;transform:none;}
  .hp-btn-secondary{background:#fff;color:#333;border:1px solid #e0e0e0;box-shadow:none;}
  .hp-btn-secondary:hover:not(:disabled){background:#eeeef6;border-color:#4f46e5;transform:none;box-shadow:none;}
  #hpStatus{font-size:12.5px;color:#555;white-space:pre-wrap;word-break:break-word;font-weight:500;}
  .hp-progress-wrap{margin-top:12px;display:none;}
  .hp-progress-track{height:8px;border-radius:6px;background:#eeeef6;overflow:hidden;border:1px solid #e0e0e0;}
  .hp-progress-fill{height:100%;border-radius:6px;background:#4f46e5;width:100%;
    background-image:repeating-linear-gradient(45deg,#4f46e5 0 12px,#4338ca 12px 24px);
    background-size:34px 100%;animation:hp-progress-stripes 1s linear infinite;}
  @keyframes hp-progress-stripes{from{background-position:0 0;}to{background-position:-34px 0;}}
  .hp-progress-meta{display:flex;justify-content:space-between;margin-top:6px;font-size:11.5px;color:#999;}
  .hp-result-wrap{margin-top:16px;display:none;}
  .hp-section{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;margin-bottom:14px;}
  .hp-section-title{padding:10px 16px;background:#4f46e5;color:#fff;font-size:11.5px;font-weight:700;
    text-transform:uppercase;letter-spacing:.4px;}
  .hp-section-table{width:100%;border-collapse:collapse;}
  .hp-section-table tr:nth-child(odd){background:#fff;}
  .hp-section-table tr:nth-child(even){background:#f8f8fc;}
  .hp-section-table td{padding:8px 16px;font-size:12.5px;border-bottom:1px solid #eee;vertical-align:top;}
  .hp-section-table tr:last-child td{border-bottom:none;}
  .hp-field-label{width:38%;color:#777;font-weight:600;text-transform:capitalize;}
  .hp-field-value{color:#222;font-weight:500;word-break:break-word;}
  .hp-not-found{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);padding:16px;color:#f87171;font-weight:600;}
</style>

<div class="hp-card">
  <div class="hp-card-body">
    <input type="text" id="hpNumberBox" placeholder="9876543210" maxlength="10"
           style="width:100%;padding:11px 16px;font-size:13px;color:#333;border:1px solid #e0e0e0;border-radius:9px;background:#fff;outline:none;">
    <div class="hp-row">
      <button id="hpSearchBtn" class="hp-btn">Search</button>
      <button id="hpClearBtn" class="hp-btn hp-btn-secondary" type="button">Clear</button>
      <span id="hpStatus"></span>
    </div>
    <div class="hp-progress-wrap" id="hpProgressWrap">
      <div class="hp-progress-track"><div class="hp-progress-fill" id="hpProgressFill"></div></div>
      <div class="hp-progress-meta">
        <span id="hpProgressLabel">Logging in and running the search…</span>
        <span id="hpProgressElapsed"></span>
      </div>
    </div>
  </div>
</div>

<div class="hp-result-wrap" id="hpResultWrap"></div>

<script>
const searchBtn      = document.getElementById("hpSearchBtn");
const clearBtn       = document.getElementById("hpClearBtn");
const numberBox      = document.getElementById("hpNumberBox");
const statusEl       = document.getElementById("hpStatus");
const resultWrap     = document.getElementById("hpResultWrap");
const quotaBadge     = document.getElementById("hpGasQuotaBadge");
const progressWrap   = document.getElementById("hpProgressWrap");
const progressLabel  = document.getElementById("hpProgressLabel");
const progressElapsed= document.getElementById("hpProgressElapsed");

let progressTimer = null;
let searchStartedAt = null;

function formatDuration(seconds) {
  seconds = Math.max(0, Math.round(seconds));
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

// There's no per-step progress to report here (unlike LPG's job-based
// polling) - this is one blocking fetch for the whole login+search+scrape
// sequence in Gas/lpg_web/hp_gas.py, so the bar itself is always
// indeterminate (striped, animating). The countdown is a fixed estimate
// (typical observed run: ~20-30s), not anything server-reported - same
// idea as LPG's own "~Xs remaining", just without real done/total numbers
// to base it on for a single one-shot search.
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

// Sections come from hp_gas.py scraping locateme.services' own result cards
// generically (label/value pairs grouped under section headers like
// "Consumer Details", "Bank & LPG Linkage") - rendered as-is here rather
// than assuming fixed field names, since whatever sections/fields
// locateme.services shows for a given number is what gets displayed.
function renderResult(data) {
  resultWrap.innerHTML = "";

  if (!data.found) {
    resultWrap.innerHTML = `<div class="hp-not-found">Not found for ${data.mobileNumber}.</div>`;
  } else if (Array.isArray(data.sections) && data.sections.length) {
    data.sections.forEach(section => {
      const box = document.createElement("div");
      box.className = "hp-section";
      const title = document.createElement("div");
      title.className = "hp-section-title";
      title.textContent = section.title;
      box.appendChild(title);

      const table = document.createElement("table");
      table.className = "hp-section-table";
      const tbody = document.createElement("tbody");
      section.fields.forEach(field => {
        const tr = document.createElement("tr");
        tr.innerHTML = `<td class="hp-field-label"></td><td class="hp-field-value"></td>`;
        tr.querySelector(".hp-field-label").textContent = field.label;
        tr.querySelector(".hp-field-value").textContent = field.value || "—";
        tbody.appendChild(tr);
      });
      table.appendChild(tbody);
      box.appendChild(table);
      resultWrap.appendChild(box);
    });
  } else {
    // Fallback if locateme.services' DOM structure ever changes and
    // hp_gas.py couldn't extract labeled sections - see hp_gas.py.
    const box = document.createElement("div");
    box.className = "hp-section";
    box.innerHTML = `<div class="hp-section-title">Result for ${data.mobileNumber}</div>
      <div style="padding:16px;font-family:monospace;font-size:12px;white-space:pre-wrap;word-break:break-word;"></div>`;
    box.querySelector("div:last-child").textContent = data.rawText || "(no details captured)";
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
    const res = await fetch("hp_gas_api.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ mobileNumber })
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

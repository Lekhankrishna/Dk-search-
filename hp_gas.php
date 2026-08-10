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
  .hp-hint{color:#999;margin:0 0 14px;font-size:13px;}
  /* Mode toggle - which one is active swaps the input below between a
     single-line box and the multi-number textarea (see updateMode() JS),
     same pattern as lpg_search.php. */
  .hp-mode-toggle{display:flex;gap:8px;margin-bottom:12px;}
  .hp-mode-btn{padding:9px 18px;border-radius:999px;border:1px solid #e0e0e0;background:#fff;
    color:#555;font-size:12.5px;font-weight:600;cursor:pointer;transition:all 150ms;}
  .hp-mode-btn:hover{border-color:#4f46e5;color:#4f46e5;}
  .hp-mode-btn.active{background:#4f46e5;border-color:#4f46e5;color:#fff;box-shadow:0 4px 14px rgba(79,70,229,.35);}
  .hp-input{width:100%;padding:11px 16px;font-size:13px;color:#333;
    border:1px solid #e0e0e0;border-radius:9px;background:#fff;outline:none;}
  .hp-input:focus{border-color:#4f46e5;box-shadow:0 0 0 3px rgba(79,70,229,.25);}
  .hp-textarea{width:100%;height:110px;padding:9px 14px;font-size:13px;color:#333;
    border:1px solid #e0e0e0;border-radius:9px;background:#fff;resize:vertical;outline:none;}
  .hp-textarea:focus{border-color:#4f46e5;box-shadow:0 0 0 3px rgba(79,70,229,.25);}
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
  .hp-progress-fill{height:100%;border-radius:6px;background:#4f46e5;width:0%;transition:width .4s ease;}
  .hp-progress-fill.indeterminate{width:100%;
    background:repeating-linear-gradient(45deg,#4f46e5 0 12px,#4338ca 12px 24px);
    background-size:34px 100%;animation:hp-progress-stripes 1s linear infinite;}
  @keyframes hp-progress-stripes{from{background-position:0 0;}to{background-position:-34px 0;}}
  .hp-progress-meta{display:flex;justify-content:space-between;margin-top:6px;font-size:11.5px;color:#999;}
  .hp-result-wrap{margin-top:16px;display:none;}
  .hp-record{margin-bottom:22px;}
  .hp-record-heading{display:flex;align-items:center;gap:10px;margin-bottom:8px;}
  .hp-record-number{font-family:'Consolas','Cascadia Code','Courier New',monospace;font-size:13.5px;font-weight:700;color:#333;}
  .hp-record-badge{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;padding:3px 9px;border-radius:999px;}
  .hp-badge-hit{background:rgba(16,185,129,.15);color:#0d9668;border:1px solid rgba(16,185,129,.35);}
  .hp-badge-miss{background:rgba(153,153,153,.15);color:#777;border:1px solid rgba(153,153,153,.3);}
  .hp-badge-error{background:rgba(248,113,113,.15);color:#f87171;border:1px solid rgba(248,113,113,.35);}
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
    <div class="hp-mode-toggle" role="tablist">
      <button type="button" class="hp-mode-btn active" data-mode="single">Single Search</button>
      <button type="button" class="hp-mode-btn" data-mode="bulk">Bulk Search</button>
    </div>
    <input type="text" id="hpSingleBox" class="hp-input" placeholder="9876543210" maxlength="10">
    <textarea id="hpNumbersBox" class="hp-textarea" placeholder="9876543210, 9876543211, ..." style="display:none"></textarea>
    <p class="hp-hint" id="hpBulkHint" style="display:none;margin-top:10px">Runs every number in the box, up to 10 at a time - each one counts separately against your monthly limit.</p>
    <div class="hp-row">
      <button id="hpSearchBtn" class="hp-btn">Search</button>
      <button id="hpClearBtn" class="hp-btn hp-btn-secondary" type="button">Clear</button>
      <span id="hpStatus"></span>
    </div>
    <div class="hp-progress-wrap" id="hpProgressWrap">
      <div class="hp-progress-track"><div class="hp-progress-fill" id="hpProgressFill"></div></div>
      <div class="hp-progress-meta">
        <span id="hpProgressLabel"></span>
        <span id="hpProgressEta"></span>
      </div>
    </div>
  </div>
</div>

<div class="hp-result-wrap" id="hpResultWrap"></div>

<script>
// Flat cap for everyone - not a technical ceiling, a deliberate usage cap
// (each number also counts on its own against the monthly credit limit).
const BULK_NUMBER_LIMIT = 10;

const searchBtn      = document.getElementById("hpSearchBtn");
const clearBtn       = document.getElementById("hpClearBtn");
const singleBox      = document.getElementById("hpSingleBox");
const numbersBox     = document.getElementById("hpNumbersBox");
const bulkHint       = document.getElementById("hpBulkHint");
const modeButtons    = document.querySelectorAll(".hp-mode-btn");
const statusEl       = document.getElementById("hpStatus");
const resultWrap     = document.getElementById("hpResultWrap");
const quotaBadge     = document.getElementById("hpGasQuotaBadge");
const progressWrap   = document.getElementById("hpProgressWrap");
const progressFill   = document.getElementById("hpProgressFill");
const progressLabel  = document.getElementById("hpProgressLabel");
const progressEta    = document.getElementById("hpProgressEta");

let activeMode = "single";
let pollTimer = null;
let searchStartedAt = null;

// Swaps the input below the toggle - single-line box for one number, the
// textarea for many - same pattern as lpg_search.php.
function updateMode(mode) {
  activeMode = mode;
  modeButtons.forEach(b => b.classList.toggle("active", b.dataset.mode === mode));
  const isBulk = mode === "bulk";
  singleBox.style.display = isBulk ? "none" : "block";
  numbersBox.style.display = isBulk ? "block" : "none";
  bulkHint.style.display = isBulk ? "block" : "none";
  statusEl.textContent = "";
}
modeButtons.forEach(btn => btn.addEventListener("click", () => updateMode(btn.dataset.mode)));

function formatDuration(seconds) {
  seconds = Math.max(0, Math.round(seconds));
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

// Real done/total progress from the job queue (2026-08-11) - replaces the
// old fixed ~25s estimate that the single-blocking-request version had to
// use since it had no way to report incremental progress.
function updateProgress(done, total, status) {
  if (!total || (status !== "processing" && status !== "queued" && done === 0)) {
    progressWrap.style.display = "none";
    return;
  }
  progressWrap.style.display = "block";
  const pct = Math.round((done / total) * 100);
  const elapsed = searchStartedAt ? (Date.now() - searchStartedAt) / 1000 : 0;

  if (done === 0) {
    progressFill.classList.add("indeterminate");
    progressFill.style.width = "100%";
    progressEta.textContent = "Estimating time…";
  } else {
    progressFill.classList.remove("indeterminate");
    progressFill.style.width = pct + "%";
    if (done < total) {
      const avgPerItem = elapsed / done;
      progressEta.textContent = `~${formatDuration(avgPerItem * (total - done))} remaining`;
    } else {
      progressEta.textContent = `Done in ${formatDuration(elapsed)}`;
    }
  }
  progressLabel.textContent = `${done} / ${total} searched`;
}

function updateQuotaBadge(used, limit) {
  if (!quotaBadge) return;
  const remaining = Math.max(0, limit - used);
  quotaBadge.textContent = `${remaining} of ${limit} left this month`;
  quotaBadge.classList.toggle("badge-danger", used >= limit);
  quotaBadge.classList.toggle("badge-neutral", used < limit);
}

// Same number-parsing as lpg_search.php's parseNumbers() - merges
// "XXXXX XXXXX"-formatted numbers back into one 10-digit number and strips
// stray punctuation, instead of splitting on the internal space.
function parseNumbers(raw) {
  const tokens = raw
    .split(/[\s,]+/)
    .map(s => s.trim())
    .filter(s => s.length > 0);

  const merged = [];
  for (let i = 0; i < tokens.length; i++) {
    const cur = tokens[i].replace(/\D+/g, "");
    const next = tokens[i + 1] ? tokens[i + 1].replace(/\D+/g, "") : "";
    if (cur.length === 5 && next.length === 5) {
      merged.push(cur + next);
      i++;
    } else if (cur.length > 0) {
      merged.push(cur);
    }
  }

  return merged.slice(0, BULK_NUMBER_LIMIT);
}

// Sections come from hp_gas.py scraping locateme.services' own result cards
// generically (label/value pairs grouped under section headers like
// "Consumer Details", "Bank & LPG Linkage") - rendered as-is here rather
// than assuming fixed field names. Re-renders the full current results list
// on every poll (same as lpg_search.php's renderResults()), one block per
// number searched so far.
function renderResults(results) {
  resultWrap.innerHTML = "";

  if (!results.length) {
    resultWrap.style.display = "none";
    return;
  }

  results.forEach(record => {
    const rec = document.createElement("div");
    rec.className = "hp-record";

    const heading = document.createElement("div");
    heading.className = "hp-record-heading";
    const numberSpan = document.createElement("span");
    numberSpan.className = "hp-record-number";
    numberSpan.textContent = record.mobileNumber;
    const badge = document.createElement("span");
    badge.className = "hp-record-badge " + (record.error ? "hp-badge-error" : record.found ? "hp-badge-hit" : "hp-badge-miss");
    badge.textContent = record.error ? "Search failed" : record.found ? "Found" : "Not found";
    heading.appendChild(numberSpan);
    heading.appendChild(badge);
    rec.appendChild(heading);

    if (record.error) {
      const box = document.createElement("div");
      box.className = "hp-not-found";
      box.textContent = `Could not complete this search - it will need to be run again.`;
      rec.appendChild(box);
    } else if (!record.found) {
      const box = document.createElement("div");
      box.className = "hp-not-found";
      box.textContent = `Not found for ${record.mobileNumber}.`;
      rec.appendChild(box);
    } else if (Array.isArray(record.sections) && record.sections.length) {
      record.sections.forEach(section => {
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
        rec.appendChild(box);
      });
    } else {
      // Fallback if locateme.services' DOM structure ever changes and
      // hp_gas.py couldn't extract labeled sections - see hp_gas.py.
      const box = document.createElement("div");
      box.className = "hp-section";
      box.innerHTML = `<div class="hp-section-title">Result for ${record.mobileNumber}</div>
        <div style="padding:16px;font-family:monospace;font-size:12px;white-space:pre-wrap;word-break:break-word;"></div>`;
      box.querySelector("div:last-child").textContent = record.rawText || "(no details captured)";
      rec.appendChild(box);
    }

    resultWrap.appendChild(rec);
  });

  resultWrap.style.display = "block";
}

function poll(jobId) {
  pollTimer = setInterval(async () => {
    try {
      const res = await fetch(`hp_gas_api.php?action=status&jobId=${jobId}`);
      const data = await res.json();
      if (res.status === 401) {
        clearInterval(pollTimer);
        window.location.href = data.loginUrl || "login.php";
        return;
      }
      if (!res.ok) throw new Error(data.error || `server returned ${res.status}`);

      statusEl.textContent = data.status === "queued"
        ? (data.queuePosition > 0
            ? `Waiting in line - ${data.queuePosition} search${data.queuePosition === 1 ? "" : "es"} ahead of you…`
            : "Next in line - starting shortly…")
        : `Status: ${data.status} (${data.done}/${data.total})`;
      renderResults(data.results);
      updateProgress(data.done, data.total, data.status);
      if (typeof data.used === "number" && typeof data.limit === "number") {
        updateQuotaBadge(data.used, data.limit);
      }

      if (data.status === "completed" || data.status === "failed") {
        clearInterval(pollTimer);
        searchBtn.disabled = false;
        if (data.status === "failed") {
          statusEl.textContent = `Failed: ${data.error || "unknown error"}`;
          progressWrap.style.display = "none";
          stopConfetti();
        } else if (data.results.some(r => r.found)) {
          startConfetti();
        } else {
          stopConfetti();
        }
      }
    } catch (pollErr) {
      clearInterval(pollTimer);
      statusEl.textContent = `Lost connection while checking status: ${pollErr.message}`;
      searchBtn.disabled = false;
      progressWrap.style.display = "none";
    }
  }, 2000);
}

async function runSearch(numbers) {
  searchBtn.disabled = true;
  statusEl.textContent = "Starting search...";
  renderResults([]);
  searchStartedAt = Date.now();
  updateProgress(0, numbers.length, "processing");

  try {
    const res = await fetch("hp_gas_api.php?action=start", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ numbers })
    });

    const data = await res.json();
    if (res.status === 401) {
      window.location.href = data.loginUrl || "login.php";
      return;
    }
    if (!res.ok) {
      statusEl.textContent = `Error: ${data.error || "could not start search"}`;
      if (typeof data.used === "number" && typeof data.limit === "number") {
        updateQuotaBadge(data.used, data.limit);
      }
      searchBtn.disabled = false;
      progressWrap.style.display = "none";
      return;
    }

    statusEl.textContent = `Status: processing (0/${numbers.length})`;
    poll(data.jobId);
  } catch (err) {
    statusEl.textContent = `Could not reach the server: ${err.message}`;
    searchBtn.disabled = false;
    progressWrap.style.display = "none";
  }
}

// One button, dispatched by whichever mode the toggle above is on - single
// mode reads the plain input (only ever one number to parse), bulk mode
// reads the textarea and keeps up to BULK_NUMBER_LIMIT.
searchBtn.addEventListener("click", () => {
  const numbers = activeMode === "single"
    ? parseNumbers(singleBox.value).slice(0, 1)
    : parseNumbers(numbersBox.value);

  if (numbers.length === 0) {
    statusEl.textContent = activeMode === "single" ? "Enter a mobile number." : "Enter at least one mobile number.";
    return;
  }
  runSearch(numbers);
});

singleBox.addEventListener("keydown", (e) => {
  if (e.key === "Enter" && !searchBtn.disabled) searchBtn.click();
});

clearBtn.addEventListener("click", () => {
  if (pollTimer) clearInterval(pollTimer);
  singleBox.value = "";
  numbersBox.value = "";
  statusEl.textContent = "";
  renderResults([]);
  progressWrap.style.display = "none";
  searchBtn.disabled = false;
  stopConfetti();
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

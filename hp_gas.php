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
  .hp-btn{padding:11px 26px;border-radius:9px;border:none;background:#2e9e3f;color:#fff;
    font-size:12.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;cursor:pointer;
    transition:all 150ms;box-shadow:0 4px 18px rgba(46,158,63,.3);}
  .hp-btn:hover:not(:disabled){background:#257e32;transform:translateY(-1px);box-shadow:0 6px 20px rgba(46,158,63,.45);}
  .hp-btn:disabled{opacity:.65;cursor:wait;transform:none;}
  .hp-btn-secondary{background:#fff;color:#333;border:1px solid #e0e0e0;box-shadow:none;}
  .hp-btn-secondary:hover:not(:disabled){background:#eeeef6;border-color:#2e9e3f;transform:none;box-shadow:none;}
  #hpStatus{font-size:12.5px;color:#555;white-space:pre-wrap;word-break:break-word;font-weight:500;}
  .hp-progress-wrap{margin-top:12px;display:none;}
  .hp-progress-track{height:8px;border-radius:6px;background:#eeeef6;overflow:hidden;border:1px solid #e0e0e0;}
  .hp-progress-fill{height:100%;border-radius:6px;background:#2e9e3f;width:100%;
    background-image:repeating-linear-gradient(45deg,#2e9e3f 0 12px,#257e32 12px 24px);
    background-size:34px 100%;animation:hp-progress-stripes 1s linear infinite;}
  @keyframes hp-progress-stripes{from{background-position:0 0;}to{background-position:-34px 0;}}
  .hp-progress-meta{display:flex;justify-content:space-between;margin-top:6px;font-size:11.5px;color:#999;}
  .hp-result-wrap{margin-top:16px;display:none;}
  .hp-section{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;margin-bottom:14px;}
  .hp-section-title{padding:10px 16px;background:#2e9e3f;color:#fff;font-size:11.5px;font-weight:700;
    text-transform:uppercase;letter-spacing:.4px;}
  .hp-section-table{width:100%;border-collapse:collapse;}
  .hp-section-table tr:nth-child(odd){background:#fff;}
  .hp-section-table tr:nth-child(even){background:#f8f8fc;}
  .hp-section-table td{padding:8px 16px;font-size:12.5px;border-bottom:1px solid #eee;vertical-align:top;}
  .hp-section-table tr:last-child td{border-bottom:none;}
  .hp-field-label{width:38%;color:#777;font-weight:600;text-transform:capitalize;}
  .hp-field-value{color:#222;font-weight:500;word-break:break-word;}
  .hp-not-found{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);padding:16px;color:#f87171;font-weight:600;}

  /* Bulk Search - mode tabs match advanced_search.php's .as-tabs/.as-tab
     exactly. */
  .hp-tabs{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;}
  .hp-tab{display:inline-flex;align-items:center;gap:8px;padding:11px 18px;border-radius:999px;
    border:1px solid #e2e2ea;background:#fff;font-size:12.5px;font-weight:600;color:#555;
    cursor:pointer;transition:all 150ms;white-space:nowrap;}
  .hp-tab i{font-size:14px;}
  .hp-tab:hover{border-color:#2e9e3f;color:#2e9e3f;}
  .hp-tab.active{background:#2e9e3f;border-color:#2e9e3f;color:#fff;box-shadow:0 4px 14px rgba(46,158,63,.35);}
  .hp-textarea{width:100%;height:110px;padding:9px 14px;font-size:13px;color:#333;
    border:1px solid #e0e0e0;border-radius:9px;background:#fff;resize:vertical;outline:none;}
  .hp-textarea:focus{border-color:#2e9e3f;box-shadow:0 0 0 3px rgba(46,158,63,.25);}
  /* Overrides the single-search bar's always-indeterminate stripes with a
     real done/total percentage, same as lpg_bulk_search.php's .lpg-progress-fill. */
  .hp-progress-fill.determinate{background-image:none;animation:none;background:#2e9e3f;
    width:0%;transition:width .3s ease;}
  .hp-bulk-item{margin-bottom:16px;}
  .hp-bulk-item-header{display:flex;align-items:center;gap:10px;padding:10px 16px;background:#eeeef6;
    border-radius:10px 10px 0 0;font-size:12.5px;font-weight:700;color:#333;
    border:1px solid #e0e0e0;border-bottom:none;}
  .hp-bulk-badge{margin-left:auto;font-size:10.5px;padding:2px 10px;border-radius:999px;
    font-weight:700;text-transform:uppercase;letter-spacing:.3px;background:#e0e0ea;color:#555;}
  .hp-bulk-badge-found{background:rgba(16,185,129,.15);color:#0d9668;}
  .hp-bulk-badge-notfound,.hp-bulk-badge-error{background:rgba(248,113,113,.15);color:#dc2626;}
  .hp-bulk-item .hp-section{margin-bottom:0;border-radius:0;box-shadow:none;border:1px solid #e0e0e0;border-top:none;}
  .hp-bulk-item .hp-not-found{border-radius:0;box-shadow:none;border:1px solid #e0e0e0;border-top:none;}
  .hp-bulk-item .hp-section:last-child,.hp-bulk-item .hp-not-found{border-radius:0 0 10px 10px;}
</style>

<div class="hp-tabs">
  <button type="button" class="hp-tab active" id="hpTabSingle"><i class="bi bi-search"></i> Single Search</button>
  <button type="button" class="hp-tab" id="hpTabBulk"><i class="bi bi-list-ol"></i> Bulk Search</button>
</div>

<div id="hpSingleMode">
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
</div>

<!-- Bulk Search: sequential, one number at a time, over the same
     hp_gas_api.php single-search endpoint - see the JS below for why this
     isn't parallelized. Available to every agent (not admin-only), capped
     lower than Single Search's per-search monthly limit would otherwise
     allow in one batch - see BULK_LIMIT below. -->
<div id="hpBulkMode" style="display:none">
  <div class="hp-card">
    <div class="hp-card-body">
      <textarea id="hpBulkNumbersBox" class="hp-textarea" placeholder="9876543210, 9876543211, ..."></textarea>
      <div class="hp-row">
        <button id="hpBulkSearchBtn" class="hp-btn">Bulk Search</button>
        <button id="hpBulkClearBtn" class="hp-btn hp-btn-secondary" type="button">Clear</button>
        <button id="hpBulkExportBtn" class="hp-btn hp-btn-secondary" type="button" disabled>
          <i class="bi bi-file-earmark-excel"></i> Export CSV
        </button>
        <span id="hpBulkStatus"></span>
      </div>
      <div class="hp-progress-wrap" id="hpBulkProgressWrap">
        <div class="hp-progress-track"><div class="hp-progress-fill determinate" id="hpBulkProgressFill"></div></div>
        <div class="hp-progress-meta">
          <span id="hpBulkProgressLabel"></span>
          <span id="hpBulkProgressEta"></span>
        </div>
      </div>
    </div>
  </div>
  <div id="hpBulkResultsWrap" style="margin-top:16px"></div>
</div>

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
// Takes a target container so Bulk Search (below) can render one of these
// per number instead of duplicating this markup logic.
function renderResultSections(container, data) {
  container.innerHTML = "";

  if (!data.found) {
    container.innerHTML = `<div class="hp-not-found">Not found for ${data.mobileNumber}.</div>`;
  } else if (Array.isArray(data.sections) && data.sections.length) {
    // "Bank & LPG Linkage" hidden from the UI per explicit instruction -
    // still scraped/present in data.sections (hp_gas.py stays generic), just
    // filtered out here rather than in the scraper.
    data.sections.filter(section => !/bank/i.test(section.title)).forEach(section => {
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
      container.appendChild(box);
    });
  } else {
    // Fallback if locateme.services' DOM structure ever changes and
    // hp_gas.py couldn't extract labeled sections - see hp_gas.py.
    const box = document.createElement("div");
    box.className = "hp-section";
    box.innerHTML = `<div class="hp-section-title">Result for ${data.mobileNumber}</div>
      <div style="padding:16px;font-family:monospace;font-size:12px;white-space:pre-wrap;word-break:break-word;"></div>`;
    box.querySelector("div:last-child").textContent = data.rawText || "(no details captured)";
    container.appendChild(box);
  }
}

function renderResult(data) {
  renderResultSections(resultWrap, data);
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

// Bulk Search - available to every agent, not just admins (2026-08-11).
const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
const tabBulk = document.getElementById("hpTabBulk");
if (tabBulk) {
  const tabSingle       = document.getElementById("hpTabSingle");
  const singleMode      = document.getElementById("hpSingleMode");
  const bulkMode        = document.getElementById("hpBulkMode");
  const bulkNumbersBox  = document.getElementById("hpBulkNumbersBox");
  const bulkSearchBtn   = document.getElementById("hpBulkSearchBtn");
  const bulkClearBtn    = document.getElementById("hpBulkClearBtn");
  const bulkExportBtn   = document.getElementById("hpBulkExportBtn");
  const bulkStatus      = document.getElementById("hpBulkStatus");
  const bulkResultsWrap = document.getElementById("hpBulkResultsWrap");
  const bulkProgressWrap  = document.getElementById("hpBulkProgressWrap");
  const bulkProgressFill  = document.getElementById("hpBulkProgressFill");
  const bulkProgressLabel = document.getElementById("hpBulkProgressLabel");
  const bulkProgressEta   = document.getElementById("hpBulkProgressEta");

  tabSingle.addEventListener("click", () => {
    tabSingle.classList.add("active");
    tabBulk.classList.remove("active");
    singleMode.style.display = "";
    bulkMode.style.display = "none";
  });
  tabBulk.addEventListener("click", () => {
    tabBulk.classList.add("active");
    tabSingle.classList.remove("active");
    bulkMode.style.display = "";
    singleMode.style.display = "none";
  });

  // ~25-90s per number (see ESTIMATED_SECONDS/hp_gas_api.php's 90s proxy
  // timeout) - 50 (admins) keeps a full run under roughly an hour worst case
  // rather than letting a pasted list run unbounded. Agents are capped at 10
  // per explicit instruction - each number still spends real locateme.services
  // credits and counts against their own hp_gas_monthly_limit (enforced
  // server-side per search in hp_gas_api.php), same as Single Search.
  const BULK_LIMIT = IS_ADMIN ? 50 : 10;
  let bulkResults = []; // [{ mobileNumber, found, sections, error }]

  // Same number-parsing/merging as lpg_bulk_search.php's parseNumbers, minus
  // the alternate-number-length tolerance HP Gas doesn't need (10-digit only).
  function parseBulkNumbers(raw) {
    const tokens = raw.split(/[\s,]+/).map(s => s.trim()).filter(s => s.length > 0);
    const merged = [];
    for (let i = 0; i < tokens.length; i++) {
      const cur = tokens[i].replace(/\D+/g, "");
      const next = tokens[i + 1] ? tokens[i + 1].replace(/\D+/g, "") : "";
      if (cur.length === 5 && next.length === 5) {
        merged.push(cur + next);
        i++;
      } else if (cur.length === 10) {
        merged.push(cur);
      }
    }
    return merged.slice(0, BULK_LIMIT);
  }

  function bulkBadge(state) {
    if (state === "found") return '<span class="hp-bulk-badge hp-bulk-badge-found">Found</span>';
    if (state === "notfound") return '<span class="hp-bulk-badge hp-bulk-badge-notfound">Not found</span>';
    if (state === "error") return '<span class="hp-bulk-badge hp-bulk-badge-error">Error</span>';
    return '<span class="hp-bulk-badge">Searching…</span>';
  }

  // Sequential, one number at a time - Gas/lpg_web/hp_gas.py drives a single
  // Selenium session against the shared locateme.services login (same
  // account rc_print.php uses), so firing these in parallel would mean
  // multiple browser sessions racing over that one login instead of each
  // waiting its turn.
  async function runBulkSearch() {
    const numbers = parseBulkNumbers(bulkNumbersBox.value);
    if (numbers.length === 0) {
      bulkStatus.textContent = "Enter at least one valid 10-digit mobile number.";
      return;
    }

    bulkSearchBtn.disabled = true;
    bulkExportBtn.disabled = true;
    bulkResults = [];
    bulkResultsWrap.innerHTML = "";
    bulkProgressWrap.style.display = "block";
    bulkProgressFill.style.width = "0%";
    const startedAt = Date.now();

    for (let i = 0; i < numbers.length; i++) {
      const num = numbers[i];
      bulkStatus.textContent = `Searching ${num}… (${i + 1}/${numbers.length})`;
      bulkProgressLabel.textContent = `${i} / ${numbers.length} searched`;

      const item = document.createElement("div");
      item.className = "hp-bulk-item";
      const header = document.createElement("div");
      header.className = "hp-bulk-item-header";
      header.innerHTML = `<span>${num}</span>${bulkBadge("searching")}`;
      item.appendChild(header);
      const sectionsBox = document.createElement("div");
      item.appendChild(sectionsBox);
      bulkResultsWrap.appendChild(item);

      try {
        const res = await fetch("hp_gas_api.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ mobileNumber: num })
        });
        const data = await res.json();

        if (res.status === 401) {
          window.location.href = data.loginUrl || "login.php";
          return;
        }

        if (!res.ok) {
          header.innerHTML = `<span>${num}</span>${bulkBadge("error")}`;
          sectionsBox.innerHTML = `<div class="hp-not-found">${data.error || "Search failed."}</div>`;
          bulkResults.push({ mobileNumber: num, found: false, sections: [], error: data.error || "Search failed." });
        } else {
          header.innerHTML = `<span>${num}</span>${bulkBadge(data.found ? "found" : "notfound")}`;
          renderResultSections(sectionsBox, data);
          bulkResults.push({ mobileNumber: num, found: !!data.found, sections: data.sections || [] });
        }
      } catch (err) {
        header.innerHTML = `<span>${num}</span>${bulkBadge("error")}`;
        sectionsBox.innerHTML = `<div class="hp-not-found">Could not reach the server: ${err.message}</div>`;
        bulkResults.push({ mobileNumber: num, found: false, sections: [], error: err.message });
      }

      const elapsed = (Date.now() - startedAt) / 1000;
      const avgPerItem = elapsed / (i + 1);
      bulkProgressFill.style.width = `${Math.round(((i + 1) / numbers.length) * 100)}%`;
      bulkProgressLabel.textContent = `${i + 1} / ${numbers.length} searched`;
      bulkProgressEta.textContent = i + 1 < numbers.length
        ? `~${formatDuration(avgPerItem * (numbers.length - i - 1))} remaining`
        : `Done in ${formatDuration(elapsed)}`;
    }

    bulkStatus.textContent = `Completed ${numbers.length} search${numbers.length === 1 ? "" : "es"}.`;
    bulkSearchBtn.disabled = false;
    bulkExportBtn.disabled = bulkResults.length === 0;
    if (bulkResults.some(r => r.found)) startConfetti(); else stopConfetti();
  }

  function csvEscape(value) {
    const s = (value ?? "").toString();
    return /[",\r\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  }

  // Columns are fully dynamic (whatever sections/fields locateme.services
  // showed for each number - see renderResultSections) - built as the union
  // of every "Section: Field" label seen, in first-seen order, so every row
  // lines up even though not every number returns the same fields.
  function exportBulkCsv() {
    if (!bulkResults.length) return;
    const columns = [];
    const columnSet = new Set();
    bulkResults.forEach(r => {
      (r.sections || []).forEach(section => {
        section.fields.forEach(field => {
          const key = `${section.title}: ${field.label}`;
          if (!columnSet.has(key)) { columnSet.add(key); columns.push(key); }
        });
      });
    });

    const headers = ["Mobile Number", "Status", ...columns];
    const lines = [headers.join(",")];
    bulkResults.forEach(r => {
      const valueMap = {};
      (r.sections || []).forEach(section => {
        section.fields.forEach(field => {
          valueMap[`${section.title}: ${field.label}`] = field.value || "";
        });
      });
      const row = [
        r.mobileNumber,
        r.error ? "Error" : (r.found ? "Found" : "Not found"),
        ...columns.map(c => valueMap[c] || "")
      ];
      lines.push(row.map(csvEscape).join(","));
    });

    const blob = new Blob(["﻿" + lines.join("\r\n")], { type: "text/csv;charset=utf-8;" });
    const url = URL.createObjectURL(blob);
    const stamp = new Date().toISOString().replace(/[:.]/g, "-").slice(0, 19);
    const a = document.createElement("a");
    a.href = url;
    a.download = `hp_gas_bulk_search_${stamp}.csv`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  }

  bulkSearchBtn.addEventListener("click", runBulkSearch);
  bulkExportBtn.addEventListener("click", exportBulkCsv);
  bulkClearBtn.addEventListener("click", () => {
    bulkNumbersBox.value = "";
    bulkStatus.textContent = "";
    bulkResultsWrap.innerHTML = "";
    bulkProgressWrap.style.display = "none";
    bulkExportBtn.disabled = true;
    bulkResults = [];
    stopConfetti();
  });
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

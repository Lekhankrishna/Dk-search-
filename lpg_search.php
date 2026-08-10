<?php
require __DIR__ . '/includes/auth.php';
requireLpgSearchAccess(); // requireLogin() + a 403 for logged-in users without the "LPG Search Access" permission (Admin > Agents)

$basePath = '';
require __DIR__ . '/includes/header.php';
?>

<!-- Confetti overlay - populated/cleared by startConfetti()/stopConfetti()
     (assets/confetti.js), only while a search has actually succeeded. -->
<div class="confetti-container" id="confetti-container"></div>

<div class="page-header" style="display:flex;align-items:center;flex-wrap:wrap;gap:12px">
  <h1 class="page-title" style="margin:0"><i class="bi bi-fuel-pump-fill"></i> Indian LPG Search</h1>
</div>

<style>
  /* Self-contained rather than pulled into assets/style.css - this page's
     table/progress-bar/chip styling is specific to LPG search results and
     doesn't need to be global. Tokens match assets/style.css's own. */
  .lpg-card{background:var(--c-surface,#fff);border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;}
  .lpg-card-body{padding:16px 18px;}
  .lpg-hint{color:#999;margin:0 0 14px;font-size:13px;}
  /* Mode toggle - which one is active swaps the input below between a
     single-line box and the multi-number textarea (see updateMode() JS). */
  .lpg-mode-toggle{display:flex;gap:8px;margin-bottom:12px;}
  .lpg-mode-btn{padding:9px 18px;border-radius:999px;border:1px solid #e0e0e0;background:#fff;
    color:#555;font-size:12.5px;font-weight:600;cursor:pointer;transition:all 150ms;}
  .lpg-mode-btn:hover{border-color:#4f46e5;color:#4f46e5;}
  .lpg-mode-btn.active{background:#4f46e5;border-color:#4f46e5;color:#fff;box-shadow:0 4px 14px rgba(79,70,229,.35);}
  .lpg-input{width:100%;padding:11px 16px;font-size:13px;color:#333;
    border:1px solid #e0e0e0;border-radius:9px;background:#fff;outline:none;}
  .lpg-input:focus{border-color:#4f46e5;box-shadow:0 0 0 3px rgba(79,70,229,.25);}
  .lpg-textarea{width:100%;height:110px;padding:9px 14px;font-size:13px;color:#333;
    border:1px solid #e0e0e0;border-radius:9px;background:#fff;resize:vertical;outline:none;}
  .lpg-textarea:focus{border-color:#4f46e5;box-shadow:0 0 0 3px rgba(79,70,229,.25);}
  .lpg-row{display:flex;align-items:center;gap:12px;margin-top:12px;flex-wrap:wrap;}
  .lpg-btn{padding:11px 26px;border-radius:9px;border:none;background:#4f46e5;color:#fff;
    font-size:12.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;cursor:pointer;
    transition:all 150ms;box-shadow:0 4px 18px rgba(79,70,229,.3);}
  .lpg-btn:hover:not(:disabled){background:#4338ca;transform:translateY(-1px);box-shadow:0 6px 20px rgba(79,70,229,.45);}
  .lpg-btn:disabled{opacity:.65;cursor:wait;transform:none;}
  .lpg-btn-secondary{background:#fff;color:#333;border:1px solid #e0e0e0;box-shadow:none;}
  .lpg-btn-secondary:hover:not(:disabled){background:#eeeef6;border-color:#4f46e5;transform:none;box-shadow:none;}
  .lpg-btn-sm{padding:7px 16px;font-size:11px;}
  .lpg-btn-export{background:#065f46;color:#fff;border:none;box-shadow:0 4px 18px rgba(6,95,70,.35);}
  .lpg-btn-export:hover:not(:disabled){background:#054a37;transform:translateY(-1px);box-shadow:0 6px 20px rgba(6,95,70,.5);}
  #lpgStatus{font-size:12.5px;color:#555;white-space:pre-wrap;word-break:break-word;font-weight:500;}
  .lpg-progress-wrap{margin-top:12px;display:none;}
  .lpg-progress-track{height:8px;border-radius:6px;background:#eeeef6;overflow:hidden;border:1px solid #e0e0e0;}
  .lpg-progress-fill{height:100%;border-radius:6px;background:#4f46e5;width:0%;transition:width .4s ease;}
  .lpg-progress-fill.indeterminate{width:100%;
    background:repeating-linear-gradient(45deg,#4f46e5 0 12px,#4338ca 12px 24px);
    background-size:34px 100%;animation:lpg-progress-stripes 1s linear infinite;}
  @keyframes lpg-progress-stripes{from{background-position:0 0;}to{background-position:-34px 0;}}
  .lpg-progress-meta{display:flex;justify-content:space-between;margin-top:6px;font-size:11.5px;color:#999;}
  .lpg-results-wrap{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);margin-top:16px;overflow-x:auto;max-width:100%;}
  .lpg-results-toolbar{display:flex;align-items:center;justify-content:space-between;padding:10px 16px;border-bottom:1px solid #e0e0e0;background:#eeeef6;}
  .lpg-results-toolbar .count{font-size:12px;color:#555;font-weight:600;}
  .lpg-table{width:100%;border-collapse:collapse;font-size:11.5px;}
  .lpg-table thead tr{background:#4f46e5;}
  .lpg-table th{color:#fff;font-size:10px;font-weight:700;letter-spacing:.3px;text-transform:uppercase;padding:6px 8px;text-align:left;border-right:1px solid rgba(255,255,255,.18);white-space:nowrap;}
  .lpg-table td{padding:5px 8px;border-right:1px solid #e0e0e0;border-bottom:1px solid #e0e0e0;vertical-align:top;color:#333;max-width:220px;}
  .lpg-table tbody tr:nth-child(odd){background:#fff;}
  .lpg-table tbody tr:nth-child(even){background:#eeeef6;}
  .lpg-table tbody tr:hover{background:rgba(79,70,229,.06);box-shadow:inset 3px 0 0 #4f46e5;}
  .lpg-table tbody tr:last-child td{border-bottom:none;}
  /* Colour-coded columns (2026-08-03), same palette as pan_india.php/index.php -
     column 1 ("#") stays plain, columns 2-10 each get their own header colour
     plus a light tint on the cell (this page is a fixed light theme, not the
     shared dark/light CSS variables, so plain hex/rgba is used directly). */
  .lpg-table th:nth-child(2){background:rgb(219,39,119);}
  .lpg-table th:nth-child(3){background:rgb(124,58,237);}
  .lpg-table th:nth-child(4){background:rgb(234,88,12);}
  .lpg-table th:nth-child(5){background:rgb(5,150,105);}
  .lpg-table th:nth-child(6){background:rgb(13,148,136);}
  .lpg-table th:nth-child(7){background:rgb(37,99,235);}
  .lpg-table th:nth-child(8){background:rgb(217,119,6);}
  .lpg-table th:nth-child(9){background:rgb(225,29,72);}
  .lpg-table th:nth-child(10){background:rgb(2,132,199);}
  .lpg-table td:nth-child(2){background:rgba(219,39,119,.08);border-left:3px solid rgba(219,39,119,.5);}
  .lpg-table td:nth-child(3){background:rgba(124,58,237,.08);border-left:3px solid rgba(124,58,237,.5);}
  .lpg-table td:nth-child(4){background:rgba(234,88,12,.08);border-left:3px solid rgba(234,88,12,.5);}
  .lpg-table td:nth-child(5){background:rgba(5,150,105,.08);border-left:3px solid rgba(5,150,105,.5);}
  .lpg-table td:nth-child(6){background:rgba(13,148,136,.08);border-left:3px solid rgba(13,148,136,.5);}
  .lpg-table td:nth-child(7){background:rgba(37,99,235,.08);border-left:3px solid rgba(37,99,235,.5);}
  .lpg-table td:nth-child(8){background:rgba(217,119,6,.08);border-left:3px solid rgba(217,119,6,.5);}
  .lpg-table td:nth-child(9){background:rgba(225,29,72,.08);border-left:3px solid rgba(225,29,72,.5);}
  .lpg-table td:nth-child(10){background:rgba(2,132,199,.08);border-left:3px solid rgba(2,132,199,.5);}
  .lpg-cell-name{font-weight:700;color:#333;}
  .lpg-cell-mobile{font-family:'Consolas','Cascadia Code','Courier New',monospace;font-size:12px;
    background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.35);padding:1px 7px;border-radius:5px;
    letter-spacing:.2px;display:inline-block;color:#0d9668;font-weight:700;}
  .lpg-cell-dob{font-family:'Consolas','Cascadia Code','Courier New',monospace;font-size:12px;color:#fff;
    font-weight:700;letter-spacing:.2px;background:#4f46e5;padding:1px 7px;border-radius:5px;display:inline-block;}
  .lpg-cell-empty{color:#aaa;}
  .lpg-error-row td{color:#f87171;}
  .lpg-error-row .lpg-cell-name::before{content:"Not found";font-weight:700;}
</style>

<div class="lpg-card">
  <div class="lpg-card-body">
    <div class="lpg-mode-toggle" role="tablist">
      <button type="button" class="lpg-mode-btn active" data-mode="single">Single Search</button>
      <button type="button" class="lpg-mode-btn" data-mode="bulk">Bulk Search</button>
    </div>
    <input type="text" id="lpgSingleBox" class="lpg-input" placeholder="9876543210">
    <textarea id="lpgNumbersBox" class="lpg-textarea" placeholder="9876543210, 9876543211, ..." style="display:none"></textarea>
    <p class="lpg-hint" id="lpgBulkHint" style="display:none;margin-top:10px">Runs every number in the box, up to 10 at a time.</p>
    <div class="lpg-row">
      <button id="lpgSearchBtn" class="lpg-btn">Search</button>
      <button id="lpgClearBtn" class="lpg-btn lpg-btn-secondary" type="button">Clear</button>
      <button id="lpgRefreshBtn" class="lpg-btn lpg-btn-secondary" type="button">Refresh</button>
      <span id="lpgStatus"></span>
    </div>
    <div class="lpg-progress-wrap" id="lpgProgressWrap">
      <div class="lpg-progress-track"><div class="lpg-progress-fill" id="lpgProgressFill"></div></div>
      <div class="lpg-progress-meta">
        <span id="lpgProgressLabel"></span>
        <span id="lpgProgressEta"></span>
      </div>
    </div>
  </div>
</div>

<div class="lpg-results-wrap" id="lpgResultsWrap" style="display:none;">
  <div class="lpg-results-toolbar">
    <span class="count" id="lpgResultsCount"></span>
    <button id="lpgExportBtn" class="lpg-btn lpg-btn-export lpg-btn-sm" type="button">⬇ Export to Excel</button>
  </div>
  <table class="lpg-table" id="lpgResultsTable">
    <thead>
      <tr>
        <th style="width:36px">#</th>
        <th>Mobile Number</th>
        <th>Alternate Number</th>
        <th>Full Name</th>
        <th>DOB</th>
        <th>Relationship Id</th>
        <th>Address</th>
        <th>Country</th>
        <th>Pin Code</th>
        <th>Urban/Rural</th>
      </tr>
    </thead>
    <tbody id="lpgResultsBody"></tbody>
  </table>
</div>

<script>
// Flat cap for everyone, agent or admin - not a technical ceiling (the
// backend can handle more), a deliberate usage cap.
const BULK_NUMBER_LIMIT = 10;
const searchBtn = document.getElementById("lpgSearchBtn");
const clearBtn = document.getElementById("lpgClearBtn");
const refreshBtn = document.getElementById("lpgRefreshBtn");
const exportBtn = document.getElementById("lpgExportBtn");
const singleBox = document.getElementById("lpgSingleBox");
const numbersBox = document.getElementById("lpgNumbersBox");
const bulkHint = document.getElementById("lpgBulkHint");
const modeButtons = document.querySelectorAll(".lpg-mode-btn");
let activeMode = "single";

// Swaps the input below the toggle - single-line box for one number, the
// textarea for many - rather than keeping both around and just changing
// which button/label is active.
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
const statusEl = document.getElementById("lpgStatus");
const resultsWrap = document.getElementById("lpgResultsWrap");
const resultsBody = document.getElementById("lpgResultsBody");
const resultsCount = document.getElementById("lpgResultsCount");
const progressWrap = document.getElementById("lpgProgressWrap");
const progressFill = document.getElementById("lpgProgressFill");
const progressLabel = document.getElementById("lpgProgressLabel");
const progressEta = document.getElementById("lpgProgressEta");

let pollTimer = null;
let lastResults = [];
let searchStartedAt = null;

function formatDuration(seconds) {
  seconds = Math.max(0, Math.round(seconds));
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

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

function parseNumbers(raw) {
  const tokens = raw
    .split(/[\s,]+/)
    .map(s => s.trim())
    .filter(s => s.length > 0);

  // Merge "XXXXX XXXXX" formatted Indian mobile numbers (a common way
  // they're written/copied - contact exports, business cards, SDMS itself)
  // back into one 10-digit number, instead of splitting on that internal
  // space (found 2026-08-05 live: "9901431238" pasted as "99014 31238"
  // silently became two garbage 5-digit searches instead of one real one).
  // Also strips any other punctuation (dashes, etc.) from every token, so a
  // dash-formatted number like "9901-431238" doesn't survive as one
  // unsearchable non-numeric string either.
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

function cell(value, cls) {
  if (!value) return `<td><span class="lpg-cell-empty">—</span></td>`;
  return `<td><span class="${cls || ''}">${value}</span></td>`;
}

function renderResults(results) {
  lastResults = results;
  resultsBody.innerHTML = "";
  results.forEach((r, i) => {
    const tr = document.createElement("tr");
    const notFound = !!r["NOT_FOUND"];
    if (notFound) tr.classList.add("lpg-error-row");
    tr.innerHTML = `
      <td>${i + 1}</td>
      ${cell(r["Mobile Number"], "lpg-cell-mobile")}
      ${cell(r["Alternate Number"], "lpg-cell-mobile")}
      <td><span class="lpg-cell-name">${notFound ? "" : (r["Full Name"] || "—")}</span></td>
      ${cell(r["DOB"], "lpg-cell-dob")}
      <td>${notFound ? "" : (r["Relationship Id"] || "—")}</td>
      <td>${notFound ? "" : (r["Address"] || "—")}</td>
      <td>${notFound ? "" : (r["Country"] || "—")}</td>
      <td>${notFound ? "" : (r["Pin Code"] || "—")}</td>
      <td>${notFound ? "" : (r["Urban/Rural"] || "—")}</td>
    `;
    resultsBody.appendChild(tr);
  });
  resultsWrap.style.display = results.length ? "block" : "none";
  resultsCount.textContent = results.length ? `${results.length} result${results.length === 1 ? "" : "s"}` : "";
  exportBtn.disabled = results.length === 0;
}

function csvEscape(value) {
  const s = (value ?? "").toString();
  return /[",\r\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

function exportToExcel() {
  if (!lastResults.length) return;
  const headers = ["Mobile Number", "Alternate Number", "Full Name", "DOB", "Relationship Id", "Address", "Country", "Pin Code", "Urban/Rural"];
  const lines = [headers.join(",")];
  lastResults.forEach(r => {
    const notFound = !!r["NOT_FOUND"];
    const row = [
      r["Mobile Number"] || "",
      r["Alternate Number"] || "",
      notFound ? "Not found" : (r["Full Name"] || ""),
      r["DOB"] || "",
      notFound ? "" : (r["Relationship Id"] || ""),
      notFound ? "" : (r["Address"] || ""),
      notFound ? "" : (r["Country"] || ""),
      notFound ? "" : (r["Pin Code"] || ""),
      notFound ? "" : (r["Urban/Rural"] || "")
    ];
    lines.push(row.map(csvEscape).join(","));
  });
  const blob = new Blob(["﻿" + lines.join("\r\n")], { type: "text/csv;charset=utf-8;" });
  const url = URL.createObjectURL(blob);
  const stamp = new Date().toISOString().replace(/[:.]/g, "-").slice(0, 19);
  const a = document.createElement("a");
  a.href = url;
  a.download = `lpg_search_${stamp}.csv`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}
exportBtn.addEventListener("click", exportToExcel);

function poll(jobId) {
  pollTimer = setInterval(async () => {
    try {
      const res = await fetch(`lpg_search_api.php?action=status&jobId=${jobId}`);
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

      if (data.status === "completed" || data.status === "failed") {
        clearInterval(pollTimer);
        searchBtn.disabled = false;
        if (data.status === "failed") {
          statusEl.textContent = `Failed: ${data.error || "unknown error"}`;
          progressWrap.style.display = "none";
          stopConfetti();
        } else if (data.results.some(r => !r["NOT_FOUND"])) {
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
    const res = await fetch("lpg_search_api.php?action=start", {
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

refreshBtn.addEventListener("click", () => location.reload());
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

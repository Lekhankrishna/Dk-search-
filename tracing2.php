<?php
require __DIR__ . '/includes/auth.php';
requireTracing2Access(); // requireLogin() + a 403 for logged-in users without the "Tracing 2.0 Access" permission (Admin > Agents)
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/tracing2_tools.php';

// Same quota-badge pattern as hp_gas.php/rc_print.php, but this badge
// tracks the generic Tracing 2.0 bucket's actual CREDIT spend (2026-08-17),
// not a search count - see tracing2_api.php's own comment on why. RC
// Print/HP Gas Advanced's tabs still spend against their own separate,
// count-based quotas (checked server-side per search, same as before);
// this page-level badge only ever reflects the generic bucket.
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$quota = null;
if (!$isAdmin) {
    $stmt = $pdo->prepare('SELECT tracing2_monthly_limit FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $monthlyLimit = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(credits_spent), 0) FROM search_logs WHERE user_id = :id AND search_type = 'tracing2' AND searched_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
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
  <h1 class="page-title" style="margin:0"><i class="bi bi-geo-alt-fill"></i> <span id="t2PageTitleText">Tracing 2.0</span></h1>
  <?php if ($isAdmin): ?>
    <span id="t2QuotaBadge" class="badge badge-neutral" style="margin-left:auto">Unlimited (Admin)</span>
  <?php elseif ($quota !== null): ?>
    <span id="t2QuotaBadge" class="badge <?= $quota['used'] >= $quota['limit'] ? 'badge-danger' : 'badge-neutral' ?>"
          style="margin-left:auto">
      <?= $quota['limit'] - $quota['used'] > 0 ? $quota['limit'] - $quota['used'] : 0 ?> of <?= $quota['limit'] ?> credits left this month
    </span>
  <?php endif; ?>
</div>

<style>
  /* Same tokens/shape as hp_gas.php/rc_print.php's cards. */
  .t2-card{background:var(--c-surface,#fff);border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;}
  .t2-card-body{padding:16px 18px;}
  .t2-hint{color:#999;margin:0 0 14px;font-size:13px;}
  .t2-row{display:flex;align-items:center;gap:12px;margin-top:12px;flex-wrap:wrap;}
  .t2-btn{padding:11px 26px;border-radius:9px;border:none;background:#2e9e3f;color:#fff;
    font-size:12.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;cursor:pointer;
    transition:all 150ms;box-shadow:0 4px 18px rgba(46,158,63,.3);}
  .t2-btn:hover:not(:disabled){background:#257e32;transform:translateY(-1px);box-shadow:0 6px 20px rgba(46,158,63,.45);}
  .t2-btn:disabled{opacity:.65;cursor:wait;transform:none;}
  .t2-btn-secondary{background:#fff;color:#333;border:1px solid #e0e0e0;box-shadow:none;}
  .t2-btn-secondary:hover:not(:disabled){background:#eeeef6;border-color:#2e9e3f;transform:none;box-shadow:none;}
  #t2Status{font-size:12.5px;color:#555;white-space:pre-wrap;word-break:break-word;font-weight:500;}
  .t2-progress-wrap{margin-top:12px;display:none;}
  .t2-progress-track{height:8px;border-radius:6px;background:#eeeef6;overflow:hidden;border:1px solid #e0e0e0;}
  .t2-progress-fill{height:100%;border-radius:6px;background:#2e9e3f;width:100%;
    background-image:repeating-linear-gradient(45deg,#2e9e3f 0 12px,#257e32 12px 24px);
    background-size:34px 100%;animation:t2-progress-stripes 1s linear infinite;}
  @keyframes t2-progress-stripes{from{background-position:0 0;}to{background-position:-34px 0;}}
  .t2-progress-meta{display:flex;justify-content:space-between;margin-top:6px;font-size:11.5px;color:#999;}
  /* Results - one card per record/section (name+icon header, optional
     status badge, then a 2-column grid of icon+label+value fields) -
     same general shape as locateme.services' own result page, light
     card tokens matching hp_gas.php/rc_print.php's cards elsewhere in
     this app rather than that site's dark theme. */
  .t2-result-wrap{margin-top:16px;display:none;}
  .t2-result-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:12px;}
  .t2-result-count{font-size:12px;color:#777;font-weight:600;}
  .t2-export-btn{background:#10b981;color:#fff;border:none;box-shadow:0 4px 18px rgba(16,185,129,.3);padding:9px 18px;
    border-radius:9px;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;cursor:pointer;
    display:inline-flex;align-items:center;gap:8px;transition:all 150ms;}
  .t2-export-btn:hover:not(:disabled){background:#0d9668;transform:translateY(-1px);box-shadow:0 6px 20px rgba(16,185,129,.45);}
  .t2-export-btn:disabled{opacity:.5;cursor:not-allowed;transform:none;box-shadow:none;}
  .t2-group-heading{font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;
    color:#2e9e3f;margin:20px 0 8px;padding-bottom:6px;border-bottom:2px solid #e2e2ea;}
  .t2-group-heading:first-child{margin-top:0;}
  .t2-record{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;margin-bottom:14px;}
  .t2-record-header{padding:12px 16px;background:#2e9e3f;color:#fff;display:flex;align-items:center;gap:10px;}
  .t2-record-header i{font-size:16px;}
  .t2-record-name{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;}
  .t2-record-status{margin-left:auto;font-size:10.5px;padding:2px 10px;border-radius:999px;
    font-weight:700;text-transform:uppercase;letter-spacing:.3px;background:rgba(255,255,255,.2);}
  .t2-field-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;padding:16px;}
  .t2-field-item{display:flex;align-items:flex-start;gap:10px;}
  .t2-field-item i{font-size:15px;color:#2e9e3f;margin-top:2px;flex-shrink:0;}
  .t2-field-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;color:#999;margin-bottom:2px;}
  .t2-field-value{font-size:13px;font-weight:600;color:#222;word-break:break-word;}
  .t2-raw-body{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);padding:16px;
    font-family:monospace;font-size:12px;white-space:pre-wrap;word-break:break-word;color:#333;margin-top:16px;display:none;}
  .t2-no-results{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;
    padding:56px 16px;color:#999;margin-top:16px;}
  .t2-no-results i{font-size:38px;color:#ccc;width:74px;height:74px;display:flex;align-items:center;justify-content:center;
    border-radius:50%;border:1.5px solid #e5e5e5;}
  .t2-no-results span{font-size:14px;color:#888;}
  /* Tool picker - same tab-pill look as advanced_search.php's .as-tabs/.as-tab,
     just renamed with this page's own t2- prefix. Wraps onto multiple lines
     for Tracing 2.0's 24 tools instead of advanced_search.php's 5 modes, same
     as that page's row already supports via flex-wrap. */
  .t2-tabs{display:flex;gap:10px;flex-wrap:wrap;padding-bottom:18px;margin-bottom:18px;border-bottom:1px solid #eee;}
  .t2-tab{display:inline-flex;align-items:center;gap:8px;padding:11px 18px;border-radius:10px;
    border:1px solid #e2e2ea;background:#fff;font-size:12.5px;font-weight:600;color:#555;
    cursor:pointer;transition:all 150ms;white-space:nowrap;}
  .t2-tab:hover{border-color:#2e9e3f;color:#2e9e3f;}
  .t2-tab.active{background:#2e9e3f;border-color:#2e9e3f;color:#fff;box-shadow:0 4px 14px rgba(46,158,63,.35);}
  /* RC Print's result is a PDF, not label/value fields - same iframe
     preview + download pattern as the old standalone rc_print.php. */
  .t2-pdf-wrap{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);margin-top:16px;overflow:hidden;display:none;}
  .t2-pdf-frame{width:100%;height:80vh;border:none;display:block;}
</style>

<div class="t2-card">
  <div class="t2-card-body">
    <p class="t2-hint" id="t2Hint">Pick a tool, then enter the matching value to search.</p>
    <button id="t2ChangeToolBtn" class="t2-btn t2-btn-secondary" type="button" style="display:none;margin-bottom:14px">
      <i class="bi bi-arrow-left"></i> Change tool
    </button>
    <?php
    // Restricting an agent to a subset of tools (per-tool checklist below)
    // can mean mobile-info itself isn't in their allowed list - the first
    // tool actually rendered becomes the active one instead of assuming
    // mobile-info always is, and its slug/placeholder feed the JS default
    // below so the query box and the JS-side activeTool variable start in
    // sync with whichever tab is visually marked active.
    $firstVisibleTool = null;
    ?>
    <div class="t2-tabs" id="t2ToolTabs" role="tablist">
      <?php foreach (TRACING2_TOOLS as $slug => $toolDef):
        // RC Print/HP Gas Advanced only show up here for agents who already
        // have that specific access; every other tool is gated by the
        // per-tool checklist (Admin > Agents > "Tracing 2.0") - see
        // includes/tracing2_tools.php and hasTracing2ToolAccess().
        $requires = $toolDef['requiresAccess'] ?? null;
        if ($requires === 'rc_print' && !hasRcPrintAccess()) continue;
        if ($requires === 'hp_gas' && !hasHpGasAccess()) continue;
        if ($requires === 'indane_gas' && !hasIndaneGasAccess()) continue;
        if ($requires === null && !hasTracing2ToolAccess($slug)) continue;
        if ($firstVisibleTool === null) $firstVisibleTool = ['slug' => $slug, 'placeholder' => $toolDef['placeholder']];
      ?>
        <button type="button" class="t2-tab<?= $slug === $firstVisibleTool['slug'] ? ' active' : '' ?>"
                data-tool="<?= htmlspecialchars($slug) ?>" data-placeholder="<?= htmlspecialchars($toolDef['placeholder']) ?>">
          <?= htmlspecialchars($toolDef['label']) ?>
        </button>
      <?php endforeach; ?>
    </div>
    <input type="text" id="t2QueryBox" placeholder="<?= htmlspecialchars($firstVisibleTool['placeholder'] ?? '') ?>" maxlength="100"
           style="width:100%;padding:11px 16px;font-size:13px;color:#333;border:1px solid #e0e0e0;border-radius:9px;background:#fff;outline:none;">
    <div class="t2-row">
      <button id="t2SearchBtn" class="t2-btn">Search</button>
      <button id="t2ClearBtn" class="t2-btn t2-btn-secondary" type="button">Clear</button>
      <span id="t2Status"></span>
    </div>
    <div class="t2-progress-wrap" id="t2ProgressWrap">
      <div class="t2-progress-track"><div class="t2-progress-fill" id="t2ProgressFill"></div></div>
      <div class="t2-progress-meta">
        <span id="t2ProgressLabel">Logging in and running the search…</span>
        <span id="t2ProgressElapsed"></span>
      </div>
    </div>
  </div>
</div>

<div class="t2-result-wrap" id="t2ResultWrap">
  <div class="t2-result-toolbar">
    <span class="t2-result-count" id="t2ResultCountText"></span>
    <button id="t2ExportBtn" class="t2-export-btn" type="button" disabled>
      <i class="bi bi-file-earmark-excel"></i> Export as Excel (CSV)
    </button>
  </div>
  <div id="t2RecordsWrap"></div>
</div>
<div class="t2-raw-body" id="t2RawBody"></div>
<div class="t2-pdf-wrap" id="t2PdfWrap">
  <div class="t2-result-toolbar">
    <span class="t2-result-count" id="t2PdfCountText"></span>
    <button id="t2PdfDownloadBtn" class="t2-export-btn" type="button">
      <i class="bi bi-download"></i> Download PDF
    </button>
  </div>
  <iframe class="t2-pdf-frame" id="t2PdfFrame" title="RC PDF Preview"></iframe>
</div>
<div class="t2-no-results" id="t2NoResults" style="display:none">
  <i class="bi bi-search"></i>
  <span>No records found</span>
</div>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
// Per explicit instruction: every search failure - regardless of cause
// (bad input, quota reached, access not granted, a Selenium crash, a
// timeout, an unreachable backend) - shows this same generic message to
// the agent instead of whatever real error text the server returned.
const SERVER_DOWN_MESSAGE = "Server is down. Please try again later.";

const searchBtn      = document.getElementById("t2SearchBtn");
const clearBtn       = document.getElementById("t2ClearBtn");
const toolTabs       = document.getElementById("t2ToolTabs");
const queryBox       = document.getElementById("t2QueryBox");
const statusEl       = document.getElementById("t2Status");
const resultWrap     = document.getElementById("t2ResultWrap");
const resultCountText= document.getElementById("t2ResultCountText");
const exportBtn      = document.getElementById("t2ExportBtn");
const recordsWrap    = document.getElementById("t2RecordsWrap");
const rawBody        = document.getElementById("t2RawBody");
const pdfWrap        = document.getElementById("t2PdfWrap");
const pdfFrame       = document.getElementById("t2PdfFrame");
const pdfCountText   = document.getElementById("t2PdfCountText");
const pdfDownloadBtn = document.getElementById("t2PdfDownloadBtn");
const noResultsEl    = document.getElementById("t2NoResults");
const quotaBadge     = document.getElementById("t2QuotaBadge");
const progressWrap   = document.getElementById("t2ProgressWrap");
const progressLabel  = document.getElementById("t2ProgressLabel");
const progressElapsed= document.getElementById("t2ProgressElapsed");

let progressTimer = null;
let searchStartedAt = null;

function formatDuration(seconds) {
  seconds = Math.max(0, Math.round(seconds));
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

// Tab picker - same active-tab pattern as advanced_search.php's .as-tabs.
// Placeholder swaps to match whatever the selected tool actually expects
// (mobile number, Aadhaar number, vehicle number, email, IFSC code, ...) -
// see includes/tracing2_tools.php for the full list, mirrored server-side
// in Gas/lpg_web/tracing2_tools.py's TOOL_REGISTRY.
let activeTool = "<?= htmlspecialchars($firstVisibleTool['slug'] ?? '', ENT_QUOTES) ?>";
const pageTitleText = document.getElementById("t2PageTitleText");
const hintEl         = document.getElementById("t2Hint");
const changeToolBtn  = document.getElementById("t2ChangeToolBtn");

// Clicking a tab focuses that single tool - the other 23 tabs and the
// generic "Tracing 2.0" heading are just clutter once an agent has already
// picked one (same idea as the dedicated single-tool pages, e.g.
// indane_gas_info.php, just without a separate PHP file per tool). The
// full tab bar still shows on first load so there's something to pick
// from, and "Change tool" brings it back.
function selectTool(tab) {
  toolTabs.querySelectorAll(".t2-tab").forEach(t => t.classList.remove("active"));
  tab.classList.add("active");
  activeTool = tab.dataset.tool;
  queryBox.placeholder = tab.dataset.placeholder;
  statusEl.textContent = "";
  pageTitleText.textContent = tab.textContent.trim();
  toolTabs.style.display = "none";
  hintEl.style.display = "none";
  changeToolBtn.style.display = "inline-flex";
}
toolTabs.querySelectorAll(".t2-tab").forEach(tab => tab.addEventListener("click", () => selectTool(tab)));

changeToolBtn.addEventListener("click", () => {
  toolTabs.style.display = "flex";
  hintEl.style.display = "block";
  changeToolBtn.style.display = "none";
  pageTitleText.textContent = "Tracing 2.0";
});

// Deep-link support (e.g. the "Indane Gas Info" sidebar shortcut,
// includes/header.php, linking here with ?tool=indane-gas-info) - falls
// back to the default first-visible tab if the slug is missing, not a
// real tool, or not one this agent has access to (not rendered as a tab
// at all in that case, per-tool checklist, Admin > Agents).
const deepLinkTool = new URLSearchParams(location.search).get("tool");
if (deepLinkTool) {
  const deepLinkTab = toolTabs.querySelector(`.t2-tab[data-tool="${CSS.escape(deepLinkTool)}"]`);
  if (deepLinkTab) selectTool(deepLinkTab);
}

// There's no per-step progress to report here (unlike LPG's job-based
// polling) - this is one blocking fetch for the whole login+search+scrape
// sequence in Gas/lpg_web/tracing2_tools.py, so the bar itself is always
// indeterminate (striped, animating). The countdown is a fixed estimate,
// same idea as hp_gas.php's own "~Xs remaining".
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

// unit is "credits" for every tool except RC Print/HP Gas Advanced, which
// still spend against their own separate, count-based quotas ("searches")
// - see tracing2_api.php's own comment on why those two stayed count-based.
function updateQuotaBadge(used, limit, unit) {
  if (!quotaBadge) return;
  const remaining = Math.max(0, limit - used);
  quotaBadge.textContent = `${remaining} of ${limit} ${unit || "credits"} left this month`;
  quotaBadge.classList.toggle("badge-danger", used >= limit);
  quotaBadge.classList.toggle("badge-neutral", used < limit);
}

// Best-guess icon per field label - not a literal match to whatever icon
// locateme.services itself shows (that varies per tool/field and isn't
// worth hand-mapping across 24 tools), just a reasonable visual cue based
// on common keywords so the field grid doesn't read as a flat wall of text.
function fieldIcon(label) {
  const l = label.toLowerCase();
  if (/(phone|mobile|node|number)/.test(l)) return "bi-telephone-fill";
  if (/(address|location|city|state|pincode|circle)/.test(l)) return "bi-geo-alt-fill";
  if (/name/.test(l)) return "bi-person-fill";
  if (/(bank|account|ifsc)/.test(l)) return "bi-bank";
  if (/(email|mail)/.test(l)) return "bi-envelope-fill";
  if (/(aadhaar|pan|id|linkage|imei)/.test(l)) return "bi-credit-card-2-front-fill";
  if (/(valid|verif|status|merchant)/.test(l)) return "bi-shield-check";
  if (/(vpa|upi|credit)/.test(l)) return "bi-wallet2";
  return "bi-info-circle-fill";
}

// Records come from Gas/lpg_web/tracing2_tools.py scraping locateme.services'
// own result cards generically (label/value field pairs under a
// name+status header, or under a section title - see tracing2_tools.py) -
// rendered as-is here rather than assuming fixed field names, since
// whatever fields locateme.services shows for a given query is what gets
// displayed. Some queries (e.g. a mobile number that's changed hands/been
// ported, or a "lookup" tool with several grouped sections) return more
// than one record - each gets its own card.
function buildRecordCard(record) {
  const box = document.createElement("div");
  box.className = "t2-record";

  const header = document.createElement("div");
  header.className = "t2-record-header";
  const headerIcon = record.status ? "bi-person-circle" : "bi-folder2-open";
  header.innerHTML = `<i class="bi ${headerIcon}"></i><span class="t2-record-name"></span><span class="t2-record-status"></span>`;
  header.querySelector(".t2-record-name").textContent = record.name || "Record";
  const statusBadge = header.querySelector(".t2-record-status");
  if (record.status) { statusBadge.textContent = record.status; } else { statusBadge.remove(); }
  box.appendChild(header);

  const grid = document.createElement("div");
  grid.className = "t2-field-grid";
  (record.fields || []).forEach(field => {
    const item = document.createElement("div");
    item.className = "t2-field-item";
    item.innerHTML = `<i class="bi"></i><div><div class="t2-field-label"></div><div class="t2-field-value"></div></div>`;
    item.querySelector("i").classList.add(fieldIcon(field.label));
    item.querySelector(".t2-field-label").textContent = field.label;
    item.querySelector(".t2-field-value").textContent = field.value || "—";
    grid.appendChild(item);
  });
  box.appendChild(grid);
  return box;
}

let lastRecords = [];
let lastPdfDataUri = null;

function renderResult(data) {
  recordsWrap.innerHTML = "";
  resultWrap.style.display = "none";
  rawBody.style.display = "none";
  pdfWrap.style.display = "none";
  noResultsEl.style.display = "none";
  exportBtn.disabled = true;
  lastRecords = [];
  lastPdfDataUri = null;

  // RC Print's result is a PDF, not label/value fields - handled first
  // and separately from the records/rawText/no-results paths below.
  if (data.pdfDataUri) {
    lastPdfDataUri = data.pdfDataUri;
    pdfFrame.src = data.pdfDataUri + "#toolbar=0&navpanes=0";
    pdfCountText.textContent = data.query || "";
    pdfWrap.style.display = "block";
    startConfetti();
    if (typeof data.used === "number" && typeof data.limit === "number") {
      updateQuotaBadge(data.used, data.limit, data.unit);
    }
    return;
  }

  const records = (data.found && Array.isArray(data.records)) ? data.records : [];

  if (records.length) {
    lastRecords = records;
    resultCountText.textContent = `${records.length} result${records.length === 1 ? "" : "s"} found`;
    exportBtn.disabled = false;
    // Records from a table-shaped result (e.g. "Family Member Profile" -
    // see tracing2_tools.py's _extract_tables()) carry a shared "section"
    // name - grouped here under one heading instead of repeating it above
    // every single card. Records with no section (the common case) render
    // as standalone cards, same as before.
    let lastSection = null;
    records.forEach(r => {
      if (r.section) {
        if (r.section !== lastSection) {
          const heading = document.createElement("div");
          heading.className = "t2-group-heading";
          heading.textContent = r.section;
          recordsWrap.appendChild(heading);
          lastSection = r.section;
        }
      } else {
        lastSection = null;
      }
      recordsWrap.appendChild(buildRecordCard(r));
    });
    resultWrap.style.display = "block";
  } else if (data.found && data.rawText) {
    // Fallback if locateme.services' DOM structure matches neither
    // extraction pattern for this particular tool (e.g. whatsapp-dp, which
    // returns an image rather than label/value fields) - see
    // tracing2_tools.py's module docstring.
    rawBody.textContent = data.rawText;
    rawBody.style.display = "block";
  } else {
    noResultsEl.style.display = "flex";
  }

  if (records.length) startConfetti(); else stopConfetti();
  if (typeof data.used === "number" && typeof data.limit === "number") {
    updateQuotaBadge(data.used, data.limit, data.unit);
  }
}

// Columns are fully dynamic (whatever fields locateme.services showed for
// each record - see buildRecordCard), built as the union of every label
// seen in first-seen order, same approach as hp_gas.php's bulk-search CSV
// export.
exportBtn.addEventListener("click", () => {
  if (!lastRecords.length) return;
  const hasName   = lastRecords.some(r => r.name);
  const hasStatus = lastRecords.some(r => r.status);
  const columns = [];
  if (hasName) columns.push("Record");
  if (hasStatus) columns.push("Status");
  const columnSet = new Set(columns);
  lastRecords.forEach(r => (r.fields || []).forEach(f => {
    if (!columnSet.has(f.label)) { columnSet.add(f.label); columns.push(f.label); }
  }));

  const aoa = [columns, ...lastRecords.map(r => {
    const valueMap = {};
    if (hasName) valueMap["Record"] = r.name || "";
    if (hasStatus) valueMap["Status"] = r.status || "";
    (r.fields || []).forEach(f => { valueMap[f.label] = f.value || ""; });
    return columns.map(c => valueMap[c] || "");
  })];
  const ws = XLSX.utils.aoa_to_sheet(aoa);
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, "Result");
  const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, "-");
  XLSX.writeFile(wb, `tracing2-${activeTool}-${stamp}.xlsx`);
});

pdfDownloadBtn.addEventListener("click", () => {
  if (!lastPdfDataUri) return;
  const a = document.createElement("a");
  a.href = lastPdfDataUri;
  a.download = `RC_${pdfCountText.textContent || "print"}.pdf`;
  document.body.appendChild(a);
  a.click();
  a.remove();
});

async function runSearch() {
  const tool = activeTool;
  const query = queryBox.value.trim();
  if (!query) {
    statusEl.textContent = SERVER_DOWN_MESSAGE;
    return;
  }

  searchBtn.disabled = true;
  statusEl.textContent = "";
  resultWrap.style.display = "none";
  rawBody.style.display = "none";
  pdfWrap.style.display = "none";
  noResultsEl.style.display = "none";
  startProgress();

  try {
    const res = await fetch("tracing2_api.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ tool, query })
    });
    const data = await res.json();

    if (!res.ok) {
      stopProgress(null);
      // Per explicit instruction: every error (validation, quota, backend
      // failure - no exceptions) shows this same generic message rather
      // than whatever text the server actually returned in data.error.
      statusEl.textContent = SERVER_DOWN_MESSAGE;
      if (typeof data.used === "number" && typeof data.limit === "number") {
        updateQuotaBadge(data.used, data.limit, data.unit);
      }
      return;
    }

    stopProgress(data.found ? "Result found" : "No result found");
    renderResult(data);
  } catch (err) {
    stopProgress(null);
    statusEl.textContent = SERVER_DOWN_MESSAGE;
  } finally {
    searchBtn.disabled = false;
  }
}

searchBtn.addEventListener("click", runSearch);
queryBox.addEventListener("keydown", (e) => {
  if (e.key === "Enter" && !searchBtn.disabled) runSearch();
});
clearBtn.addEventListener("click", () => {
  queryBox.value = "";
  statusEl.textContent = "";
  resultWrap.style.display = "none";
  rawBody.style.display = "none";
  pdfWrap.style.display = "none";
  pdfFrame.src = "";
  noResultsEl.style.display = "none";
  exportBtn.disabled = true;
  lastRecords = [];
  lastPdfDataUri = null;
  stopProgress(null);
  stopConfetti();
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

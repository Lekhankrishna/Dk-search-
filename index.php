<?php
require __DIR__ . '/includes/auth.php';
requireLogin();
$user = currentUser();
require __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

<!-- Confetti overlay (2026-08-08) - populated/cleared by startConfetti()/
     stopConfetti() below, only while a search has actually returned rows.
     Fixed to the right-hand content panel only (offset past the sidebar,
     see .confetti-container CSS) so it never covers the sidebar, and spans
     the full panel height rather than being boxed into the results card.
     pointer-events:none the whole way down so it never blocks clicking
     anything underneath it. -->
<div class="confetti-container" id="confetti-container"></div>

<h1 class="page-title-main" id="page-title">DK SEARCH</h1>

<!-- ── Search Panel ── -->
<div class="sp-wrap">

  <!-- Search mode row -->
  <div class="sp-row sp-row--mode">
    <div class="sp-pills" id="mode-pills">
      <button class="sp-pill active" data-mode="mobile"><i class="bi bi-phone-fill"></i> Mobile Number</button>
      <button class="sp-pill" data-mode="name"><i class="bi bi-person-fill"></i> Name</button>
      <button class="sp-pill" data-mode="address"><i class="bi bi-geo-alt-fill"></i> Address</button>
      <button class="sp-pill" data-mode="name_father"><i class="bi bi-people-fill"></i> Name+Father</button>
      <button class="sp-pill" data-mode="name_dob"><i class="bi bi-calendar-event-fill"></i> Name+DOB</button>
      <button class="sp-pill" data-mode="name_address"><i class="bi bi-person-lines-fill"></i> Name+Addr</button>
      <button class="sp-pill" data-mode="identity"><i class="bi bi-credit-card-2-front-fill"></i> Identity</button>
      <button class="sp-pill" data-mode="full_address"><i class="bi bi-house-door-fill"></i> Full Address</button>
      <button class="sp-pill" data-mode="pincode_address" id="pincode-address-pill" style="display:none"><i class="bi bi-mailbox2"></i> Pincode+Addr</button>
    </div>
  </div>

  <!-- Search form -->
  <div class="sp-row sp-row--form">
    <form id="search-form" class="sp-form">
      <div class="sp-fields">
        <div class="field-group" data-for="mobile">
          <input class="sp-input" type="text" name="mobile" placeholder="Enter mobile number… (paste multiple to bulk search)" id="mobile-input">
        </div>
        <div class="field-group" data-for="name" style="display:none">
          <input class="sp-input" type="text" name="name_only" placeholder="Customer name…">
        </div>
        <div class="field-group" data-for="identity" style="display:none">
          <input class="sp-input" type="text" name="identity" placeholder="PAN / Aadhaar / DL / Voter ID…">
        </div>
        <div class="field-group" data-for="address" style="display:none">
          <input class="sp-input" type="text" name="address" placeholder="Street, area, city, landmark…">
        </div>
        <div class="field-group" data-for="name_father" style="display:none">
          <input class="sp-input" type="text" name="name" placeholder="Name">
          <input class="sp-input" type="text" name="father_name" placeholder="Father's Name">
        </div>
        <div class="field-group" data-for="name_dob" style="display:none">
          <input class="sp-input" type="text" name="name2" placeholder="Name">
          <input class="sp-input" type="text" name="dob" placeholder="DD/MM/YYYY" maxlength="12">
        </div>
        <div class="field-group" data-for="name_address" style="display:none">
          <input class="sp-input" type="text" name="name3" placeholder="Name">
          <input class="sp-input" type="text" name="address2" placeholder="Address">
        </div>
        <div class="field-group" data-for="full_address" style="display:none">
          <input class="sp-input" type="text" name="full_address" placeholder="Full or partial address…">
        </div>
        <div class="field-group" data-for="pincode_address" style="display:none">
          <input class="sp-input" type="text" name="pincode" placeholder="Pincode" maxlength="6" style="max-width:140px">
          <input class="sp-input" type="text" name="address4" placeholder="Address">
        </div>
        <div class="field-group" data-for="multi_mobile" style="display:none">
          <textarea class="sp-input sp-textarea" name="mobiles"
            placeholder="Paste numbers — one per line or comma-separated (max 50)"></textarea>
        </div>
      </div>
      <button type="submit" class="sp-btn" id="search-btn">
        <i class="bi bi-search"></i> Search
      </button>
      <button type="button" class="sp-btn sp-btn-clear" id="clear-btn" onclick="clearSearch()">
        <i class="bi bi-x-circle"></i> Clear
      </button>
      <button type="button" class="sp-btn sp-btn-export" id="export-btn">
        <i class="bi bi-file-earmark-excel"></i> Export
      </button>
    </form>
  </div>

</div>

<!-- ── Results ── -->
<div class="results-wrap" id="results-card" style="display:none">

  <!-- Table controls toolbar -->
  <div class="dt-toolbar">
    <div class="dt-toolbar__left">
      <label class="dt-show-label">Show
        <select id="dt-length-select" class="dt-select">
          <option value="5">5</option>
          <option value="10">10</option>
          <option value="15">15</option>
          <option value="25" selected>25</option>
          <option value="50">50</option>
        </select>
        entries
      </label>
    </div>
    <div class="dt-toolbar__right">
      <div class="dt-search-box">
        <i class="bi bi-search"></i>
        <input type="text" id="dt-search-input" placeholder="Filter results…">
      </div>
      <button type="button" class="dt-copy-btn" id="copy-all-btn">
        <i class="bi bi-copy"></i> Copy All Results
      </button>
    </div>
  </div>

  <!-- Table -->
  <div class="results-table-wrap">
    <table id="results" class="results-table" style="width:100%">
      <thead>
        <tr>
          <th>Name</th>
          <th>Mobile</th>
          <th>Alt. Mobile</th>
          <th>DOB</th>
          <th>Gender</th>
          <th>Father's Name</th>
          <th>Address</th>
          <th>Perm. Address</th>
          <th>Email</th>
          <th>Identity Doc</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
  </div>

  <!-- Footer: info + pagination -->
  <div class="dt-footer">
    <div class="dt-info-group">
      <div class="dt-info" id="dt-info-text"></div>
      <span class="dt-timing-badge" id="dt-timing-badge" hidden></span>
    </div>
    <div class="dt-pagination" id="dt-pagination"></div>
  </div>

</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
let activeType   = 'mobile';
let activeState  = new URLSearchParams(location.search).get('state') || '';
let lastQueryMs  = null;
let lastResults  = [];

function genderBadge(g) {
  const m = { M:['badge-male','Male'], F:['badge-female','Female'], O:['badge-other','Other'] };
  const [c,l] = m[g?.toUpperCase()] ?? ['badge-neutral', g||'—'];
  return `<span class="badge ${c}">${l}</span>`;
}
function esc(s){ return (s||'').replace(/"/g,'&quot;'); }
/* Identity data is a messy comma-joined blob with placeholder noise (-, NA, blanks).
   Strip the noise and stray leading/trailing dashes, show one clean fragment per line. */
function fmtIdentity(s){
  if (!s) return '';
  const parts = s.split(',')
    .map(p => p.trim().replace(/^-+\s*/, '').replace(/\s*-+$/, ''))
    .filter(p => p !== '' && !/^n\/?a$/i.test(p));
  return parts.map(esc).join('<br>');
}
function trunc(s,n){ return s && s.length>n ? s.slice(0,n)+'…' : (s||''); }
/* DB stores DOB as YYYY-MM-DD — display as DD/MM/YYYY throughout the UI and export. */
function fmtDob(s){
  if (!s) return '';
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s);
  return m ? `${m[3]}/${m[2]}/${m[1]}` : s;
}
/* Inverse of fmtDob — the DOB search field accepts free-form typed/pasted
   dates, the API/DB need YYYY-MM-DD. Deliberately lenient: numeric dates with
   /, -, . or space separators, 2-or-4-digit years, ISO (YYYY-MM-DD) order,
   and month-name dates ("4 Feb 1988", "Feb 4, 1988", "4th February 1988").
   Day/month defaults to DD/MM (this app's data is Indian), but falls back to
   MM/DD when DD/MM isn't a valid date and the swap is — e.g. "02/13/1988"
   can only be MM/DD since there's no 13th month. */
const DOB_MONTHS = {jan:1,feb:2,mar:3,apr:4,may:5,jun:6,jul:7,aug:8,sep:9,sept:9,oct:10,nov:11,dec:12};
function dobPad(n){ return String(n).padStart(2,'0'); }
function dobYear(y){
  y = +y;
  if (y >= 100) return y;
  return y <= 30 ? 2000 + y : 1900 + y; // 2-digit year pivot, tuned for birth dates
}
function dobValid(y, mo, d){
  if (mo < 1 || mo > 12 || d < 1) return false;
  return d <= new Date(y, mo, 0).getDate();
}
function parseDob(s){
  s = (s||'').trim();
  if (!s) return '';

  let m = /^(\d{1,2})[\/\-.\s](\d{1,2})[\/\-.\s](\d{2,4})$/.exec(s);
  if (m) {
    let d = +m[1], mo = +m[2];
    const y = dobYear(m[3]);
    if (!dobValid(y, mo, d) && dobValid(y, d, mo)) { [d, mo] = [mo, d]; }
    if (dobValid(y, mo, d)) return `${y}-${dobPad(mo)}-${dobPad(d)}`;
  }

  m = /^(\d{4})[\/\-.\s](\d{1,2})[\/\-.\s](\d{1,2})$/.exec(s);
  if (m) {
    const y = +m[1], mo = +m[2], d = +m[3];
    if (dobValid(y, mo, d)) return `${y}-${dobPad(mo)}-${dobPad(d)}`;
  }

  const mon = '(' + Object.keys(DOB_MONTHS).sort((a,b)=>b.length-a.length).join('|') + ')[a-z]*';
  m = new RegExp(`^(\\d{1,2})(?:st|nd|rd|th)?[\\s,\\-]+${mon}[\\s,\\-]+(\\d{2,4})$`, 'i').exec(s);
  if (m) {
    const d = +m[1], mo = DOB_MONTHS[m[2].toLowerCase()], y = dobYear(m[3]);
    if (dobValid(y, mo, d)) return `${y}-${dobPad(mo)}-${dobPad(d)}`;
  }
  m = new RegExp(`^${mon}[\\s,\\-]+(\\d{1,2})(?:st|nd|rd|th)?,?[\\s,\\-]+(\\d{2,4})$`, 'i').exec(s);
  if (m) {
    const mo = DOB_MONTHS[m[1].toLowerCase()], d = +m[2], y = dobYear(m[3]);
    if (dobValid(y, mo, d)) return `${y}-${dobPad(mo)}-${dobPad(d)}`;
  }

  return '';
}

/* DataTable — fluid percentage-width columns so all 12 columns fit on one
   screen without horizontal scroll; autoWidth off so DataTables doesn't
   recompute pixel widths and fight the percentages. */
const dataTable = $('#results').DataTable({
  pageLength: 25,
  lengthMenu: [5,10,15,25,50],
  dom: 'rt',
  autoWidth: false,
  language: { emptyTable:'<div class="dt-empty"><i class="bi bi-inbox"></i><p>No records found</p></div>' },
  columns: [
    { data:'name',             width:'8%', render:d=>`<strong class="col-name" title="${esc(d)}">${d||'—'}</strong>` },
    { data:'mobile_no',        width:'7%',  render:d=>d?`<span class="chip-mobile">${d}</span>`:'<span class="na">—</span>' },
    { data:'alternative_no',   width:'7%',  render:d=>d?`<span class="chip-mobile">${d}</span>`:'<span class="na">—</span>' },
    { data:'dob',              width:'6%',  render:d=>d?`<span class="col-dob">${fmtDob(d)}</span>`:'<span class="na">—</span>' },
    { data:'gender',           width:'6%',  render:d=>genderBadge(d) },
    { data:'father_name',      width:'8%',  render:d=>`<span class="col-wrap" title="${esc(d)}">${d||'—'}</span>` },
    { data:'address',          width:'13%', render:d=>d?`<span class="col-addr" title="${esc(d)}">${d}</span>`:'<span class="na">—</span>' },
    { data:'permanent_address',width:'13%', render:d=>d?`<span class="col-addr" title="${esc(d)}">${d}</span>`:'<span class="na">—</span>' },
    { data:'email',            width:'7%',  render:d=>d?`<a class="col-email" href="mailto:${d}" title="${esc(d)}">${d}</a>`:'<span class="na">—</span>' },
    { data:'identity_no',      width:'13%', render:d=>{ const f=fmtIdentity(d); return f ? `<span class="chip-id" title="${esc(d)}">${f}</span>` : '<span class="na">—</span>'; } },
  ],
  drawCallback(){
    $('#results tbody tr').each((i,tr)=>{ tr.style.animationDelay=(i*15)+'ms'; });
    const info = this.api().page.info();
    const showing = info.recordsDisplay === 0 ? '0' :
      `${info.start+1}–${Math.min(info.end, info.recordsDisplay)}`;
    document.getElementById('dt-info-text').textContent =
      `Showing ${showing} of ${info.recordsDisplay} entries`;
    const badge = document.getElementById('dt-timing-badge');
    if (lastQueryMs !== null) { badge.textContent = `${lastQueryMs}ms`; badge.hidden = false; }
    else { badge.hidden = true; }
    renderPagination(info);
  }
});

/* Custom length select — the full batch is already loaded client-side, so
   changing page size is just a re-page, no server round trip needed. */
document.getElementById('dt-length-select').addEventListener('change', function(){
  dataTable.page.len(+this.value).draw();
});

/* Custom search */
document.getElementById('dt-search-input').addEventListener('input', function(){
  dataTable.search(this.value).draw();
});

/* Custom pagination renderer */
function renderPagination(info) {
  const box = document.getElementById('dt-pagination');
  const cur  = info.page, total = info.pages;
  let html = '';
  html += `<button class="pg-btn${cur===0?' pg-disabled':''}" data-p="${cur-1}" ${cur===0?'disabled':''}>‹ Prev</button>`;
  for(let p=0;p<total;p++){
    if(total>7 && Math.abs(p-cur)>2 && p!==0 && p!==total-1){ if(Math.abs(p-cur)===3) html+='<span class="pg-dots">…</span>'; continue; }
    html += `<button class="pg-btn${p===cur?' pg-active':''}" data-p="${p}">${p+1}</button>`;
  }
  html += `<button class="pg-btn${cur===total-1||total===0?' pg-disabled':''}" data-p="${cur+1}" ${cur===total-1||total===0?'disabled':''}>Next ›</button>`;
  box.innerHTML = html;
  box.querySelectorAll('.pg-btn:not([disabled])').forEach(b=>{
    b.addEventListener('click',()=>{ dataTable.page(+b.dataset.p).draw('page'); });
  });
}

/* Page title reflects the selected state */
function setPageTitle(state) {
  document.getElementById('page-title').textContent = state ? state.toUpperCase() : 'DK SEARCH';
}
setPageTitle(activeState);

/* Pincode+Address search mode is Kerala-only (Kerala's data reliably has
   pincode populated; other states' import sources don't). Falls back to
   Mobile if the state changes away from Kerala while it's active. */
function updatePincodeAddressVisibility() {
  const pill = document.getElementById('pincode-address-pill');
  const isKerala = activeState === 'Kerala';
  pill.style.display = isKerala ? '' : 'none';
  if (!isKerala && activeType === 'pincode_address') {
    document.querySelector('#mode-pills .sp-pill[data-mode="mobile"]').click();
  }
}
updatePincodeAddressVisibility();

/* Sidebar search-region links — click updates state and auto-re-searches if results are visible */
document.querySelectorAll('.sidebar-state-item').forEach(item => {
  item.addEventListener('click', e => {
    e.preventDefault();
    document.querySelectorAll('.sidebar-state-item').forEach(b=>b.classList.remove('active'));
    item.classList.add('active');
    activeState = item.dataset.state;
    setPageTitle(activeState);
    updatePincodeAddressVisibility();
    const url = 'index.php' + (activeState ? '?state=' + encodeURIComponent(activeState) : '');
    history.replaceState(null, '', url);
    // auto re-search if results panel is already open
    if (document.getElementById('results-card').style.display !== 'none') {
      document.getElementById('search-form').dispatchEvent(new Event('submit', {cancelable:true}));
    }
  });
});

/* Mode pills */
document.querySelectorAll('#mode-pills .sp-pill').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('#mode-pills .sp-pill').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    activeType = btn.dataset.mode;
    document.querySelectorAll('.field-group').forEach(g => {
      g.style.display = g.dataset.for === activeType ? '' : 'none';
    });
  });
});

/* Search */
const searchForm = document.getElementById('search-form');

function buildSearchParams() {
  const form = searchForm;
  const p    = new URLSearchParams();
  p.set('type', activeType);
  if (activeState) p.set('state', activeState);
  if (activeType==='mobile')       p.set('mobile',     form.mobile.value.trim());
  if (activeType==='name')         p.set('name',       form.name_only.value.trim());
  if (activeType==='identity')     p.set('identity',   form.identity.value.trim());
  if (activeType==='address')      p.set('address',    form.address.value.trim());
  if (activeType==='name_father')  { p.set('name',form.name.value.trim()); p.set('father_name',form.father_name.value.trim()); }
  if (activeType==='name_dob')     { p.set('name',form.name2.value.trim()); p.set('dob',parseDob(form.dob.value)); }
  if (activeType==='name_address') { p.set('name',form.name3.value.trim()); p.set('address',form.address2.value.trim()); }
  if (activeType==='full_address') p.set('address',   form.full_address.value.trim());
  if (activeType==='multi_mobile') p.set('mobiles',   form.mobiles.value.trim());
  if (activeType==='pincode_address') { p.set('pincode',form.pincode.value.trim()); p.set('address',form.address4.value.trim()); }

  // Auto-detect multiple numbers pasted into Mobile field → treat as bulk
  if (activeType === 'mobile') {
    const raw = form.mobile.value.trim();
    const nums = raw.split(/[\s,]+/).filter(n => n !== '');
    if (nums.length > 1) {
      p.set('type', 'multi_mobile');
      p.set('mobiles', nums.join(','));
      p.delete('mobile');
    }
  }

  // Always fetch a generous, fixed batch — independent of the "Show entries"
  // page size below, so there's real data to page through past the first page.
  p.set('limit', 1000);
  return p;
}

async function runSearch(p) {
  const btn = document.getElementById('search-btn');
  btn.innerHTML='<i class="bi bi-hourglass-split"></i> Searching…'; btn.disabled=true;
  try {
    const res = await fetch('api/search.php?'+p);
    if (res.status === 401) {
      // Session expired/replaced - a database-unavailable message here was
      // actively misleading (found 2026-08-04): 401 only ever means "not
      // logged in", never a DB problem, and "try again shortly" doesn't
      // help when what's actually needed is signing in again.
      const data = await res.json().catch(() => ({}));
      window.location.href = data.loginUrl || 'login.php';
      return;
    }
    if (!res.ok) {
      alert(`Search failed: server returned ${res.status} ${res.statusText}. The database may be temporarily unavailable — please try again shortly.`);
      return;
    }
    const data = await res.json();
    if (!data.ok){ alert(data.error||'Search failed.'); return; }
    lastResults = data.rows;
    lastQueryMs = typeof data.queryMs === 'number' ? data.queryMs : null;
    // Use the active state filter directly when one is selected — inspecting
    // returned rows alone breaks on a zero-result search (empty array can't
    // confirm "all Kerala"), which left Gender/Perm.Address visible again.
    // Falls back to inspecting rows only when searching across all states.
    const allKarnataka = activeState === 'Karnataka' || (!activeState && data.rows.length > 0 && data.rows.every(r => r.state === 'Karnataka'));
    const allTamilNadu = activeState === 'Tamil Nadu' || (!activeState && data.rows.length > 0 && data.rows.every(r => r.state === 'Tamil Nadu'));
    const allKerala     = activeState === 'Kerala'     || (!activeState && data.rows.length > 0 && data.rows.every(r => r.state === 'Kerala'));
    // Karnataka's source data never had Gender/Permanent Address — hide those
    // columns when every visible row is Karnataka. Kerala hides them too (per request).
    // false = don't redraw yet; each .visible() call redraws by default, and
    // doing that 3x with stale/empty data before the real data is set below
    // was corrupting the "No records found" empty-state render.
    [4, 7].forEach(i => dataTable.column(i).visible(!allKarnataka && !allKerala, false));
    // Email is only meaningfully populated for Tamil Nadu — show it only then
    // (already off for Kerala/Karnataka since this is a plain equality check).
    dataTable.column(8).visible(allTamilNadu, false);
    // Page size stays whatever's selected in "Show entries" — anything beyond
    // it paginates to the next page rather than piling onto one page.
    dataTable.page.len(+document.getElementById('dt-length-select').value);
    dataTable.clear().rows.add(data.rows).draw();
    const card = document.getElementById('results-card');
    card.style.display='';
    dataTable.columns.adjust();
    card.scrollIntoView({behavior:'smooth',block:'start'});
    if (data.rows.length > 0) startConfetti(); else stopConfetti();
  } catch (err) {
    alert('Search failed: could not reach the server. Please check your connection and try again.');
  } finally { btn.innerHTML='<i class="bi bi-search"></i> Search'; btn.disabled=false; }
}

searchForm.addEventListener('submit', e => {
  e.preventDefault();
  runSearch(buildSearchParams());
});

/* Confetti - startConfetti()/stopConfetti() come from the shared
   assets/confetti.js (loaded in includes/footer.php on every page). */

/* Clear */
function clearSearch() {
  document.getElementById('search-form').reset();
  document.querySelectorAll('.sp-input').forEach(el => el.value = '');
  document.getElementById('results-card').style.display = 'none';
  stopConfetti();
  lastResults = [];
}

/* Export current results to Excel (.xlsx) */
document.getElementById('export-btn').addEventListener('click', () => {
  if (!lastResults.length) { alert('No records to export.'); return; }
  // Export exactly what "Show entries" is currently set to, not the whole fetched batch.
  const shown = +document.getElementById('dt-length-select').value;
  const exportRows = lastResults.slice(0, shown);
  const headers = ['Name','Mobile','Alt. Mobile','DOB','Gender',"Father's Name",
                    'Address','Perm. Address','Email','Identity Doc','State'];
  const aoa = [headers, ...exportRows.map(r => [
    r.name || '', r.mobile_no || '', r.alternative_no || '', fmtDob(r.dob), r.gender || '',
    r.father_name || '', r.address || '', r.permanent_address || '', r.email || '',
    r.identity_no || '', r.state || '',
  ])];
  const ws = XLSX.utils.aoa_to_sheet(aoa);
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Results');
  const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-');
  XLSX.writeFile(wb, `crm-search-export-${stamp}.xlsx`);
});

/* Copy every loaded result (not just the current page) as tab-separated
   text - pastes cleanly into Excel/Sheets as real columns, unlike a plain
   comma-joined string. */
document.getElementById('copy-all-btn').addEventListener('click', async () => {
  const btn = document.getElementById('copy-all-btn');
  if (!lastResults.length) { alert('No records to copy.'); return; }
  const headers = ['Name','Mobile','Alt. Mobile','DOB','Gender',"Father's Name",
                    'Address','Perm. Address','Email','Identity Doc','State'];
  const lines = [headers.join('\t'), ...lastResults.map(r => [
    r.name || '', r.mobile_no || '', r.alternative_no || '', fmtDob(r.dob), r.gender || '',
    r.father_name || '', r.address || '', r.permanent_address || '', r.email || '',
    r.identity_no || '', r.state || '',
  ].join('\t'))];
  try {
    await navigator.clipboard.writeText(lines.join('\n'));
    const original = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-check2"></i> Copied!';
    setTimeout(() => { btn.innerHTML = original; }, 1500);
  } catch (err) {
    alert('Could not copy to clipboard — your browser may be blocking clipboard access on this page.');
  }
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>

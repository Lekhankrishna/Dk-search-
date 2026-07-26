<?php
require __DIR__ . '/includes/auth.php';
requireLogin();
$user = currentUser();
require __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

<div class="page-header" style="display:flex;align-items:center;flex-wrap:wrap;gap:12px">
  <h1 class="page-title-main" style="margin:0">E COMMERCE</h1>
  <?php if ($user['role'] === 'admin'): ?>
    <a href="admin/ecommerce_import.php" class="btn btn-secondary btn-sm" style="margin-left:auto">
      <i class="bi bi-cloud-upload-fill"></i> Import Data
    </a>
  <?php endif; ?>
</div>

<!-- ── Search Panel ── -->
<div class="sp-wrap">

  <!-- Search mode row -->
  <div class="sp-row sp-row--mode">
    <div class="sp-pills" id="mode-pills">
      <button class="sp-pill active" data-mode="mobile"><i class="bi bi-phone-fill"></i> Mobile Number</button>
      <button class="sp-pill" data-mode="name"><i class="bi bi-person-fill"></i> Name</button>
      <button class="sp-pill" data-mode="address"><i class="bi bi-geo-alt-fill"></i> Address</button>
      <button class="sp-pill" data-mode="delivery_date"><i class="bi bi-calendar-event-fill"></i> Delivery Date</button>
      <button class="sp-pill" data-mode="location"><i class="bi bi-pin-map-fill"></i> Location</button>
    </div>
  </div>

  <!-- Search form -->
  <div class="sp-row sp-row--form">
    <form id="search-form" class="sp-form">
      <div class="sp-fields">
        <div class="field-group" data-for="mobile">
          <input class="sp-input" type="text" name="mobile" placeholder="Enter mobile number…" id="mobile-input">
        </div>
        <div class="field-group" data-for="name" style="display:none">
          <input class="sp-input" type="text" name="name_only" placeholder="Customer name…">
        </div>
        <div class="field-group" data-for="address" style="display:none">
          <input class="sp-input" type="text" name="address" placeholder="Street, area, city, landmark…">
        </div>
        <div class="field-group" data-for="delivery_date" style="display:none">
          <input class="sp-input" type="text" name="delivery_date" placeholder="DD/MM/YYYY" maxlength="12">
        </div>
        <div class="field-group" data-for="location" style="display:none">
          <input class="sp-input" type="text" name="location" placeholder="Location, e.g. 12.9716,77.5946">
          <input class="sp-input" type="number" name="radius" placeholder="Radius (km)" min="0.1" step="0.1" style="max-width:140px">
        </div>
      </div>
      <button type="submit" class="sp-btn" id="search-btn">
        <i class="bi bi-search"></i> Search
      </button>
      <button type="button" class="sp-btn sp-btn-clear" id="clear-btn" onclick="clearSearch()">
        <i class="bi bi-x-circle"></i> Clear
      </button>
    </form>
  </div>

</div>

<!-- ── Results ── -->
<!-- no-copy: E-Commerce results are excluded from export/copy (unlike the state
     search pages) — see the copy/cut/contextmenu blockers + user-select:none below. -->
<div class="results-wrap no-copy" id="results-card" style="display:none">

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
    </div>
  </div>

  <!-- Table -->
  <div class="results-table-wrap">
    <table id="results" class="results-table" style="width:100%">
      <thead>
        <tr>
          <th>Name</th>
          <th>Mobile</th>
          <th>Address</th>
          <th>Delivery Date</th>
          <th>Location</th>
          <th>Distance</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
  </div>

  <!-- Footer: info + pagination -->
  <div class="dt-footer">
    <div class="dt-info" id="dt-info-text"></div>
    <div class="dt-pagination" id="dt-pagination"></div>
  </div>

</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
let activeType  = 'mobile';

function esc(s){ return (s||'').replace(/"/g,'&quot;'); }
/* Stored as separate latitude/longitude columns in the DB, but shown and
   exported as one combined "Location" value — matches how it's supplied on
   import (a single "lat,long" cell, see includes/ecommerce_import_helpers.php).
   Plain text (no markup) so it can feed both the table render and the export. */
function fmtLocation(lat, lng){
  const hasLat = lat !== null && lat !== undefined && lat !== '';
  const hasLng = lng !== null && lng !== undefined && lng !== '';
  if (!hasLat && !hasLng) return '';
  return `${hasLat ? lat : '?'},${hasLng ? lng : '?'}`;
}
/* DB stores delivery_date as YYYY-MM-DD — display as DD/MM/YYYY, same convention as DOB elsewhere in this app. */
function fmtDate(s){
  if (!s) return '';
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s);
  return m ? `${m[3]}/${m[2]}/${m[1]}` : s;
}
/* Inverse of fmtDate — accepts free-form typed/pasted dates, API/DB need YYYY-MM-DD. */
function dobPad(n){ return String(n).padStart(2,'0'); }
function dobYear(y){
  y = +y;
  if (y >= 100) return y;
  return y <= 30 ? 2000 + y : 1900 + y;
}
function dateValid(y, mo, d){
  if (mo < 1 || mo > 12 || d < 1) return false;
  return d <= new Date(y, mo, 0).getDate();
}
function parseDate(s){
  s = (s||'').trim();
  if (!s) return '';
  let m = /^(\d{1,2})[\/\-.\s](\d{1,2})[\/\-.\s](\d{2,4})$/.exec(s);
  if (m) {
    let d = +m[1], mo = +m[2];
    const y = dobYear(m[3]);
    if (!dateValid(y, mo, d) && dateValid(y, d, mo)) { [d, mo] = [mo, d]; }
    if (dateValid(y, mo, d)) return `${y}-${dobPad(mo)}-${dobPad(d)}`;
  }
  m = /^(\d{4})[\/\-.\s](\d{1,2})[\/\-.\s](\d{1,2})$/.exec(s);
  if (m) {
    const y = +m[1], mo = +m[2], d = +m[3];
    if (dateValid(y, mo, d)) return `${y}-${dobPad(mo)}-${dobPad(d)}`;
  }
  return '';
}

const dataTable = $('#results').DataTable({
  pageLength: 25,
  lengthMenu: [5,10,15,25,50],
  dom: 'rt',
  autoWidth: false,
  language: { emptyTable:'<div class="dt-empty"><i class="bi bi-inbox"></i><p>No records found</p></div>' },
  columns: [
    { data:'name',          width:'18%', render:d=>`<strong class="col-name" title="${esc(d)}">${d||'—'}</strong>` },
    { data:'mobile_no',     width:'10%', render:d=>d?`<span class="chip-mobile">${d}</span>`:'<span class="na">—</span>' },
    { data:'address',       width:'28%', render:d=>d?`<span class="col-addr" title="${esc(d)}">${d}</span>`:'<span class="na">—</span>' },
    { data:'delivery_date', width:'12%', render:d=>d?`<span class="col-dob">${fmtDate(d)}</span>`:'<span class="na">—</span>' },
    { data:null,            width:'20%', render:r=>fmtLocation(r.latitude, r.longitude) || '<span class="na">—</span>' },
    { data:'distance_km',   width:'8%',  visible:false, render:d=>(d!==null&&d!==undefined)?`${(+d).toFixed(2)} km`:'<span class="na">—</span>' },
  ],
  drawCallback(){
    $('#results tbody tr').each((i,tr)=>{ tr.style.animationDelay=(i*15)+'ms'; });
    const info = this.api().page.info();
    const showing = info.recordsDisplay === 0 ? '0' :
      `${info.start+1}–${Math.min(info.end, info.recordsDisplay)}`;
    document.getElementById('dt-info-text').textContent =
      `Showing ${showing} of ${info.recordsDisplay} entries`;
    renderPagination(info);
  }
});

document.getElementById('dt-length-select').addEventListener('change', function(){
  dataTable.page.len(+this.value).draw();
});
document.getElementById('dt-search-input').addEventListener('input', function(){
  dataTable.search(this.value).draw();
});

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

const searchForm = document.getElementById('search-form');

function buildSearchParams() {
  const form = searchForm;
  const p    = new URLSearchParams();
  p.set('type', activeType);
  if (activeType==='mobile')        p.set('mobile', form.mobile.value.trim());
  if (activeType==='name')          p.set('name',   form.name_only.value.trim());
  if (activeType==='address')       p.set('address',form.address.value.trim());
  if (activeType==='delivery_date') p.set('delivery_date', parseDate(form.delivery_date.value));
  if (activeType==='location')      { p.set('location', form.location.value.trim()); p.set('radius', form.radius.value.trim()); }
  p.set('limit', 1000);
  return p;
}

async function runSearch(p) {
  const btn = document.getElementById('search-btn');
  btn.innerHTML='<i class="bi bi-hourglass-split"></i> Searching…'; btn.disabled=true;
  try {
    const res = await fetch('api/ecommerce_search.php?'+p);
    if (!res.ok) {
      alert(`Search failed: server returned ${res.status} ${res.statusText}. Please try again shortly.`);
      return;
    }
    const data = await res.json();
    if (!data.ok){ alert(data.error||'Search failed.'); return; }
    dataTable.page.len(+document.getElementById('dt-length-select').value);
    dataTable.clear().rows.add(data.rows).draw();
    const card = document.getElementById('results-card');
    card.style.display='';
    dataTable.columns.adjust();
    card.scrollIntoView({behavior:'smooth',block:'start'});
  } catch (err) {
    alert('Search failed: could not reach the server. Please check your connection and try again.');
  } finally { btn.innerHTML='<i class="bi bi-search"></i> Search'; btn.disabled=false; }
}

searchForm.addEventListener('submit', e => {
  e.preventDefault();
  runSearch(buildSearchParams());
});

function clearSearch() {
  document.getElementById('search-form').reset();
  document.querySelectorAll('.sp-input').forEach(el => el.value = '');
  document.getElementById('results-card').style.display = 'none';
}

/* E-Commerce results are excluded from copy/export (unlike the state search
   pages) — blocks the clipboard copy/cut events and the right-click context
   menu (which offers its own "Copy" item) on the results area specifically.
   Paired with user-select:none in assets/style.css on .no-copy so drag-selecting
   text is disabled too. This deters casual copy-paste; it isn't a guarantee
   against someone reading the page's own network responses or source. */
document.querySelector('.no-copy').addEventListener('copy', e => e.preventDefault());
document.querySelector('.no-copy').addEventListener('cut', e => e.preventDefault());
document.querySelector('.no-copy').addEventListener('contextmenu', e => e.preventDefault());
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>

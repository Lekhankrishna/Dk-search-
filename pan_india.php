<?php
require __DIR__ . '/includes/auth.php';
requireLogin();
$user = currentUser();
require __DIR__ . '/includes/header.php';
?>
<div class="pan-page">
  <div class="pan-heading">
    <div>
      <div class="pan-kicker">PAN INDIA SEARCH</div>
      <h1>Search available records</h1>
      <p>Search by email, Aadhaar number, or contact number.</p>
    </div>
    <div class="pan-connect" id="pan-connect" hidden>
    </div>
  </div>

  <form class="pan-search-card" id="pan-search-form">
    <div class="pan-tabs" role="tablist" aria-label="Search method">
      <button type="button" class="pan-tab" data-type="email" role="tab">Email</button>
      <button type="button" class="pan-tab" data-type="aadhaar" role="tab">Aadhaar Number</button>
      <button type="button" class="pan-tab active" data-type="contact" role="tab" aria-selected="true">Contact Number</button>
    </div>
    <div class="pan-search-row">
      <label class="sr-only" for="pan-query">Contact Number</label>
      <div class="pan-input-wrap">
        <input id="pan-query" type="tel" inputmode="tel" autocomplete="off" placeholder="Enter mobile number" required>
      </div>
      <button class="pan-search-btn" id="pan-search-btn" type="submit">Search</button>
      <button class="pan-clear-btn" id="pan-clear-btn" type="button">Clear</button>
    </div>
  </form>

  <div class="pan-alert" id="pan-alert" role="alert" hidden></div>
  <section class="pan-results" id="pan-results" aria-live="polite" hidden>
    <div class="pan-results-heading">
      <div><div class="pan-kicker">SEARCH RESULTS</div><h2>Available information</h2></div>
      <div class="pan-results-heading-right">
        <span id="pan-result-count"></span>
        <button type="button" class="pan-pdf-btn" id="pan-pdf-btn"><i class="bi bi-file-earmark-pdf"></i> Download as PDF</button>
      </div>
    </div>
    <div id="pan-result-list"></div>
  </section>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/4.2.1/jspdf.umd.min.js"></script>
<script>
(() => {
  const types = {
    email: {label:'Email', placeholder:'Enter email address', mode:'email', type:'email'},
    aadhaar: {label:'Aadhaar Number', placeholder:'Enter 12-digit Aadhaar number', mode:'numeric', type:'text'},
    contact: {label:'Contact Number', placeholder:'Enter mobile number', mode:'tel', type:'tel'}
  };
  let activeType = 'contact';
  const form = document.getElementById('pan-search-form');
  const query = document.getElementById('pan-query');
  const label = form.querySelector('label');
  const button = document.getElementById('pan-search-btn');
  const alertBox = document.getElementById('pan-alert');
  const results = document.getElementById('pan-results');
  const list = document.getElementById('pan-result-list');
  const count = document.getElementById('pan-result-count');
  const connect = document.getElementById('pan-connect');
  const showError = message => { alertBox.textContent = message; alertBox.hidden = !message; };
  let lastRecords = [];
  let lastQuery = '';

  // A bot message often bundles several distinct people under one result
  // (found 2026-07-31: searching a shared number returned three unrelated
  // records - different Aadhaar numbers, different names - back to back in
  // one message), separated by a blank line. Splitting on that keeps each
  // person as its own row instead of every one collapsing into a single
  // mashed-together row further down.
  function splitIntoBlocks(text) {
    return (text || '').split(/\r?\n\s*\r?\n+/).map(b => b.trim()).filter(Boolean);
  }

  // Parses one block's raw "Label: value" lines into an ordered
  // Label -> value map. A label repeated within the SAME block is joined
  // rather than the later value silently discarding the earlier one, with
  // one exception: repeated "Phone Number:" lines (common - these records
  // often list several numbers for one person) split into a separate
  // "Alternate Number" column instead, mirroring LPG Search's own Phone
  // Number / Alternate Number split rather than cramming every number into
  // one cell. A line without a recognizable "label:" prefix is skipped
  // rather than breaking the column layout.
  function parseFields(text) {
    const fields = new Map();
    (text || '').split(/\r\n|\r|\n/).forEach(line => {
      const trimmed = line.trim();
      if (!trimmed) return;
      const match = /^([^:]{1,40}):\s*(.*)$/.exec(trimmed);
      if (!match) return;
      let label = match[1].trim();
      const value = match[2].trim() || '—';
      if (/^phone number$/i.test(label) && fields.has(label)) label = 'Alternate Number';
      else if (/^address$/i.test(label) && fields.has(label)) label = 'Alt Address';
      fields.set(label, fields.has(label) ? fields.get(label) + ', ' + value : value);
    });
    return fields;
  }

  // One shared table for the whole result set: column headers in the first
  // row (union of every field label seen, in first-seen order), one row per
  // PERSON below (see splitIntoBlocks) - not one row per API result, since
  // a single result can contain several people's blocks.
  function buildResultsTable(records) {
    const parsed = [];
    records.forEach(record => {
      const blocks = record.text ? splitIntoBlocks(record.text) : [''];
      blocks.forEach((block, i) => {
        parsed.push({ fields: parseFields(block), hasMedia: i === 0 && !!record.hasMedia });
      });
    });

    const columns = [];
    parsed.forEach(r => {
      r.fields.forEach((_, label) => { if (!columns.includes(label)) columns.push(label); });
    });
    const showMediaColumn = parsed.some(r => r.hasMedia);

    // Every column gets a share of the table width based on its content,
    // with guards to keep it usable across 6-8 columns of very mixed length:
    // - sqrt() dampens how much one verbose field (a combined address) can
    //   claim, instead of it swallowing most of the row's width raw-linearly
    // - the floor is tied to the HEADER's own length, so short-but-wordy
    //   headers ("Aadhaar Number") always get room to wrap at a space
    // - a SEPARATE floor covers the longest single unbroken token in the
    //   data (e.g. a bare 12-digit Aadhaar number has no space to wrap at
    //   all) - sqrt() alone still let that column end up narrower than the
    //   number itself, forcing an ugly mid-digit break even though the
    //   value can't wrap anywhere else.
    const longestToken = value => value.split(/[\s,;]+/).reduce((max, tok) => Math.max(max, tok.length), 0);
    const rawWeights = columns.map(col => {
      let maxLen = col.length;
      let maxToken = col.length;
      parsed.forEach(r => {
        const v = r.fields.get(col);
        if (v) { maxLen = Math.max(maxLen, v.length); maxToken = Math.max(maxToken, longestToken(v)); }
      });
      return Math.max(Math.sqrt(maxLen) * 5, col.length * 1.5, maxToken * 3.2, 16);
    });
    if (showMediaColumn) rawWeights.push(16);
    const rawTotal = rawWeights.reduce((a, b) => a + b, 0);
    const cap = rawTotal * 0.22;
    const weights = rawWeights.map(w => Math.min(w, cap));
    const totalWeight = weights.reduce((a, b) => a + b, 0);

    // Curated highlighting (2026-08-04, matching a reference tool) - only a
    // few "at a glance" fields get a solid colour fill (Alternate Number,
    // Address, Aadhaar/ID-like fields); Phone/Mobile gets coloured HEADER
    // TEXT only, no cell fill; everything else (name, father's name,
    // operator, etc.) stays fully plain so the table doesn't compete with
    // itself. Matched by field LABEL, so it still works for any dynamic
    // label the bot sends, not a fixed column list.
    function classifyColumn(label) {
      if (/alternate/i.test(label)) { const c = [219, 39, 119]; return { headerColor: c, bodyColor: c }; }
      if (/address/i.test(label))   { const c = [5, 150, 105];  return { headerColor: c, bodyColor: c }; }
      if (/aadhaar|passport|identity/i.test(label)) { const c = [37, 99, 235]; return { headerColor: c, bodyColor: c }; }
      if (/^phone|mobile/i.test(label)) return { headerColor: [217, 119, 6], bodyColor: null };
      return { headerColor: null, bodyColor: null };
    }
    const colClasses = columns.map(classifyColumn);
    if (showMediaColumn) colClasses.push({ headerColor: null, bodyColor: null });

    const table = document.createElement('table');
    table.className = 'results-table pan-results-table';

    const colgroup = document.createElement('colgroup');
    weights.forEach(w => {
      const col = document.createElement('col');
      col.style.width = (w / totalWeight * 100).toFixed(2) + '%';
      colgroup.appendChild(col);
    });
    table.appendChild(colgroup);

    const thead = document.createElement('thead');
    const headRow = document.createElement('tr');
    columns.forEach((col, i) => {
      const th = document.createElement('th');
      th.textContent = col;
      const hc = colClasses[i].headerColor;
      th.style.color = hc ? `rgb(${hc[0]},${hc[1]},${hc[2]})` : '#fff';
      headRow.appendChild(th);
    });
    if (showMediaColumn) {
      const th = document.createElement('th');
      th.textContent = 'Attachment';
      th.style.color = '#fff';
      headRow.appendChild(th);
    }
    thead.appendChild(headRow);

    const tbody = document.createElement('tbody');
    parsed.forEach(r => {
      const tr = document.createElement('tr');
      columns.forEach((col, i) => {
        const td = document.createElement('td');
        const bc = colClasses[i].bodyColor;
        if (bc) {
          td.style.background = `rgb(${bc[0]},${bc[1]},${bc[2]})`;
          td.style.color = '#fff';
        }
        let value = r.fields.get(col) || '—';
        // Semicolons show up two ways: multiple values this code joined
        // itself (now ", " - see parseFields), and ones already baked into
        // the bot's own raw text (e.g. "AIRTEL KARNATKA;KARNATAKA VI" in a
        // single Operator line). Normalized to ", " either way for a
        // consistent, less cramped-looking list in the cell.
        value = value.replace(/\s*;\s*/g, ', ');
        // The bot's own records store phone numbers with the "91" India
        // country code baked in (search normalization sends "91"+10 digits
        // to match that format) - stripped here for display only, since
        // agents expect the plain 10-digit local number they typed.
        if (/phone|alternate/i.test(col)) value = value.replace(/(?<!\d)91(\d{10})(?!\d)/g, '$1');
        if (/alternate/i.test(col)) {
          // One number per line - clearer than a comma-run, and avoids two
          // numbers ever visually reading as one longer digit string.
          // Built with DOM text nodes/<br>, not innerHTML, since this is
          // third-party bot text and must never be parsed as markup.
          value.split(/\s*,\s*/).filter(Boolean).forEach((part, idx) => {
            if (idx > 0) td.appendChild(document.createElement('br'));
            td.appendChild(document.createTextNode(part));
          });
        } else {
          td.textContent = value;
        }
        tr.appendChild(td);
      });
      if (showMediaColumn) {
        const td = document.createElement('td');
        td.textContent = r.hasMedia ? 'Available' : '—';
        tr.appendChild(td);
      }
      tbody.appendChild(tr);
    });

    table.append(thead, tbody);
    return { table, rowCount: parsed.length };
  }

  function validate(value) {
    if (activeType === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) return 'Enter a valid email address.';
    if (activeType === 'aadhaar' && !/^\d{12}$/.test(value.replace(/\s/g, ''))) return 'Enter a valid 12-digit Aadhaar number.';
    if (activeType === 'contact' && !/^\+?[0-9][0-9\s()-]{5,19}$/.test(value)) return 'Enter a valid contact number.';
    return '';
  }

  document.querySelectorAll('.pan-tab').forEach(tab => tab.addEventListener('click', () => {
    activeType = tab.dataset.type;
    const config = types[activeType];
    document.querySelectorAll('.pan-tab').forEach(item => {
      const selected = item === tab;
      item.classList.toggle('active', selected);
      item.setAttribute('aria-selected', selected ? 'true' : 'false');
    });
    query.value = ''; query.type = config.type; query.inputMode = config.mode;
    query.placeholder = config.placeholder; label.textContent = config.label;
    showError(''); results.hidden = true; query.focus();
  }));

  async function readJson(response) {
    const contentType = response.headers.get('content-type') || '';
    if (!contentType.includes('application/json')) {
      throw new Error('The server returned an invalid response. Please refresh the page and sign in again.');
    }
    return response.json();
  }

  form.addEventListener('submit', async event => {
    event.preventDefault();
    const value = query.value.trim();
    const validationError = validate(value);
    if (validationError) { showError(validationError); return; }
    showError(''); results.hidden = false; count.textContent = '';
    list.innerHTML = '<div class="pan-result-state"><span class="pan-spinner"></span> Waiting for the response…</div>';
    button.disabled = true; button.textContent = 'Searching…';
    try {
      const body = new URLSearchParams();
      body.set('searchType', activeType);
      body.set('query', value);
      const response = await fetch('api/pan_india.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
        body
      });
      const data = await readJson(response);
      if (response.status === 401) {
        window.location.href = data.loginUrl || 'login.php';
        return;
      }
      if (!response.ok || !data.ok) {
        connect.hidden = !data.loginUrl;
        throw new Error(data.error || 'The search could not be completed.');
      }
      connect.hidden = true;
      const records = data.results || [];
      lastRecords = records;
      lastQuery = value;
      list.replaceChildren();
      if (!records.length) {
        count.textContent = '0 results';
        const empty = document.createElement('div'); empty.className = 'pan-result-state';
        empty.textContent = 'No matching records found.'; list.appendChild(empty);
      } else {
        const { table, rowCount } = buildResultsTable(records);
        count.textContent = rowCount + ' result' + (rowCount === 1 ? '' : 's');
        const wrap = document.createElement('div'); wrap.className = 'results-table-wrap';
        wrap.appendChild(table);
        list.appendChild(wrap);
      }
    } catch (error) {
      results.hidden = true; showError(error.message);
    } finally {
      button.disabled = false; button.textContent = 'Search';
    }
  });

  document.getElementById('pan-clear-btn').addEventListener('click', () => {
    query.value = ''; showError(''); results.hidden = true; list.replaceChildren(); query.focus();
  });

  // "Download as PDF" - builds a real .pdf file client-side (jsPDF) and
  // saves it directly, rather than the browser's print dialog (found
  // 2026-07-29: print-to-PDF requires the user to manually pick "Save as
  // PDF" as the destination on every click, which isn't a direct download).
  document.getElementById('pan-pdf-btn').addEventListener('click', () => {
    if (!lastRecords.length) return;
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ unit: 'pt', format: 'a4' });
    const pageWidth = doc.internal.pageSize.getWidth();
    const pageHeight = doc.internal.pageSize.getHeight();
    const margin = 40;
    const maxWidth = pageWidth - margin * 2;
    let y = margin;

    function ensureSpace(lineHeight) {
      if (y + lineHeight > pageHeight - margin) { doc.addPage(); y = margin; }
    }

    doc.setFontSize(16);
    doc.text('PAN India Search Results', margin, y);
    y += 22;
    doc.setFontSize(10);
    doc.setTextColor(90);
    doc.text(`Query: ${lastQuery}  |  ${lastRecords.length} result${lastRecords.length === 1 ? '' : 's'}  |  ${new Date().toLocaleString()}`, margin, y);
    y += 22;
    doc.setTextColor(0);

    lastRecords.forEach((record, index) => {
      ensureSpace(20);
      doc.setFontSize(11);
      doc.setFont(undefined, 'bold');
      doc.text(`Record ${index + 1}`, margin, y);
      y += 16;
      doc.setFont(undefined, 'normal');
      doc.setFontSize(10);
      const text = record.text || 'A record attachment is available.';
      text.split(/\r\n|\r|\n/).forEach(rawLine => {
        const wrapped = doc.splitTextToSize(rawLine || ' ', maxWidth);
        wrapped.forEach(line => {
          ensureSpace(14);
          doc.text(line, margin, y);
          y += 14;
        });
      });
      if (record.hasMedia) {
        ensureSpace(14);
        doc.setTextColor(120);
        doc.text('[Attachment available]', margin, y);
        doc.setTextColor(0);
        y += 14;
      }
      y += 12;
    });

    const stamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
    doc.save(`pan-india-results-${stamp}.pdf`);
  });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>

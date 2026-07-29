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
      <span id="pan-result-count"></span>
    </div>
    <div id="pan-result-list"></div>
  </section>
</div>

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
      count.textContent = records.length + ' result' + (records.length === 1 ? '' : 's');
      list.replaceChildren();
      if (!records.length) {
        const empty = document.createElement('div'); empty.className = 'pan-result-state';
        empty.textContent = 'No matching records found.'; list.appendChild(empty);
      }
      records.forEach(record => {
        const article = document.createElement('article'); article.className = 'pan-result-card';
        const pre = document.createElement('pre'); pre.textContent = record.text || 'A record attachment is available.';
        article.appendChild(pre);
        if (record.hasMedia) {
          const badge = document.createElement('span'); badge.className = 'pan-attachment';
          badge.textContent = 'Attachment available'; article.appendChild(badge);
        }
        list.appendChild(article);
      });
    } catch (error) {
      results.hidden = true; showError(error.message);
    } finally {
      button.disabled = false; button.textContent = 'Search';
    }
  });

  document.getElementById('pan-clear-btn').addEventListener('click', () => {
    query.value = ''; showError(''); results.hidden = true; list.replaceChildren(); query.focus();
  });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>

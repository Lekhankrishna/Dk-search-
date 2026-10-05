/* "Hacking" console while a search runs (2026-10-05, per explicit
   instruction) - DK Search's green take on crm-app-v3's assets/hack.js: a
   terminal panel (middle of the screen) types out log lines and a progress bar creeps up while the
   search is loading, then stamps ACCESS GRANTED (something found) or
   ACCESS DENIED (nothing found / error) when the answer arrives.

   Decorative only, and needs no per-page code: it watches this page's own
   fetch() calls and plays for any request to a search endpoint (SEARCH_URL
   below), so every tool - single and bulk - gets it. The response is cloned
   for the found/not-found check; the page still reads its own copy exactly
   as before. The panel is fixed, pointer-events:none - it never blocks a
   click. Input values are masked in the log. Loaded on every page from
   includes/footer.php, after assets/confetti.js. */
(function () {
  'use strict';
  if (window.DKHack || !window.fetch) return;
  if (/\/admin\//.test(location.pathname)) return;   // admin pages: no searches

  // Search endpoints: api/search.php (states), api/ecommerce_search.php,
  // api/pan_india.php, every *_api.php and every *_search.php tool endpoint.
  // lpg_search_api.php is left out - it polls a job every second.
  var SEARCH_URL = /(^|\/)(api\/(search|ecommerce_search|pan_india)\.php|[a-z0-9_]+_api\.php|[a-z0-9_]+_search\.php)(\?|$)/i;
  var SKIP_URL = /lpg_search_api\.php/i;

  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var GLYPHS = '0123456789ABCDEF';
  // The hooded-hacker picture from crm-app-v3 (assets/hacker.svg), built in so
  // the panel needs no second download.
  var HACKER_SVG = "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 420 340\" role=\"img\" aria-label=\"Hooded hacker at a laptop\"><defs><radialGradient id=\"halo\" cx=\"50%\" cy=\"55%\" r=\"50%\"><stop offset=\"0\" stop-color=\"#00e88f\" stop-opacity=\".28\"/><stop offset=\".6\" stop-color=\"#22d3ee\" stop-opacity=\".06\"/><stop offset=\"1\" stop-color=\"#22d3ee\" stop-opacity=\"0\"/></radialGradient><linearGradient id=\"hood\" x1=\"0\" y1=\"0\" x2=\"0\" y2=\"1\"><stop offset=\"0\" stop-color=\"#132232\"/><stop offset=\"1\" stop-color=\"#070d14\"/></linearGradient><linearGradient id=\"rim\" x1=\"0\" y1=\"0\" x2=\"1\" y2=\"1\"><stop offset=\"0\" stop-color=\"#22d3ee\"/><stop offset=\"1\" stop-color=\"#00e88f\"/></linearGradient><radialGradient id=\"screenlight\" cx=\"50%\" cy=\"100%\" r=\"80%\"><stop offset=\"0\" stop-color=\"#00e88f\" stop-opacity=\".35\"/><stop offset=\"1\" stop-color=\"#00e88f\" stop-opacity=\"0\"/></radialGradient><filter id=\"glow\" x=\"-50%\" y=\"-50%\" width=\"200%\" height=\"200%\"><feGaussianBlur stdDeviation=\"3\" result=\"b\"/><feMerge><feMergeNode in=\"b\"/><feMergeNode in=\"SourceGraphic\"/></feMerge></filter><clipPath id=\"faceClip\"><path d=\"M210 104c-30 0-48 25-48 58s18 54 48 56c30-2 48-23 48-56s-18-58-48-58z\"/></clipPath></defs><style>\n    .blink{animation:blink 1.2s steps(1) infinite}\n    .flick{animation:flick 3.2s ease-in-out infinite}\n    .bar{animation:bar 2.4s ease-in-out infinite;transform-box:fill-box;transform-origin:left}\n    .b2{animation-delay:.4s}.b3{animation-delay:.8s}.b4{animation-delay:1.2s}\n    .float{animation:float 5s ease-in-out infinite}\n    .float2{animation:float 6s ease-in-out -2s infinite}\n    .bin{animation:rise 7s linear infinite}\n    .bin2{animation:rise 9s linear -3s infinite}\n    .spin{animation:spin 14s linear infinite;transform-box:fill-box;transform-origin:center}\n    .scanline{animation:scan 2.6s linear infinite}\n    @keyframes blink{50%{opacity:0}}\n    @keyframes flick{0%,100%{opacity:.85}45%{opacity:.55}50%{opacity:1}55%{opacity:.6}}\n    @keyframes bar{0%,100%{transform:scaleX(.35)}50%{transform:scaleX(1)}}\n    @keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}\n    @keyframes rise{from{transform:translateY(40px);opacity:0}15%{opacity:.7}to{transform:translateY(-120px);opacity:0}}\n    @keyframes spin{to{transform:rotate(360deg)}}\n    @keyframes scan{from{transform:translateY(0)}to{transform:translateY(90px)}}\n  </style><!-- halo + orbit ring --><circle cx=\"210\" cy=\"180\" r=\"160\" fill=\"url(#halo)\"/><g class=\"spin\" opacity=\".45\"><circle cx=\"210\" cy=\"180\" r=\"138\" fill=\"none\" stroke=\"#00e88f\" stroke-width=\"1\" stroke-dasharray=\"3 9\"/><circle cx=\"348\" cy=\"180\" r=\"3\" fill=\"#22d3ee\"/></g><circle cx=\"210\" cy=\"180\" r=\"118\" fill=\"none\" stroke=\"#22d3ee\" stroke-opacity=\".15\"/><!-- rising binary --><g font-family=\"monospace\" font-size=\"11\" fill=\"#00e88f\"><text class=\"bin\" x=\"36\" y=\"300\">1011</text><text class=\"bin2\" x=\"360\" y=\"310\">0110</text><text class=\"bin2\" x=\"70\" y=\"250\" fill=\"#22d3ee\">01</text><text class=\"bin\" x=\"330\" y=\"260\" style=\"animation-delay:-4s\">1101</text></g><!-- hood --><path d=\"M210 52c-52 0-86 42-89 100-2 36 5 57-11 82-21 22-48 34-54 76h308c-6-42-33-54-54-76-16-25-9-46-11-82-3-58-37-100-89-100z\"\n        fill=\"url(#hood)\" stroke=\"url(#rim)\" stroke-width=\"2\" stroke-opacity=\".8\" filter=\"url(#glow)\"/><!-- hood inner fold --><path d=\"M210 88c-40 0-64 30-66 74-1 30 6 48 18 62\" fill=\"none\" stroke=\"#22d3ee\" stroke-opacity=\".18\" stroke-width=\"2\"/><path d=\"M210 88c40 0 64 30 66 74 1 30-6 48-18 62\" fill=\"none\" stroke=\"#00e88f\" stroke-opacity=\".18\" stroke-width=\"2\"/><!-- face void, lit from the screen below --><path d=\"M210 104c-30 0-48 25-48 58s18 54 48 56c30-2 48-23 48-56s-18-58-48-58z\" fill=\"#020508\"/><g clip-path=\"url(#faceClip)\"><rect x=\"150\" y=\"150\" width=\"120\" height=\"80\" fill=\"url(#screenlight)\" class=\"flick\"/><!-- glasses reflecting code --><g class=\"flick\" filter=\"url(#glow)\"><rect x=\"173\" y=\"156\" width=\"30\" height=\"13\" rx=\"5\" fill=\"#00e88f\" fill-opacity=\".22\" stroke=\"#00e88f\" stroke-width=\"1.4\"/><rect x=\"217\" y=\"156\" width=\"30\" height=\"13\" rx=\"5\" fill=\"#00e88f\" fill-opacity=\".22\" stroke=\"#00e88f\" stroke-width=\"1.4\"/><path d=\"M203 162h14\" stroke=\"#00e88f\" stroke-width=\"1.4\"/><path d=\"M178 160h12M178 164h18M222 160h16M222 164h10\" stroke=\"#caffea\" stroke-width=\"1\" stroke-opacity=\".8\"/></g></g><!-- laptop (back of the lid faces us) --><rect x=\"128\" y=\"214\" width=\"164\" height=\"98\" rx=\"7\" fill=\"#0b1520\" stroke=\"url(#rim)\" stroke-width=\"1.6\"/><rect x=\"136\" y=\"222\" width=\"148\" height=\"82\" rx=\"4\" fill=\"none\" stroke=\"#00e88f\" stroke-opacity=\".12\"/><g filter=\"url(#glow)\"><text x=\"210\" y=\"272\" text-anchor=\"middle\" font-family=\"monospace\" font-size=\"26\" font-weight=\"700\" fill=\"#00e88f\">&lt;/&gt;</text></g><rect x=\"96\" y=\"312\" width=\"228\" height=\"8\" rx=\"3\" fill=\"#0f1c28\" stroke=\"#00e88f\" stroke-opacity=\".35\"/><path d=\"M40 322h340\" stroke=\"#00e88f\" stroke-opacity=\".25\"/><!-- floating window: left, code bars --><g class=\"float\"><rect x=\"12\" y=\"70\" width=\"112\" height=\"80\" rx=\"7\" fill=\"#07111a\" fill-opacity=\".92\" stroke=\"#00e88f\" stroke-opacity=\".55\"/><circle cx=\"24\" cy=\"81\" r=\"2.6\" fill=\"#ff5f57\"/><circle cx=\"33\" cy=\"81\" r=\"2.6\" fill=\"#febc2e\"/><circle cx=\"42\" cy=\"81\" r=\"2.6\" fill=\"#28c840\"/><g fill=\"#00e88f\"><rect class=\"bar\" x=\"22\" y=\"94\" width=\"80\" height=\"4\" rx=\"2\"/><rect class=\"bar b2\" x=\"22\" y=\"105\" width=\"60\" height=\"4\" rx=\"2\" fill=\"#22d3ee\"/><rect class=\"bar b3\" x=\"22\" y=\"116\" width=\"88\" height=\"4\" rx=\"2\"/><rect class=\"bar b4\" x=\"22\" y=\"127\" width=\"50\" height=\"4\" rx=\"2\" fill=\"#22d3ee\"/><rect x=\"22\" y=\"138\" width=\"6\" height=\"7\" class=\"blink\"/></g></g><!-- floating window: right, access granted + lock --><g class=\"float2\"><rect x=\"300\" y=\"46\" width=\"110\" height=\"86\" rx=\"7\" fill=\"#07111a\" fill-opacity=\".92\" stroke=\"#22d3ee\" stroke-opacity=\".55\"/><g transform=\"translate(355 78)\" stroke=\"#00e88f\" stroke-width=\"2.2\" fill=\"none\" filter=\"url(#glow)\"><path d=\"M-9 0v-7a9 9 0 0 1 18 0v7\"/><rect x=\"-13\" y=\"0\" width=\"26\" height=\"20\" rx=\"3\" fill=\"#00e88f\" fill-opacity=\".15\"/><circle cx=\"0\" cy=\"9\" r=\"2.6\" fill=\"#00e88f\"/></g><text x=\"355\" y=\"120\" text-anchor=\"middle\" font-family=\"monospace\" font-size=\"9\" font-weight=\"700\" fill=\"#00e88f\" class=\"blink\" letter-spacing=\"1\">ACCESS GRANTED</text><clipPath id=\"winClip\"><rect x=\"300\" y=\"46\" width=\"110\" height=\"86\" rx=\"7\"/></clipPath><rect class=\"scanline\" x=\"300\" y=\"46\" width=\"110\" height=\"2\" fill=\"#22d3ee\" fill-opacity=\".5\" clip-path=\"url(#winClip)\"/></g><!-- small floating chip: fingerprint --><g class=\"float\" style=\"animation-delay:-1.5s\"><rect x=\"322\" y=\"200\" width=\"62\" height=\"62\" rx=\"10\" fill=\"#07111a\" fill-opacity=\".92\" stroke=\"#00e88f\" stroke-opacity=\".45\"/><g transform=\"translate(353 231)\" fill=\"none\" stroke=\"#00e88f\" stroke-width=\"1.6\" stroke-linecap=\"round\" opacity=\".9\"><path d=\"M-14 4a14 14 0 0 1 28-6\"/><path d=\"M-10 10a10 10 0 0 1 2-16a10 10 0 0 1 17 6v6\"/><path d=\"M-5 14c2-4 1-8 1-12a4 4 0 0 1 8 0c0 5 0 10-2 14\"/><path d=\"M0 2c0 6-1 11-4 15\"/></g></g></svg>";
  var box = null, log, bar, pct, stamp, title, timers = [], progress = 0, active = 0, lastFinish = 0, quick = false;

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text) n.textContent = text;
    return n;
  }
  function rand(n) { return Math.random() * n | 0; }
  function hex(n) { var s = ''; while (s.length < n) s += GLYPHS.charAt(rand(16)); return s; }
  function ip() { return [10 + rand(180), rand(255), rand(255), 1 + rand(254)].join('.'); }
  function mask(v) {
    v = String(v);
    if (v.length <= 3) return '***';
    return v.slice(0, 2) + new Array(Math.min(v.length - 3, 10) + 1).join('*') + v.slice(-1);
  }
  function later(fn, ms) { timers.push(setTimeout(fn, ms)); }
  function clearTimers() { timers.forEach(clearTimeout); timers = []; }

  function styles() {
    var s = el('style');
    s.id = 'dk-hack-style';
    s.textContent = [
      /* Clean, light DK look (2026-10-05, per explicit instruction): a white
         card like DK's result cards, green title bar, pale-green log. */
      '.dkh{position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);z-index:9998;width:min(640px,calc(100vw - 32px));',
      '  background:#fff;border:1px solid #e2e2ea;border-radius:14px;overflow:hidden;pointer-events:none;',
      '  box-shadow:0 2px 8px rgba(0,0,0,.08),0 16px 40px rgba(31,41,55,.14);animation:dkhIn .25s ease;',
      '  font-family:"Inter","Segoe UI",system-ui,sans-serif;}',
      '.dkh[hidden]{display:none;}',
      /* In-page placement: directly under the search box, in the page flow (like v3), so it never covers the form. */
      '.dkh.inline{position:relative;left:auto;top:auto;bottom:auto;transform:none;width:auto;max-width:none;margin:16px 0;z-index:1;animation:dkhInI .25s ease;}',
      '.dkh.inline.closing{animation:dkhOutI .35s ease forwards;}',
      '@keyframes dkhInI{from{opacity:0;transform:translateY(-6px)}}',
      '@keyframes dkhOutI{to{opacity:0;transform:translateY(-6px)}}',
      '@keyframes dkhIn{from{opacity:0;transform:translate(-50%,-50%) scale(.96)}}',
      '.dkh.closing{animation:dkhOut .35s ease forwards;}',
      '@keyframes dkhOut{to{opacity:0;transform:translate(-50%,-50%) scale(.96)}}',
      '.dkh-head{display:flex;align-items:center;gap:10px;padding:10px 16px;background:#2e9e3f;color:#fff;',
      '  font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;}',
      '.dkh-head svg{width:15px;height:15px;flex-shrink:0;}',
      '.dkh-title{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}',
      '.dkh-pct{font:700 12px Consolas,ui-monospace,monospace;color:#fff;opacity:.95;}',
      '.dkh-body{display:grid;grid-template-columns:92px minmax(0,1fr);gap:14px;align-items:center;padding:14px 16px 16px;}',
      '.dkh-side{background:#0b1520;border-radius:12px;padding:6px;}',
      '.dkh-side img{width:100%;display:block;filter:hue-rotate(-28deg) saturate(1.1);}',
      '.dkh-log{margin:0;height:128px;overflow:hidden;padding:9px 12px;border-radius:10px;background:#f5fbf6;border:1px solid #e1efe4;',
      '  font:12px/1.6 Consolas,ui-monospace,monospace;color:#3d6f48;white-space:pre-wrap;word-break:break-all;}',
      '.dkh .hl.ok{color:#2e9e3f;font-weight:700;} .dkh .hl.dim{color:#a5c4ad;} .dkh .hl.bad{color:#d6334f;font-weight:700;}',
      '.dkh.running .hl:last-child::after{content:"";display:inline-block;width:7px;height:12px;margin-left:3px;background:#2e9e3f;vertical-align:-1px;animation:dkhBlink 1s steps(1) infinite;}',
      '@keyframes dkhBlink{50%{opacity:0}}',
      '.dkh-bar{margin-top:10px;height:8px;border-radius:6px;background:#eeeef6;border:1px solid #e0e0e0;overflow:hidden;}',
      '.dkh-bar i{display:block;height:100%;width:0;border-radius:6px;transition:width .2s linear;',
      '  background-image:repeating-linear-gradient(45deg,#2e9e3f 0 12px,#257e32 12px 24px);background-size:34px 100%;animation:dkhStripes 1s linear infinite;}',
      '@keyframes dkhStripes{from{background-position:0 0}to{background-position:-34px 0}}',
      '.dkh-stamp{position:absolute;left:0;right:0;top:38px;bottom:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;',
      '  opacity:0;background:rgba(255,255,255,.9);}',
      '.dkh-stamp b{font:800 28px/1 "Inter","Segoe UI",sans-serif;letter-spacing:5px;text-transform:uppercase;}',
      '.dkh-stamp small{font-size:12px;font-weight:600;color:#6b7280;letter-spacing:.3px;}',
      '.dkh.granted .dkh-stamp,.dkh.denied .dkh-stamp{opacity:1;animation:dkhStamp .4s cubic-bezier(.2,1.4,.4,1);}',
      '@keyframes dkhStamp{from{opacity:0;transform:scale(1.15)}}',
      '.dkh.granted .dkh-stamp b{color:#2e9e3f;}',
      '.dkh.denied .dkh-stamp b{color:#d6334f;}',
      '.dkh.granted{border-color:#2e9e3f;box-shadow:0 0 0 3px rgba(46,158,63,.18),0 16px 40px rgba(46,158,63,.22);}',
      '.dkh.denied{border-color:#e5484d;box-shadow:0 0 0 3px rgba(229,72,77,.15),0 16px 40px rgba(31,41,55,.14);}',
      '.dkh.denied .dkh-head{background:#d6334f;}',
      '.dkh.denied .dkh-bar i{background:#e5484d;animation:none;}',
      '@media (max-width:640px){.dkh-body{grid-template-columns:minmax(0,1fr);}.dkh-side{display:none;}}',
      '@media (prefers-reduced-motion: reduce){.dkh,.dkh-bar i,.dkh-stamp{animation:none!important;}}'
    ].join('\n');
    document.head.appendChild(s);
  }

  var SHIELD = '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 1.2 2.6 3.3v4.1c0 3.4 2.3 6.1 5.4 7.4 3.1-1.3 5.4-4 5.4-7.4V3.3z"/><path d="m5.6 8.1 1.7 1.7 3.2-3.3"/></svg>';

  function build() {
    styles();
    box = el('section', 'dkh');
    box.setAttribute('aria-hidden', 'true');
    var head = el('div', 'dkh-head');
    head.innerHTML = SHIELD;
    title = el('span', 'dkh-title', 'Secure search');
    pct = el('span', 'dkh-pct', '0%');
    head.appendChild(title);
    head.appendChild(pct);
    var body = el('div', 'dkh-body');
    var side = el('div', 'dkh-side');
    var img = el('img');
    img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(HACKER_SVG);
    img.alt = '';
    side.appendChild(img);
    var main = el('div', 'dkh-main');
    log = el('pre', 'dkh-log');
    bar = el('div', 'dkh-bar');
    bar.appendChild(el('i'));
    main.appendChild(log);
    main.appendChild(bar);
    body.appendChild(side);
    body.appendChild(main);
    stamp = el('div', 'dkh-stamp');
    box.appendChild(head);
    box.appendChild(body);
    box.appendChild(stamp);
    box.hidden = true;
    document.body.appendChild(box);
  }

  function line(text, cls) {
    var l = el('div', 'hl' + (cls ? ' ' + cls : ''));
    log.appendChild(l);
    while (log.children.length > 8) log.removeChild(log.firstChild);
    if (reduced) { l.textContent = text; return; }
    var i = 0;
    (function type() {
      i += 2;
      l.textContent = text.slice(0, i);
      if (i < text.length) later(type, 12);
    })();
  }

  function setProgress(p) {
    progress = p;
    bar.firstChild.style.width = p + '%';
    pct.textContent = Math.floor(p) + '%';
  }

  // The tool's name (the page heading) and the masked inputs, for the log.
  function toolName() {
    var h = document.querySelector('.page-title, h1');
    return h ? h.textContent.replace(/\s+/g, ' ').trim() : 'DK Search';
  }
  function inputsOf(url, init) {
    var vals = [];
    try {
      var q = url.indexOf('?') >= 0 ? url.slice(url.indexOf('?') + 1) : '';
      new URLSearchParams(q).forEach(function (v, k) { if (v && !/^(limit|offset|page|action|jobId)$/i.test(k)) vals.push(k + '=' + mask(v)); });
      if (init && typeof init.body === 'string' && init.body.charAt(0) === '{') {
        var b = JSON.parse(init.body);
        Object.keys(b).forEach(function (k) {
          var v = b[k];
          if (v && typeof v !== 'object') vals.push(k + '=' + mask(v));
          else if (v && typeof v === 'object') Object.keys(v).forEach(function (k2) { if (v[k2] && typeof v[k2] !== 'object') vals.push(k2 + '=' + mask(v[k2])); });
        });
      }
    } catch (e) {}
    return vals.slice(0, 4).join(' ') || 'query=***';
  }

  // What the agent clicked / pressed Enter on - the search box around it is
  // where the console goes.
  var lastTrigger = null;
  document.addEventListener('click', function (e) { lastTrigger = e.target; }, true);
  document.addEventListener('keydown', function (e) { if (e.key === 'Enter') lastTrigger = e.target; }, true);
  var BOX_CLASS = /(^|\s)([a-z0-9]+-card|card|sp-form|search-panel|panel)(\s|$)/;
  function searchBoxOf(n) {
    while (n && n !== document.body && n.nodeType === 1) {
      if (n !== box && BOX_CLASS.test(n.className || '') && !(box && box.contains(n))) return n;
      n = n.parentElement;
    }
    return null;
  }
  function place() {
    var anchor = lastTrigger && document.contains(lastTrigger) ? searchBoxOf(lastTrigger) : null;
    if (anchor && anchor.parentNode) {
      box.classList.add('inline');
      if (box.previousSibling !== anchor) anchor.parentNode.insertBefore(box, anchor.nextSibling);
    } else {
      box.classList.remove('inline');
      if (box.parentNode !== document.body) document.body.appendChild(box);
    }
  }

  function start(url, init) {
    if (!box) build();
    quick = Date.now() - lastFinish < 2500;
    clearTimers();
    box.className = 'dkh running';
    place();
    box.hidden = false;
    if (box.classList.contains('inline')) { try { box.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'nearest' }); } catch (e) {} }
    log.textContent = '';
    stamp.textContent = '';
    title.textContent = 'Secure search \u00b7 ' + toolName();
    setProgress(0);
    var script = [
      ['[*] target locked  -> ' + toolName(), ''],
      ['[*] resolving secure gateway ' + ip() + ':443', ''],
      ['[+] TLS 1.3 handshake ok  cipher=AES-256-GCM', 'ok'],
      ['[*] injecting query payload  ' + inputsOf(url, init), ''],
      ['[*] tunnelling via proxy chain [' + ip() + ' > ' + ip() + ']', ''],
      ['[*] bypassing rate shield ... done', 'ok'],
      ['[*] awaiting response stream', '']
    ];
    script.forEach(function (s, i) { later(function () { line(s[0], s[1]); }, i * 320); });
    (function tick() {
      if (progress < 92) setProgress(progress + Math.max(0.4, (92 - progress) * 0.06));
      later(tick, 120);
    })();
    later(function dump() {
      line('    0x' + hex(4) + '  ' + hex(8) + ' ' + hex(8) + ' ' + hex(8), 'dim');
      later(dump, 420);
    }, script.length * 320 + 200);
  }

  function finish(ok, message) {
    if (!box) return Promise.resolve();
    clearTimers();
    lastFinish = Date.now();
    setProgress(ok ? 100 : progress);
    box.classList.remove('running');
    box.classList.add(ok ? 'granted' : 'denied');
    if (ok) {
      line('[+] decryption complete  records extracted', 'ok');
      stamp.innerHTML = '<b>Access Granted</b><small>Records extracted</small>';
    } else {
      line('[!] ' + (message || 'no records found'), 'bad');
      stamp.innerHTML = '<b>Access Denied</b><small></small>';
      stamp.lastChild.textContent = message || 'No records found';
    }
    // The page gets its answer (and shows the result) only once the stamp
    // has been seen (per explicit instruction, 2026-10-05) - as in v3. Back-
    // to-back searches (a bulk run) hold the stamp only briefly.
    var hold = quick ? 450 : (reduced ? 700 : 1300);
    // Resolved only after the popup has fully closed, so the result shows up
    // once the console is gone (per explicit instruction, 2026-10-05).
    return new Promise(function (resolve) {
      later(function () {
        box.classList.add('closing');
        later(function () { box.hidden = true; box.classList.remove('closing'); resolve(); }, quick ? 200 : 380);
      }, hold);
    });
  }

  function hide() {
    if (!box) return;
    clearTimers();
    box.hidden = true;
  }

  // Did this answer find anything? Each tool shapes its JSON differently, so
  // look for any of the usual "found" signals.
  function hasData(v) {
    if (Array.isArray(v)) return v.length > 0;
    if (v && typeof v === 'object') return Object.keys(v).length > 0;
    return false;
  }
  function judge(res, data) {
    if (!res.ok) return { ok: false, msg: (data && (data.error || data.message)) || ('request failed (HTTP ' + res.status + ')') };
    if (!data || typeof data !== 'object') return { ok: false, msg: 'unexpected response' };
    if (data.ok === false || data.success === false) return { ok: false, msg: data.error || data.message || 'request rejected' };
    if (data.found === true) return { ok: true };
    if (data.found === false) return { ok: false, msg: data.message || 'no records found' };
    if (Number(data.totalResults) > 0 || Number(data.total) > 0 || Number(data.count) > 0) return { ok: true };
    var keys = ['rows', 'records', 'results', 'data', 'accounts', 'sections', 'members'];
    for (var i = 0; i < keys.length; i++) if (hasData(data[keys[i]])) return { ok: true };
    return { ok: false, msg: data.error || data.message || 'no records found' };
  }

  var realFetch = window.fetch;
  window.fetch = function (input, init) {
    var url = typeof input === 'string' ? input : (input && input.url) || '';
    if (!SEARCH_URL.test(url) || SKIP_URL.test(url)) return realFetch.apply(this, arguments);
    active++;
    try { start(url, init); } catch (e) {}
    var p = realFetch.apply(this, arguments);
    // The page's own promise settles only after the console has stamped
    // its verdict, so the result appears after ACCESS GRANTED / DENIED.
    return new Promise(function (resolve, reject) {
      p.then(function (res) {
        if (res.status === 401) { active = Math.max(0, active - 1); hide(); resolve(res); return; }   // signed out - page redirects
        res.clone().json().then(function (data) {
          var v = judge(res, data);
          return finish(v.ok, v.msg);
        }, function () {
          return finish(res.ok, res.ok ? '' : 'request failed (HTTP ' + res.status + ')');
        }).then(function () {
          active = Math.max(0, active - 1);
          resolve(res);
        }, function () {
          active = Math.max(0, active - 1);
          resolve(res);
        });
      }, function (err) {
        finish(false, 'connection lost').then(function () {
          active = Math.max(0, active - 1);
          reject(err);
        });
      });
    });
  };

  // The ACCESS GRANTED badge from assets/confetti.js would repeat what this
  // console just stamped - skip it right after a console run.
  if (typeof window.showAccessGranted === 'function') {
    var badge = window.showAccessGranted;
    window.showAccessGranted = function () {
      if (active > 0 || Date.now() - lastFinish < 3000) return;
      badge.apply(this, arguments);
    };
  }

  window.DKHack = { start: start, finish: finish, hide: hide };
})();

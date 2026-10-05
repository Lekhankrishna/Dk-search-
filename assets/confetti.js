/* Confetti (2026-08-08) - a subtle celebratory float, only while a search
   has actually found something. Pure CSS animation (transform only, so
   it's GPU-composited) with one <div> per piece; JS just randomizes each
   piece's color/size/timing once up front rather than driving the motion
   itself, so ~220 of these costs nothing on the main thread while they run.
   Negative animation-delay starts each piece mid-cycle instead of every
   piece beginning together at the top, so it reads as an ambient drift
   already in progress rather than one single burst.

   Shared across every search page (index.php, ecommerce.php, rc_print.php,
   hp_gas.php, advance_pan_india.php, lpg_search.php, lpg_bulk_search.php) - each
   page just needs its own <div class="confetti-container" id="confetti-
   container"></div> and to call startConfetti()/stopConfetti() from its own
   search success/clear handlers. */
const CONFETTI_COLORS = ['#facc15', '#22c55e', '#3b82f6', '#ec4899', '#f97316', '#a855f7'];
const CONFETTI_COUNT = 220;
const CONFETTI_DURATION_MS = 2000;
let confettiTimer = null;

function startConfetti() {
  const container = document.getElementById('confetti-container');
  if (!container) return;
  container.innerHTML = '';
  for (let i = 0; i < CONFETTI_COUNT; i++) {
    const el = document.createElement('div');
    el.className = 'confetti-piece';
    const duration = 10 + Math.random() * 12;
    const delay = Math.random() * duration;
    el.style.left = `${Math.random() * 100}%`;
    el.style.width = `${4 + Math.random() * 5}px`;
    el.style.height = `${2 + Math.random() * 2}px`;
    el.style.background = CONFETTI_COLORS[Math.floor(Math.random() * CONFETTI_COLORS.length)];
    el.style.animationDuration = `${duration}s`;
    el.style.animationDelay = `-${delay}s`;
    el.style.setProperty('--rot-start', `${Math.random() * 360}deg`);
    el.style.setProperty('--sway', `${20 + Math.random() * 40}px`);
    container.appendChild(el);
  }

  // Runs for CONFETTI_DURATION_MS only, not for as long as results stay on
  // screen - cleared and reset on every call so back-to-back searches each
  // get their own full run rather than the timer from an earlier search
  // cutting a later one short.
  clearTimeout(confettiTimer);
  confettiTimer = setTimeout(stopConfetti, CONFETTI_DURATION_MS);
}

function stopConfetti() {
  clearTimeout(confettiTimer);
  const container = document.getElementById('confetti-container');
  if (container) container.innerHTML = '';
}

/* ACCESS GRANTED stamp (2026-10-05, per explicit instruction) - DK Search's
   own green take on crm-app-v3's success stamp (its assets/hack.js), shown
   for a moment whenever a search has found something. Hooked into
   startConfetti() below, since every search page already calls that on a
   real result - so every page gets it without per-page changes. Fixed,
   pointer-events:none overlay: never blocks a click. Shown at most once per
   few seconds so a bulk run doesn't flash it per number. */
const ACCESS_GRANTED_MS = 1500;
const ACCESS_GRANTED_MIN_GAP_MS = 4000;
let accessGrantedLastAt = 0;
let accessGrantedTimer = null;

function ensureAccessGrantedStyles() {
  if (document.getElementById('access-granted-style')) return;
  const style = document.createElement('style');
  style.id = 'access-granted-style';
  style.textContent = `
  .ag-stamp-wrap{position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;
    pointer-events:none;background:radial-gradient(ellipse at center,rgba(46,158,63,.16),rgba(46,158,63,0) 60%);
    animation:agStampBg 1.5s ease forwards;}
  .ag-stamp{position:relative;overflow:hidden;display:flex;align-items:center;gap:14px;padding:18px 30px;border-radius:14px;
    background:rgba(255,255,255,.96);border:2px solid #2e9e3f;color:#1f7a2e;
    box-shadow:0 0 0 4px rgba(46,158,63,.15),0 12px 40px rgba(46,158,63,.35);
    font:800 26px/1 "Consolas","SFMono-Regular",ui-monospace,monospace;letter-spacing:4px;text-transform:uppercase;
    animation:agStampIn .45s cubic-bezier(.2,1.6,.4,1) both, agStampOut .4s ease 1.1s forwards;}
  .ag-stamp i{font-size:34px;color:#2e9e3f;}
  .ag-stamp small{display:block;margin-top:6px;font:600 11px/1 "Segoe UI",sans-serif;letter-spacing:1.5px;color:#4b8f57;}
  .ag-stamp::after{content:"";position:absolute;top:0;bottom:0;width:40%;left:-40%;
    background:linear-gradient(90deg,transparent,rgba(46,158,63,.18),transparent);animation:agStampScan 1s ease .2s;}
  @keyframes agStampIn{from{opacity:0;transform:scale(1.6) rotate(-4deg);}to{opacity:1;transform:scale(1) rotate(-2deg);}}
  @keyframes agStampOut{to{opacity:0;transform:scale(.92) rotate(-2deg);}}
  @keyframes agStampScan{to{left:110%;}}
  @keyframes agStampBg{0%{opacity:0}15%{opacity:1}75%{opacity:1}100%{opacity:0}}
  @media (prefers-reduced-motion: reduce){
    .ag-stamp,.ag-stamp-wrap,.ag-stamp::after{animation:none!important;}
  }`;
  document.head.appendChild(style);
}

function showAccessGranted(subtitle) {
  const now = Date.now();
  if (now - accessGrantedLastAt < ACCESS_GRANTED_MIN_GAP_MS) return;
  accessGrantedLastAt = now;
  ensureAccessGrantedStyles();
  document.querySelectorAll('.ag-stamp-wrap').forEach(n => n.remove());
  const wrap = document.createElement('div');
  wrap.className = 'ag-stamp-wrap';
  wrap.setAttribute('aria-hidden', 'true');
  wrap.innerHTML = '<div class="ag-stamp"><i class="bi bi-shield-fill-check"></i><div>Access Granted<small></small></div></div>';
  wrap.querySelector('small').textContent = subtitle || 'Result found';
  document.body.appendChild(wrap);
  clearTimeout(accessGrantedTimer);
  accessGrantedTimer = setTimeout(() => wrap.remove(), ACCESS_GRANTED_MS + 100);
}

// Every successful search on every page goes through startConfetti().
const startConfettiBase = startConfetti;
startConfetti = function () {
  startConfettiBase();
  showAccessGranted();
};

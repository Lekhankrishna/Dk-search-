/* Confetti (2026-08-08) - a subtle celebratory float, only while a search
   has actually found something. Pure CSS animation (transform only, so
   it's GPU-composited) with one <div> per piece; JS just randomizes each
   piece's color/size/timing once up front rather than driving the motion
   itself, so ~220 of these costs nothing on the main thread while they run.
   Negative animation-delay starts each piece mid-cycle instead of every
   piece beginning together at the top, so it reads as an ambient drift
   already in progress rather than one single burst.

   Shared across every search page (index.php, ecommerce.php, rc_print.php,
   hp_gas.php, eagle_eye.php, lpg_search.php, lpg_bulk_search.php) - each
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

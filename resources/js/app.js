import registerChat from './chat';
import { initCheckout } from './checkout/index.js';
import { initVoice } from './voice.js';

/**
 * Aziv AI — customer application behaviour.
 *
 * Deliberately framework-free at this stage: Livewire and Alpine arrive with
 * the interactive features in Phase 2+. Navigation must work without them,
 * including before hydration.
 */

/* --------------------------------------------------------------------------
 * Navigation drawer
 * Focus is trapped while open and restored on close; Escape closes it. A
 * drawer that strands keyboard focus behind an overlay is unusable with a
 * keyboard or a screen reader.
 * ----------------------------------------------------------------------- */
function initDrawer() {
  const drawer = document.querySelector('[data-drawer]');
  if (!drawer) return;

  const openers = document.querySelectorAll('[data-drawer-open]');
  const closers = drawer.querySelectorAll('[data-drawer-close]');
  let lastFocused = null;

  const focusable = () =>
    drawer.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])');

  function open() {
    lastFocused = document.activeElement;
    drawer.hidden = false;
    document.body.style.overflow = 'hidden';
    openers.forEach((b) => b.setAttribute('aria-expanded', 'true'));
    focusable()[0]?.focus();
    document.addEventListener('keydown', onKeydown);
  }

  function close() {
    drawer.hidden = true;
    document.body.style.overflow = '';
    openers.forEach((b) => b.setAttribute('aria-expanded', 'false'));
    lastFocused?.focus();
    document.removeEventListener('keydown', onKeydown);
  }

  function onKeydown(e) {
    if (e.key === 'Escape') return close();
    if (e.key !== 'Tab') return;

    const items = [...focusable()];
    if (!items.length) return;
    const first = items[0];
    const last = items[items.length - 1];

    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  }

  openers.forEach((b) => b.addEventListener('click', open));
  closers.forEach((b) => b.addEventListener('click', close));

  // Returning to desktop width while the drawer is open would otherwise leave
  // a stuck overlay over a perfectly good sidebar.
  window.matchMedia('(min-width: 768px)').addEventListener('change', (e) => {
    if (e.matches && !drawer.hidden) close();
  });
}

/* --------------------------------------------------------------------------
 * Bottom navigation: hide on scroll down, restore on scroll up.
 * ----------------------------------------------------------------------- */
function initBottomBar() {
  const bar = document.querySelector('[data-bottom-bar]');
  if (!bar) return;

  let lastY = window.scrollY;
  bar.style.transition = 'transform .2s ease';

  window.addEventListener(
    'scroll',
    () => {
      const y = window.scrollY;
      if (Math.abs(y - lastY) < 8) return;
      // Never hide it at the very top, or it flickers on small bounces.
      bar.style.transform = y > lastY && y > 80 ? 'translateY(100%)' : 'translateY(0)';
      lastY = y;
    },
    { passive: true },
  );
}

document.addEventListener('DOMContentLoaded', () => {
  initDrawer();
  initBottomBar();
  // A no-op on every page without a checkout mount, and the gateway's own
  // script is fetched only when the customer presses Pay.
  initCheckout();
  // A no-op on every page without a microphone button or a play button, and
  // the microphone is only ever requested when somebody presses record.
  initVoice();
  registerServiceWorker();
});

/**
 * The app shell (Owner Addendum A, Phase 9).
 *
 * REGISTERED ONLY OVER HTTPS OR ON LOCALHOST, because browsers refuse it
 * anywhere else and the console error reads like a bug in the application.
 *
 * The worker itself caches the compiled assets and one offline page and
 * nothing else — no conversation, no invoice, no generated image. A service
 * worker cache is origin-scoped and survives sign-out, so anything private in
 * it would be readable by the next person to use the device.
 */
function registerServiceWorker() {
  if (!('serviceWorker' in navigator)) return;
  if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') return;

  // After load, so it never competes with the first paint for bandwidth.
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/service-worker.js', { scope: '/' }).catch(() => {
      // A shell that fails to register is a slower repeat visit, never a
      // broken site. Nothing here is worth an error in a customer's console.
    });
  });
}

// Chat behaviour registers itself as an Alpine component (Phase 4).
registerChat();

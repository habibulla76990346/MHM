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
});

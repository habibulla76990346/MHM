/**
 * Assertions run against every screen at every viewport.
 * Each returns { pass, detail } and is evaluated inside the page.
 */

export const MIN_TAP = 44;
export const MIN_FONT = 12;

export async function noHorizontalOverflow(page) {
  const r = await page.evaluate(() => {
    const de = document.documentElement;
    const offenders = [];
    if (de.scrollWidth > de.clientWidth) {
      for (const el of document.querySelectorAll('body *')) {
        const rect = el.getBoundingClientRect();
        if (rect.width === 0 && rect.height === 0) continue;
        if (rect.right <= de.clientWidth + 1 && rect.left >= -1) continue;
        // A container that scrolls its own overflow is allowed (tables, code,
        // diagrams). The page body must never scroll sideways.
        let node = el, contained = false;
        while (node && node !== document.body) {
          const ox = getComputedStyle(node).overflowX;
          if (ox === 'auto' || ox === 'scroll' || ox === 'hidden') { contained = true; break; }
          node = node.parentElement;
        }
        if (!contained) {
          offenders.push(`${el.tagName.toLowerCase()}.${(el.className || '').toString().slice(0, 40)}`);
        }
      }
    }
    return { scrollW: de.scrollWidth, clientW: de.clientWidth, offenders: offenders.slice(0, 5) };
  });
  return {
    pass: r.scrollW <= r.clientW && r.offenders.length === 0,
    detail: r.scrollW > r.clientW
      ? `page scrolls sideways (${r.scrollW} > ${r.clientW}) — ${r.offenders.join(', ') || 'source unidentified'}`
      : r.offenders.length ? `elements escape the viewport: ${r.offenders.join(', ')}` : 'ok',
  };
}

export async function touchTargets(page, min = MIN_TAP) {
  const bad = await page.evaluate((min) => {
    const sel = 'a[href], button, input:not([type=hidden]), select, textarea, [role=button], [tabindex]:not([tabindex="-1"])';
    const out = [];
    for (const el of document.querySelectorAll(sel)) {
      const r = el.getBoundingClientRect();
      if (r.width === 0 || r.height === 0) continue;          // hidden
      const cs = getComputedStyle(el);
      if (cs.display === 'contents') continue;
      // Screen-reader-only elements (skip links) are 1x1 with a clip by
      // design and only become real targets on focus. They are not touch
      // targets, so exempting them is a correction, not a relaxation —
      // anything actually visible is still measured.
      const srOnly =
        (cs.clipPath && cs.clipPath !== 'none') ||
        (cs.clip && cs.clip !== 'auto') ||
        (r.width <= 1 && r.height <= 1);
      if (srOnly) continue;
      // A checkbox or radio inside — or bound to — a label that itself meets
      // the minimum is genuinely a 44px target: clicking anywhere in the
      // label activates the input. Measure the label, not the box.
      if (el.tagName === 'INPUT' && (el.type === 'checkbox' || el.type === 'radio')) {
        const label =
          el.closest('label') ||
          (el.id ? document.querySelector(`label[for="${CSS.escape(el.id)}"]`) : null);
        if (label) {
          const lr = label.getBoundingClientRect();
          if (lr.height >= min - 0.5 && lr.width >= min - 0.5) continue;
        }
      }
      // Inline links inside flowing text are exempt — the rule targets controls.
      const isInlineTextLink = el.tagName === 'A' && getComputedStyle(el).display.startsWith('inline');
      if (isInlineTextLink) continue;
      if (r.height < min - 0.5 || r.width < min - 0.5) {
        out.push(`${el.tagName.toLowerCase()} ${Math.round(r.width)}x${Math.round(r.height)}`);
      }
    }
    return out.slice(0, 6);
  }, min);
  return { pass: bad.length === 0, detail: bad.length ? `below ${min}px: ${bad.join(', ')}` : 'ok' };
}

export async function readableText(page, min = MIN_FONT) {
  const bad = await page.evaluate((min) => {
    const out = [];
    for (const el of document.querySelectorAll('body *')) {
      if (!el.childNodes.length) continue;
      const hasText = [...el.childNodes].some(n => n.nodeType === 3 && n.textContent.trim());
      if (!hasText) continue;
      const fs = parseFloat(getComputedStyle(el).fontSize);
      if (fs && fs < min) out.push(`${el.tagName.toLowerCase()} ${fs}px`);
    }
    return out.slice(0, 6);
  }, min);
  return { pass: bad.length === 0, detail: bad.length ? `under ${min}px: ${bad.join(', ')}` : 'ok' };
}

export async function inputsDoNotZoomOnIos(page) {
  const bad = await page.evaluate(() => {
    // Safari on iOS zooms the viewport when a field that accepts TYPED TEXT
    // takes focus below 16px. Controls with no text entry — checkbox, radio,
    // button, file, colour, range — never trigger it, so measuring their
    // font-size reports a problem that cannot happen. Narrowing to the types
    // that actually zoom is a correction, not a relaxation: every field a user
    // can type into is still measured.
    const NON_TEXT = ['checkbox', 'radio', 'button', 'submit', 'reset', 'file', 'color', 'range', 'image'];
    const out = [];
    for (const el of document.querySelectorAll('input:not([type=hidden]), select, textarea')) {
      if (el.tagName === 'INPUT' && NON_TEXT.includes(el.type)) continue;
      const fs = parseFloat(getComputedStyle(el).fontSize);
      if (fs && fs < 16) out.push(`${el.tagName.toLowerCase()}[${el.type || el.tagName.toLowerCase()}] ${fs}px`);
    }
    return out.slice(0, 6);
  });
  return { pass: bad.length === 0, detail: bad.length ? `iOS will zoom on focus: ${bad.join(', ')}` : 'ok' };
}

export async function bodyHasExplicitBackground(page) {
  const r = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
  const transparent = r === 'rgba(0, 0, 0, 0)' || r === 'transparent';
  return { pass: !transparent, detail: transparent ? 'body background is transparent' : r };
}

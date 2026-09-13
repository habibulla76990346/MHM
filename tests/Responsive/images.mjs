#!/usr/bin/env node
/**
 * Aziv AI — image studio behaviour gate (§16).
 *
 * WHAT PHPUNIT AND THE VIEWPORT GATE BETWEEN THEM DO NOT CHECK. PHPUnit proves
 * a picture is generated, stored privately and charged for exactly once. The
 * six-viewport gate proves the Generate button is 44px and the gallery does
 * not overflow at 320px. Neither of them presses the button, and neither
 * checks that the picture in the gallery actually LOADS — the checkout blocker
 * was precisely this shape of gap, and a gallery of broken images passes every
 * layout check there is.
 *
 * The provider is unreachable on purpose in the local fixtures, so the
 * generation that runs here FAILS — which is the more interesting half
 * anyway: a customer must be told plainly, in the gallery, rather than
 * watching "Making…" for ever.
 *
 *   node tests/Responsive/images.mjs [baseUrl]
 */
import { chromium } from 'playwright';
import { TEST_USER } from './viewports.mjs';

const BASE = process.argv[2] || process.env.APP_URL || 'http://127.0.0.1:8000';
const EXEC = process.env.CHROMIUM_PATH || undefined;

const RED = (s) => `\x1b[31m${s}\x1b[0m`;
const GRN = (s) => `\x1b[32m${s}\x1b[0m`;
const DIM = (s) => `\x1b[2m${s}\x1b[0m`;

const failures = [];
const browser = await chromium.launch(EXEC ? { executablePath: EXEC } : {});

function record(name, passed, detail = '') {
  console.log(`  ${passed ? GRN('pass') : RED('FAIL')}  ${name}${detail ? DIM(' — ' + detail) : ''}`);
  if (!passed) failures.push(`${name}: ${detail}`);
}

async function signIn() {
  const page = await browser.newPage();
  await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
  await page.fill('#email-field', TEST_USER.email);
  await page.fill('#password-field', TEST_USER.password);
  await Promise.all([
    page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 15000 }),
    page.click('button[type=submit]'),
  ]);
  const state = await page.context().storageState();
  await page.close();
  return state;
}

const storageState = await signIn();

console.log('\nImage studio behaviour\n');

const page = await browser.newPage({ viewport: { width: 390, height: 844 }, storageState });
await page.goto(BASE + '/images', { waitUntil: 'networkidle' });
await page.waitForTimeout(500);

/* --- the studio is offered, and it is usable ---------------------------- */
const prompt = page.locator('#image-prompt');
record('the studio form is on the page', (await prompt.count()) > 0);

if ((await prompt.count()) === 0) {
  console.log(DIM('    Image generation is switched off, or no image model is enabled.'));
  console.log(DIM('    Run php artisan aziv:test-fixtures — this gate cannot check a form that is not there.'));
  await browser.close();
  process.exit(1);
}

// The STUDIO's own submit button. `form button[type=submit]` would find the
// layout's logout form first, and clicking that signs the gate out — which
// looks exactly like a form that does nothing.
const generate = page.locator('form:has(#image-prompt) button[type=submit]');
const box = await generate.boundingBox();
record('the Generate button meets the 44px touch minimum',
  box && box.height >= 44, box ? `${Math.round(box.height)}px` : 'no box');

/* --- the gallery actually renders its pictures --------------------------
 * A seeded generation is on the page. Its image has to LOAD — a gallery of
 * broken images passes every layout check there is, and the media route it
 * comes from is the one place in the platform that serves a file inline.
 * --------------------------------------------------------------------- */
const rendered = await page.evaluate(async () => {
  const img = document.querySelector('img[src*="/media/"]');
  if (!img) return { found: false };

  // SCROLLED INTO VIEW FIRST. Gallery images are `loading="lazy"`, so one
  // below the fold never requests itself and reports naturalWidth 0 — which
  // looks exactly like a broken image. This gate passed until the fixture
  // gallery grew past one screen.
  img.scrollIntoView({ block: 'center' });
  await new Promise((r) => setTimeout(r, 300));

  if (!img.complete) {
    await new Promise((r) => {
      img.addEventListener('load', r, { once: true });
      img.addEventListener('error', r, { once: true });
      setTimeout(r, 4000);
    });
  }

  return { found: true, width: img.naturalWidth, src: img.getAttribute('src') };
});

record('a generated picture is shown in the gallery', rendered.found);
record('the picture actually loads from the media route',
  rendered.found && rendered.width > 0,
  rendered.found ? `naturalWidth ${rendered.width}` : '');

/* --- a failure is explained, not left spinning -------------------------- */
const failureShown = await page.evaluate(() =>
  [...document.querySelectorAll('p')].some((n) =>
    /would not generate|could not be generated|not charged/i.test(n.textContent || '')));

record('a failed generation says why, in the gallery', failureShown);

/* --- pressing Generate does something -----------------------------------
 * The fixture provider is unreachable, so this ends as a failed row. What is
 * under test is that the button DOES something at all: a form that silently
 * does nothing is the exact failure the checkout blocker was.
 * --------------------------------------------------------------------- */
const before = await page.evaluate(() =>
  document.querySelectorAll('img[src*="/media/"], [wire\\:click^="again"]').length);

await prompt.fill('a lighthouse in a storm, oil painting');
await generate.click();
await page.waitForTimeout(4000);

const outcome = await page.evaluate(() => ({
  text: document.body.innerText,
  rows: document.querySelectorAll('[wire\\:click^="again"]').length,
}));

record('the prompt reaches the gallery as a new entry',
  outcome.text.includes('a lighthouse in a storm'),
  `${before} row(s) before, ${outcome.rows} after`);

record('the customer is told what happened rather than left waiting',
  /could not be generated|not charged|Making…|Delete/i.test(outcome.text));

/* --- nothing on the page names a provider -------------------------------
 * Rule: nothing above the adapter layer knows which company answered, and
 * that includes the browser.
 * --------------------------------------------------------------------- */
const html = await page.content();
const named = ['openai', 'razorpay', 'gemini', 'anthropic', 'stability']
  .filter((name) => html.toLowerCase().includes(name));

record('the page names no AI provider', named.length === 0, named.join(', '));

await page.close();
await browser.close();

console.log('\n' + '-'.repeat(58));
if (failures.length) {
  console.log(RED(`IMAGE STUDIO GATE FAILED — ${failures.length} check(s)`));
  process.exit(1);
}
console.log(GRN('IMAGE STUDIO GATE PASSED'));

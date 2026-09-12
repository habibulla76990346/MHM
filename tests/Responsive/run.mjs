#!/usr/bin/env node
/**
 * Aziv AI — responsive gate.
 *
 * Runs every screen at every viewport and asserts the rules from
 * Owner Addendum A. A screen failing at ANY viewport fails the phase.
 *
 *   node tests/Responsive/run.mjs [baseUrl]
 *
 * Exits non-zero on any failure so CI treats it as a build break.
 */
import { chromium } from 'playwright';
import { VIEWPORTS, SCREENS, TEST_USER, TEST_ADMIN } from './viewports.mjs';
import * as C from './checks.mjs';

const BASE = process.argv[2] || process.env.APP_URL || 'http://127.0.0.1:8000';
const EXEC = process.env.CHROMIUM_PATH || undefined;

const CHECKS = [
  ['no horizontal overflow', C.noHorizontalOverflow],
  ['touch targets >= 44px',  C.touchTargets],
  ['text >= 12px',           C.readableText],
  ['inputs >= 16px (no iOS zoom)', C.inputsDoNotZoomOnIos],
  ['body has explicit background',  C.bodyHasExplicitBackground],
];

/**
 * Some screens hide the thing worth checking until someone interacts.
 *
 * A form section behind a choice, or a repeater with no rows, renders nothing
 * — and a gate that measures only what is visible would pass it by default.
 * Each preparer opens one of those, so the fields inside are measured like
 * every other field.
 */
const PREPARERS = {
  async customApiMapping(page) {
    // "Custom API" is the one adapter that needs a mapping described by hand,
    // so choosing it is what reveals the builder.
    await page.selectOption('select[id$="adapter_type"]', 'custom_http');
    await page.waitForTimeout(700);

    const add = page.locator('button:has-text("Add to mappings")').first();

    if (await add.count()) {
      await add.click();
      await page.waitForTimeout(900);
    }
  },
};

const RED = s => `\x1b[31m${s}\x1b[0m`;
const GRN = s => `\x1b[32m${s}\x1b[0m`;
const DIM = s => `\x1b[2m${s}\x1b[0m`;

const failures = [];
const browser = await chromium.launch(EXEC ? { executablePath: EXEC } : {});

/**
 * Signing in once and reusing the storage state keeps the gate fast: without
 * it, every screen at every viewport would repeat a full login.
 */
async function authenticate(loginPath, user, emailSelector, passwordSelector) {
  const page = await browser.newPage();
  await page.goto(BASE + loginPath, { waitUntil: 'networkidle' });
  await page.fill(emailSelector, user.email);
  await page.fill(passwordSelector, user.password);
  await Promise.all([
    page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 15000 }),
    page.click('button[type=submit]'),
  ]);
  const state = await page.context().storageState();
  await page.close();
  return state;
}

async function signIn(kind, run, hint) {
  try {
    return await run();
  } catch (e) {
    console.log(RED(`Could not sign in for ${kind} screens: ${e.message}`));
    console.log(DIM(hint));
    await browser.close();
    process.exit(1);
  }
}

let storageState;
let adminState;

if (SCREENS.some((s) => s.auth)) {
  storageState = await signIn(
    'customer',
    () => authenticate('/login', TEST_USER, '#email-field', '#password-field'),
    'Run: php artisan aziv:test-user',
  );
}

if (SCREENS.some((s) => s.admin)) {
  // Filament renders its own login form, so the field selectors differ from
  // the customer one.
  adminState = await signIn(
    'admin',
    () => authenticate('/admin/login', TEST_ADMIN, 'input[id$="email"]', 'input[type="password"]'),
    'Run: php artisan aziv:test-user --admin',
  );
}

/**
 * Some screens need an id that only exists at runtime — checkout needs a plan
 * that is actually on sale. Resolved by reading the pricing page as the signed
 * in customer, exactly as a customer would reach it.
 *
 * A screen whose placeholder cannot be resolved FAILS. Skipping it would mean
 * the gate quietly stopped checking the one screen Addendum A names by name.
 */
async function resolvePlaceholders(screens) {
  if (!screens.some((s) => s.path.includes('__PLAN__'))) return screens;

  const page = await browser.newPage({ storageState });
  let planPath = null;

  try {
    await page.goto(BASE + '/pricing', { waitUntil: 'networkidle' });
    planPath = await page.evaluate(() => {
      const link = [...document.querySelectorAll('a[href*="/checkout/"]')][0];
      return link ? new URL(link.href).pathname : null;
    });
  } catch {
    planPath = null;
  }

  await page.close();

  if (!planPath) {
    console.log(RED('Could not find a plan on sale, so checkout cannot be checked.'));
    console.log(DIM('Run: php artisan aziv:test-fixtures'));
    await browser.close();
    process.exit(1);
  }

  return screens.map((s) => (s.path.includes('__PLAN__') ? { ...s, path: planPath } : s));
}

const RESOLVED = await resolvePlaceholders(SCREENS);

for (const screen of RESOLVED) {
  console.log(`\n${screen.name}  ${DIM(BASE + screen.path)}`);
  for (const vp of VIEWPORTS) {
    const page = await browser.newPage({
      viewport: { width: vp.width, height: vp.height },
      ...(screen.admin ? { storageState: adminState } : screen.auth ? { storageState } : {}),
    });
    let line = `  ${vp.name.padEnd(9)} ${DIM(vp.class.padEnd(8))}`;
    try {
      const res = await page.goto(BASE + screen.path, { waitUntil: 'networkidle', timeout: 20000 });
      if (!res || res.status() >= 400) throw new Error(`HTTP ${res ? res.status() : 'no response'}`);
      if (screen.prepare) {
        // A preparer that cannot do its job is a FAILURE, not a skip: the
        // fields it was there to reveal would otherwise go unchecked while
        // the screen still reported green.
        await PREPARERS[screen.prepare](page);
      }
      const bad = [];
      for (const [label, fn] of CHECKS) {
        const r = await fn(page);
        if (!r.pass) { bad.push(`${label}: ${r.detail}`); }
      }
      if (bad.length) {
        console.log(`${line} ${RED('FAIL')}`);
        bad.forEach(b => console.log(`      ${RED('x')} ${b}`));
        failures.push({ screen: screen.name, viewport: vp.name, issues: bad });
      } else {
        console.log(`${line} ${GRN('pass')}`);
      }
    } catch (e) {
      console.log(`${line} ${RED('ERROR')} ${e.message}`);
      failures.push({ screen: screen.name, viewport: vp.name, issues: [e.message] });
    }
    await page.close();
  }
}
await browser.close();

const total = SCREENS.length * VIEWPORTS.length;
console.log('\n' + '-'.repeat(58));
if (failures.length) {
  console.log(RED(`RESPONSIVE GATE FAILED — ${failures.length} of ${total} checks failed`));
  process.exit(1);
}
console.log(GRN(`RESPONSIVE GATE PASSED — ${total} screen/viewport combinations clean`));

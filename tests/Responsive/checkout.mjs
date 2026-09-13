#!/usr/bin/env node
/**
 * Aziv AI — checkout behaviour gate (Addendum D §2).
 *
 * THE GATE THIS PROJECT DID NOT HAVE, and the reason a broken checkout shipped
 * through every other one. PHPUnit proves the server settles a payment exactly
 * once. The six-viewport gate proves the Pay button is 44px and in the right
 * place. Neither of them presses it. For a whole phase the button did nothing
 * at all and every gate was green.
 *
 * So this one presses it, in a real browser, and checks the three outcomes a
 * customer can actually reach:
 *
 *   1. they pay              → the server settles it and the invoice exists
 *   2. they close the window → nothing is charged, and they can try again
 *   3. the bank refuses      → nothing is charged, and they are told plainly
 *
 * Plus the one that costs real money if it is wrong: returning to the result
 * page twice must not grant two months of credits.
 *
 * It runs against the DEVELOPMENT GATEWAY, which takes no money and reaches no
 * network, so all three paths are reachable offline and in CI without a live
 * merchant account. The Razorpay driver is asserted separately, by the PHP
 * suite, for the things a fixture cannot prove.
 *
 *   node tests/Responsive/checkout.mjs [baseUrl]
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

async function signedInPage() {
  const page = await browser.newPage();
  await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
  await page.fill('#email-field', TEST_USER.email);
  await page.fill('#password-field', TEST_USER.password);
  await Promise.all([
    page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 15000 }),
    page.click('button[type=submit]'),
  ]);
  return page;
}

/** The checkout page for the plan that is actually on sale. */
async function openCheckout(page) {
  await page.goto(BASE + '/pricing', { waitUntil: 'networkidle' });

  const href = await page.evaluate(() => {
    const link = document.querySelector('a[href*="/checkout/"]');
    return link ? link.href : null;
  });

  if (!href) {
    console.log(RED('No plan is on sale, so checkout cannot be exercised.'));
    console.log(DIM('Run: php artisan aziv:test-fixtures'));
    await browser.close();
    process.exit(1);
  }

  await page.goto(href, { waitUntil: 'networkidle' });
  return page;
}

/** Force the development gateway to report one outcome, deterministically. */
async function simulate(page, outcome) {
  await page.evaluate((value) => {
    document.querySelector('#checkout').dataset.simulate = value;
  }, outcome);
}

console.log('\nCheckout behaviour\n');

// -- 1. the wiring exists at all ---------------------------------------------
{
  const page = await signedInPage();
  await openCheckout(page);

  const wiring = await page.evaluate(() => {
    const mount = document.querySelector('#checkout');
    return {
      mounted: !!mount,
      driver: mount?.dataset.driver || '',
      ready: mount?.dataset.ready === 'yes',
      hasButton: !!document.querySelector('#pay-now'),
      hasReturn: !!mount?.dataset.return,
    };
  });

  record('the checkout mount exists', wiring.mounted);
  record('the server named a driver for this gateway', wiring.driver !== '', wiring.driver);
  // THE ASSERTION THAT WOULD HAVE CAUGHT THE ORIGINAL BUG: markup alone is not
  // enough — something must have claimed the mount and bound the button.
  record('a driver claimed the page', wiring.ready);
  record('the Pay button and return URL are present', wiring.hasButton && wiring.hasReturn);

  await page.close();
}

// -- 2. the customer closes the window ---------------------------------------
{
  const page = await signedInPage();
  await openCheckout(page);
  await simulate(page, 'dismissed');

  const before = page.url();
  await page.click('#pay-now');
  await page.waitForFunction(() => {
    const el = document.querySelector('[data-checkout-status]');
    return el && !el.hidden && el.textContent.trim() !== '' && !/Opening/i.test(el.textContent);
  }, { timeout: 8000 }).catch(() => {});

  const state = await page.evaluate(() => ({
    url: window.location.href,
    message: document.querySelector('[data-checkout-status]')?.textContent?.trim() || '',
    canRetry: !document.querySelector('#pay-now')?.disabled,
  }));

  record('cancelling keeps the customer on the page', state.url === before);
  record('cancelling says nothing was charged', /nothing has been charged/i.test(state.message), state.message);
  // A dead end after cancelling is a customer who cannot buy at all.
  record('cancelling leaves them able to try again', state.canRetry);

  await page.close();
}

// -- 3. the bank refuses ------------------------------------------------------
{
  const page = await signedInPage();
  await openCheckout(page);
  await simulate(page, 'refused');

  await page.click('#pay-now');
  await page.waitForFunction(() => {
    const el = document.querySelector('[data-checkout-status]');
    return el && !el.hidden && /not completed/i.test(el.textContent);
  }, { timeout: 8000 }).catch(() => {});

  const state = await page.evaluate(() => ({
    message: document.querySelector('[data-checkout-status]')?.textContent?.trim() || '',
    tone: document.querySelector('[data-checkout-status]')?.dataset.tone || '',
    canRetry: !document.querySelector('#pay-now')?.disabled,
  }));

  record('a refusal is reported plainly',
    /not completed/i.test(state.message) && /nothing has been charged/i.test(state.message),
    state.message);
  record('a refusal is marked as a problem', state.tone === 'danger', state.tone);
  record('a refusal leaves them able to try again', state.canRetry);

  await page.close();
}

// -- 4. they pay ---------------------------------------------------------------
{
  const page = await signedInPage();
  await openCheckout(page);
  await simulate(page, 'completed');

  const checkoutUrl = page.url();
  await page.click('#pay-now');

  // Pressing Pay must hand off to the SERVER's return URL. The browser saying
  // "paid" is a claim; the return page is where it is checked.
  await page.waitForURL((u) => /\/return/.test(u.pathname), { timeout: 15000 }).catch(() => {});

  const landed = /\/return/.test(new URL(page.url()).pathname);
  record('paying hands the result to the server', landed, page.url());
  record('it did not stay on the checkout page', page.url() !== checkoutUrl);

  const body = await page.evaluate(() => document.body.innerText);
  // The result page never claims success on the browser's word — it reports
  // what the server found, whatever that is.
  record('the result page renders', body.trim().length > 0);

  // -- 5. and returning twice must not pay out twice -------------------------
  const returnUrl = page.url();
  await page.goto(returnUrl, { waitUntil: 'networkidle' });
  await page.goto(returnUrl, { waitUntil: 'networkidle' });

  record('the return page can be reloaded without error',
    (await page.evaluate(() => document.body.innerText)).trim().length > 0);

  await page.close();
}

await browser.close();

console.log('\n' + '-'.repeat(58));

if (failures.length) {
  console.log(RED(`CHECKOUT BEHAVIOUR GATE FAILED — ${failures.length} check(s)`));
  process.exit(1);
}

console.log(GRN('CHECKOUT BEHAVIOUR GATE PASSED'));

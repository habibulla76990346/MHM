#!/usr/bin/env node
/**
 * Aziv AI — chat behaviour gate (Phase 4, Owner Addendum A).
 *
 * The six-viewport gate checks that no screen overflows and every target is
 * big enough. It cannot check the two things Addendum A asks for specifically
 * about chat, because both are about how the page BEHAVES:
 *
 *   1. the composer stays visible above a mobile keyboard
 *   2. scrolling back during a stream does not yank the view to the bottom
 *
 * Both are exercised here against the real page. The keyboard is simulated by
 * replacing visualViewport before any page script runs — the same signal iOS
 * gives — rather than by trusting that the code reads it.
 *
 *   node tests/Responsive/chat.mjs [baseUrl]
 */
import { chromium } from 'playwright';
import { TEST_USER } from './viewports.mjs';

const BASE = process.argv[2] || process.env.APP_URL || 'http://127.0.0.1:8000';
const EXEC = process.env.CHROMIUM_PATH || undefined;

const RED = (s) => `\x1b[31m${s}\x1b[0m`;
const GRN = (s) => `\x1b[32m${s}\x1b[0m`;
const DIM = (s) => `\x1b[2m${s}\x1b[0m`;

const KEYBOARD_HEIGHT = 320;
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

/* ---------------------------------------------------------------------------
 * 1. The composer above a mobile keyboard.
 *
 * On iOS the layout viewport does not shrink when the keyboard opens, so a
 * bottom-anchored composer ends up underneath it. visualViewport reports the
 * real visible area; the page must read it and lift the composer clear.
 * ------------------------------------------------------------------------ */
{
  const page = await browser.newPage({ viewport: { width: 390, height: 844 }, storageState });

  // Replace visualViewport BEFORE the page's own scripts run, so the page sees
  // exactly what it would see on a device with the keyboard open.
  await page.addInitScript((kb) => {
    const height = window.innerHeight - kb;
    Object.defineProperty(window, 'visualViewport', {
      configurable: true,
      value: {
        height,
        offsetTop: 0,
        width: window.innerWidth,
        addEventListener() {},
        removeEventListener() {},
      },
    });
  }, KEYBOARD_HEIGHT);

  await page.goto(BASE + '/dashboard', { waitUntil: 'networkidle' });
  await page.waitForTimeout(600);

  const result = await page.evaluate((kb) => {
    const composer = document.querySelector('.aziv-composer');
    const input = document.querySelector('.aziv-composer-input');
    if (!composer || !input) return { found: false };

    const inset = getComputedStyle(document.documentElement)
      .getPropertyValue('--keyboard-inset')
      .trim();

    return {
      found: true,
      inset,
      // The INPUT is what must stay visible — measuring the composer's outer
      // box would pass even if its padding were the only thing above the fold.
      inputBottom: input.getBoundingClientRect().bottom,
      keyboardTop: window.innerHeight - kb,
      inputFontSize: parseFloat(getComputedStyle(input).fontSize),
    };
  }, KEYBOARD_HEIGHT);

  record('composer is present', result.found);

  if (result.found) {
    record(
      'keyboard inset is published from visualViewport',
      result.inset === `${KEYBOARD_HEIGHT}px`,
      `--keyboard-inset = "${result.inset}", expected ${KEYBOARD_HEIGHT}px`,
    );

    // The whole point: the field you type into must not be under the keyboard.
    record(
      'composer input sits above the keyboard',
      result.inputBottom <= result.keyboardTop + 1,
      `input bottom ${Math.round(result.inputBottom)}px, keyboard top ${Math.round(result.keyboardTop)}px`,
    );

    record(
      'composer input is at least 16px (no iOS zoom)',
      result.inputFontSize >= 16,
      `${result.inputFontSize}px`,
    );
  }

  await page.close();
}

/* ---------------------------------------------------------------------------
 * 2. Scroll anchoring during a stream.
 *
 * New text must follow the reader only while they are AT the end. Scrolling
 * back to re-read something and being yanked forward is the single most
 * irritating failure in a streaming chat UI.
 *
 * Synthetic messages are injected so this does not depend on a configured AI
 * provider — the behaviour under test is the scrolling rule, not the model.
 * ------------------------------------------------------------------------ */
{
  const page = await browser.newPage({ viewport: { width: 390, height: 844 }, storageState });
  await page.goto(BASE + '/dashboard', { waitUntil: 'networkidle' });
  await page.waitForTimeout(400);

  const result = await page.evaluate(async () => {
    const thread = document.querySelector('.aziv-chat-thread');
    if (!thread) return { found: false };

    const anchor = thread.querySelector('.aziv-chat-anchor');

    // Enough content to make the thread scrollable.
    for (let i = 0; i < 40; i++) {
      const article = document.createElement('article');
      article.className = 'aziv-msg aziv-msg-ai';
      article.innerHTML = '<div class="aziv-msg-body">Filler message ' + i + '</div>';
      thread.insertBefore(article, anchor);
    }

    const target = thread.querySelector('.aziv-msg:last-of-type .aziv-msg-body');
    const alpine = window.Alpine?.$data(document.querySelector('.aziv-chat'));
    if (!alpine) return { found: true, alpine: false };

    alpine.$refs.streamTarget = target;

    // Start at the bottom, as a reader watching a reply would be.
    thread.scrollTop = thread.scrollHeight;
    await new Promise((r) => setTimeout(r, 250));

    alpine.append(' more text while pinned');
    await new Promise((r) => setTimeout(r, 150));
    const followedWhilePinned = thread.scrollHeight - thread.scrollTop - thread.clientHeight < 40;

    // Now the reader scrolls back to re-read something.
    thread.scrollTop = 0;
    await new Promise((r) => setTimeout(r, 300));

    const before = thread.scrollTop;
    alpine.append(' and more text after scrolling away');
    await new Promise((r) => setTimeout(r, 200));
    const after = thread.scrollTop;

    return {
      found: true,
      alpine: true,
      followedWhilePinned,
      stayedPut: Math.abs(after - before) < 10,
      before,
      after,
      jumpButtonVisible: !alpine.pinned,
    };
  });

  record('chat thread is present', result.found);

  if (result.found && result.alpine) {
    record(
      'streaming text follows the reader while they are at the end',
      result.followedWhilePinned,
    );

    record(
      'scrolling back during a stream is NOT yanked to the bottom',
      result.stayedPut,
      `scrollTop ${result.before} → ${result.after}`,
    );

    record('a jump-to-latest affordance appears once scrolled away', result.jumpButtonVisible);
  } else if (result.found) {
    record('Alpine component is initialised', false, 'window.Alpine.$data returned nothing');
  }

  await page.close();
}

/* ---------------------------------------------------------------------------
 * 3. Long unbroken content must not widen the page.
 * ------------------------------------------------------------------------ */
for (const width of [320, 390, 768]) {
  const page = await browser.newPage({ viewport: { width, height: 800 }, storageState });
  await page.goto(BASE + '/dashboard', { waitUntil: 'networkidle' });

  const overflows = await page.evaluate(() => {
    const thread = document.querySelector('.aziv-chat-thread');
    if (!thread) return null;

    const article = document.createElement('article');
    article.className = 'aziv-msg aziv-msg-ai';
    article.innerHTML =
      '<div class="aziv-msg-body">https://example.test/' +
      'a'.repeat(300) +
      ' and_an_unbroken_identifier_' + 'b'.repeat(200) +
      '</div>';
    thread.appendChild(article);

    return document.documentElement.scrollWidth > document.documentElement.clientWidth + 1;
  });

  record(
    `no horizontal overflow with a very long URL at ${width}px`,
    overflows === false,
    overflows === null ? 'thread not found' : '',
  );

  await page.close();
}

await browser.close();

console.log('\n' + '-'.repeat(58));
if (failures.length) {
  console.log(RED(`CHAT BEHAVIOUR GATE FAILED — ${failures.length} check(s)`));
  process.exit(1);
}
console.log(GRN('CHAT BEHAVIOUR GATE PASSED'));

#!/usr/bin/env node
/**
 * Aziv AI — voice behaviour gate (§18, Owner Addendum A).
 *
 * WHAT NEITHER OTHER GATE CAN CHECK. PHPUnit proves a recording becomes a
 * transcript and that nobody is charged for a failure. The six-viewport gate
 * proves the microphone button is 44px. Neither of them PRESSES it — and the
 * checkout blocker taught this project exactly what that costs: a button that
 * is beautifully laid out and inert passes every gate there is.
 *
 * So this one presses it, in a real browser, and checks the three things that
 * matter about a microphone:
 *
 *   1. pressing record makes it UNMISTAKABLE that the microphone is on
 *   2. pressing stop sends the audio and puts the transcript in the composer
 *      WITHOUT sending it — speech recognition gets names and negations wrong
 *   3. stopping releases the microphone track, so the browser's own recording
 *      indicator goes out
 *
 * THE MICROPHONE IS SIMULATED, not requested. `getUserMedia` and
 * `MediaRecorder` are replaced before any page script runs — the same
 * technique the chat gate uses for the mobile keyboard — because a CI runner
 * has no microphone and a gate that skips itself there is not a gate.
 * Everything under test is our own code either way: the state machine, the
 * upload, the polling and where the transcript lands.
 *
 *   node tests/Responsive/voice.mjs [baseUrl]
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

/**
 * A microphone that is not there.
 *
 * Records whether the track was stopped, because a track left open keeps the
 * browser's recording indicator lit — which tells the customer they are still
 * being listened to when they are not, and is the single most damaging thing
 * a voice feature can get wrong.
 */
const FAKE_MICROPHONE = () => {
  window.__voiceProbe = { tracksStopped: 0, tracksStarted: 0, posted: null };

  const track = () => ({
    kind: 'audio',
    stop() {
      window.__voiceProbe.tracksStopped++;
    },
  });

  navigator.mediaDevices = navigator.mediaDevices || {};
  navigator.mediaDevices.getUserMedia = async () => {
    window.__voiceProbe.tracksStarted++;
    const t = track();
    return { getTracks: () => [t] };
  };

  class FakeRecorder {
    constructor() {
      this.state = 'inactive';
      this.mimeType = 'audio/webm';
      this._handlers = {};
    }

    static isTypeSupported() {
      return true;
    }

    addEventListener(name, fn) {
      (this._handlers[name] ||= []).push(fn);
    }

    _emit(name, event) {
      (this._handlers[name] || []).forEach((fn) => fn(event));
    }

    start() {
      this.state = 'recording';
    }

    stop() {
      this.state = 'inactive';
      this._emit('dataavailable', { data: new Blob(['fake-audio-bytes'], { type: 'audio/webm' }) });
      this._emit('stop', {});
    }
  }

  window.MediaRecorder = FakeRecorder;

  // The server is exercised by the PHP suite; what is under test HERE is the
  // browser's own state machine and where the transcript ends up. Stubbing the
  // two endpoints keeps this gate offline and deterministic.
  const realFetch = window.fetch.bind(window);

  window.fetch = async (input, init = {}) => {
    const url = typeof input === 'string' ? input : input.url;

    if (url.includes('/voice/transcribe')) {
      window.__voiceProbe.posted = true;
      return new Response(JSON.stringify({ uuid: 'fake-job', status: 'queued' }), {
        status: 202,
        headers: { 'Content-Type': 'application/json' },
      });
    }

    if (/\/voice\/fake-job$/.test(url)) {
      return new Response(
        JSON.stringify({ uuid: 'fake-job', status: 'completed', text: 'hello from the microphone' }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      );
    }

    return realFetch(input, init);
  };
};

const storageState = await signIn();

console.log('\nVoice behaviour\n');

{
  const page = await browser.newPage({ viewport: { width: 390, height: 844 }, storageState });
  await page.addInitScript(FAKE_MICROPHONE);
  await page.goto(BASE + '/dashboard', { waitUntil: 'networkidle' });
  await page.waitForTimeout(500);

  // A FRESH CONVERSATION FIRST, and this is not tidiness. The test account
  // keeps its history, and the previous run left an assistant reply stuck in
  // `pending` — the provider in the local fixtures is unreachable on purpose —
  // which correctly refuses a new message in the same conversation. Without
  // this the gate passes once and fails for ever after, which is the worst
  // kind of gate there is.
  //
  // On a phone the conversation list is a bottom SHEET, so it has to be opened
  // before "New chat" can be pressed. That is also the journey a customer
  // takes, which is the point of running this at 390px.
  await page.evaluate(() => window.Alpine?.$data(document.querySelector('.aziv-chat'))?.openSheet());
  await page.waitForTimeout(400);
  await page.click('.aziv-chat-new');
  await page.waitForTimeout(1500);

  const button = page.locator('[data-voice-record]');
  const present = (await button.count()) > 0;

  record('the microphone button is on the composer', present);

  if (!present) {
    console.log(DIM('    Voice input is switched off, or no transcription model is enabled.'));
    console.log(DIM('    Enable one, or set voice.input_enabled — this gate cannot check a control that is not there.'));
    await page.close();
    await browser.close();
    process.exit(1);
  }

  // A control you have to hunt for on a phone is a control nobody uses.
  const box = await button.boundingBox();
  record(
    'it meets the 44px touch minimum',
    box && box.height >= 44 && box.width >= 44,
    box ? `${Math.round(box.width)}x${Math.round(box.height)}` : 'no box',
  );

  record('it starts NOT pressed', (await button.getAttribute('aria-pressed')) === 'false');

  /* --- 1. the recording state is unmistakable --------------------------- */
  await button.click();
  await page.waitForTimeout(400);

  record('pressing record asks for the microphone',
    (await page.evaluate(() => window.__voiceProbe.tracksStarted)) === 1);

  record('the button reports itself as pressed',
    (await button.getAttribute('aria-pressed')) === 'true');

  record('the button says how to stop',
    /stop/i.test((await button.textContent()) || ''),
    (await button.textContent())?.trim());

  const status = page.locator('[data-voice-status]');
  const statusText = (await status.textContent()) || '';

  record('a live region announces that it is recording',
    /recording/i.test(statusText),
    statusText.trim());

  record('the live region is polite, not an alert',
    (await status.getAttribute('aria-live')) === 'polite');

  record('the timer says how long, and how long is allowed',
    /\d+s\s*\/\s*\d+s/.test(statusText),
    statusText.trim());

  record('the composer itself shows the state',
    await page.evaluate(() => !!document.querySelector('.aziv-composer.is-recording')));

  /* --- 2. stopping sends, and fills the composer WITHOUT sending -------- */
  const draftBefore = await page.inputValue('#chat-draft');

  // COUNTED, not merely looked for. The test account keeps its chat history
  // between runs, so "is the transcript in the thread?" would be true from
  // yesterday's run on the second run and false on a machine that had never
  // run this — a check whose answer depends on the machine is not a check.
  const spokenBefore = await page.evaluate(() =>
    [...document.querySelectorAll('.aziv-msg-body')]
      .filter((n) => (n.textContent || '').includes('hello from the microphone')).length);

  await button.click();
  await page.waitForTimeout(2500);   // one poll interval plus slack

  record('stopping posts the recording',
    (await page.evaluate(() => window.__voiceProbe.posted)) === true);

  const draftAfter = await page.inputValue('#chat-draft');

  record('the transcript lands in the composer',
    draftAfter.includes('hello from the microphone'),
    `"${draftBefore}" → "${draftAfter}"`);

  // The whole point: speech recognition gets names, numbers and negations
  // wrong, so the customer reads it before it goes.
  const spokenAfterTranscript = await page.evaluate(() =>
    [...document.querySelectorAll('.aziv-msg-body')]
      .filter((n) => (n.textContent || '').includes('hello from the microphone')).length);

  record('it is NOT sent on the customer\'s behalf',
    spokenAfterTranscript === spokenBefore,
    `${spokenBefore} in the thread before, ${spokenAfterTranscript} after`);

  /* --- the server actually received it ---------------------------------
   * THE CHECK THAT NEARLY WAS NOT HERE. Reading the textarea proves the DOM
   * changed and nothing more. Livewire binds `draft` on input, so setting
   * `.value` without dispatching the event leaves the server holding an empty
   * draft — the message sends blank and the transcript is lost. Sabotaging
   * exactly that passed every other check in this file.
   *
   * So the transcript is SENT, and it has to come back in the thread as the
   * customer's own message. The reply will fail — there is no reachable
   * provider on a test machine — and that is fine: what is under test is that
   * the words survived the round trip.
   * ------------------------------------------------------------------- */
  await page.click('.aziv-composer button[type=submit]');
  await page.waitForTimeout(2500);

  const spokenAfterSend = await page.evaluate(() =>
    [...document.querySelectorAll('.aziv-msg-body')]
      .filter((n) => (n.textContent || '').includes('hello from the microphone')).length);

  // When this fails, say WHY. A refusal the product wrote — out of credits, a
  // daily limit — is a different problem from a draft that never reached the
  // server, and a gate that cannot tell them apart wastes an afternoon.
  const refusal = await page.evaluate(() =>
    (document.querySelector('.aziv-chat-error')?.textContent || '').trim());

  record('the server received the transcript, not an empty draft',
    spokenAfterSend > spokenBefore,
    spokenAfterSend > spokenBefore
      ? ''
      : (refusal || 'Livewire never saw the transcript'));

  /* --- 3. the microphone is released ------------------------------------ */
  record('stopping releases the microphone track',
    (await page.evaluate(() => window.__voiceProbe.tracksStopped)) >= 1,
    'the browser recording indicator would stay lit');

  record('the button returns to its resting state',
    (await button.getAttribute('aria-pressed')) === 'false');

  record('the live region is cleared once the transcript arrives',
    await status.evaluate((el) => el.hidden || (el.textContent || '').trim() === ''));

  await page.close();
}

/* ---------------------------------------------------------------------------
 * A tab hidden mid-recording must let go of the microphone.
 * ------------------------------------------------------------------------ */
{
  const page = await browser.newPage({ viewport: { width: 390, height: 844 }, storageState });
  await page.addInitScript(FAKE_MICROPHONE);
  await page.goto(BASE + '/dashboard', { waitUntil: 'networkidle' });
  await page.waitForTimeout(400);

  const button = page.locator('[data-voice-record]');

  if ((await button.count()) > 0) {
    await button.click();
    await page.waitForTimeout(300);

    // A phone locking, or a tab switch. Recording whatever happens next is
    // not something a customer consented to.
    await page.evaluate(() => {
      Object.defineProperty(document, 'hidden', { configurable: true, value: true });
      document.dispatchEvent(new Event('visibilitychange'));
    });
    await page.waitForTimeout(400);

    record('hiding the tab releases the microphone',
      (await page.evaluate(() => window.__voiceProbe.tracksStopped)) >= 1);
  }

  await page.close();
}

await browser.close();

console.log('\n' + '-'.repeat(58));
if (failures.length) {
  console.log(RED(`VOICE BEHAVIOUR GATE FAILED — ${failures.length} check(s)`));
  process.exit(1);
}
console.log(GRN('VOICE BEHAVIOUR GATE PASSED'));

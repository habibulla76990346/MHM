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
import { VIEWPORTS, SCREENS } from './viewports.mjs';
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

const RED = s => `\x1b[31m${s}\x1b[0m`;
const GRN = s => `\x1b[32m${s}\x1b[0m`;
const DIM = s => `\x1b[2m${s}\x1b[0m`;

const failures = [];
const browser = await chromium.launch(EXEC ? { executablePath: EXEC } : {});

for (const screen of SCREENS) {
  console.log(`\n${screen.name}  ${DIM(BASE + screen.path)}`);
  for (const vp of VIEWPORTS) {
    const page = await browser.newPage({ viewport: { width: vp.width, height: vp.height } });
    let line = `  ${vp.name.padEnd(9)} ${DIM(vp.class.padEnd(8))}`;
    try {
      const res = await page.goto(BASE + screen.path, { waitUntil: 'networkidle', timeout: 20000 });
      if (!res || res.status() >= 400) throw new Error(`HTTP ${res ? res.status() : 'no response'}`);
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

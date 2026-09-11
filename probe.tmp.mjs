import { chromium } from 'playwright';
import * as C from './tests/Responsive/checks.mjs';
const BASE='http://127.0.0.1:8000';
const b = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH });
const p = await b.newPage();
await p.goto(BASE+'/admin/login',{waitUntil:'networkidle'});
await p.fill('input[id$="email"]','responsive-admin@aziv.test');
await p.fill('input[type="password"]','Responsive-Test-2026');
await Promise.all([p.waitForURL(u=>!u.pathname.endsWith('/login'),{timeout:15000}), p.click('button[type=submit]')]);
const state = await p.context().storageState();
await p.close();

for (const w of [320, 390, 768, 1440]) {
  const page = await b.newPage({viewport:{width:w,height:900}, storageState:state});
  await page.goto(BASE+'/admin/appearance',{waitUntil:'networkidle'});
  // Open "Everything else", then expand every token group inside it.
  const sections = await page.locator('button.fi-section-collapse-btn').all();
  for (const s of sections) { try { await s.click(); await page.waitForTimeout(150); } catch {} }
  const groups = await page.locator('button[aria-expanded="false"]').all();
  for (const g of groups) { try { await g.click(); await page.waitForTimeout(120); } catch {} }
  await page.waitForTimeout(500);
  const visible = await page.locator('input[type=color]').count();
  const out = [];
  for (const [label, fn] of [['overflow',C.noHorizontalOverflow],['tap',C.touchTargets],['text',C.readableText],['zoom',C.inputsDoNotZoomOnIos]]) {
    const r = await fn(page);
    if (!r.pass) out.push(`${label}: ${r.detail}`);
  }
  console.log(`@${w} — ${visible} colour inputs rendered — ${out.length ? 'FAIL' : 'pass'}`);
  out.forEach(o=>console.log('   x '+o));
  await page.close();
}
await b.close();

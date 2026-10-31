// Records the provisional demo from the running app, on the simulated demo homes.
// Usage: PLAYWRIGHT_DIR=<path to node_modules/playwright> BASE=http://localhost:8080 node demo/record.mjs OUT_DIR
import { createRequire } from 'node:module';
import { readFileSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

const { chromium } = createRequire(import.meta.url)(process.env.PLAYWRIGHT_DIR);
const BASE = process.env.BASE ?? 'http://localhost:8080';
const out = resolve(process.argv[2]);
const scenes = resolve('samples/scenes');
const cues = JSON.parse(readFileSync('demo/cues.json', 'utf8')).cues;
const dataUrl = (p) => `data:image/jpeg;base64,${readFileSync(p).toString('base64')}`;

const browser = await chromium.launch();
const context = await browser.newContext({
  viewport: { width: 1280, height: 720 },
  recordVideo: { dir: out, size: { width: 1280, height: 720 } },
});
const page = await context.newPage();
const recordingStart = Date.now();

const card = (title, sub = '', img = '') => `<!doctype html><meta charset=utf-8><style>
  body{margin:0;height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;background:#16201d;color:#fff;font:600 40px/1.25 -apple-system,sans-serif;text-align:center;padding:0 80px;box-sizing:border-box}
  small{font:400 24px/1.4 -apple-system,sans-serif;color:#b9c6c1;margin-top:14px;display:block}img{max-height:58vh;border-radius:10px;margin-bottom:22px;background:#fff}
  .flow{display:flex;gap:10px;font:600 21px -apple-system,sans-serif;margin-top:26px}.flow span{background:#26332f;padding:14px 16px;border-radius:8px}.flow .me{background:#1f8a70}
  </style>${img ? `<img src="${img}">` : ''}<div>${title}</div>${sub ? `<small>${sub}</small>` : ''}`;

const caption = async (text) => {
  await page.evaluate((t) => {
    let c = document.getElementById('cap');
    if (!c) {
      c = document.createElement('div');
      c.id = 'cap';
      c.style.cssText = 'position:fixed;left:50%;bottom:18px;transform:translateX(-50%);max-width:1120px;background:rgba(10,16,14,.86);color:#fff;font:500 21px/1.35 -apple-system,sans-serif;padding:9px 18px;border-radius:8px;z-index:99;text-align:center';
      document.body.appendChild(c);
    }
    c.textContent = t;
  }, text);
};

let t0;
const at = async (i) => {
  const wait = cues[i].at * 1000 - (Date.now() - t0);
  if (wait > 0) await page.waitForTimeout(wait);
};
const show = async (i, html) => { await at(i); await page.setContent(html); await caption(cues[i].text); };
const go = async (i, url) => { await at(i); await page.goto(BASE + url); await caption(cues[i].text); };
const to = (selector) => page.evaluate((s) => document.querySelector(s)?.scrollIntoView({ behavior: 'smooth', block: 'start' }), selector);
const toHeading = (text) => page.evaluate((t) => [...document.querySelectorAll('h2')].find((h) => h.textContent.includes(t))?.scrollIntoView({ behavior: 'smooth', block: 'start' }), text);

await page.setContent(card('Built during the hackathon', 'Fresh repo · Simulated scenes'));
await caption(cues[0].text);
t0 = Date.now();
const skip = (t0 - recordingStart) / 1000;

await show(1, card('A home can look fine and still block the way', '', dataUrl(join(scenes, 'door_before.jpg'))));
await show(2, card('See it. Fix it. Prove it.', 'Measure → subsidy draft → re-verify'));
await show(3, card('How it works', '', '') + `<div class=flow><span>Photos / video</span><span>SAM 3.1</span><span>Rule table</span><span class=me>Muse Spark agent</span><span>Subsidy draft</span><span>Re-shoot</span></div>`);

await go(4, '/?lang=en');
await go(5, '/scans/1');
await toHeading('What we found');
await at(6);
await caption(cues[6].text);
await page.evaluate(() => {
  const img = document.querySelectorAll('main .grid .card img')[1];
  img.style.cssText = 'position:fixed;inset:0;margin:auto;max-height:100vh;width:auto;z-index:50;border-radius:0';
  img.onclick = null;
});
await at(7);
await page.evaluate(() => { document.querySelectorAll('main .grid .card img')[1].style.cssText = ''; });
await caption(cues[7].text);
await page.evaluate(() => document.querySelector('main table')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));

await at(8);
await caption(cues[8].text);
await toHeading('Agent trace');
await at(9);
await caption(cues[9].text);
await page.evaluate(() => {
  const row = document.querySelector('tr.err');
  row?.querySelector('details')?.setAttribute('open', '');
  row?.scrollIntoView({ behavior: 'smooth', block: 'center' });
});
await at(10);
await caption(cues[10].text);
await toHeading('Clear today');
await page.waitForTimeout(3500);
await toHeading('Work orders');

await at(11);
await caption(cues[11].text);
await toHeading('subsidy draft');
await page.waitForTimeout(6000);
const pdf = process.env.PACK_IMAGE;
if (pdf) {
  await page.setContent(card('', '', `data:image/png;base64,${readFileSync(pdf).toString('base64')}`).replace('max-height:58vh', 'max-height:98vh').replace('padding:0 80px', 'padding:0'));
  await caption(cues[11].text);
}

await at(12);
await page.goto(BASE + '/scans/1');
await caption(cues[12].text);
await toHeading('After the fix');
await page.waitForTimeout(1000);
for (const index of [0, 1, 2]) {
  for (let v = 50; v >= 0; v -= 4) { await slide(index, v); }
  for (let v = 0; v <= 100; v += 4) { await slide(index, v); }
}
async function slide(index, value) {
  await page.evaluate(([i, v]) => {
    const input = document.querySelectorAll('.ba input')[i];
    input.value = v;
    input.dispatchEvent(new Event('input'));
  }, [index, value]);
  await page.waitForTimeout(90);
}

await at(13);
await caption(cues[13].text);
await page.evaluate(() => [...document.querySelectorAll('.tablewrap')].find((c) => c.textContent.includes('verified fixed'))?.scrollIntoView({ behavior: 'smooth', block: 'start' }));

await show(14, card('SafePath AI', 'SAM 3.1 · Muse Spark · Muse Image (simulated scenes)<br>github.com/seantw0301/safepath-ai<br><br>See it. Fix it. Prove it.'));
await page.waitForTimeout(9000);

const seconds = (Date.now() - t0) / 1000;
const video = page.video();
await context.close();
await browser.close();
const file = await video.path();
writeFileSync(join(out, 'meta.json'), JSON.stringify({ file, skip, seconds }));
console.log(JSON.stringify({ file, skip, seconds }));

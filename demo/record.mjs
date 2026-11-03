// Records the final demo from the running app: real home (doors, hallway) and labelled simulated scenes.
// Usage: PLAYWRIGHT_DIR=<node_modules/playwright> BASE=http://localhost:8080 MEDIA=<dir from make.sh> DURATIONS=<durations.json> node demo/record.mjs OUT_DIR
// Scan ids: REAL_DOORS (photos), REAL_HALL (video, re-shot), SIM (simulated demo home with the subsidy pack).
import { createRequire } from 'node:module';
import { readFileSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

const { chromium } = createRequire(import.meta.url)(process.env.PLAYWRIGHT_DIR);
const BASE = process.env.BASE ?? 'http://localhost:8080';
const MEDIA = resolve(process.env.MEDIA);
const DOORS = process.env.REAL_DOORS ?? '10';
const HALL = process.env.REAL_HALL ?? '7';
const SIM = process.env.SIM ?? '1';
const out = resolve(process.argv[2]);
const cues = JSON.parse(readFileSync('demo/cues.json', 'utf8')).cues;
const durations = JSON.parse(readFileSync(process.env.DURATIONS, 'utf8'));
const data = (name) => {
  const type = name.endsWith('.webm') ? 'video/webm' : name.endsWith('.png') ? 'image/png' : 'image/jpeg';
  return `data:${type};base64,${readFileSync(join(MEDIA, name)).toString('base64')}`;
};

const browser = await chromium.launch();
const context = await browser.newContext({
  viewport: { width: 1280, height: 720 },
  recordVideo: { dir: out, size: { width: 1280, height: 720 } },
});
const page = await context.newPage();
const recordingStart = Date.now();

const BADGE = { real: ['REAL HOME', '#1f8a70'], sim: ['SIMULATED', '#b4541a'] };
const badgeHtml = (kind) => kind ? `<div class=badge style="background:${BADGE[kind][1]}">${BADGE[kind][0]}</div>` : '';
const css = `body{margin:0;height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;background:#16201d;color:#fff;font:600 40px/1.25 -apple-system,sans-serif;text-align:center;padding:0 70px 70px;box-sizing:border-box}
  small{font:400 24px/1.4 -apple-system,sans-serif;color:#b9c6c1;margin-top:14px;display:block}
  .badge{position:fixed;top:16px;right:18px;font:700 18px -apple-system,sans-serif;letter-spacing:.08em;padding:7px 14px;border-radius:6px;color:#fff;z-index:98}
  .row{display:flex;gap:26px;align-items:flex-start;justify-content:center}.row figure{margin:0;font:500 19px/1.35 -apple-system,sans-serif;color:#dfe8e4}
  .row img,.row video{height:470px;border-radius:10px;display:block;margin:0 auto 10px}
  .flow{display:flex;gap:10px;font:600 21px -apple-system,sans-serif;margin-top:26px}.flow span{background:#26332f;padding:14px 16px;border-radius:8px}.flow .me{background:#1f8a70}
  table{border-collapse:collapse;font:500 22px/1.3 -apple-system,sans-serif;margin-top:6px}td,th{padding:8px 16px;border-bottom:1px solid #33433e;text-align:left}th{color:#9fb3ac;font-weight:500}
  .red{color:#ff8a80}.green{color:#7fdcb6}.grey{color:#b9c6c1}`;
const card = (kind, body) => `<!doctype html><meta charset=utf-8><style>${css}</style>${badgeHtml(kind)}${body}`;

// Burned-in subtitles: each cue is split into sentences, timed by length over the cue's speech.
let captionTimer;
const caption = async (i) => {
  clearTimeout(captionTimer);
  const parts = cues[i].text.split(/(?<=[a-z][.?!])\s+/);
  const total = parts.reduce((n, p) => n + p.length, 0);
  const started = Date.now();
  let elapsed = 0;
  const showPart = async (k) => {
    if (k >= parts.length) return;
    await page.evaluate((t) => {
      let c = document.getElementById('cap');
      if (!c) {
        c = document.createElement('div');
        c.id = 'cap';
        c.style.cssText = 'position:fixed;left:50%;bottom:16px;transform:translateX(-50%);max-width:1120px;background:rgba(10,16,14,.88);color:#fff;font:500 23px/1.35 -apple-system,sans-serif;padding:8px 18px;border-radius:8px;z-index:99;text-align:center';
        document.body.appendChild(c);
      }
      c.textContent = t;
    }, parts[k].trim()).catch(() => {});
    elapsed += durations[i] * 1000 * parts[k].length / total;
    captionTimer = setTimeout(() => showPart(k + 1), Math.max(0, elapsed - (Date.now() - started)));
  };
  await showPart(0);
};
const badge = (kind) => page.evaluate(([t, bg]) => {
  const b = document.createElement('div');
  b.style.cssText = `position:fixed;top:16px;right:18px;font:700 18px -apple-system,sans-serif;letter-spacing:.08em;padding:7px 14px;border-radius:6px;color:#fff;z-index:98;background:${bg}`;
  b.textContent = t;
  document.body.appendChild(b);
}, BADGE[kind]);

let t0;
const at = async (i) => {
  const wait = cues[i].at * 1000 - (Date.now() - t0);
  if (wait > 0) await page.waitForTimeout(wait);
};
const show = async (i, html) => { await at(i); await page.setContent(html); await caption(i); };
const go = async (i, url, kind) => { await at(i); await page.goto(BASE + url); await badge(kind); await caption(i); };
const keepCaption = async (fn) => {
  const text = await page.evaluate(() => document.getElementById('cap')?.textContent ?? '').catch(() => '');
  await fn();
  await page.evaluate((t) => {
    const c = document.createElement('div');
    c.id = 'cap';
    c.style.cssText = 'position:fixed;left:50%;bottom:16px;transform:translateX(-50%);max-width:1120px;background:rgba(10,16,14,.88);color:#fff;font:500 23px/1.35 -apple-system,sans-serif;padding:8px 18px;border-radius:8px;z-index:99;text-align:center';
    c.textContent = t;
    if (t) document.body.appendChild(c);
  }, text);
};
const swap = (html) => keepCaption(() => page.setContent(html));
const reopen = (url, kind) => keepCaption(async () => { await page.goto(BASE + url); await badge(kind); });
const toHeading = (text, block = 'start') => page.evaluate(([t, b]) => [...document.querySelectorAll('h2')].find((h) => h.textContent.includes(t))?.scrollIntoView({ behavior: 'smooth', block: b }), [text, block]);
const dropPhotos = () => page.evaluate(() => document.querySelector('main .grid')?.remove()); // frame 0 shows part of a person
const pause = (s) => page.waitForTimeout(s * 1000);

// 0 Opening
await page.setContent(card('', `<div>Built during the hackathon</div><small><b style="color:#7fdcb6">REAL HOME</b>: the doors and the hallway &nbsp;·&nbsp; <b style="color:#f0a46a">SIMULATED</b>: the other scenes, labelled</small>`));
t0 = Date.now();
await caption(0);
const skip = (t0 - recordingStart) / 1000;

// 1 Threshold under a tape
await show(1, card('real', `<div class=row><figure><img src="${data('tape.jpg')}" style="height:520px"></figure></div><small>Bathroom threshold · tape measure: 3.5 cm</small>`));

// 2–3 Title and flow
await show(2, card('', `<div>SafePath AI</div><small>Film the home → measured → paperwork → prove the fix</small>`));
await show(3, card('', `<div>How it works</div><div class=flow><span>Phone video / photos</span><span>SAM 3.1</span><span>Rule table</span><span class=me>Muse Spark agent</span><span>Subsidy draft</span><span>Re-shoot</span></div>`));

// 4 Door widths: tape vs SafePath (scan REAL_DOORS)
await show(4, card('real', `<div class=row>
  <figure><img src="${data('door_front.jpg')}" style="height:400px"><span>Front door</span></figure>
  <figure><img src="${data('door_bath.jpg')}" style="height:400px"><span>Bathroom door</span></figure>
  <div style="align-self:center"><table>
    <tr><th>Clear opening</th><th>Tape</th><th>SafePath</th></tr>
    <tr><td>Front door</td><td>90 cm</td><td>84.9 cm <span class=grey>(−6%)</span></td></tr>
    <tr><td>Bathroom</td><td>65 cm</td><td class=grey>not reported:<br>door runs off the photo</td></tr>
  </table><small style="font-size:20px">Width is a reference value, about ±10%</small></div></div>`));

// 5 Borderline threshold: the app asks for a tape measure
await go(5, `/scans/${DOORS}?lang=en`, 'real');
await page.evaluate(() => {
  const r = [...document.querySelectorAll('tr')].find((x) => x.textContent.includes('Door threshold'));
  if (r) { r.style.outline = '3px solid #f0a46a'; r.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
});
await pause(5.5);
await toHeading('Check by hand', 'center');
await pause(3.5);
await swap(card('real', `<div class=row><figure><img src="${data('tape.jpg')}" style="height:470px"></figure>
  <div style="align-self:center"><table><tr><td>SafePath reads</td><td>3.8 cm</td></tr><tr><td>Line</td><td>3 cm</td></tr><tr><td>Photo error</td><td>±1 cm</td></tr><tr><td>Verdict</td><td style="color:#f0a46a">measure by hand</td></tr><tr><td>Tape</td><td>3.5 cm</td></tr></table></div></div>`));

// 6 Real hallway walkthrough (scan REAL_HALL)
await show(6, card('real', `<div class=row>
  <figure><video src="${data('hall_before.webm')}" autoplay muted></video><span>Walk-through video</span></figure>
  <figure><img src="${data('hall_red.jpg')}"><span>SAM 3.1 + walking path</span></figure></div>`));
await pause(8.5);
await reopen(`/scans/${HALL}?lang=en`, 'real');
await dropPhotos();
await toHeading('What we found');

// 7 Rule table (simulated home)
await go(7, `/scans/${SIM}?lang=en`, 'sim');
await page.evaluate(() => document.querySelector('main table')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));

// 8 Sticker false positive (simulated)
await show(8, card('sim', `<div class=row><figure><img src="${data('false_positive.jpg')}" style="height:440px"></figure></div><small>Detector: "shoes", 0.9 &nbsp;·&nbsp; Agent: a sticker, handed to a person</small>`));

// 9 Real agent self-correction (scan REAL_HALL)
await go(9, `/scans/${HALL}?lang=en`, 'real');
await dropPhotos();
await toHeading('Agent trace');
await pause(4);
await page.evaluate(() => {
  const row = document.querySelector('tr.err');
  row?.querySelector('details')?.setAttribute('open', '');
  row?.scrollIntoView({ behavior: 'smooth', block: 'center' });
});

// 10 Clearing list (real)
await at(10);
await caption(10);
await toHeading('Clear today');

// 11 Subsidy draft for construction items (simulated)
await go(11, `/scans/${SIM}?lang=en`, 'sim');
await toHeading('subsidy draft');
await pause(5);
await swap(card('sim', `<img src="${data('pack.png')}" style="max-height:600px;border-radius:6px;background:#fff">`));

// 12 Real re-shoot: before vs after, red to green
await show(12, card('real', `<div class=row>
  <figure><video src="${data('hall_before.webm')}" autoplay muted></video><span class=red>Before · scan #${HALL}</span></figure>
  <figure><video src="${data('hall_after.webm')}" autoplay muted></video><span class=green>After · re-shoot</span></figure></div>`));
await pause(6);
await reopen(`/scans/${HALL}?lang=en`, 'real');
await dropPhotos();
await toHeading('After the fix');

// 13 Construction fix verified (simulated)
await go(13, `/scans/${SIM}?lang=en`, 'sim');
await toHeading('After the fix');
await pause(1);
const slide = async (index, value) => {
  await page.evaluate(([i, v]) => {
    const input = document.querySelectorAll('.ba input')[i];
    if (!input) return;
    input.value = v;
    input.dispatchEvent(new Event('input'));
  }, [index, value]);
  await page.waitForTimeout(70);
};
for (const index of [0, 1]) {
  for (let v = 50; v >= 0; v -= 5) await slide(index, v);
  for (let v = 0; v <= 100; v += 5) await slide(index, v);
}

// 14 Close
await show(14, card('', `<div>SafePath AI</div><small>SAM 3.1 sees and measures · Muse Spark does the paperwork<br>github.com/seantw0301/safepath-ai &nbsp;·&nbsp; demo.jxdtw.com/metademo<br><br>See it. Fix it. Prove it.</small>`));
await pause(durations[14] + 3.5);

clearTimeout(captionTimer);
const seconds = (Date.now() - t0) / 1000;
const video = page.video();
await context.close();
await browser.close();
const file = await video.path();
writeFileSync(join(out, 'meta.json'), JSON.stringify({ file, skip, seconds }));
console.log(JSON.stringify({ file, skip, seconds }));

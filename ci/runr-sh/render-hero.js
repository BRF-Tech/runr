#!/usr/bin/env node
// Renders the still images of the landing's 3D hero, which phones, touch screens and reduced
// motion show instead of the canvas (landing/index.html, .hero-static):
//
//   landing/assets/hero-static.webp        412 px wide   screens up to 768 px
//   landing/assets/hero-static-wide.webp   960 px wide   wider screens with a coarse pointer or reduced motion
//
//   node ci/runr-sh/render-hero.js [LANDING_DIR]
//
// It serves the landing, opens it at a phone and at a desktop viewport, runs js/hero3d.js (which
// downloads three.js from jsdelivr) with the hero's other layers hidden, screenshots the canvas a
// few times and keeps the frame with the most lit pods, encoded as WebP by the browser. Needs the
// playwright package (`npm i -D playwright` or NODE_PATH pointing at an installed copy) and a
// Chromium with WebGL (SwiftShader is enabled); PW_EXE overrides the browser executable.
'use strict';
const fs = require('fs');
const http = require('http');
const path = require('path');
const { chromium } = require('playwright');

const landing = path.resolve(process.argv[2] || path.join(__dirname, '..', '..', 'landing'));
const types = {
  '.html': 'text/html; charset=utf-8', '.css': 'text/css', '.js': 'text/javascript', '.svg': 'image/svg+xml',
  '.png': 'image/png', '.webp': 'image/webp', '.json': 'application/json',
};

const server = http.createServer((req, res) => {
  let file = path.join(landing, decodeURIComponent(req.url.split('?')[0]));
  if (file.endsWith(path.sep)) file = path.join(file, 'index.html');
  if (!file.startsWith(landing) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) {
    res.writeHead(404); res.end(); return;
  }
  res.writeHead(200, { 'Content-Type': types[path.extname(file)] || 'application/octet-stream' });
  fs.createReadStream(file).pipe(res);
});

// Everything in the hero but the canvas is hidden (layout kept, so the canvas has its real size);
// at phone width the canvas is shown again and the 3D hero started by hand, as the page itself
// does not load it there.
const ONLY_CANVAS = `
  .hero-content, .hero-shot, .hero-fade, .hero-sparks, .nav, #bg-canvas, .marquee { visibility: hidden !important; }
  .hero::before, .hero::after { display: none !important; }
  .hero .hero-canvas { display: block !important; }
  .hero-static { display: none !important; }
`;

const JOBS = [
  { file: 'hero-static.webp', viewport: { width: 412, height: 915 }, width: 412 },
  { file: 'hero-static-wide.webp', viewport: { width: 1440, height: 900 }, width: 960 },
];
const FRAMES = 4;
const QUALITY = 0.72;

(async () => {
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  const base = 'http://127.0.0.1:' + server.address().port;
  const launch = { headless: true, args: ['--enable-unsafe-swiftshader', '--use-angle=swiftshader', '--ignore-gpu-blocklist'] };
  if (process.env.PW_EXE) launch.executablePath = process.env.PW_EXE;
  const browser = await chromium.launch(launch);
  for (const job of JOBS) {
    const context = await browser.newContext({ viewport: job.viewport, deviceScaleFactor: 1, colorScheme: 'dark', locale: 'en-US' });
    const page = await context.newPage();
    await page.goto(base + '/', { waitUntil: 'load' });
    await page.addStyleTag({ content: ONLY_CANVAS });
    if (!(await page.evaluate(() => [...document.scripts].some((s) => /hero3d\.js/.test(s.src))))) {
      await page.addScriptTag({ url: '/js/hero3d.js' });
    }
    await page.waitForFunction(() => typeof THREE !== 'undefined', null, { timeout: 30000 });
    await page.waitForTimeout(5000);
    let best = null;
    for (let i = 0; i < FRAMES; i++) {
      const png = await page.locator('#hero3d').screenshot({ type: 'png' });
      const out = await page.evaluate(async ({ b64, width, quality }) => {
        const img = new Image();
        img.src = 'data:image/png;base64,' + b64;
        await img.decode();
        const h = Math.round(img.height * width / img.width);
        const c = document.createElement('canvas');
        c.width = width; c.height = h;
        const ctx = c.getContext('2d');
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(img, 0, 0, width, h);
        const px = ctx.getImageData(0, 0, width, h).data;
        let lit = 0;
        for (let p = 0; p < px.length; p += 4) if (px[p] > 200 && px[p + 1] > 90 && px[p + 1] < 190 && px[p + 2] < 120) lit++;
        return { webp: c.toDataURL('image/webp', quality), lit: lit / (px.length / 4), w: width, h };
      }, { b64: png.toString('base64'), width: job.width, quality: QUALITY });
      if (!best || out.lit > best.lit) best = out;
      await page.waitForTimeout(2500);
    }
    if (!best.webp.startsWith('data:image/webp')) throw new Error('the browser did not encode WebP');
    const file = path.join(landing, 'assets', job.file);
    fs.writeFileSync(file, Buffer.from(best.webp.split(',')[1], 'base64'));
    console.log('wrote %s (%dx%d, %d bytes, lit pods %s%%)', file, best.w, best.h, fs.statSync(file).size, (best.lit * 100).toFixed(1));
    await context.close();
  }
  await browser.close();
  server.close();
})().catch((e) => { console.error(e); process.exit(1); });

#!/usr/bin/env node
// Renders the two social images of runr.sh from landing/assets/og-image.src.html with headless
// Chromium (Playwright):
//
//   landing/assets/og-image.png               1200 x 630   og:image, twitter:image
//   landing/assets/github-social-preview.png  1280 x 640   GitHub repository "Social preview"
//
//   node ci/runr-sh/render-social.js [LANDING_DIR]
//
// The source page shows ../shots/deploy-rollout-dark.png (a canary rollout in the console) in a browser
// frame; while that file is missing, the CSS mock of the console in the page takes its place, so
// re-run this after the shots land. Needs the playwright package (`npm i -D playwright` or
// NODE_PATH pointing at an installed copy) and a Chromium it can launch; PW_EXE overrides the
// browser executable (e.g. an installed ms-playwright chrome-headless-shell).
'use strict';
const fs = require('fs');
const http = require('http');
const path = require('path');
const { chromium } = require('playwright');

const landing = path.resolve(process.argv[2] || path.join(__dirname, '..', '..', 'landing'));
const types = { '.html': 'text/html; charset=utf-8', '.png': 'image/png', '.svg': 'image/svg+xml', '.css': 'text/css' };

const server = http.createServer((req, res) => {
  const file = path.join(landing, decodeURIComponent(req.url.split('?')[0]));
  if (!file.startsWith(landing) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) {
    res.writeHead(404); res.end(); return;
  }
  res.writeHead(200, { 'Content-Type': types[path.extname(file)] || 'application/octet-stream' });
  fs.createReadStream(file).pipe(res);
});

(async () => {
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  const base = 'http://127.0.0.1:' + server.address().port;
  const launch = { headless: true };
  if (process.env.PW_EXE) launch.executablePath = process.env.PW_EXE;
  const browser = await chromium.launch(launch);
  const jobs = [
    ['og-image.png', 1200, 630],
    ['github-social-preview.png', 1280, 640],
  ];
  for (const [name, width, height] of jobs) {
    const context = await browser.newContext({ viewport: { width, height }, deviceScaleFactor: 1, colorScheme: 'dark' });
    const page = await context.newPage();
    await page.goto(base + '/assets/og-image.src.html', { waitUntil: 'load' });
    await page.waitForTimeout(600);
    const mock = await page.evaluate(() => document.getElementById('frame').classList.contains('missing'));
    const out = path.join(landing, 'assets', name);
    await page.screenshot({ path: out, type: 'png' });
    console.log('wrote %s (%dx%d%s)', out, width, height, mock ? ', CSS mock: the console screenshot is missing' : ', with the console screenshot');
    await context.close();
  }
  await browser.close();
  server.close();
})().catch((e) => { console.error(e); process.exit(1); });

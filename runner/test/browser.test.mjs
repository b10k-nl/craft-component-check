// End-to-end: a tiny site with real markers, a real Chromium, the real runner.
// Skipped when Playwright or its Chromium is not installed.

import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { loadPlaywright } from '../lib/playwright.mjs';
import { runManifest } from '../lib/runner.mjs';

const TOKEN = 'test-token';
const HEADER = 'X-Component-Check';

let server;
let baseUrl;
let loaded;
let outputDir;

function layout(req, body) {
  // Markers only when the token is present — as the plugin does.
  const marked = req.headers[HEADER.toLowerCase()] === TOKEN;
  const m = (s) => (marked ? s : '');
  return `<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1">
<style>body{margin:0;font-family:sans-serif} section{padding:20px}</style>
<script src="https://www.googletagmanager.com/gtm.js"></script>
</head><body>${body(m)}</body></html>`;
}

const pages = {
  '/': (m) => `
    ${m('<!--cc:start hero 1-->')}<section class="hero"><h1>Welcome</h1></section>${m('<!--cc:end 1-->')}
    <section ${m('data-cc-component="cards" data-cc-block="7"')}><p>Cards</p></section>`,
  '/about': (m) => `
    ${m('<!--cc:start hero 2-->')}<section class="hero"><div style="width:800px;max-width:none">Wide hero</div></section>${m('<!--cc:end 2-->')}`,
  // Velo's hero, broken on purpose: a 1200px heading inside overflow-hidden.
  // No sideways scroll, so only the clipped-text check can see it. The
  // off-screen slide next to it is a carousel doing its job, not a bug.
  '/clip': (m) => `
    ${m('<!--cc:start hero 6-->')}<section style="overflow:hidden;position:relative">
      <div style="padding:40px;max-width:672px"><h1 style="width:1200px;font-size:48px">Ride further, feel stronger, every single week</h1></div>
      <div style="display:flex;width:300%"><div style="width:33%">Slide one</div><div style="width:33%;transform:translateX(200%)">Slide two far away</div></div>
    </section>${m('<!--cc:end 6-->')}`,
  '/missing': (m) => `${m('<!--cc:start hero 3-->')}<section>Only three</section>${m('<!--cc:end 3-->')}`,
  '/js': (m) => `${m('<!--cc:start hero 4-->')}<section>JS</section>${m('<!--cc:end 4-->')}<script>undefinedFunction()</script>`,
};

before(async () => {
  loaded = await loadPlaywright();
  if (loaded) {
    try {
      if (!fs.existsSync(loaded.playwright.chromium.executablePath())) loaded = null;
    } catch {
      loaded = null;
    }
  }

  server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://x');
    if (url.pathname === '/broken') {
      res.writeHead(500, { 'content-type': 'text/html' });
      return res.end('<!doctype html><p>Error</p>');
    }
    const page = pages[url.pathname];
    if (!page) {
      res.writeHead(404);
      return res.end();
    }
    res.writeHead(200, { 'content-type': 'text/html' });
    res.end(layout(req, page));
  });
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  baseUrl = `http://127.0.0.1:${server.address().port}`;
  outputDir = fs.mkdtempSync(path.join(os.tmpdir(), 'cc-runner-'));
});

after(() => {
  server?.close();
  if (outputDir) fs.rmSync(outputDir, { recursive: true, force: true });
});

function manifest(markers = true) {
  return {
    schema: 1,
    outputDir,
    markers: markers ? { header: HEADER, token: TOKEN } : null,
    viewports: { desktop: { width: 1440, height: 900 }, mobile: { width: 390, height: 844 } },
    options: {
      timeout: 15000,
      concurrency: 3,
      ignoreErrors: [],
      blockRequests: ['googletagmanager.com'],
      ignoreHttpsErrors: true,
    },
    pages: [
      { id: 'p1', url: `${baseUrl}/`, title: 'Home', components: { card: [70], cards: [7], hero: [1] } },
      { id: 'p2', url: `${baseUrl}/about`, title: 'About', components: { hero: [2] } },
      { id: 'p3', url: `${baseUrl}/missing`, title: 'Missing', components: { hero: [3, 30] } },
      { id: 'p4', url: `${baseUrl}/js`, title: 'JS', components: { hero: [4] } },
      { id: 'p5', url: `${baseUrl}/broken`, title: 'Broken', components: { hero: [5] } },
      { id: 'p6', url: `${baseUrl}/clip`, title: 'Clip', components: { hero: [6] } },
    ],
  };
}

const find = (results, pageId, viewport) => results.runs.find((r) => r.pageId === pageId && r.viewport === viewport);
const failed = (run) => run.checks.filter((c) => c.status === 'failed').map((c) => `${c.check}:${c.blockId ?? ''}`);

test('real browser: finds components, catches mobile overflow, JS errors and HTTP errors', async (t) => {
  if (!loaded) return t.skip('playwright/chromium not installed');

  const results = await runManifest(manifest(), { playwright: loaded.playwright });

  assert.equal(results.runs.length, 12);
  assert.equal(results.error, null);

  // Home: both marker styles found, everything passes, blocked GTM is not a failure.
  for (const vp of ['desktop', 'mobile']) {
    const home = find(results, 'p1', vp);
    assert.deepEqual(failed(home), [], `home ${vp}`);
    assert.equal(home.checks.filter((c) => c.check === 'component-present' && c.status === 'passed').length, 2);
    // A nested block type with no markers of its own is skipped, not failed.
    assert.equal(home.checks.find((c) => c.blockId === 70).status, 'skipped');
  }

  // About: fine on desktop, 800px child overflows a 390px viewport.
  assert.deepEqual(failed(find(results, 'p2', 'desktop')), []);
  const aboutMobile = find(results, 'p2', 'mobile');
  assert.deepEqual(failed(aboutMobile), ['component-overflow:2']);
  assert.equal(aboutMobile.artifacts.components.length, 1);
  assert.ok(fs.existsSync(aboutMobile.artifacts.components[0].path), 'component screenshot written');
  assert.ok(fs.existsSync(aboutMobile.artifacts.trace), 'trace written');

  // Missing: block 30 exists in content but not in the page.
  assert.deepEqual(failed(find(results, 'p3', 'desktop')), ['component-present:30']);

  // Clipped heading: invisible to the overflow check, caught on mobile.
  const clipMobile = find(results, 'p6', 'mobile');
  assert.deepEqual(failed(clipMobile), ['component-clipped:6']);
  assert.match(clipMobile.checks.find((c) => c.check === 'component-clipped').message, /Ride further/);
  assert.doesNotMatch(clipMobile.checks.find((c) => c.check === 'component-clipped').message, /Slide two/);

  // JS error and HTTP 500 are page-level.
  assert.deepEqual(failed(find(results, 'p4', 'desktop')), ['js-errors:']);
  assert.ok(failed(find(results, 'p5', 'desktop')).includes('http-status:'));
  assert.ok(find(results, 'p5', 'desktop').artifacts.page, 'page screenshot for page-level failure');
});

test('real browser: without a token there are no markers, so component checks skip', async (t) => {
  if (!loaded) return t.skip('playwright/chromium not installed');

  const m = manifest(false);
  m.pages = m.pages.slice(0, 2);
  m.viewports = { desktop: m.viewports.desktop };
  const results = await runManifest(m, { playwright: loaded.playwright });

  const home = find(results, 'p1', 'desktop');
  assert.ok(home.checks.filter((c) => c.component).every((c) => c.status === 'skipped'));
  assert.equal(home.status, 'passed');
});

import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { changedFiles, formatRun, scanFiles, summarize } from '../lib/watch-lib.mjs';
import { loadPlaywright } from '../lib/playwright.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));

test('scanFiles picks watchable files and skips ignored and hidden folders', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'cc-scan-'));
  const write = (rel) => {
    fs.mkdirSync(path.dirname(path.join(root, rel)), { recursive: true });
    fs.writeFileSync(path.join(root, rel), 'x');
  };
  write('templates/_blocks/hero.twig');
  write('web/dist/app.css');
  write('web/cpresources/big.js');
  write('web/.git/x.js');
  write('web/image.png');

  const files = [...scanFiles([path.join(root, 'templates'), path.join(root, 'web')], ['cpresources']).keys()]
    .map((f) => path.relative(root, f))
    .sort();
  assert.deepEqual(files, ['templates/_blocks/hero.twig', 'web/dist/app.css']);
  fs.rmSync(root, { recursive: true, force: true });
});

test('changedFiles reports changed, added and removed files', () => {
  const a = new Map([['/t/a.twig', 1], ['/t/b.twig', 1], ['/t/c.twig', 1]]);
  const b = new Map([['/t/a.twig', 1], ['/t/b.twig', 2], ['/t/d.twig', 1]]);
  assert.deepEqual(changedFiles(a, b), ['/t/b.twig', '/t/c.twig', '/t/d.twig']);
});

const manifest = {
  viewports: { desktop: {}, mobile: {} },
  components: { hero: { label: 'Hero' }, richText: { label: 'Rich Text' } },
  pages: [{ id: 'p1', components: { hero: [1], richText: [2] } }],
};
const run = (viewport, checks = [], artifacts = {}) => ({ pageId: 'p1', viewport, checks, artifacts });
const changed = { check: 'component-changed', component: 'hero', blockId: 1, status: 'failed', message: 'Changed since snapshot: h1: width 334→1200px' };

test('summary: one line per component, reasons and before/after for failures', () => {
  const results = { runs: [
    run('desktop'),
    run('mobile', [changed], { components: [{ component: 'hero', blockId: 1, path: '/now.png', before: '/before.png' }] }),
  ] };
  const lines = formatRun(manifest, summarize(manifest, results));
  assert.equal(lines[0], '  Hero       desktop ✓  mobile ✕');
  assert.equal(lines[1], '      ✕ mobile: Changed since snapshot: h1: width 334→1200px');
  assert.equal(lines[2], '        now:    /now.png');
  assert.equal(lines[3], '        before: /before.png');
  assert.equal(lines[4], '  Rich Text  desktop ✓  mobile ✓');
  assert.equal(lines.at(-1), '  ✕ 1 of 4 case(s) changed or failing');
});

test('summary: says what got fixed or newly broke since the previous run', () => {
  const broken = summarize(manifest, { runs: [run('desktop'), run('mobile', [changed])] });
  const fixed = summarize(manifest, { runs: [run('desktop'), run('mobile')] });
  assert.match(formatRun(manifest, fixed, broken)[0], /\(mobile fixed\)$/);
  assert.match(formatRun(manifest, broken, fixed)[0], /\(mobile new failure\)$/);
  assert.equal(formatRun(manifest, fixed, broken).at(-1), '  ✓ all 4 case(s) match the snapshot');
});

// ——— End to end: a "template" on disk, a server rendering it, watch.mjs watching it.

let loaded;
let server;
let baseUrl;
let work;

before(async () => {
  loaded = await loadPlaywright();
  try {
    if (loaded && !fs.existsSync(loaded.playwright.chromium.executablePath())) loaded = null;
  } catch {
    loaded = null;
  }
  work = fs.mkdtempSync(path.join(os.tmpdir(), 'cc-watch-'));
  fs.mkdirSync(path.join(work, 'templates'));
  fs.writeFileSync(path.join(work, 'templates', 'hero.twig'), '<h1>Fitting, coaching and servicing in one place</h1>');
  server = http.createServer((req, res) => {
    const hero = fs.readFileSync(path.join(work, 'templates', 'hero.twig'), 'utf8');
    res.writeHead(200, { 'content-type': 'text/html' });
    res.end(`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">
      <!--cc:start hero 1--><section style="overflow:hidden;padding:20px"><div style="max-width:672px">${hero}</div></section><!--cc:end 1-->`);
  });
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  baseUrl = `http://127.0.0.1:${server.address().port}`;
});

after(() => {
  server?.close();
  if (work) fs.rmSync(work, { recursive: true, force: true });
});

test('watch: snapshots at start, reports a template change, then the fix', { timeout: 60000 }, async (t) => {
  if (!loaded) return t.skip('playwright/chromium not installed');

  const m = {
    schema: 1,
    outputDir: path.join(work, 'latest'),
    markers: { header: 'X-Component-Check', token: 't' },
    viewports: { desktop: { width: 1440, height: 900 }, mobile: { width: 390, height: 844 } },
    options: { timeout: 15000, concurrency: 2, ignoreErrors: [], blockRequests: [], ignoreHttpsErrors: true, tolerance: 2 },
    components: { hero: { label: 'Hero' } },
    pages: [{ id: 'p1', url: `${baseUrl}/`, title: 'Home', components: { hero: [1] }, blocks: { 1: { updated: null } } }],
    snapshot: { dir: path.join(work, 'snapshot') },
  };
  const manifestFile = path.join(work, 'manifest.json');
  fs.writeFileSync(manifestFile, JSON.stringify(m));

  const child = spawn(process.execPath, [
    path.join(here, '..', 'watch.mjs'),
    '--manifest', manifestFile,
    '--results', path.join(work, 'latest', 'results.json'),
    '--path', path.join(work, 'templates'),
    '--interval', '200',
  ], { cwd: path.join(here, '..', '..'), stdio: ['ignore', 'pipe', 'pipe'] });

  let output = '';
  child.stdout.on('data', (d) => { output += d; });
  child.stderr.on('data', (d) => { output += d; });
  const waitFor = async (re, ms = 20000) => {
    const start = Date.now();
    while (!re.test(output)) {
      if (Date.now() - start > ms) throw new Error(`Timed out waiting for ${re}\n---\n${output}`);
      await new Promise((r) => setTimeout(r, 100));
    }
  };

  try {
    await waitFor(/Snapshot taken: 2 block snapshot\(s\)/);
    await waitFor(/Watching/);

    // Break it.
    fs.writeFileSync(path.join(work, 'templates', 'hero.twig'), '<h1 style="width:1200px">Fitting, coaching and servicing in one place</h1>');
    await waitFor(/templates\/hero\.twig changed[\s\S]*Hero\s+desktop ✕  mobile ✕/);
    assert.match(output, /✕ mobile: Changed since snapshot: h1 “Fitting, coaching[^\n]*now cut off by \d+px/);

    // Fix it.
    const mark = output.length;
    fs.writeFileSync(path.join(work, 'templates', 'hero.twig'), '<h1>Fitting, coaching and servicing in one place</h1>');
    await waitFor(/\(desktop fixed, mobile fixed\)[\s\S]*✓ all 2 case\(s\) match the snapshot/);
    assert.ok(output.length > mark);
  } finally {
    child.kill('SIGTERM');
  }
});

test('selectTargets mirrors ChangeSelection.php', async () => {
  const { selectTargets } = await import('../lib/watch-lib.mjs');
  const known = ['cardsGrid', 'hero'];
  assert.deepEqual(selectTargets({ entryTypes: ['hero'], unmapped: [] }, known), { mode: 'some', components: ['hero'], reason: '→ hero' });
  assert.equal(selectTargets({ entryTypes: [], unmapped: ['web/app.css'] }, known).mode, 'all');
  assert.match(selectTargets({ entryTypes: [], unmapped: ['web/app.css'] }, known).reason, /web\/app\.css changed → all/);
  assert.equal(selectTargets({ entryTypes: ['quote'] }, known).mode, 'none');
  assert.equal(selectTargets({ entryTypes: [] }, known).reason, 'no block is rendered through it');
});

test('filterManifest keeps only the pages and blocks of the chosen components', async () => {
  const { filterManifest } = await import('../lib/watch-lib.mjs');
  const m = {
    components: { hero: {}, richText: {} },
    pages: [
      { id: 'p1', components: { hero: [1], richText: [2, 3] }, blocks: { 1: {}, 2: {}, 3: {} } },
      { id: 'p2', components: { richText: [4] }, blocks: { 4: {} } },
    ],
  };
  const f = filterManifest(m, ['hero']);
  assert.equal(f.pages.length, 1);
  assert.deepEqual(f.pages[0].components, { hero: [1] });
  assert.deepEqual(Object.keys(f.pages[0].blocks), ['1']);
  assert.deepEqual(Object.keys(f.components), ['hero']);
});

test('watch with Component Map: a save re-checks only the block it affects', { timeout: 60000 }, async (t) => {
  if (!loaded) return t.skip('playwright/chromium not installed');

  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'cc-watch-map-'));
  fs.mkdirSync(path.join(dir, 'templates'));
  fs.writeFileSync(path.join(dir, 'templates', 'hero.twig'), '<h1>Hero heading</h1>');
  fs.writeFileSync(path.join(dir, 'templates', 'rich.twig'), '<p>Rich text</p>');
  // Stands in for `php craft component-map/impact --json <files>`.
  const fakeImpact = path.join(dir, 'impact.mjs');
  fs.writeFileSync(fakeImpact, `
    const files = (process.argv.at(-1) || '').split(',');
    const entryTypes = files.some((f) => f.endsWith('hero.twig')) ? ['hero'] : [];
    const unmapped = files.filter((f) => f.endsWith('.css'));
    process.stdout.write(JSON.stringify({ status: 'ok', entryTypes, unmapped }));
  `);

  const srv = http.createServer((req, res) => {
    const hero = fs.readFileSync(path.join(dir, 'templates', 'hero.twig'), 'utf8');
    const rich = fs.readFileSync(path.join(dir, 'templates', 'rich.twig'), 'utf8');
    res.writeHead(200, { 'content-type': 'text/html' });
    res.end(`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">
      <!--cc:start hero 1--><section>${hero}</section><!--cc:end 1-->
      <!--cc:start richText 2--><section>${rich}</section><!--cc:end 2-->`);
  });
  await new Promise((r) => srv.listen(0, '127.0.0.1', r));
  const url = `http://127.0.0.1:${srv.address().port}/`;

  const m = {
    schema: 1,
    outputDir: path.join(dir, 'latest'),
    markers: { header: 'X-Component-Check', token: 't' },
    viewports: { desktop: { width: 1440, height: 900 } },
    options: { timeout: 15000, concurrency: 1, ignoreErrors: [], blockRequests: [], ignoreHttpsErrors: true, tolerance: 2 },
    components: { hero: { label: 'Hero' }, richText: { label: 'Rich Text' } },
    pages: [{ id: 'p1', url, title: 'Home', components: { hero: [1], richText: [2] }, blocks: { 1: {}, 2: {} } }],
    snapshot: { dir: path.join(dir, 'snapshot') },
  };
  fs.writeFileSync(path.join(dir, 'manifest.json'), JSON.stringify(m));

  const child = spawn(process.execPath, [
    path.join(here, '..', 'watch.mjs'),
    '--manifest', path.join(dir, 'manifest.json'),
    '--path', path.join(dir, 'templates'),
    '--interval', '200',
    '--impact', JSON.stringify([process.execPath, fakeImpact]),
  ], { cwd: path.join(here, '..', '..'), stdio: ['ignore', 'pipe', 'pipe'] });
  let output = '';
  child.stdout.on('data', (d) => { output += d; });
  child.stderr.on('data', (d) => { output += d; });
  const waitFor = async (re, ms = 20000) => {
    const start = Date.now();
    while (!re.test(output)) {
      if (Date.now() - start > ms) throw new Error(`Timed out waiting for ${re}\n---\n${output}`);
      await new Promise((r) => setTimeout(r, 100));
    }
  };

  try {
    await waitFor(/Component Map is installed/);
    await waitFor(/Watching/);

    fs.writeFileSync(path.join(dir, 'templates', 'hero.twig'), '<h1 style="color:rgb(200,0,0)">Hero heading</h1>');
    await waitFor(/hero\.twig changed → hero[\s\S]*Hero\s+desktop ✕/);
    const run = output.slice(output.lastIndexOf('hero.twig changed'));
    assert.doesNotMatch(run.split('✕ 1 of')[0], /Rich Text/, 'rich text was not re-run');

    fs.writeFileSync(path.join(dir, 'templates', 'rich.twig'), '<p>Rich text, edited</p>');
    await waitFor(/rich\.twig changed — no block is rendered through it, nothing to check/);
  } finally {
    child.kill('SIGTERM');
    srv.close();
    fs.rmSync(dir, { recursive: true, force: true });
  }
});

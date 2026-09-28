import fs from 'node:fs';
import path from 'node:path';
import { buildChecks, clampClip, isMobileViewport, matchesAny, runStatus, slug, FAILED } from './checks.mjs';
import { collectLayout, scrollThrough } from './layout.mjs';
import { compareWithSnapshot, componentOf, loadIndex, snapshotKey, writeIndex } from './snapshot.mjs';

/**
 * Visits every manifest page on every viewport and records what it saw.
 *
 * @param {object} manifest see ManifestBuilder.php
 * @param {object} opts
 * @param {object} opts.playwright the loaded `playwright` module
 * @param {boolean} [opts.headed]
 * @param {(line: string) => void} [opts.log]
 * @returns {Promise<object>} results (see ResultsReport.php)
 */
export async function runManifest(manifest, { playwright, headed = false, log = () => {} }) {
  const startedAt = new Date();
  const outputDir = manifest.outputDir;
  const options = manifest.options || {};
  const jobs = [];

  for (const page of manifest.pages) {
    for (const [name, size] of Object.entries(manifest.viewports)) {
      jobs.push({ page, viewportName: name, viewport: size });
    }
  }

  const snapshot = manifest.snapshot ?? null;
  const ctx = {
    snapshot,
    index: snapshot?.action === 'compare' ? loadIndex(snapshot.dir) : null,
  };

  const browser = await playwright.chromium.launch({ headless: !headed });
  const runs = [];

  try {
    await pool(jobs, Math.max(1, options.concurrency || 1), async (job) => {
      const run = await runOne(browser, job, manifest, outputDir, ctx);
      runs.push(run);
      const mark = run.status === FAILED ? '✕' : '✓';
      log(`${mark} ${job.viewportName.padEnd(8)} ${job.page.url}`);
    });
  } finally {
    await browser.close();
  }

  // Stable order regardless of concurrency.
  const order = new Map(jobs.map((j, i) => [`${j.page.id}|${j.viewportName}`, i]));
  runs.sort((a, b) => order.get(`${a.pageId}|${a.viewport}`) - order.get(`${b.pageId}|${b.viewport}`));

  let recorded = null;
  if (snapshot?.action === 'record') {
    const entries = runs.flatMap((r) => r.recorded ?? []);
    writeIndex(snapshot.dir, entries);
    recorded = entries.length;
  }
  for (const r of runs) delete r.recorded;

  const finishedAt = new Date();
  return {
    schema: 1,
    snapshot: snapshot ? {
      action: snapshot.action,
      dir: snapshot.dir,
      recorded,
      comparedAgainst: ctx.index?.updatedAt ?? null,
    } : null,
    startedAt: startedAt.toISOString(),
    finishedAt: finishedAt.toISOString(),
    durationMs: finishedAt - startedAt,
    error: null,
    runs,
  };
}

async function runOne(browser, { page: target, viewportName, viewport }, manifest, outputDir, ctx = {}) {
  const options = manifest.options || {};
  const markers = manifest.markers;
  const origin = new URL(target.url).origin;
  const blockRequests = options.blockRequests || [];
  const blockIds = Object.values(target.components).flat();

  const context = await browser.newContext({
    viewport: { width: viewport.width, height: viewport.height },
    isMobile: isMobileViewport(viewport),
    hasTouch: isMobileViewport(viewport),
    ignoreHTTPSErrors: Boolean(options.ignoreHttpsErrors),
    reducedMotion: 'reduce',
  });

  await context.tracing.start({ screenshots: true, snapshots: true });

  const blocked = new Set();
  await context.route('**/*', async (route) => {
    const request = route.request();
    const url = request.url();
    if (matchesAny(url, blockRequests)) {
      blocked.add(url);
      return route.abort('blockedbyclient');
    }
    // The marker token only ever goes to the site itself.
    if (markers && sameOrigin(url, origin)) {
      return route.continue({ headers: { ...request.headers(), [markers.header.toLowerCase()]: markers.token } });
    }
    return route.continue();
  });

  const page = await context.newPage();
  const observed = {
    status: null,
    navigationError: null,
    pageErrors: [],
    consoleErrors: [],
    failedRequests: [],
    layout: null,
  };

  page.on('pageerror', (error) => observed.pageErrors.push(String(error?.message ?? error)));
  page.on('console', (msg) => {
    if (msg.type() === 'error') observed.consoleErrors.push(msg.text());
  });
  page.on('requestfailed', (request) => {
    if (blocked.has(request.url())) return;
    observed.failedRequests.push({
      url: request.url(),
      type: request.resourceType(),
      reason: request.failure()?.errorText ?? 'failed',
    });
  });
  page.on('response', (response) => {
    if (response.status() >= 400) {
      const request = response.request();
      if (request.resourceType() === 'document' && request.frame() === page.mainFrame()) return; // reported as http-status
      observed.failedRequests.push({ url: response.url(), type: request.resourceType(), reason: `HTTP ${response.status()}` });
    }
  });

  const timeout = options.timeout || 30000;

  try {
    const response = await page.goto(target.url, { waitUntil: 'load', timeout });
    observed.status = response ? response.status() : null;
    await page.waitForLoadState('networkidle', { timeout: Math.min(5000, timeout) }).catch(() => {});
    await page.evaluate(scrollThrough).catch(() => {});
    await page.waitForLoadState('networkidle', { timeout: Math.min(3000, timeout) }).catch(() => {});
    observed.layout = await page.evaluate(collectLayout, blockIds);
  } catch (error) {
    observed.navigationError = String(error?.message ?? error).split('\n')[0];
  }

  const checks = buildChecks(observed, target.components, {
    ignoreErrors: options.ignoreErrors || [],
    markersExpected: Boolean(markers),
  });
  if (ctx.index) {
    checks.push(...compareWithSnapshot({
      layout: observed.layout,
      target,
      viewport: viewportName,
      index: ctx.index,
      dir: ctx.snapshot.dir,
      tolerance: options.tolerance,
    }));
  }
  const status = runStatus(checks);

  const artifacts = { page: null, components: [], trace: null };
  const base = `${target.id}-${slug(viewportName)}`;

  if (status === FAILED) {
    const pageFailed = checks.some((c) => c.status === FAILED && c.component === null);

    for (const c of checks) {
      if (c.status !== FAILED || c.component === null || !observed.layout) continue;
      if (artifacts.components.some((a) => a.blockId === c.blockId)) continue;
      const region = observed.layout.regions.find((r) => r.blockId === c.blockId);
      if (!region || !region.rect) continue;
      const file = path.join(outputDir, 'screenshots', slug(c.component), `${base}-${c.blockId}.png`);
      try {
        fs.mkdirSync(path.dirname(file), { recursive: true });
        await page.screenshot({
          path: file,
          fullPage: true,
          animations: 'disabled',
          clip: clampClip(region.rect, observed.layout.pageWidth, observed.layout.pageHeight),
        });
        const before = ctx.index?.blocks?.[snapshotKey(c.blockId, viewportName)]?.screenshot;
        artifacts.components.push({
          component: c.component,
          blockId: c.blockId,
          path: file,
          before: before ? path.join(ctx.snapshot.dir, before) : null,
        });
      } catch {
        // A failed screenshot must not hide the failure itself.
      }
    }

    if (pageFailed || artifacts.components.length === 0) {
      const file = path.join(outputDir, 'screenshots', '_pages', `${base}.png`);
      try {
        fs.mkdirSync(path.dirname(file), { recursive: true });
        await page.screenshot({ path: file, fullPage: true, animations: 'disabled' });
        artifacts.page = file;
      } catch {
        // ignore
      }
    }

    const trace = path.join(outputDir, 'traces', `${base}.zip`);
    fs.mkdirSync(path.dirname(trace), { recursive: true });
    await context.tracing.stop({ path: trace }).catch(() => {});
    artifacts.trace = fs.existsSync(trace) ? trace : null;
  } else {
    await context.tracing.stop().catch(() => {});
  }

  // Recording a snapshot: geometry + screenshot of every block we could find.
  const recorded = [];
  if (ctx.snapshot?.action === 'record' && observed.layout) {
    for (const region of observed.layout.regions) {
      const component = componentOf(target, region.blockId);
      if (!component || !region.found || !region.geometry || !region.rect) continue;
      const rel = path.join(slug(viewportName), String(region.blockId));
      const geometryFile = `${rel}.json`;
      const screenshotFile = `${rel}.png`;
      fs.mkdirSync(path.join(ctx.snapshot.dir, slug(viewportName)), { recursive: true });
      fs.writeFileSync(path.join(ctx.snapshot.dir, geometryFile), JSON.stringify(region.geometry));
      let shot = screenshotFile;
      try {
        await page.screenshot({
          path: path.join(ctx.snapshot.dir, screenshotFile),
          fullPage: true,
          animations: 'disabled',
          clip: clampClip(region.rect, observed.layout.pageWidth, observed.layout.pageHeight),
        });
      } catch {
        shot = null;
      }
      recorded.push({
        blockId: region.blockId,
        viewport: viewportName,
        component,
        url: target.url,
        updated: target.blocks?.[region.blockId]?.updated ?? null,
        takenAt: new Date().toISOString(),
        geometry: geometryFile,
        screenshot: shot,
      });
    }
  }

  await context.close();

  return {
    recorded,
    pageId: target.id,
    url: target.url,
    title: target.title,
    viewport: viewportName,
    status,
    checks,
    artifacts,
  };
}

function sameOrigin(url, origin) {
  try {
    return new URL(url).origin === origin;
  } catch {
    return false;
  }
}

async function pool(items, size, worker) {
  let next = 0;
  const lanes = Array.from({ length: Math.min(size, items.length) }, async () => {
    while (next < items.length) {
      const item = items[next++];
      await worker(item);
    }
  });
  await Promise.all(lanes);
}

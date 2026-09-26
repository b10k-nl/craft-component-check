import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildChecks, clampClip, isMobileViewport, matchesAny, runStatus, slug } from '../lib/checks.mjs';

const ok = () => ({
  status: 200,
  navigationError: null,
  pageErrors: [],
  consoleErrors: [],
  failedRequests: [],
  layout: {
    markersPresent: true,
    pageOverflow: 0,
    regions: [{ blockId: 1, found: true, width: 1440, height: 600, overflow: 0, brokenImages: [] }],
  },
});

const byId = (checks, id, blockId = null) => checks.find((c) => c.check === id && (blockId === null || c.blockId === blockId));

test('a healthy page passes every check', () => {
  const checks = buildChecks(ok(), { hero: [1] }, { markersExpected: true });
  assert.equal(runStatus(checks), 'passed');
  assert.equal(byId(checks, 'component-present').status, 'passed');
});

test('navigation failure is a single page-level failure', () => {
  const checks = buildChecks({ ...ok(), navigationError: 'net::ERR_CONNECTION_REFUSED' }, { hero: [1] });
  assert.equal(checks.length, 1);
  assert.equal(checks[0].status, 'failed');
  assert.equal(checks[0].component, null);
});

test('HTTP errors and uncaught exceptions fail the page', () => {
  const checks = buildChecks({ ...ok(), status: 500, pageErrors: ['TypeError: x is undefined'] }, { hero: [1] });
  assert.equal(byId(checks, 'http-status').status, 'failed');
  assert.equal(byId(checks, 'js-errors').status, 'failed');
  assert.match(byId(checks, 'js-errors').message, /TypeError/);
});

test('ignored errors do not fail', () => {
  const checks = buildChecks({ ...ok(), pageErrors: ['ResizeObserver loop limit exceeded'] }, { hero: [1] }, {
    ignoreErrors: ['ResizeObserver'],
  });
  assert.equal(byId(checks, 'js-errors').status, 'passed');
});

test('console errors warn but do not fail', () => {
  const checks = buildChecks({ ...ok(), consoleErrors: ['Failed to load thing'] }, { hero: [1] });
  assert.equal(byId(checks, 'console-errors').status, 'warning');
  assert.equal(runStatus(checks), 'passed');
});

test('only critical requests fail the page', () => {
  const img = buildChecks({ ...ok(), failedRequests: [{ url: '/a.png', type: 'image', reason: 'HTTP 404' }] }, { hero: [1] });
  assert.equal(byId(img, 'requests').status, 'passed');

  const css = buildChecks({ ...ok(), failedRequests: [{ url: '/app.css', type: 'stylesheet', reason: 'HTTP 404' }] }, { hero: [1] });
  assert.equal(byId(css, 'requests').status, 'failed');
  assert.match(byId(css, 'requests').message, /app\.css/);
});

test('component overflow blames the component, not the page', () => {
  const observed = ok();
  observed.layout.pageOverflow = 410;
  observed.layout.regions[0].overflow = 410;

  const checks = buildChecks(observed, { hero: [1] });
  assert.equal(byId(checks, 'page-overflow').status, 'warning');
  assert.equal(byId(checks, 'component-overflow').status, 'failed');
  assert.equal(byId(checks, 'component-overflow').component, 'hero');
});

test('unexplained page overflow fails the page', () => {
  const observed = ok();
  observed.layout.pageOverflow = 50;
  assert.equal(byId(buildChecks(observed, { hero: [1] }), 'page-overflow').status, 'failed');
});

test('a block in the content but not in the page fails', () => {
  const checks = buildChecks(ok(), { hero: [1], cards: [9] });
  assert.equal(byId(checks, 'component-present', 9).status, 'failed');
  assert.equal(byId(checks, 'component-present', 9).component, 'cards');
});

test('no markers on the page skips component checks', () => {
  const observed = ok();
  observed.layout.markersPresent = false;
  const checks = buildChecks(observed, { hero: [1] }, { markersExpected: true });
  assert.equal(byId(checks, 'component-present').status, 'skipped');
  assert.match(byId(checks, 'component-present').message, /craft\.regression\.start/);
  assert.equal(runStatus(checks), 'passed');
});

test('zero-size and broken images fail', () => {
  const observed = ok();
  observed.layout.regions[0] = { blockId: 1, found: true, width: 0, height: 0, overflow: 0, brokenImages: ['/x.jpg'] };
  const checks = buildChecks(observed, { hero: [1] });
  assert.equal(byId(checks, 'component-visible').status, 'failed');
  assert.equal(byId(checks, 'component-images').status, 'failed');
});

test('helpers', () => {
  assert.equal(matchesAny('https://www.googletagmanager.com/gtm.js', ['googletagmanager.com']), true);
  assert.equal(matchesAny('', ['x']), false);
  assert.deepEqual(clampClip({ x: -5, y: 10.4, width: 5000, height: 20.2 }, 1440, 3000), { x: 0, y: 10, width: 1440, height: 21 });
  assert.equal(slug('Hero / Dark!'), 'Hero-Dark');
  assert.equal(isMobileViewport({ width: 390, height: 844 }), true);
  assert.equal(isMobileViewport({ width: 1440, height: 900 }), false);
  assert.equal(isMobileViewport({ width: 390, height: 844, isMobile: false }), false);
});

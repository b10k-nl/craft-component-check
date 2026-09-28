// Pure functions: turn what the browser observed into pass/fail checks.
// No Playwright here, so everything in this file is unit-tested with node:test.

export const PASSED = 'passed';
export const FAILED = 'failed';
export const SKIPPED = 'skipped';
export const WARNING = 'warning';

// Two kinds of verdict:
// - FAILED for things that are wrong whatever the design intends: HTTP errors,
//   uncaught exceptions, missing scripts/stylesheets, a block that is in the
//   content but not on the page, a block with no size, a broken image.
// - WARNING for layout heuristics (sideways scrolling, overflow, cut-off
//   text): often a bug, sometimes the design. They never fail a run on their
//   own; a change against a snapshot does (see snapshot.mjs).

/** Resource types whose failure means the page did not load as intended. */
export const CRITICAL_RESOURCES = new Set(['document', 'script', 'stylesheet']);

/** Pixels of slack before an overflow counts (sub-pixel rounding). */
export const OVERFLOW_TOLERANCE = 1;

export function matchesAny(text, patterns) {
  if (!text) return false;
  return (patterns || []).some((p) => p && String(text).includes(p));
}

function check(id, status, message, component = null, blockId = null) {
  return { check: id, component, blockId, status, message };
}

function list(items, max = 3) {
  const shown = items.slice(0, max).join(' | ');
  return items.length > max ? `${shown} (+${items.length - max} more)` : shown;
}

/**
 * @param {object} observed
 * @param {?number} observed.status HTTP status of the document, null if navigation failed
 * @param {?string} observed.navigationError
 * @param {string[]} observed.pageErrors uncaught exceptions
 * @param {string[]} observed.consoleErrors console.error messages
 * @param {Array<{url: string, type: string, reason: string}>} observed.failedRequests
 * @param {?object} observed.layout result of collectLayout() in the page
 * @param {Object<string, number[]>} expected component handle → block IDs
 * @param {object} options
 * @param {string[]} options.ignoreErrors
 * @param {boolean} options.markersExpected whether the run asked for markers
 */
export function buildChecks(observed, expected, options = {}) {
  const ignore = options.ignoreErrors || [];
  const checks = [];

  if (observed.navigationError) {
    checks.push(check('navigation', FAILED, `Page did not load: ${observed.navigationError}`));
    return checks;
  }

  checks.push(
    observed.status !== null && observed.status < 400
      ? check('http-status', PASSED, `HTTP ${observed.status}`)
      : check('http-status', FAILED, `HTTP ${observed.status}`),
  );

  const pageErrors = (observed.pageErrors || []).filter((e) => !matchesAny(e, ignore));
  checks.push(
    pageErrors.length === 0
      ? check('js-errors', PASSED, 'No uncaught JavaScript errors')
      : check('js-errors', FAILED, `Uncaught JavaScript error: ${list(pageErrors)}`),
  );

  const consoleErrors = (observed.consoleErrors || []).filter((e) => !matchesAny(e, ignore));
  if (consoleErrors.length > 0) {
    checks.push(check('console-errors', WARNING, `console.error: ${list(consoleErrors)}`));
  }

  const critical = (observed.failedRequests || []).filter(
    (r) => CRITICAL_RESOURCES.has(r.type) && !matchesAny(r.url, ignore),
  );
  checks.push(
    critical.length === 0
      ? check('requests', PASSED, 'No failed critical requests')
      : check('requests', FAILED, `Failed ${critical[0].type}: ${list(critical.map((r) => `${r.url} (${r.reason})`))}`),
  );

  const layout = observed.layout;
  if (!layout) {
    return checks;
  }

  const regions = layout.regions || [];
  const overflowing = regions.filter((r) => r.found && r.overflow > OVERFLOW_TOLERANCE);

  if (layout.pageOverflow > OVERFLOW_TOLERANCE) {
    // If a marked component explains it, blame the component, not the page.
    checks.push(
      overflowing.length > 0
        ? check('page-overflow', WARNING, `Page scrolls sideways by ${layout.pageOverflow}px (caused by a component below)`)
        : check('page-overflow', WARNING, `Page scrolls sideways by ${layout.pageOverflow}px`),
    );
  } else {
    checks.push(check('page-overflow', PASSED, 'No horizontal scrolling'));
  }

  for (const [component, blockIds] of Object.entries(expected)) {
    for (const blockId of blockIds) {
      const region = regions.find((r) => r.blockId === blockId);

      if (!layout.markersPresent) {
        checks.push(
          check(
            'component-present',
            SKIPPED,
            options.markersExpected ? 'No markers on this page — is craft.componentCheck.start() in the block loop?' : 'Markers off: page-level checks only',
            component,
            blockId,
          ),
        );
        continue;
      }

      // Only blocks rendered through a marked loop can be located. A nested
      // block (a card inside a cards grid) is usually rendered by its parent's
      // template without markers of its own: skip it rather than blame it.
      if (Array.isArray(layout.markedComponents) && !layout.markedComponents.includes(component)) {
        checks.push(
          check('component-present', SKIPPED, `No markers for “${component}” on this page (rendered inside another block?)`, component, blockId),
        );
        continue;
      }

      if (!region || !region.found) {
        checks.push(check('component-present', FAILED, `Block #${blockId} is in the content but was not rendered`, component, blockId));
        continue;
      }
      checks.push(check('component-present', PASSED, 'Rendered', component, blockId));

      checks.push(
        region.width > 0 && region.height > 0
          ? check('component-visible', PASSED, `${region.width}×${region.height}px`, component, blockId)
          : check('component-visible', FAILED, `Block #${blockId} renders with no size (${region.width}×${region.height}px)`, component, blockId),
      );

      checks.push(
        region.overflow > OVERFLOW_TOLERANCE
          ? check('component-overflow', WARNING, `Block #${blockId} is ${region.overflow}px wider than the viewport`, component, blockId)
          : check('component-overflow', PASSED, 'Fits the viewport', component, blockId),
      );

      const clipped = region.clippedText || [];
      checks.push(
        clipped.length === 0
          ? check('component-clipped', PASSED, 'No text cut off', component, blockId)
          : check(
              'component-clipped',
              WARNING,
              `Text in block #${blockId} is cut off by ${clipped[0].px}px: “${clipped[0].text}”${clipped.length > 1 ? ` (+${clipped.length - 1} more)` : ''}`,
              component,
              blockId,
            ),
      );

      const broken = region.brokenImages || [];
      checks.push(
        broken.length === 0
          ? check('component-images', PASSED, 'Images load', component, blockId)
          : check('component-images', FAILED, `Broken image in block #${blockId}: ${list(broken)}`, component, blockId),
      );
    }
  }

  return checks;
}

export function runStatus(checks) {
  return checks.some((c) => c.status === FAILED) ? FAILED : PASSED;
}

/** Keep a screenshot clip inside the page and at least 1px big. */
export function clampClip(rect, pageWidth, pageHeight) {
  const x = Math.max(0, Math.floor(rect.x));
  const y = Math.max(0, Math.floor(rect.y));
  const width = Math.max(1, Math.min(Math.ceil(rect.width), pageWidth - x));
  const height = Math.max(1, Math.min(Math.ceil(rect.height), pageHeight - y));
  return { x, y, width, height };
}

/** Filesystem-safe fragment for artifact names. */
export function slug(value) {
  return String(value).replace(/[^A-Za-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80) || 'x';
}

export function isMobileViewport(viewport) {
  if (typeof viewport.isMobile === 'boolean') return viewport.isMobile;
  return viewport.width < 600;
}

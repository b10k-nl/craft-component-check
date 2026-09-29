// The parts of watch mode that need neither a browser nor a terminal:
// finding changed files, and turning a run into a few lines that say what is
// different from the previous run. Unit-tested.

import fs from 'node:fs';
import path from 'node:path';

export const WATCH_EXTENSIONS = new Set(['.twig', '.html', '.css', '.js', '.mjs', '.svg', '.json']);

/**
 * mtime of every watchable file under `roots`, skipping ignored folder names.
 * Polling, not fs.watch: file events do not reliably cross the Docker mount
 * between the Mac and the DDEV container, a stat loop always works.
 *
 * @returns {Map<string, number>} absolute path → mtimeMs
 */
export function scanFiles(roots, ignore = []) {
  const ignored = new Set(ignore);
  const files = new Map();
  const walk = (dir) => {
    let entries;
    try {
      entries = fs.readdirSync(dir, { withFileTypes: true });
    } catch {
      return;
    }
    for (const entry of entries) {
      if (ignored.has(entry.name) || entry.name.startsWith('.')) continue;
      const full = path.join(dir, entry.name);
      if (entry.isDirectory()) {
        walk(full);
      } else if (entry.isFile() && WATCH_EXTENSIONS.has(path.extname(entry.name).toLowerCase())) {
        try {
          files.set(full, fs.statSync(full).mtimeMs);
        } catch {
          // deleted between readdir and stat
        }
      }
    }
  };
  for (const root of roots) walk(root);
  return files;
}

/** Files added, changed or removed between two scans. */
export function changedFiles(before, after) {
  const changed = [];
  for (const [file, mtime] of after) {
    if (before.get(file) !== mtime) changed.push(file);
  }
  for (const file of before.keys()) {
    if (!after.has(file)) changed.push(file);
  }
  return changed.sort();
}

/**
 * Per component × viewport: the worst status and the first reason.
 *
 * @returns {Map<string, {component: string, viewport: string, status: string, reason: string|null, screenshot: string|null, before: string|null, warnings: number}>}
 */
export function summarize(manifest, results) {
  const state = new Map();
  const rank = { passed: 0, skipped: 1, failed: 2 };

  for (const run of results.runs ?? []) {
    const page = manifest.pages.find((p) => p.id === run.pageId);
    if (!page) continue;
    for (const component of Object.keys(page.components)) {
      const key = `${component}|${run.viewport}`;
      const entry = state.get(key) ?? {
        component, viewport: run.viewport, status: 'passed', reason: null, screenshot: null, before: null, warnings: 0,
      };
      for (const c of run.checks) {
        if (c.component !== null && c.component !== component) continue;
        if (c.status === 'warning') entry.warnings++;
        if (c.status === 'failed' && rank.failed > rank[entry.status]) {
          entry.status = 'failed';
          entry.reason = c.message;
          const shot = (run.artifacts?.components ?? []).find((a) => a.component === component);
          entry.screenshot = shot?.path ?? run.artifacts?.page ?? null;
          entry.before = shot?.before ?? null;
        }
      }
      state.set(key, entry);
    }
  }
  return state;
}

/**
 * The lines watch mode prints after a run: one per component, both viewports
 * side by side, with "fixed" / "new" relative to the previous run.
 */
export function formatRun(manifest, state, previous = null) {
  const lines = [];
  const viewports = Object.keys(manifest.viewports);
  const components = [...new Set([...state.values()].map((e) => e.component))].sort();
  const width = Math.max(8, ...components.map((c) => (manifest.components?.[c]?.label ?? c).length));

  for (const component of components) {
    const label = manifest.components?.[component]?.label ?? component;
    const cells = viewports.map((vp) => {
      const e = state.get(`${component}|${vp}`);
      const mark = !e ? '–' : e.status === 'failed' ? '✕' : '✓';
      return `${vp} ${mark}`;
    });

    const notes = [];
    for (const vp of viewports) {
      const now = state.get(`${component}|${vp}`);
      const was = previous?.get(`${component}|${vp}`);
      if (!now) continue;
      if (was && was.status === 'failed' && now.status !== 'failed') notes.push(`${vp} fixed`);
      if (was && was.status !== 'failed' && now.status === 'failed') notes.push(`${vp} new failure`);
    }

    lines.push(`  ${label.padEnd(width)}  ${cells.join('  ')}${notes.length ? `   (${notes.join(', ')})` : ''}`);

    for (const vp of viewports) {
      const e = state.get(`${component}|${vp}`);
      if (e?.status !== 'failed') continue;
      lines.push(`      ✕ ${vp}: ${e.reason}`);
      if (e.screenshot) lines.push(`        now:    ${e.screenshot}`);
      if (e.before) lines.push(`        before: ${e.before}`);
    }
  }

  const failed = [...state.values()].filter((e) => e.status === 'failed').length;
  const warnings = [...state.values()].reduce((n, e) => n + e.warnings, 0);
  lines.push(
    failed === 0
      ? `  ✓ all ${state.size} case(s) match the snapshot${warnings ? ` · ${warnings} warning(s)` : ''}`
      : `  ✕ ${failed} of ${state.size} case(s) changed or failing${warnings ? ` · ${warnings} warning(s)` : ''}`,
  );
  return lines;
}

/**
 * What to re-run after a change, from `component-map/impact --json`. Mirrors
 * ChangeSelection.php: templates → the blocks rendered through them; CSS, JS
 * or PHP → everything; nothing that renders a block → nothing.
 *
 * @returns {{mode: 'all'|'some'|'none', components: string[], reason: string}}
 */
export function selectTargets(impact, known) {
  const unmapped = (impact?.unmapped ?? []).filter((f) => typeof f === 'string' && isFrontEndFile(f));
  if (unmapped.length > 0) {
    return { mode: 'all', components: [...known], reason: `${unmapped.length === 1 ? unmapped[0] : `${unmapped.length} non-template files`} changed → all` };
  }
  const affected = [...new Set((impact?.entryTypes ?? []).filter((h) => typeof h === 'string'))].sort();
  const testable = affected.filter((h) => known.includes(h));
  if (testable.length === 0) {
    return {
      mode: 'none',
      components: [],
      reason: affected.length === 0 ? 'no block is rendered through it' : `affects ${affected.join(', ')} — not on any tested page`,
    };
  }
  return { mode: 'some', components: testable, reason: `→ ${testable.join(', ')}` };
}

/** The manifest narrowed to some components: fewer pages, fewer checks. */
export function filterManifest(manifest, components) {
  const keep = new Set(components);
  const pages = [];
  for (const page of manifest.pages) {
    const kept = Object.fromEntries(Object.entries(page.components).filter(([c]) => keep.has(c)));
    if (Object.keys(kept).length === 0) continue;
    const ids = new Set(Object.values(kept).flat().map(String));
    const blocks = page.blocks ? Object.fromEntries(Object.entries(page.blocks).filter(([id]) => ids.has(id))) : undefined;
    pages.push({ ...page, components: kept, ...(blocks ? { blocks } : {}) });
  }
  const componentsMeta = Object.fromEntries(Object.entries(manifest.components ?? {}).filter(([c]) => keep.has(c)));
  return { ...manifest, pages, components: componentsMeta };
}

/** Mirrors ChangeSelection::isFrontEndFile(): can this non-template file change how pages look? */
export function isFrontEndFile(file) {
  const p = String(file).replace(/\\/g, '/').replace(/^\/+/, '');
  const segments = p.split('/');
  if (segments.some((s) => s.startsWith('.'))) return false;
  if (['storage', 'vendor', 'node_modules', 'config/project'].some((d) => p === d || p.startsWith(`${d}/`) || p.includes(`/${d}/`))) return false;
  const base = segments.at(-1).toLowerCase();
  if (['composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml', 'bun.lockb', 'license', 'license.md'].includes(base)) return false;
  const ext = base.includes('.') ? base.split('.').pop() : '';
  return !['md', 'markdown', 'txt', 'rst', 'log', 'lock'].includes(ext);
}

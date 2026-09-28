// Before/after snapshots of blocks, kept locally (storage/component-check/snapshot).
//
//   component-check/snapshot hero   → records geometry + a screenshot per block
//   …change the template or CSS…
//   component-check/test hero       → compares against it
//
// A snapshot answers "what changed?" instead of "is this bad?", so it needs no
// guess about what the designer intended: text that was cut off before the
// change is cut off in the snapshot too, and nothing is reported.

import fs from 'node:fs';
import path from 'node:path';
import { FAILED, PASSED, SKIPPED, WARNING } from './checks.mjs';
import { diffGeometry } from './diff.mjs';

export const INDEX_FILE = 'index.json';

export function snapshotKey(blockId, viewport) {
  return `${blockId}|${viewport}`;
}

export function loadIndex(dir) {
  try {
    const index = JSON.parse(fs.readFileSync(path.join(dir, INDEX_FILE), 'utf8'));
    return index && typeof index === 'object' && index.blocks ? index : null;
  } catch {
    return null;
  }
}

/** Merges new entries into the index on disk (a snapshot of `hero` keeps `cards`). */
export function writeIndex(dir, entries) {
  const index = loadIndex(dir) ?? { schema: 1, blocks: {} };
  for (const entry of entries) {
    index.blocks[snapshotKey(entry.blockId, entry.viewport)] = entry;
  }
  index.updatedAt = new Date().toISOString();
  fs.mkdirSync(dir, { recursive: true });
  fs.writeFileSync(path.join(dir, INDEX_FILE), JSON.stringify(index, null, 2));
  return index;
}

export function componentOf(target, blockId) {
  for (const [component, ids] of Object.entries(target.components)) {
    if (ids.includes(blockId)) return component;
  }
  return null;
}

/**
 * One `component-changed` check per block that has a snapshot.
 */
export function compareWithSnapshot({ layout, target, viewport, index, dir, tolerance }) {
  const checks = [];
  if (!layout || !index) return checks;

  for (const region of layout.regions) {
    const component = componentOf(target, region.blockId);
    if (!component || !region.found || !region.geometry) continue;

    const entry = index.blocks[snapshotKey(region.blockId, viewport)];
    const check = (status, message) => ({
      check: 'component-changed', component, blockId: region.blockId, status, message,
    });

    if (!entry) {
      checks.push(check(SKIPPED, 'No snapshot of this block yet'));
      continue;
    }

    const updated = target.blocks?.[region.blockId]?.updated ?? null;
    if (entry.updated && updated && entry.updated !== updated) {
      checks.push(check(WARNING, `Content of block #${region.blockId} was edited after the snapshot — not compared`));
      continue;
    }

    let before;
    try {
      before = JSON.parse(fs.readFileSync(path.join(dir, entry.geometry), 'utf8'));
    } catch {
      checks.push(check(SKIPPED, 'Snapshot file missing'));
      continue;
    }

    const diff = diffGeometry(before, region.geometry, { tolerance });
    checks.push(
      diff.changed
        ? check(FAILED, `Changed since snapshot: ${diff.summary}`)
        : check(PASSED, 'Unchanged since snapshot'),
    );
  }

  return checks;
}

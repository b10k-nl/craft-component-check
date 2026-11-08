import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { editedSince, environmentDiff, environmentOf, loadIndex, writeIndex } from '../lib/snapshot.mjs';
import { environmentNote } from '../lib/watch-lib.mjs';

test('a snapshot records what rendered it', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'cc-snap-'));
  const env = environmentOf({ version: () => '141.0.7390.37' });
  assert.equal(env.browser, 'chromium 141.0.7390.37');
  assert.equal(env.platform, `${process.platform}-${process.arch}`);

  writeIndex(dir, [{ blockId: 1, viewport: 'desktop', geometry: 'desktop/1.json' }], env);
  // A later partial snapshot without an environment keeps the recorded one.
  writeIndex(dir, [{ blockId: 2, viewport: 'desktop', geometry: 'desktop/2.json' }]);
  const index = loadIndex(dir);
  assert.deepEqual(index.environment, env);
  assert.equal(Object.keys(index.blocks).length, 2);
});

test('comparing in another browser or OS is flagged, not hidden', () => {
  const laptop = { browser: 'chromium 141.0', platform: 'darwin-arm64' };
  const ci = { browser: 'chromium 141.0', platform: 'linux-x64' };

  assert.equal(environmentDiff(null, ci), null, 'old snapshots without an environment say nothing');
  assert.equal(environmentDiff(laptop, laptop).matches, true);

  const diff = environmentDiff(laptop, ci);
  assert.equal(diff.matches, false);
  assert.deepEqual(diff.differs, ['platform']);
  assert.equal(
    environmentNote(diff),
    'The snapshot was taken with darwin-arm64; this run uses linux-x64. Differences may be rendering, not your change — re-take the snapshot here.',
  );
  assert.equal(environmentNote(environmentDiff(laptop, laptop)), null);
});

test('a browser that cannot say its version still gives the platform', () => {
  const env = environmentOf({ version: () => { throw new Error('closed'); } });
  assert.equal(env.browser, null);
  assert.ok(env.platform);
});

test('a changed site time zone is not an edit', () => {
  // Velo: the same save, read before and after the time zone changed.
  assert.equal(editedSince('2026-08-16T07:23:49-07:00', '2026-08-16T16:23:49+02:00'), false);
  assert.equal(editedSince('2026-09-22T00:27:03-07:00', '2026-11-08T09:29:28+01:00'), true);
  assert.equal(editedSince(null, '2026-11-08T09:29:28+01:00'), false);
  assert.equal(editedSince('yesterday', 'today'), true, 'unparseable: compare as text');
});

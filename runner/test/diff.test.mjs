import { test } from 'node:test';
import assert from 'node:assert/strict';
import { diffGeometry, prettyColor } from '../lib/diff.mjs';

const node = (path, over = {}) => ({
  path, tag: 'div', x: 0, y: 0, w: 390, h: 100, text: '', hidden: false,
  color: 'rgb(0, 0, 0)', bg: 'rgba(0, 0, 0, 0)', font: '400 16px', clipped: 0, ...over,
});

const geometry = (nodes, over = {}) => ({ width: 390, height: 400, overflow: 0, nodes, ...over });

const base = () => geometry([
  node('0:section'),
  node('0:section/h1[1]', { tag: 'h1', x: 20, y: 20, w: 350, h: 60, text: 'Fitting, coaching and servicing', font: '800 48px' }),
  node('0:section/p[1]', { tag: 'p', x: 20, y: 90, w: 350, h: 24, text: 'Book a session' }),
]);

test('identical geometry has no changes', () => {
  const d = diffGeometry(base(), base());
  assert.equal(d.changed, false);
  assert.equal(d.summary, '');
});

test('sub-tolerance jitter is ignored', () => {
  const after = base();
  after.nodes[1].x += 1;
  after.nodes[1].w += 2;
  assert.equal(diffGeometry(base(), after).changed, false);
});

test('a cut-off heading is reported first and in words', () => {
  const after = base();
  after.nodes[1] = { ...after.nodes[1], w: 1200, clipped: 288, color: 'rgb(200, 0, 0)' };
  const d = diffGeometry(base(), after);
  assert.equal(d.changed, true);
  assert.equal(d.changes[0], 'h1 “Fitting, coaching and servici…”: width 350→1200px, now cut off by 288px, color #000000 → #c80000');
});

test('a moved container does not report every child as moved', () => {
  const after = base();
  for (const n of after.nodes) n.y += 40; // everything shifted down together
  after.height = 440;
  const d = diffGeometry(base(), after);
  // Only the section itself moved relative to its (absent) parent.
  assert.deepEqual(d.changes.filter((c) => c.includes('moved')), ['div: moved 0, +40px']);
});

test('removed subtrees are reported once, at the root', () => {
  const before = base();
  before.nodes.push(node('0:section/div[1]', { y: 130, h: 50 }), node('0:section/div[1]/a[1]', { tag: 'a', text: 'Book now', y: 140, h: 30 }));
  const d = diffGeometry(before, base());
  assert.deepEqual(d.changes, ['div is gone']);
});

test('new elements, text and style changes', () => {
  const after = base();
  after.nodes[2] = { ...after.nodes[2], text: 'Book a free session', font: '700 16px' };
  after.nodes.push(node('0:section/a[1]', { tag: 'a', text: 'Book now', y: 130, h: 40 }));
  const d = diffGeometry(base(), after);
  assert.ok(d.changes.includes('a “Book now” is new'));
  assert.ok(d.changes.some((c) => c.startsWith('p “Book a free session”: text “Book a session” → “Book a free session”, font 400 16px → 700 16px')));
});

test('block now wider than the viewport', () => {
  const d = diffGeometry(base(), geometry(base().nodes, { overflow: 410 }));
  assert.equal(d.changes[0], 'now 410px wider than the viewport');
});

test('only the top changes are spelled out', () => {
  const after = base();
  for (let i = 1; i <= 8; i++) after.nodes.push(node(`0:section/span[${i}]`, { tag: 'span', text: `s${i}` }));
  const d = diffGeometry(base(), after, { max: 3 });
  assert.equal(d.changes.length, 3);
  assert.match(d.summary, /\(\+5 more\)$/);
});

test('colours read as people write them', () => {
  assert.equal(prettyColor('rgba(0, 0, 0, 0)'), 'transparent');
  assert.equal(prettyColor('rgb(255, 0, 0)'), '#ff0000');
  assert.equal(prettyColor('rgba(255, 0, 0, 0.5)'), '#ff0000 at 50%');
  assert.equal(prettyColor('color(display-p3 1 0 0)'), 'color(display-p3 1 0 0)');

  const before = base();
  const after = base();
  after.nodes[1] = { ...after.nodes[1], bg: 'rgb(255, 0, 0)' };
  assert.equal(diffGeometry(before, after).changes[0], 'h1 “Fitting, coaching and servici…”: background transparent → #ff0000');
});

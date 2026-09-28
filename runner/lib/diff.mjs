// Compares two geometry snapshots of the same block (see collectLayout()) and
// describes what changed, in words a developer — or a coding agent — can act
// on: "h1 “Ride further…”: width 560→1200px, now cut off by 288px".
//
// Pure: no browser, no filesystem.

export const DEFAULT_TOLERANCE = 2;

/**
 * @param {object} before geometry from the snapshot
 * @param {object} after geometry from this run
 * @param {object} [opts]
 * @param {number} [opts.tolerance] pixels of movement to ignore
 * @param {number} [opts.max] how many changes to spell out
 * @returns {{ changed: boolean, count: number, changes: string[], summary: string }}
 */
export function diffGeometry(before, after, { tolerance = DEFAULT_TOLERANCE, max = 5 } = {}) {
  const found = [];
  const add = (score, message) => found.push({ score, message });
  const px = (a, b) => Math.abs(a - b) > tolerance;

  // The block as a whole.
  if (px(before.width, after.width) || px(before.height, after.height)) {
    add(Math.abs(before.width - after.width) + Math.abs(before.height - after.height),
      `block size ${before.width}×${before.height} → ${after.width}×${after.height}px`);
  }
  if ((before.overflow > tolerance) !== (after.overflow > tolerance) || px(before.overflow, after.overflow)) {
    add(10000, after.overflow > tolerance
      ? `now ${after.overflow}px wider than the viewport${before.overflow > tolerance ? ` (was ${before.overflow}px)` : ''}`
      : `no longer wider than the viewport (was ${before.overflow}px)`);
  }

  const beforeByPath = new Map(before.nodes.map((n) => [n.path, n]));
  const afterByPath = new Map(after.nodes.map((n) => [n.path, n]));

  // Elements that appeared or disappeared.
  const removed = before.nodes.filter((n) => !afterByPath.has(n.path) && !hasAncestorIn(n.path, beforeByPath, afterByPath));
  const added = after.nodes.filter((n) => !beforeByPath.has(n.path) && !hasAncestorIn(n.path, afterByPath, beforeByPath));
  for (const n of removed) add(5000 + n.w * n.h / 100, `${label(n)} is gone`);
  for (const n of added) add(5000 + n.w * n.h / 100, `${label(n)} is new`);

  // Elements present in both.
  for (const a of after.nodes) {
    const b = beforeByPath.get(a.path);
    if (!b) continue;
    const parts = [];
    let score = 0;

    // Position relative to the parent, so a moved container does not report
    // every child as moved too.
    const pb = parentOf(b.path, beforeByPath);
    const pa = parentOf(a.path, afterByPath);
    const bx = b.x - (pb ? pb.x : 0);
    const by = b.y - (pb ? pb.y : 0);
    const ax = a.x - (pa ? pa.x : 0);
    const ay = a.y - (pa ? pa.y : 0);
    if (px(bx, ax) || px(by, ay)) {
      parts.push(`moved ${signed(ax - bx)}, ${signed(ay - by)}px`);
      score += Math.abs(ax - bx) + Math.abs(ay - by);
    }
    if (px(b.w, a.w)) {
      parts.push(`width ${b.w}→${a.w}px`);
      score += Math.abs(a.w - b.w);
    }
    if (px(b.h, a.h)) {
      parts.push(`height ${b.h}→${a.h}px`);
      score += Math.abs(a.h - b.h);
    }
    if ((b.clipped > tolerance) !== (a.clipped > tolerance)) {
      parts.push(a.clipped > tolerance ? `now cut off by ${a.clipped}px` : 'no longer cut off');
      score += 10000;
    }
    if (b.hidden !== a.hidden) {
      parts.push(a.hidden ? 'now hidden' : 'now visible');
      score += 8000;
    }
    if (b.text !== a.text) {
      parts.push(`text “${short(b.text)}” → “${short(a.text)}”`);
      score += 7000;
    }
    if (b.color !== a.color) {
      parts.push(`color ${prettyColor(b.color)} → ${prettyColor(a.color)}`);
      score += 3000;
    }
    if (b.bg !== a.bg) {
      parts.push(`background ${prettyColor(b.bg)} → ${prettyColor(a.bg)}`);
      score += 3000;
    }
    if (b.font !== a.font) {
      parts.push(`font ${b.font} → ${a.font}`);
      score += 3000;
    }
    if (b.img !== a.img && a.img !== 'loading' && b.img !== 'loading') {
      parts.push(`image ${b.img} → ${a.img}`);
      score += 9000;
    }
    if (parts.length) add(score, `${label(a)}: ${parts.join(', ')}`);
  }

  found.sort((x, y) => y.score - x.score);
  const changes = found.slice(0, max).map((f) => f.message);
  const more = found.length - changes.length;
  const summary = changes.join('; ') + (more > 0 ? ` (+${more} more)` : '');

  return { changed: found.length > 0, count: found.length, changes, summary };
}

function parentOf(pathKey, byPath) {
  const i = pathKey.lastIndexOf('/');
  return i === -1 ? null : byPath.get(pathKey.slice(0, i)) ?? null;
}

// A removed subtree is reported once, at its root.
function hasAncestorIn(pathKey, own, other) {
  for (let i = pathKey.lastIndexOf('/'); i !== -1; i = pathKey.lastIndexOf('/', i - 1)) {
    const ancestor = pathKey.slice(0, i);
    if (own.has(ancestor) && !other.has(ancestor)) return true;
  }
  return false;
}

function label(n) {
  return n.text ? `${n.tag} “${short(n.text)}”` : n.tag;
}

function short(text, max = 30) {
  return text.length > max ? text.slice(0, max - 1) + '…' : text;
}

/**
 * Computed styles come back as rgb()/rgba(); people read hex.
 * rgba(0, 0, 0, 0) → transparent, rgb(255, 0, 0) → #ff0000,
 * rgba(255, 0, 0, 0.5) → #ff0000 at 50%. Anything else is left alone.
 */
export function prettyColor(value) {
  const m = /^rgba?\(\s*(\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?\s*\)$/.exec(String(value ?? '').trim());
  if (!m) return value;
  const alpha = m[4] === undefined ? 1 : Number(m[4]);
  if (alpha === 0) return 'transparent';
  const hex = '#' + [m[1], m[2], m[3]].map((n) => Number(n).toString(16).padStart(2, '0')).join('');
  return alpha < 1 ? `${hex} at ${Math.round(alpha * 100)}%` : hex;
}

function signed(v) {
  return v > 0 ? `+${v}` : `${v}`;
}

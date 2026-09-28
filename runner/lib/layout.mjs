// Runs inside the page (passed to page.evaluate), so it must be self-contained:
// no imports, no closures over module scope.
//
// Finds each expected block by its markers — attributes
// (data-cc-block="123") or comment pairs (<!--cc:start hero 123--> …
// <!--cc:end 123-->) — and measures it.

export function collectLayout(blockIds) {
  const doc = document.documentElement;
  const viewportWidth = doc.clientWidth;
  const scrollX = window.scrollX;
  const scrollY = window.scrollY;

  /** @type {Map<number, Element[]>} */
  const elementsByBlock = new Map();
  let markersPresent = false;

  // 1. Attribute markers.
  for (const el of document.querySelectorAll('[data-cc-block]')) {
    markersPresent = true;
    const id = Number(el.getAttribute('data-cc-block'));
    if (!elementsByBlock.has(id)) elementsByBlock.set(id, []);
    elementsByBlock.get(id).push(el);
  }

  // 2. Comment markers.
  const walker = document.createTreeWalker(document, NodeFilter.SHOW_COMMENT);
  const open = [];
  const pairs = [];
  for (let node = walker.nextNode(); node; node = walker.nextNode()) {
    const text = node.nodeValue.trim();
    let m = /^cc:start\s+(\S+)\s+(\d+)$/.exec(text);
    if (m) {
      markersPresent = true;
      open.push({ id: Number(m[2]), start: node });
      continue;
    }
    m = /^cc:end\s+(\d+)$/.exec(text);
    if (m) {
      const id = Number(m[1]);
      for (let i = open.length - 1; i >= 0; i--) {
        if (open[i].id === id) {
          pairs.push({ id, start: open[i].start, end: node });
          open.splice(i, 1);
          break;
        }
      }
    }
  }

  for (const { id, start, end } of pairs) {
    const elements = [];
    // Top-level elements strictly between the two comments.
    const range = document.createRange();
    range.setStartAfter(start);
    range.setEndBefore(end);
    const container = range.commonAncestorContainer;
    const all = container.nodeType === 1 ? container.querySelectorAll('*') : [];
    for (const el of all) {
      const afterStart = start.compareDocumentPosition(el) & Node.DOCUMENT_POSITION_FOLLOWING;
      const beforeEnd = end.compareDocumentPosition(el) & Node.DOCUMENT_POSITION_PRECEDING;
      const parentInside = elements.some((p) => p.contains(el));
      if (afterStart && beforeEnd && !parentInside && !el.contains(start)) {
        elements.push(el);
      }
    }
    if (!elementsByBlock.has(id)) elementsByBlock.set(id, []);
    elementsByBlock.get(id).push(...elements);
  }

  const regions = blockIds.map((blockId) => {
    const elements = (elementsByBlock.get(blockId) || []).filter((el) => {
      const style = getComputedStyle(el);
      return style.display !== 'none';
    });

    if (elements.length === 0) {
      return { blockId, found: elementsByBlock.has(blockId), width: 0, height: 0, overflow: 0, brokenImages: [], rect: null };
    }

    let left = Infinity;
    let top = Infinity;
    let right = -Infinity;
    let bottom = -Infinity;
    for (const el of elements) {
      const r = el.getBoundingClientRect();
      if (r.width === 0 && r.height === 0) continue;
      left = Math.min(left, r.left);
      top = Math.min(top, r.top);
      right = Math.max(right, r.right);
      bottom = Math.max(bottom, r.bottom);
    }

    const hasBox = Number.isFinite(left);
    const width = hasBox ? Math.round(right - left) : 0;
    const height = hasBox ? Math.round(bottom - top) : 0;

    // Overflow: how far the component (or anything inside it) sticks out of
    // the viewport horizontally. Children count: a fixed-width child of a
    // 100%-wide section is the classic mobile bug.
    let maxRight = hasBox ? right : 0;
    let minLeft = hasBox ? left : 0;
    for (const el of elements) {
      for (const child of [el, ...el.querySelectorAll('*')]) {
        const style = getComputedStyle(child);
        if (style.display === 'none' || style.position === 'fixed') continue;
        if (child.closest('[aria-hidden="true"]') && style.visibility === 'hidden') continue;
        const r = child.getBoundingClientRect();
        if (r.width === 0 || r.height === 0) continue;
        // Content clipped by an overflow:hidden ancestor inside the block is fine.
        let clipped = false;
        for (let p = child.parentElement; p && p !== el.parentElement; p = p.parentElement) {
          const ov = getComputedStyle(p).overflowX;
          if (ov === 'hidden' || ov === 'clip' || ov === 'auto' || ov === 'scroll') {
            const pr = p.getBoundingClientRect();
            if (pr.right <= viewportWidth + 1 && pr.left >= -1) {
              clipped = true;
              break;
            }
          }
        }
        if (clipped) continue;
        maxRight = Math.max(maxRight, r.right);
        minLeft = Math.min(minLeft, r.left);
      }
    }
    const overflow = Math.round(Math.max(0, maxRight - viewportWidth, -minLeft));

    const brokenImages = [];
    for (const el of elements) {
      for (const img of [el, ...el.querySelectorAll('img')]) {
        if (img.tagName !== 'IMG') continue;
        const src = img.currentSrc || img.getAttribute('src') || '';
        if (src && img.complete && img.naturalWidth === 0) brokenImages.push(src);
      }
    }

    return {
      blockId,
      found: true,
      width,
      height,
      overflow,
      brokenImages,
      // Document coordinates, for a clipped full-page screenshot. Widened to
      // include whatever sticks out, so the screenshot shows the problem.
      rect: hasBox ? { x: minLeft + scrollX, y: top + scrollY, width: maxRight - minLeft, height: bottom - top } : null,
    };
  });

  return {
    markersPresent,
    pageOverflow: Math.max(0, Math.round(doc.scrollWidth - viewportWidth)),
    pageWidth: Math.max(doc.scrollWidth, viewportWidth),
    pageHeight: Math.max(doc.scrollHeight, document.body ? document.body.scrollHeight : 0),
    regions,
  };
}

// Scrolls through the page so lazy-loaded content and images load before
// measuring, then returns to the top.
export async function scrollThrough() {
  const step = Math.max(200, window.innerHeight);
  const max = 40;
  for (let i = 0; i < max && window.scrollY + window.innerHeight < document.documentElement.scrollHeight; i++) {
    window.scrollBy(0, step);
    await new Promise((r) => setTimeout(r, 60));
  }
  window.scrollTo(0, 0);
  await new Promise((r) => setTimeout(r, 60));
}

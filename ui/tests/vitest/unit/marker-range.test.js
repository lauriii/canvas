// cspell:ignore aaaa bbbb cccc dddd
import { describe, expect, it } from 'vitest';

import {
  cloneMarkerRangeWithUuidMap,
  extractMarkerRangeHtml,
  findInsertionPoint,
  findMarkerRange,
  getMarkerRangeNodes,
  moveMarkerRange,
  removeMarkerRange,
  replaceMarkerRange,
} from '@/utils/markerRange';

const A = 'aaaa1111-0000-0000-0000-000000000001';
const B = 'bbbb2222-0000-0000-0000-000000000002';
const C = 'cccc3333-0000-0000-0000-000000000003';

const buildDocument = () => {
  const doc = document.implementation.createHTMLDocument('preview');
  doc.body.innerHTML = [
    '<div class="region">',
    '<!-- canvas-region-start-content -->',
    `<!-- canvas-start-${A} -->`,
    `<div data-canvas-uuid="${A}">A<!-- canvas-slot-start-${A}/main -->`,
    `<!-- canvas-start-${C} --><p>C in slot</p><!-- canvas-end-${C} -->`,
    '</div>',
    `<!-- canvas-end-${A} -->`,
    `<!-- canvas-start-${B} -->`,
    '<p>B</p>',
    `<!-- canvas-end-${B} -->`,
    '<!-- canvas-region-end-content -->',
    '</div>',
  ].join('');
  return doc;
};

describe('findMarkerRange / getMarkerRangeNodes', () => {
  it('locates a component range and its nodes inclusive of markers', () => {
    const doc = buildDocument();
    const range = findMarkerRange(doc, B);
    expect(range).not.toBeNull();
    const nodes = getMarkerRangeNodes(range);
    expect(nodes).toHaveLength(3);
    expect(nodes[1].textContent).toBe('B');
  });

  it('returns null for unknown uuids', () => {
    expect(findMarkerRange(buildDocument(), 'nope')).toBeNull();
  });
});

describe('extractMarkerRangeHtml', () => {
  it('extracts one component chunk including its markers', () => {
    const html = `<html><body><!-- canvas-start-${B} --><p>B</p><!-- canvas-end-${B} --></body></html>`;
    const chunk = extractMarkerRangeHtml(html, B);
    expect(chunk).toBe(
      `<!-- canvas-start-${B} --><p>B</p><!-- canvas-end-${B} -->`,
    );
  });

  it('returns null when markers are missing', () => {
    expect(extractMarkerRangeHtml('<p>nothing</p>', B)).toBeNull();
  });
});

describe('replaceMarkerRange', () => {
  it('swaps a subtree in place, including nested slot children', () => {
    const doc = buildDocument();
    const inserted = replaceMarkerRange(
      doc,
      A,
      `<!-- canvas-start-${A} --><section>new A</section><!-- canvas-end-${A} -->`,
    );
    expect(inserted).not.toBeNull();
    expect(doc.body.innerHTML).toContain('new A');
    expect(doc.body.innerHTML).not.toContain('C in slot');
    // Sibling B is untouched.
    expect(findMarkerRange(doc, B)).not.toBeNull();
  });
});

describe('removeMarkerRange', () => {
  it('removes the component and its markers', () => {
    const doc = buildDocument();
    expect(removeMarkerRange(doc, B)).toBe(true);
    expect(doc.body.innerHTML).not.toContain('>B<');
    expect(findMarkerRange(doc, B)).toBeNull();
  });
});

describe('moveMarkerRange / findInsertionPoint', () => {
  it('moves a component before a sibling', () => {
    const doc = buildDocument();
    const point = findInsertionPoint(doc, { regionId: 'content' }, A);
    expect(point).not.toBeNull();
    expect(moveMarkerRange(doc, B, point)).toBe(true);
    const html = doc.body.innerHTML;
    expect(html.indexOf(`canvas-start-${B}`)).toBeLessThan(
      html.indexOf(`canvas-start-${A}`),
    );
  });

  it('moves a component into a slot', () => {
    const doc = buildDocument();
    const point = findInsertionPoint(doc, { slotId: `${A}/main` }, null);
    expect(point).not.toBeNull();
    expect(moveMarkerRange(doc, B, point)).toBe(true);
    const slotParent = findMarkerRange(doc, C).start.parentElement;
    expect(slotParent.innerHTML).toContain(`canvas-start-${B}`);
  });

  it('appends at the end of the content region', () => {
    const doc = buildDocument();
    const point = findInsertionPoint(doc, { regionId: 'content' }, null);
    expect(point).not.toBeNull();
    expect(moveMarkerRange(doc, A, point)).toBe(true);
    const html = doc.body.innerHTML;
    expect(html.indexOf(`canvas-start-${B}`)).toBeLessThan(
      html.indexOf(`canvas-start-${A}`),
    );
  });
});

describe('cloneMarkerRangeWithUuidMap', () => {
  it('clones a subtree rewriting uuids in comments and attributes', () => {
    const doc = buildDocument();
    const newA = 'dddd4444-0000-0000-0000-000000000004';
    const newC = 'dddd4444-0000-0000-0000-000000000005';
    const range = findMarkerRange(doc, A);
    const clones = cloneMarkerRangeWithUuidMap(
      doc,
      A,
      { [A]: newA, [C]: newC },
      { parent: range.end.parentNode, before: range.end.nextSibling },
    );
    expect(clones).not.toBeNull();
    // Both the original and the rewritten clone exist.
    expect(findMarkerRange(doc, A)).not.toBeNull();
    const cloneRange = findMarkerRange(doc, newA);
    expect(cloneRange).not.toBeNull();
    expect(findMarkerRange(doc, newC)).not.toBeNull();
    const cloneElement = cloneRange.start.nextSibling;
    expect(cloneElement.getAttribute('data-canvas-uuid')).toBe(newA);
    // Slot comments inside the clone are rewritten too.
    expect(cloneElement.innerHTML).toContain(`canvas-slot-start-${newA}/main`);
  });
});

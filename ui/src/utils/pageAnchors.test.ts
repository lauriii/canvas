// cspell:ignore über Cber
import { describe, expect, it } from 'vitest';

import { collectPageAnchors, filterPageAnchors } from './pageAnchors';

const previewDocument = () => {
  const doc = document.implementation.createHTMLDocument();
  doc.body.innerHTML = `
    <div id="outside-components"></div>
    <section data-canvas-uuid="uuid-1" id="pricing">
      <h2 id="pricing-heading">Pricing</h2>
      <div data-canvas-uuid="uuid-2"><span id="faq"></span></div>
      <span id="canvas-internal"></span>
      <span id="pricing"></span>
    </section>
    <div data-canvas-uuid="uuid-3"><span id="unnamed"></span></div>
  `;
  return doc;
};

const names: Record<string, string> = {
  'uuid-1': 'Section',
  'uuid-2': 'Accordion',
};

describe('collectPageAnchors', () => {
  it('collects unique ids inside component markup', () => {
    expect(
      collectPageAnchors(previewDocument(), (uuid) => names[uuid] ?? ''),
    ).toEqual([
      { value: '#pricing', label: 'Section: #pricing' },
      { value: '#pricing-heading', label: 'Section: #pricing-heading' },
      { value: '#faq', label: 'Accordion: #faq' },
      { value: '#unnamed', label: '#unnamed' },
    ]);
  });

  it('percent-encodes ids that are not valid in a URI fragment', () => {
    const doc = document.implementation.createHTMLDocument();
    doc.body.innerHTML = `
      <div data-canvas-uuid="uuid-1">
        <span id="über-uns"></span><span id="heading[1]"></span>
        <span id="discount50%"></span>
      </div>
    `;
    expect(collectPageAnchors(doc, () => '')).toEqual([
      { value: '#%C3%BCber-uns', label: '#über-uns' },
      { value: '#heading%5B1%5D', label: '#heading[1]' },
      { value: '#discount50%25', label: '#discount50%' },
    ]);
  });

  it('returns nothing without a preview', () => {
    expect(collectPageAnchors(null, () => '')).toEqual([]);
  });
});

describe('filterPageAnchors', () => {
  const anchors = collectPageAnchors(
    previewDocument(),
    (uuid) => names[uuid] ?? '',
  );

  it.each([
    ['', 4],
    ['#', 4],
    ['#PRICING', 2],
    ['#accordion', 1],
    ['#nope', 0],
    ['pricing', 0],
    ['https://example.com/#pricing', 0],
  ])('filters %j to %i anchors', (term, count) => {
    expect(filterPageAnchors(anchors, term)).toHaveLength(count);
  });
});

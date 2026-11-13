import { describe, expect, it } from 'vitest';

import { NodeType } from '@/features/layout/layoutModelSlice';
import {
  insertPreviewComponent,
  movePreviewComponent,
  OPTIMISTIC_CSS_ATTRIBUTE,
  PREVIEW_HEAD_STYLES_ID,
} from '@/features/layout/preview/optimisticPreview';
import { previewEditCount } from '@/features/layout/preview/previewEdits';

import type {
  ComponentNode,
  RegionNode,
} from '@/features/layout/layoutModelSlice';

const component = (
  uuid: string,
  slots: ComponentNode['slots'] = [],
): ComponentNode => ({
  uuid,
  type: 'sdc.test@1',
  nodeType: NodeType.Component,
  slots,
});
const layout = (...components: ComponentNode[]): RegionNode[] => [
  {
    id: 'content',
    name: 'Content',
    nodeType: NodeType.Region,
    components,
  },
];
const withSlot = (...components: ComponentNode[]) =>
  component('c', [
    { id: 'c/body', name: 'body', nodeType: NodeType.Slot, components },
  ]);

// The preview markup: components a and b, and c with an empty slot.
function preview() {
  const doc = document.implementation.createHTMLDocument();
  doc.body.innerHTML =
    '<!-- canvas-region-start-content -->' +
    '<!-- canvas-start-a --><p>A</p><!-- canvas-end-a -->' +
    '<!-- canvas-start-b --><p>B</p><!-- canvas-end-b -->' +
    '<!-- canvas-start-c --><div>' +
    '<!-- canvas-slot-start-c/body --><div class="canvas--slot-empty-placeholder"></div><!-- canvas-slot-end-c/body -->' +
    '</div><!-- canvas-end-c -->' +
    '<!-- canvas-region-end-content -->';
  return doc;
}
const text = (doc: Document) =>
  Array.from(doc.body.querySelectorAll('p'), (p) => p.textContent).join();

describe('movePreviewComponent', () => {
  it('moves a component before its new next sibling', () => {
    const doc = preview();
    expect(
      movePreviewComponent(doc, layout(component('b'), component('a')), 'b'),
    ).toBe(true);
    expect(text(doc)).toBe('B,A');
  });

  it('moves a component to the end of a region', () => {
    const doc = preview();
    movePreviewComponent(
      doc,
      layout(component('b'), withSlot(), component('a')),
      'a',
    );
    expect(doc.body.lastElementChild!.textContent).toBe('A');
  });

  it('moves a component into an empty slot and hides its placeholder', () => {
    const doc = preview();
    movePreviewComponent(
      doc,
      layout(component('b'), withSlot(component('a'))),
      'a',
    );
    expect(doc.querySelector('div > p')!.textContent).toBe('A');
    expect(
      doc.querySelector<HTMLElement>('.canvas--slot-empty-placeholder')!.style
        .display,
    ).toBe('none');
  });

  it("doesn't touch a component that is shown in place already", () => {
    const doc = preview();
    doc.body.insertBefore(doc.createTextNode(' '), doc.body.childNodes[4]);
    const a = doc.querySelector('p')!;
    const edits = previewEditCount();
    expect(
      movePreviewComponent(
        doc,
        layout(component('a'), component('b'), withSlot()),
        'a',
      ),
    ).toBe(true);
    expect(doc.querySelector('p')).toBe(a);
    expect(previewEditCount()).toBe(edits);
  });

  it('leaves the preview alone when markers are missing', () => {
    const doc = preview();
    expect(
      movePreviewComponent(doc, layout(component('x'), component('a')), 'x'),
    ).toBe(false);
    expect(text(doc)).toBe('A,B');
  });
});

describe('insertPreviewComponent', () => {
  it('inserts the default markup with markers and moves CSS to the head', () => {
    const doc = preview();
    const edits = previewEditCount();
    const markup = {
      default_markup: '<p>N</p><script>window.ran = true</script>',
      css: '<link rel="stylesheet" href="n.css">',
    };
    expect(
      insertPreviewComponent(
        doc,
        layout(component('a'), component('n'), component('b')),
        'n',
        markup,
      ),
    ).toBe(true);
    expect(text(doc)).toBe('A,N,B');
    expect(
      doc.head.querySelector(`link[href="n.css"][${OPTIMISTIC_CSS_ATTRIBUTE}]`),
    ).not.toBeNull();
    expect(doc.body.innerHTML).toContain('<!-- canvas-start-n --><p>N</p>');
    expect(previewEditCount()).toBe(edits + 1);
    // The CSS of another instance is there already.
    insertPreviewComponent(
      doc,
      layout(component('a'), component('n'), component('m'), component('b')),
      'm',
      markup,
    );
    expect(doc.head.querySelectorAll('link[href="n.css"]')).toHaveLength(1);
  });

  it('gives default markup with a uuid of its own the uuid of the instance', () => {
    const doc = preview();
    insertPreviewComponent(
      doc,
      layout(component('a'), component('n'), component('b')),
      'n',
      {
        default_markup:
          '<!-- canvas-start-default --><canvas-island uid="default">' +
          '<!-- canvas-slot-start-default/body --><!-- canvas-slot-end-default/body -->' +
          '</canvas-island><!-- canvas-end-default -->',
        css: '',
      },
    );
    expect(doc.body.innerHTML).toContain(
      '<!-- canvas-end-a --><!-- canvas-start-n --><canvas-island uid="n">' +
        '<!-- canvas-slot-start-n/body --><!-- canvas-slot-end-n/body -->' +
        '</canvas-island><!-- canvas-end-n --><!-- canvas-start-b -->',
    );
  });

  it('adds CSS before the styles kept last', () => {
    const doc = preview();
    doc.head.innerHTML = `<style id="${PREVIEW_HEAD_STYLES_ID}"></style>`;
    insertPreviewComponent(
      doc,
      layout(component('a'), component('n'), component('b')),
      'n',
      { default_markup: '<p>N</p>', css: '<style>p{}</style>' },
    );
    expect(doc.head.lastElementChild!.id).toBe(PREVIEW_HEAD_STYLES_ID);
  });
});

import { afterEach, describe, expect, it } from 'vitest';

import {
  commitLivePreview,
  DROP_PREVIEW_ID,
  endLivePreview,
  showLivePreviewAt,
  startInsertPreview,
  startMovePreview,
} from '@/features/layout/preview/livePreview';

// Components a and b, and c with a slot holding d.
function preview() {
  const doc = document.implementation.createHTMLDocument();
  doc.body.innerHTML =
    '<!-- canvas-region-start-content -->' +
    '<!-- canvas-start-a --><p>A</p><!-- canvas-end-a -->' +
    '<!-- canvas-start-b --><p>B</p><!-- canvas-end-b -->' +
    '<!-- canvas-start-c --><div id="c">' +
    '<!-- canvas-slot-start-c/body --><!-- canvas-start-d --><p>D</p><!-- canvas-end-d --><!-- canvas-slot-end-c/body -->' +
    '</div><!-- canvas-end-c -->' +
    '<!-- canvas-region-end-content -->';
  return doc;
}
const text = (doc: Document) =>
  Array.from(doc.body.querySelectorAll('p'), (p) => p.textContent).join();
const region = { type: 'region' as const, id: 'content' };
const slot = { type: 'slot' as const, id: 'c/body' };

afterEach(() => endLivePreview());

describe('live preview of a moved component', () => {
  it('shows the component at each drop position and puts it back', () => {
    const doc = preview();
    startMovePreview(doc, 'a', region);
    showLivePreviewAt('b_bottom');
    expect(text(doc)).toBe('B,A,D');
    showLivePreviewAt('d_top');
    expect(text(doc)).toBe('B,A,D');
    expect(doc.querySelector('#c')!.textContent).toBe('AD');
    showLivePreviewAt(null);
    expect(text(doc)).toBe('A,B,D');
    showLivePreviewAt('b_bottom');
    endLivePreview();
    expect(text(doc)).toBe('A,B,D');
  });

  it('keeps a slot it leaves empty targetable with a placeholder', () => {
    const doc = preview();
    startMovePreview(doc, 'd', slot);
    showLivePreviewAt('a_top');
    expect(text(doc)).toBe('D,A,B');
    expect(
      doc.querySelector('#c .canvas--slot-empty-placeholder'),
    ).not.toBeNull();
    // Moving it back in removes the placeholder again.
    showLivePreviewAt(null);
    expect(doc.querySelector('.canvas--slot-empty-placeholder')).toBeNull();
  });

  it('hides the placeholder of an empty slot it is shown in', () => {
    const doc = preview();
    doc.querySelector('#c')!.innerHTML =
      '<!-- canvas-slot-start-c/body --><div class="canvas--slot-empty-placeholder"></div><!-- canvas-slot-end-c/body -->';
    startMovePreview(doc, 'a', region);
    showLivePreviewAt('c/body');
    const placeholder = doc.querySelector<HTMLElement>(
      '.canvas--slot-empty-placeholder',
    )!;
    expect(placeholder.style.display).toBe('none');
    expect(doc.querySelector('#c')!.textContent).toBe('A');
    showLivePreviewAt(null);
    expect(placeholder.style.display).toBe('');
  });

  it('shows the component where it was for positions outside the preview', () => {
    const doc = preview();
    startMovePreview(doc, 'a', region);
    showLivePreviewAt('b_bottom');
    showLivePreviewAt('layers-drop-zone');
    expect(text(doc)).toBe('A,B,D');
  });

  it('stays where it is shown when committed', () => {
    const doc = preview();
    startMovePreview(doc, 'a', region);
    showLivePreviewAt('b_bottom');
    expect(commitLivePreview()).toBe(doc);
    endLivePreview();
    expect(text(doc)).toBe('B,A,D');
  });
});

describe('live preview of a component from the library', () => {
  it('inserts the markup at drop positions and removes it when ended', () => {
    const doc = preview();
    startInsertPreview(doc, '<p>N</p><script>window.ran = true</script>', 'N');
    showLivePreviewAt(null);
    expect(text(doc)).toBe('A,B,D');
    showLivePreviewAt('a_bottom');
    expect(text(doc)).toBe('A,N,B,D');
    expect(doc.body.innerHTML).toContain(
      `<!--canvas-start-${DROP_PREVIEW_ID}--><p>N</p>`,
    );
    endLivePreview();
    expect(text(doc)).toBe('A,B,D');
  });

  it('becomes the added component when committed with its uuid', () => {
    const doc = preview();
    startInsertPreview(doc, '<p>N</p>', 'N');
    showLivePreviewAt('a_bottom');
    commitLivePreview('n');
    endLivePreview();
    expect(text(doc)).toBe('A,N,B,D');
    expect(doc.body.innerHTML).toContain(
      '<!--canvas-start-n--><p>N</p><!--canvas-end-n-->',
    );
  });

  it('shows a labeled box for components without markup', () => {
    const doc = preview();
    startInsertPreview(doc, undefined, 'Card');
    showLivePreviewAt('b_bottom');
    expect(doc.body.textContent).toContain('Card');
  });
});

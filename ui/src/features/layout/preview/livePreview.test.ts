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
    doc.querySelector('#c p')!.getBoundingClientRect = () =>
      new DOMRect(0, 0, 190, 48);
    startMovePreview(doc, 'd', slot);
    showLivePreviewAt('a_top');
    expect(text(doc)).toBe('D,A,B');
    // As large as the component was, so the slot stays where it was.
    const placeholder = doc.querySelector<HTMLElement>(
      '#c .canvas--slot-empty-placeholder',
    )!;
    expect(placeholder.style.width).toBe('190px');
    expect(placeholder.style.minHeight).toBe('48px');
    // Moving it back in removes the placeholder again.
    showLivePreviewAt(null);
    expect(doc.querySelector('.canvas--slot-empty-placeholder')).toBeNull();
  });

  it('scrolls the preview to keep under the pointer what the drop position is in', () => {
    const pane = document.createElement('div');
    pane.dataset.canvasEditorPane = 'true';
    const frame = pane.appendChild(document.createElement('iframe'));
    document.body.append(pane);
    Object.defineProperty(frame, 'offsetWidth', { value: 1000 });
    frame.getBoundingClientRect = () => new DOMRect(0, 0, 1000, 1000);
    const doc = frame.contentDocument!;
    doc.body.innerHTML = preview().body.innerHTML;
    // The pointer is followed where markup can be animated.
    (
      doc.defaultView as unknown as typeof globalThis
    ).Element.prototype.animate = () =>
      ({
        cancel() {},
        finish() {},
        reverse() {},
        effect: { getKeyframes: () => [{}], setKeyframes() {} },
        finished: Promise.resolve(),
      }) as unknown as Animation;
    // Components are 100px tall and stacked, c is 300px tall whatever it holds.
    const rect = (el: Element) => {
      let y = 0;
      for (const node of Array.from(doc.body.children)) {
        const index = Array.from(node.children).indexOf(el);
        if (node === el || index !== -1) {
          const height = el.id === 'c' ? 300 : 100;
          return new DOMRect(0, y + Math.max(index, 0) * 100, 300, height);
        }
        y += node.id === 'c' ? 300 : 100;
      }
      return new DOMRect();
    };
    doc.querySelectorAll('p, div').forEach((el) => {
      el.getBoundingClientRect = () => rect(el);
    });
    const pointTo = (clientX: number, clientY: number) =>
      window.dispatchEvent(new MouseEvent('pointermove', { clientX, clientY }));
    pane.scrollTop = 500;

    // Into c from above it, with the pointer in c: c would move up, away
    // from the pointer.
    startMovePreview(doc, 'a', region, { x: 50, y: 450 });
    showLivePreviewAt('d_bottom');
    expect(doc.querySelector('#c')!.textContent).toBe('DA');
    expect(pane.scrollTop).toBe(400);
    // Within c.
    pointTo(50, 250);
    showLivePreviewAt('d_top');
    expect(doc.querySelector('#c')!.textContent).toBe('AD');
    expect(pane.scrollTop).toBe(400);
    // After c from over d: d moves back up, but c is still under the
    // pointer. Keeping d in place would take c along with the pointer.
    showLivePreviewAt('c_bottom');
    expect(doc.querySelector('#c')!.textContent).toBe('D');
    expect(pane.scrollTop).toBe(400);
    endLivePreview();
    pane.remove();
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

  it("isn't committed when it isn't shown", () => {
    const doc = preview();
    startInsertPreview(doc, '<p>N</p>', 'N');
    showLivePreviewAt('a_bottom');
    // E.g. dropped on the layers panel.
    showLivePreviewAt(null);
    expect(commitLivePreview('n')).toBeNull();
    expect(text(doc)).toBe('A,B,D');
  });

  it('shows a labeled box for markup that renders nothing here', () => {
    const doc = preview();
    startInsertPreview(doc, undefined, 'Card');
    showLivePreviewAt('b_bottom');
    expect(doc.body.textContent).toContain('Card');
    // An element the document doesn't define, e.g. a code component island
    // on a page without code components.
    startInsertPreview(doc, '<x-island>N</x-island>', 'Island');
    showLivePreviewAt('b_bottom');
    expect(doc.body.textContent).toContain('Island');
    expect(doc.querySelector('x-island')).toBeNull();
  });
});

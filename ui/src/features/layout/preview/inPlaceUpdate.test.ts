import { afterEach, describe, expect, it, vi } from 'vitest';

import {
  applyInPlaceUpdate,
  findInPlaceUpdates,
  updateInPlace,
} from '@/features/layout/preview/inPlaceUpdate';
import { OPTIMISTIC_CSS_ATTRIBUTE } from '@/features/layout/preview/optimisticPreview';
import { notePreviewEdit } from '@/features/layout/preview/previewEdits';

const instance = (uuid: string, html: string) =>
  `<!-- canvas-start-${uuid} -->${html}<!-- canvas-end-${uuid} -->`;
const PLACEHOLDER = '<div class="canvas--slot-empty-placeholder"></div>';

const page = ({
  head = '<title>Page</title>',
  heading = 'Hello',
  card = 'Card',
  // The markup of the instances in the slot of the section.
  content,
}: { head?: string; heading?: string; card?: string; content?: string } = {}) =>
  `<!DOCTYPE html><html><head>${head}</head><body>` +
  '<!-- canvas-start-section -->' +
  '<section>' +
  '<!-- canvas-slot-start-section/content -->' +
  (content ??
    instance('heading', `<h1>${heading}</h1>`) +
      instance('card', `<p>${card}</p>`)) +
  '<!-- canvas-slot-end-section/content -->' +
  '</section>' +
  '<!-- canvas-end-section -->' +
  '</body></html>';

const HEADING = instance('heading', '<h1>Hello</h1>');
const CARD = instance('card', '<p>Card</p>');

// A page with only a region.
const regionPage = (content: string) =>
  '<!DOCTYPE html><html><head></head><body>' +
  `<!-- canvas-region-start-content -->${content}<!-- canvas-region-end-content -->` +
  '</body></html>';
const REGION_PLACEHOLDER =
  '<div class="canvas--region-empty-placeholder"></div>';

describe('findInPlaceUpdates', () => {
  it('finds the instance that changed', () => {
    expect(findInPlaceUpdates(page(), page({ card: 'New card' }))).toEqual([
      {
        componentUuid: 'card',
        html: '<p>New card</p>',
        previousHtml: '<p>Card</p>',
      },
    ]);
  });

  it('finds each instance that changed, not their parent', () => {
    expect(
      findInPlaceUpdates(page(), page({ heading: 'Hi', card: 'New card' })),
    ).toEqual([
      {
        componentUuid: 'heading',
        html: '<h1>Hi</h1>',
        previousHtml: '<h1>Hello</h1>',
      },
      {
        componentUuid: 'card',
        html: '<p>New card</p>',
        previousHtml: '<p>Card</p>',
      },
    ]);
  });

  it('finds a parent whose own markup changed, with the instances in it', () => {
    expect(
      findInPlaceUpdates(
        page(),
        page({ card: 'New card' }).replace('<section>', '<section id="s">'),
      ),
    ).toEqual([
      {
        componentUuid: 'section',
        html: expect.stringMatching(/^<section id="s">.*New card.*/),
        previousHtml: expect.stringMatching(/^<section>.*<p>Card<\/p>/),
      },
    ]);
  });

  it('returns null for changes outside any instance', () => {
    expect(
      findInPlaceUpdates(page(), page({ head: '<link rel="stylesheet">' })),
    ).toBeNull();
    expect(
      findInPlaceUpdates(page(), page({ head: '<style />', card: 'New' })),
    ).toBeNull();
  });

  it('finds nothing in identical documents', () => {
    expect(findInPlaceUpdates(page(), page())).toEqual([]);
  });

  it("doesn't compare where the instances are", () => {
    // Moved, removed, and the slot left empty.
    expect(
      findInPlaceUpdates(page(), page({ content: CARD + HEADING })),
    ).toEqual([]);
    expect(findInPlaceUpdates(page(), page({ content: HEADING }))).toEqual([]);
    expect(findInPlaceUpdates(page(), page({ content: PLACEHOLDER }))).toEqual(
      [],
    );
    expect(findInPlaceUpdates(page({ content: PLACEHOLDER }), page())).toEqual([
      { componentUuid: 'heading', html: '<h1>Hello</h1>' },
      { componentUuid: 'card', html: '<p>Card</p>' },
    ]);
    expect(
      findInPlaceUpdates(regionPage(CARD), regionPage(REGION_PLACEHOLDER)),
    ).toEqual([]);
  });

  it('ignores the order of module preload links', () => {
    const preload = (...modules: string[]) =>
      modules
        .map((module) => `<link rel="modulepreload" href="${module}" />\n`)
        .join('');
    expect(
      findInPlaceUpdates(
        page({ head: preload('a', 'b') }),
        page({ head: preload('b', 'a') }),
      ),
    ).toEqual([]);
    expect(
      findInPlaceUpdates(
        page({ head: preload('a', 'b') }),
        page({ head: preload('a', 'b', 'c') }),
      ),
    ).toBeNull();
    expect(
      findInPlaceUpdates(
        page({ head: preload('a', 'b') }),
        page({ head: preload('a', 'c') }),
      ),
    ).toBeNull();
  });

  it('returns null when the markers of instances are not nested', () => {
    expect(
      findInPlaceUpdates(
        page(),
        page().replace('<!-- canvas-end-card -->', ''),
      ),
    ).toBeNull();
  });

  it('returns null when a slot of a Canvas island becomes empty or gets content', () => {
    const island = (slot: string) =>
      page({
        content: instance(
          'island',
          '<canvas-island uid="island"><template data-astro-template="body">' +
            `<!-- canvas-slot-start-island/body -->${slot}<!-- canvas-slot-end-island/body -->` +
            '</template></canvas-island>',
        ),
      });
    expect(findInPlaceUpdates(island(CARD), island(PLACEHOLDER))).toBeNull();
    expect(findInPlaceUpdates(island(PLACEHOLDER), island(CARD))).toBeNull();
    // Not when its content changes.
    expect(
      findInPlaceUpdates(island(CARD), island(instance('card', '<p>New</p>'))),
    ).toEqual([
      {
        componentUuid: 'card',
        html: '<p>New</p>',
        previousHtml: '<p>Card</p>',
      },
    ]);
  });

  it('returns null when an instance is there twice', () => {
    const twice = (card: string) => page({ content: card + HEADING + card });
    expect(
      findInPlaceUpdates(twice(CARD), twice(instance('card', '<p>New</p>'))),
    ).toBeNull();
  });
});

describe('updateInPlace', () => {
  const preview = (html: string) => {
    const doc = document.implementation.createHTMLDocument();
    doc.documentElement.innerHTML = html;
    return doc;
  };

  it('leaves alone the instances a render has in the places they are shown in', () => {
    // The move is shown ahead of the server.
    const doc = preview(page({ content: CARD + HEADING }));
    const elements = [doc.querySelector('h1'), doc.querySelector('p')];

    expect(updateInPlace(doc, page(), page({ content: CARD + HEADING }))).toBe(
      true,
    );
    expect(doc.querySelector('h1')).toBe(elements[0]);
    expect(doc.querySelector('p')).toBe(elements[1]);
  });

  it('replaces only the instances that changed', () => {
    const doc = preview(page({ content: CARD + HEADING }));
    const card = doc.querySelector('p');

    expect(
      updateInPlace(
        doc,
        page(),
        page({ content: CARD + instance('heading', '<h1>Hi</h1>') }),
      ),
    ).toBe(true);
    expect(doc.querySelector('h1')!.textContent).toBe('Hi');
    expect(doc.querySelector('p')).toBe(card);
  });

  it("declines a render with instances in places they aren't shown in", () => {
    const doc = preview(page());
    const html = doc.documentElement.innerHTML;

    expect(updateInPlace(doc, page(), page({ content: CARD + HEADING }))).toBe(
      false,
    );
    expect(updateInPlace(doc, page(), page({ content: HEADING }))).toBe(false);
    expect(doc.documentElement.innerHTML).toBe(html);

    // A component that failed to render has no markers, but is moved, too.
    const FAILED = '<div data-component-uuid="failed">Failed to render</div>';
    const failedFirst = page({ content: FAILED + HEADING + CARD });
    const failedBetween = page({ content: HEADING + FAILED + CARD });
    const failedLast = page({ content: HEADING + CARD + FAILED });
    expect(
      updateInPlace(preview(failedBetween), failedBetween, failedLast),
    ).toBe(false);
    expect(updateInPlace(preview(failedFirst), failedFirst, failedLast)).toBe(
      false,
    );
    expect(updateInPlace(preview(failedLast), failedFirst, failedLast)).toBe(
      true,
    );
  });

  it('declines a render the preview was edited away from', () => {
    // A move shown ahead of the server, then undone in the layout only.
    const doc = preview(page({ content: CARD + HEADING }));
    notePreviewEdit(doc);
    expect(updateInPlace(doc, page(), page())).toBe(false);

    // Once a render is shown, the preview counts as not edited again: where
    // its instances are is then only checked for a render that moves them.
    expect(updateInPlace(doc, page(), page({ content: CARD + HEADING }))).toBe(
      true,
    );
    expect(updateInPlace(doc, page(), page())).toBe(true);
  });

  it('replaces the markup of an instance shown ahead of the server', () => {
    const added = instance('added', '<p>Default</p>');
    const doc = preview(page({ content: HEADING + added + CARD }));
    const card = doc.querySelector('section > p:last-of-type');

    expect(
      updateInPlace(
        doc,
        page(),
        page({ content: HEADING + instance('added', '<p>Added</p>') + CARD }),
      ),
    ).toBe(true);
    expect(doc.querySelector('section > p')!.textContent).toBe('Added');
    expect(doc.querySelector('section > p:last-of-type')).toBe(card);
  });

  it('keeps the island of an instance shown ahead of the server', () => {
    const island = (attributes: string, children = '<script></script>') =>
      `<canvas-island uid="added" component-url="/heading.js" ${attributes}>${children}</canvas-island>`;
    const next = (html: string) =>
      page({ content: HEADING + instance('added', html) + CARD });
    // It has rendered, and been mapped to its component.
    const doc = preview(next(island(`props='{"a":1}' data-canvas-uuid="x"`)));
    const live = doc.querySelector('canvas-island')!;
    live.innerHTML = '<h2>Hydrated</h2>';
    const setAttribute = vi.spyOn(live, 'setAttribute');

    expect(
      updateInPlace(doc, page(), next(island(`props='{"a":1}' ssr=""`))),
    ).toBe(true);
    expect(doc.querySelector('canvas-island')).toBe(live);
    expect(live.innerHTML).toBe('<h2>Hydrated</h2>');
    expect(setAttribute).not.toHaveBeenCalled();
    setAttribute.mockRestore();

    // Its props are those of the rendered one.
    expect(
      updateInPlace(doc, page(), next(island(`props='{"a":2}' ssr=""`))),
    ).toBe(true);
    expect(doc.querySelector('canvas-island')).toBe(live);
    expect(live.getAttribute('props')).toBe('{"a":2}');

    // Not when the rendered one differs otherwise.
    updateInPlace(
      doc,
      page(),
      next(island(`props='{"a":2}' ssr="" opts="{}"`)),
    );
    expect(doc.querySelector('canvas-island')).not.toBe(live);
  });

  it('shows the placeholder of a slot or region left empty, and removes it otherwise', () => {
    const placeholders = (doc: Document) =>
      Array.from(
        doc.querySelectorAll<HTMLElement>('[class$="-empty-placeholder"]'),
        (placeholder) => placeholder.style.display,
      );
    // The last component was moved out of the slot, ahead of the server.
    let doc = preview(page({ content: '' }));
    expect(updateInPlace(doc, page(), page({ content: PLACEHOLDER }))).toBe(
      true,
    );
    expect(placeholders(doc)).toEqual(['']);
    expect(doc.querySelector('section')!.lastChild!.nodeValue).toContain(
      'canvas-slot-end',
    );

    // A component was moved into the empty slot, which hid its placeholder.
    const HIDDEN = PLACEHOLDER.replace('>', ' style="display: none">');
    doc = preview(page({ content: HIDDEN + CARD }));
    expect(
      updateInPlace(
        doc,
        page({ content: PLACEHOLDER }),
        page({ content: CARD }),
      ),
    ).toBe(true);
    expect(placeholders(doc)).toEqual([]);

    // A component was added to the empty slot and removed again, before the
    // render of either arrived.
    doc = preview(page({ content: HIDDEN }));
    notePreviewEdit(doc);
    expect(
      updateInPlace(
        doc,
        page({ content: PLACEHOLDER }),
        page({ content: PLACEHOLDER }),
      ),
    ).toBe(true);
    expect(placeholders(doc)).toEqual(['']);

    // The same for a region.
    doc = preview(regionPage(''));
    expect(
      updateInPlace(doc, regionPage(CARD), regionPage(REGION_PLACEHOLDER)),
    ).toBe(true);
    expect(doc.body.lastElementChild!.className).toBe(
      'canvas--region-empty-placeholder',
    );
    doc = preview(regionPage(REGION_PLACEHOLDER + CARD));
    expect(
      updateInPlace(doc, regionPage(REGION_PLACEHOLDER), regionPage(CARD)),
    ).toBe(true);
    expect(placeholders(doc)).toEqual([]);

    // A component that failed to render has no markers, and no placeholder
    // beside it.
    const FAILED = '<div data-component-uuid="failed">Failed to render</div>';
    doc = preview(page({ content: FAILED }));
    notePreviewEdit(doc);
    expect(
      updateInPlace(doc, page({ content: FAILED }), page({ content: FAILED })),
    ).toBe(true);
    expect(placeholders(doc)).toEqual([]);
  });

  it('removes the CSS added ahead of the server', () => {
    const doc = preview(page());
    doc.head.innerHTML += `<link href="a.css" ${OPTIMISTIC_CSS_ATTRIBUTE}="">`;

    expect(updateInPlace(doc, page(), page({ card: 'New card' }))).toBe(true);
    expect(doc.head.querySelector('link')).toBeNull();
  });
});

describe('applyInPlaceUpdate', () => {
  afterEach(() => {
    delete (window as any).Drupal;
  });

  it('replaces the instance markup and re-attaches behaviors', () => {
    const Drupal = { attachBehaviors: vi.fn(), detachBehaviors: vi.fn() };
    (window as any).Drupal = Drupal;
    const doc = document.implementation.createHTMLDocument();
    doc.documentElement.innerHTML = page();
    // A document created this way has no window; give it this one.
    vi.spyOn(doc, 'defaultView', 'get').mockReturnValue(window as any);
    const oldCard = doc.querySelector('p');

    expect(
      applyInPlaceUpdate(doc, {
        componentUuid: 'card',
        html: '<p>New card</p>',
        previousHtml: '<p>Card</p>',
      }),
    ).toBe(true);

    expect(doc.querySelector('section')!.innerHTML).toBe(
      '<!-- canvas-slot-start-section/content -->' +
        '<!-- canvas-start-heading --><h1>Hello</h1><!-- canvas-end-heading -->' +
        '<!-- canvas-start-card --><p>New card</p><!-- canvas-end-card -->' +
        '<!-- canvas-slot-end-section/content -->',
    );
    expect(Drupal.detachBehaviors).toHaveBeenCalledWith(
      oldCard,
      null,
      'unload',
    );
    // The context contains the new root element, so selector-based behaviors
    // targeting it find it.
    expect(Drupal.attachBehaviors).toHaveBeenCalledWith(
      doc.querySelector('section'),
    );
  });

  it('updates the props of an island instead of replacing it', () => {
    const island = (props: string, child = '<script></script>') =>
      `<canvas-island uid="card" props='${props}'>${child}</canvas-island>`;
    const doc = document.implementation.createHTMLDocument();
    doc.documentElement.innerHTML = page({ card: '' }).replace(
      '<p></p>',
      island('{"a":1}'),
    );
    const live = doc.querySelector('canvas-island')!;
    live.innerHTML = '<p>Hydrated</p>';

    expect(
      applyInPlaceUpdate(doc, {
        componentUuid: 'card',
        html: island('{"a":2}'),
        previousHtml: island('{"a":1}'),
      }),
    ).toBe(true);
    expect(doc.querySelector('canvas-island')).toBe(live);
    expect(live.getAttribute('props')).toBe('{"a":2}');
    expect(live.innerHTML).toBe('<p>Hydrated</p>');

    // Props equal to the live ones, however encoded, are left alone: setting
    // them would re-render the island.
    live.setAttribute('props', '{"b":"\\"x\\"","a":"2"}');
    const setAttribute = vi.spyOn(live, 'setAttribute');
    applyInPlaceUpdate(doc, {
      componentUuid: 'card',
      html: island('{"a":"2","b":"\\"x\\""}'),
      previousHtml: island('{"a":2}'),
    });
    expect(setAttribute).not.toHaveBeenCalled();
    applyInPlaceUpdate(doc, {
      componentUuid: 'card',
      html: island('{"a":"3","b":"\\"x\\""}'),
      previousHtml: island('{"a":"2","b":"\\"x\\""}'),
    });
    expect(setAttribute).toHaveBeenCalledOnce();
    setAttribute.mockRestore();

    // Other changes, including text beside the island, replace the island.
    applyInPlaceUpdate(doc, {
      componentUuid: 'card',
      html: `${island('{"a":3}')}new text`,
      previousHtml: `${island('{"a":2}')}old text`,
    });
    const replaced = doc.querySelector('canvas-island');
    expect(replaced).not.toBe(live);
    applyInPlaceUpdate(doc, {
      componentUuid: 'card',
      html: `${island('{"a":3}', '<template></template>')}new text`,
      previousHtml: `${island('{"a":3}')}new text`,
    });
    expect(doc.querySelector('canvas-island')).not.toBe(replaced);
  });

  it('declines an unknown instance', () => {
    const doc = document.implementation.createHTMLDocument();
    doc.documentElement.innerHTML = page();
    expect(
      applyInPlaceUpdate(doc, {
        componentUuid: 'missing',
        html: '',
        previousHtml: '',
      }),
    ).toBe(false);
  });
});

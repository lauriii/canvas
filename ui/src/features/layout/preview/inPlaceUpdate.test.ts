import { afterEach, describe, expect, it, vi } from 'vitest';

import {
  applyInPlaceUpdate,
  findInPlaceUpdate,
} from '@/features/layout/preview/inPlaceUpdate';

const page = ({
  head = '<title>Page</title>',
  heading = 'Hello',
  card = 'Card',
}: { head?: string; heading?: string; card?: string } = {}) =>
  `<!DOCTYPE html><html><head>${head}</head><body>` +
  '<!-- canvas-start-section -->' +
  '<section>' +
  '<!-- canvas-slot-start-section/content -->' +
  `<!-- canvas-start-heading --><h1>${heading}</h1><!-- canvas-end-heading -->` +
  `<!-- canvas-start-card --><p>${card}</p><!-- canvas-end-card -->` +
  '<!-- canvas-slot-end-section/content -->' +
  '</section>' +
  '<!-- canvas-end-section -->' +
  '</body></html>';

describe('findInPlaceUpdate', () => {
  it('finds the innermost instance containing the change', () => {
    expect(findInPlaceUpdate(page(), page({ card: 'New card' }))).toEqual({
      componentUuid: 'card',
      html: '<p>New card</p>',
    });
  });

  it('finds the instance before the changed one', () => {
    expect(findInPlaceUpdate(page(), page({ heading: 'Hi' }))).toEqual({
      componentUuid: 'heading',
      html: '<h1>Hi</h1>',
    });
  });

  it('finds the parent when changes span sibling instances', () => {
    expect(
      findInPlaceUpdate(page(), page({ heading: 'Hi', card: 'New card' })),
    ).toEqual({
      componentUuid: 'section',
      html: expect.stringMatching(/^<section>.*<h1>Hi<\/h1>.*New card.*/),
    });
  });

  it('returns null for changes outside any instance', () => {
    expect(
      findInPlaceUpdate(page(), page({ head: '<link rel="stylesheet">' })),
    ).toBeNull();
    expect(
      findInPlaceUpdate(page(), page({ head: '<style />', card: 'New' })),
    ).toBeNull();
  });

  it('returns null for identical documents', () => {
    expect(findInPlaceUpdate(page(), page())).toBeNull();
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

  it('declines an unknown instance', () => {
    const doc = document.implementation.createHTMLDocument();
    doc.documentElement.innerHTML = page();
    expect(
      applyInPlaceUpdate(doc, { componentUuid: 'missing', html: '' }),
    ).toBe(false);
  });
});

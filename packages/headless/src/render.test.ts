import { afterEach, describe, expect, it, vi } from 'vitest';

import {
  componentElementFromName,
  componentNameFromElement,
  findCanvasComponent,
  getCanvasComponentRenderData,
  hasCanvasPreviewContentRegion,
  isCanvasComponentTreeDraft,
  isCanvasComponentTreeEmpty,
  isCanvasComponentTreeSlotEmpty,
  normalizeCanvasComponentTreeSlot,
  reportMissingCanvasComponent,
} from './render';

describe('headless component rendering helpers', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('normalizes single and multi-value slots', () => {
    const child = { element: 'js-card' };

    expect(normalizeCanvasComponentTreeSlot(undefined)).toEqual([]);
    expect(normalizeCanvasComponentTreeSlot('<p>Body</p>')).toEqual([
      '<p>Body</p>',
    ]);
    expect(normalizeCanvasComponentTreeSlot(child)).toEqual([child]);
    expect(normalizeCanvasComponentTreeSlot([child, 'Tail'])).toEqual([
      child,
      'Tail',
    ]);
    expect(
      normalizeCanvasComponentTreeSlot(['<p>Lead</p>', [child, ['Tail']]]),
    ).toEqual(['<p>Lead</p>', child, 'Tail']);
  });

  it('identifies slots without rendered children', () => {
    expect(isCanvasComponentTreeSlotEmpty(undefined)).toBe(true);
    expect(isCanvasComponentTreeSlotEmpty([])).toBe(true);
    expect(isCanvasComponentTreeSlotEmpty(['', '  '])).toBe(true);
    expect(isCanvasComponentTreeSlotEmpty('<p>Default body</p>')).toBe(true);
    expect(
      isCanvasComponentTreeSlotEmpty({
        element: 'drupal-markup',
        slots: { default: '<p>Default body</p>' },
      }),
    ).toBe(true);
    expect(
      isCanvasComponentTreeSlotEmpty([
        {
          element: 'drupal-markup',
          slots: { default: '<p>Leading markup</p>' },
        },
        { element: 'js-card' },
      ]),
    ).toBe(false);
    expect(isCanvasComponentTreeSlotEmpty({ element: 'js-card' })).toBe(false);
  });

  it('identifies top-level regions without rendered page content', () => {
    expect(isCanvasComponentTreeEmpty(null)).toBe(true);
    expect(isCanvasComponentTreeEmpty({ element: 'canvas-page' })).toBe(true);
    expect(
      isCanvasComponentTreeEmpty({
        element: 'canvas-page',
        slots: {
          content: {
            element: 'drupal-markup',
            slots: { default: '  ' },
          },
        },
      }),
    ).toBe(true);
    expect(
      isCanvasComponentTreeEmpty({
        element: 'canvas-page',
        slots: { content: { element: 'js-card' } },
      }),
    ).toBe(false);
  });

  it('identifies draft output on the root', () => {
    expect(isCanvasComponentTreeDraft(null)).toBe(false);
    expect(isCanvasComponentTreeDraft({ element: 'js-card' })).toBe(false);
    expect(
      isCanvasComponentTreeDraft({
        element: 'canvas-page',
        canvasDraftMode: true,
      }),
    ).toBe(true);
  });

  it('finds a routed content region inside page chrome', () => {
    expect(hasCanvasPreviewContentRegion(null)).toBe(false);
    expect(hasCanvasPreviewContentRegion({ element: 'js-card' })).toBe(false);
    expect(
      hasCanvasPreviewContentRegion({
        element: 'renderless-container',
        slots: {
          default: [
            { element: 'js-site-header' },
            { element: 'canvas-preview-content-region' },
            { element: 'js-site-footer' },
          ],
        },
      }),
    ).toBe(true);
  });

  it('maps external component element names to registry keys', () => {
    expect(componentNameFromElement('js-hello-card')).toBe('hello-card');
    expect(componentNameFromElement('canvas-page')).toBeNull();
    expect(componentNameFromElement('js-')).toBeNull();
    expect(componentElementFromName('HeroBanner')).toBe('js-herobanner');
    expect(componentElementFromName('hello_card')).toBe('js-hello-card');
    expect(
      findCanvasComponent(
        { HeroBanner: 'implementation' },
        { componentName: 'herobanner', element: 'js-herobanner' },
      ),
    ).toBe('implementation');
  });

  it('separates Canvas instance identity from component props', () => {
    expect(
      getCanvasComponentRenderData({
        element: 'js-hello-card',
        props: {
          title: 'Hello',
          count: 2,
          canvasUuid: '4af30fe1-3a42-4d69-9926-f38ce5cf3d90',
        },
      }),
    ).toEqual({
      element: 'js-hello-card',
      componentName: 'hello-card',
      componentUuid: '4af30fe1-3a42-4d69-9926-f38ce5cf3d90',
      props: { title: 'Hello', count: 2 },
    });
  });

  it('reports omitted component subtrees', () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});

    reportMissingCanvasComponent(
      {
        componentName: 'hello-card',
        componentUuid: '4af30fe1-3a42-4d69-9926-f38ce5cf3d90',
      },
      'tree:content:0',
    );

    expect(error).toHaveBeenCalledWith(
      '[canvas] Canvas component "hello-card" (instance "4af30fe1-3a42-4d69-9926-f38ce5cf3d90") is not registered; omitted subtree at "tree:content:0".',
    );
  });
});

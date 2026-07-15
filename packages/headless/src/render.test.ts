import { afterEach, describe, expect, it, vi } from 'vitest';

import {
  componentElementFromName,
  componentNameFromElement,
  findCanvasComponent,
  getCanvasComponentRenderData,
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

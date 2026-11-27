import { afterEach, describe, expect, it, vi } from 'vitest';
import { z } from 'zod';

import * as jsonRender from './index';
import { CanvasComponentTreeSchema, defineCatalog } from './index';
import {
  areCanvasComponentTreeChildrenEmpty,
  CANVAS_MARKER_ELEMENT,
  CANVAS_SLOT_PLACEHOLDER_ELEMENT,
  componentElementFromName,
  componentNameFromElement,
  findCanvasComponent,
  getCanvasComponentRenderData,
  getCanvasComponentTreeElementChildren,
  getCanvasComponentTreeElementSlots,
  getJsonRenderComponentData,
  hasCanvasPreviewContentRegion,
  isCanvasComponentTreeEmpty,
  isCanvasComponentTreeSlotEmpty,
  normalizeCanvasComponentTreeSlot,
  prepareCanvasJsonRender,
  reportMissingCanvasComponent,
} from './renderer';

import type { CanvasComponentTree } from './index';

const tree: CanvasComponentTree = {
  root: 'root',
  elements: {
    root: {
      type: 'renderless-container',
      props: {},
      children: ['markup', 'card-one'],
    },
    markup: {
      type: 'drupal-markup',
      props: { markup: '<p>Default body</p>' },
      children: [],
    },
    'card-one': {
      type: 'js.card',
      props: {},
      children: [],
    },
  },
};

describe('headless component rendering helpers', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('keeps document preparation on the renderer subpath', () => {
    expect(jsonRender).not.toHaveProperty('prepareCanvasJsonRender');
    expect(prepareCanvasJsonRender).toBeTypeOf('function');
  });

  it('normalizes json-render slots', () => {
    expect(normalizeCanvasComponentTreeSlot(undefined)).toEqual([]);
    expect(normalizeCanvasComponentTreeSlot(['markup', 'card-one'])).toEqual([
      'markup',
      'card-one',
    ]);
  });

  it('collects default and named-slot children in render order', () => {
    expect(
      getCanvasComponentTreeElementChildren({
        type: 'js.card',
        props: {},
        children: ['default-one'],
        slots: { media: ['media-one'], actions: ['action-one'] },
      }),
    ).toEqual(['default-one', 'media-one', 'action-one']);
  });

  it('preserves declared slots through synthetic preview elements', () => {
    const slotElement = {
      type: 'js.card',
      props: {},
      children: ['empty-default'],
      slots: { aside: ['empty-aside'] },
    } satisfies CanvasComponentTree['elements'][string];
    expect(getCanvasComponentTreeElementSlots(slotElement)).toEqual([
      ['default', ['empty-default']],
      ['aside', ['empty-aside']],
    ]);

    const prepared = prepareCanvasJsonRender({
      root: 'card-one',
      elements: {
        'card-one': slotElement,
        'empty-default': {
          type: CANVAS_SLOT_PLACEHOLDER_ELEMENT,
          props: {},
          children: [],
        },
        'empty-aside': {
          type: CANVAS_SLOT_PLACEHOLDER_ELEMENT,
          props: {},
          children: [],
        },
      },
    });
    expect(prepared.spec?.elements['card-one'].children).toEqual([
      'empty-default',
    ]);
    expect(prepared.spec?.elements['card-one'].slots).toEqual({
      aside: ['empty-aside'],
    });
  });

  it('identifies slots without rendered Canvas children', () => {
    expect(isCanvasComponentTreeSlotEmpty(tree, undefined)).toBe(true);
    expect(isCanvasComponentTreeSlotEmpty(tree, [])).toBe(true);
    expect(isCanvasComponentTreeSlotEmpty(tree, ['markup'])).toBe(true);
    expect(
      isCanvasComponentTreeSlotEmpty(
        {
          root: 'placeholder',
          elements: {
            placeholder: {
              type: CANVAS_SLOT_PLACEHOLDER_ELEMENT,
              props: {},
              children: [],
            },
          },
        },
        ['placeholder'],
      ),
    ).toBe(true);
    expect(isCanvasComponentTreeSlotEmpty(tree, ['markup', 'card-one'])).toBe(
      false,
    );
    expect(isCanvasComponentTreeSlotEmpty(tree, ['card-one'])).toBe(false);
    for (const [markup, empty] of [
      ['<!-- Example content --><p>Default body</p>', true],
      [
        '<!-- canvas-slot-start-card/content --><p>Default body</p><!-- canvas-slot-end-card/content -->',
        true,
      ],
      [
        '<!-- canvas-start-page-content --><div class="canvas--page-content-marker-placeholder">Page content</div><!-- canvas-end-page-content -->',
        false,
      ],
    ] as const) {
      const markedTree: CanvasComponentTree = {
        root: 'markup',
        elements: {
          markup: { type: 'drupal-markup', props: { markup }, children: [] },
          wrapper: { type: 'drupal-markup', props: {}, children: ['markup'] },
          marker: { type: CANVAS_MARKER_ELEMENT, props: {}, children: [] },
        },
      };
      expect(isCanvasComponentTreeSlotEmpty(markedTree, ['markup'])).toBe(
        empty,
      );
      expect(isCanvasComponentTreeSlotEmpty(markedTree, ['wrapper'])).toBe(
        empty,
      );
      expect(isCanvasComponentTreeSlotEmpty(markedTree, ['marker'])).toBe(
        false,
      );
    }
  });

  it('identifies top-level regions without rendered page content', () => {
    expect(isCanvasComponentTreeEmpty(null)).toBe(true);
    expect(isCanvasComponentTreeEmpty({ root: '', elements: {} })).toBe(true);
    for (const markup of ['', ' \n', '<h2>SDC page content</h2>']) {
      const markupTree: CanvasComponentTree = {
        root: 'root',
        elements: {
          root: {
            type: 'renderless-container',
            props: {},
            children: ['markup'],
          },
          markup: {
            type: 'drupal-markup',
            props: { markup },
            children: [],
          },
        },
      };
      const empty = markup.trim() === '';
      expect(isCanvasComponentTreeEmpty(markupTree)).toBe(empty);
      expect(areCanvasComponentTreeChildrenEmpty(markupTree, ['markup'])).toBe(
        empty,
      );
      expect(
        prepareCanvasJsonRender(markupTree, { preview: true }).emptyRegion,
      ).toBe(empty);
    }
    expect(isCanvasComponentTreeEmpty(tree)).toBe(false);

    for (const type of ['js.card', 'CustomCard', 'sdc.article-card']) {
      const componentTree: CanvasComponentTree = {
        root: 'card',
        elements: { card: { type, props: {}, children: [] } },
      };
      expect(isCanvasComponentTreeEmpty(componentTree)).toBe(false);
      expect(areCanvasComponentTreeChildrenEmpty(componentTree, ['card'])).toBe(
        false,
      );
      expect(
        prepareCanvasJsonRender(componentTree, { preview: true }).emptyRegion,
      ).toBe(false);

      for (const containerType of [
        'renderless-container',
        'canvas-preview-content-region',
      ]) {
        const regionTree: CanvasComponentTree = {
          root: 'region',
          elements: {
            ...componentTree.elements,
            region: {
              type: containerType,
              props: {},
              children: ['card'],
            },
          },
        };
        expect(isCanvasComponentTreeEmpty(regionTree)).toBe(false);
        regionTree.elements.card = {
          type: CANVAS_SLOT_PLACEHOLDER_ELEMENT,
          props: {},
          children: [],
        };
        expect(isCanvasComponentTreeEmpty(regionTree)).toBe(true);
      }
    }
  });

  it('recognizes marker elements as Canvas component instances', () => {
    const marker = {
      type: CANVAS_MARKER_ELEMENT,
      props: { markerId: 'future_marker' },
      children: [],
    } satisfies CanvasComponentTree['elements'][string];
    expect(
      isCanvasComponentTreeEmpty({
        root: 'marker-one',
        elements: { 'marker-one': marker },
      }),
    ).toBe(false);
  });

  it('finds a routed content region inside page chrome', () => {
    expect(hasCanvasPreviewContentRegion(null)).toBe(false);
    expect(hasCanvasPreviewContentRegion(tree)).toBe(false);
    expect(
      hasCanvasPreviewContentRegion({
        root: 'root',
        elements: {
          root: {
            type: 'renderless-container',
            props: {},
            children: ['header', 'content', 'footer'],
          },
          header: { type: 'js.site-header', props: {}, children: [] },
          content: {
            type: 'canvas-preview-content-region',
            props: {},
            children: [],
          },
          footer: { type: 'js.site-footer', props: {}, children: [] },
        },
      }),
    ).toBe(true);
  });

  it('maps Canvas component IDs to exact machine names', () => {
    expect(componentNameFromElement('js.hello_card')).toBe('hello_card');
    expect(componentNameFromElement('js.HeroBanner')).toBe('HeroBanner');
    expect(componentNameFromElement('js.hello-card')).toBe('hello-card');
    expect(componentNameFromElement('js-hello-card')).toBeNull();
    expect(componentNameFromElement('canvas-page')).toBeNull();
    expect(componentNameFromElement('js.')).toBeNull();
    expect(componentElementFromName('HeroBanner')).toBe('js.HeroBanner');
    expect(componentElementFromName('hello_card')).toBe('js.hello_card');
    expect(
      findCanvasComponent(
        { HeroBanner: 'implementation' },
        { componentName: 'herobanner', element: 'js.herobanner' },
      ),
    ).toBeUndefined();
    const components = {
      HeroBanner: 'camelCase implementation',
      hello_card: 'underscore implementation',
      'hello-card': 'hyphen implementation',
    };
    for (const [name, implementation] of Object.entries(components)) {
      expect(
        findCanvasComponent(components, {
          componentName: name,
          element: `js.${name}`,
        }),
      ).toBe(implementation);
    }
  });

  it.each(['js.hello_card', 'js.HeroBanner'])(
    'separates Canvas instance identity from component props for %s',
    (type) => {
      expect(
        getCanvasComponentRenderData(
          {
            type,
            props: {
              title: 'Hello',
              count: 2,
            },
            children: [],
          },
          '4af30fe1-3a42-4d69-9926-f38ce5cf3d90',
        ),
      ).toEqual({
        element: type,
        componentName: type.slice(3),
        componentUuid: '4af30fe1-3a42-4d69-9926-f38ce5cf3d90',
        props: { title: 'Hello', count: 2 },
      });
    },
  );

  it('accepts component types registered by their exact json-render key', () => {
    for (const type of ['js.card', 'sdc.article-card']) {
      const components = {
        [type]: 'exact implementation',
        card: 'machine implementation',
      };
      const data = getJsonRenderComponentData(
        { type, props: { title: 'Article' }, children: [] },
        components,
        'component-one',
      );
      expect(data).not.toBeNull();
      expect(data?.componentUuid).toBe(
        type === 'js.card' ? 'component-one' : undefined,
      );
      expect(findCanvasComponent(components, data!)).toBe(
        'exact implementation',
      );
    }
    expect(
      getJsonRenderComponentData(
        {
          type: 'sdc.article-card',
          props: { title: 'Article' },
          children: [],
        },
        { 'sdc.article-card': 'implementation' },
      ),
    ).toEqual({
      element: 'sdc.article-card',
      componentName: 'sdc.article-card',
      props: { title: 'Article' },
    });
  });

  it('reports omitted component subtrees', () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});

    reportMissingCanvasComponent(
      {
        componentName: 'hello-card',
        componentUuid: '4af30fe1-3a42-4d69-9926-f38ce5cf3d90',
      },
      'elements.card',
    );

    expect(error).toHaveBeenCalledWith(
      '[canvas] Canvas component "hello-card" (instance "4af30fe1-3a42-4d69-9926-f38ce5cf3d90") is not registered; omitted subtree at "elements.card".',
    );
  });

  it('preserves UUID component keys and element references', () => {
    const prepared = prepareCanvasJsonRender(
      {
        ...tree,
        elements: {
          ...tree.elements,
          root: {
            ...tree.elements.root,
            slots: { aside: ['card-one'] },
          },
        },
      },
      { preview: true },
    );

    expect(prepared.spec).toMatchObject({
      root: 'root',
      elements: {
        root: {
          children: ['markup', 'card-one'],
          slots: { aside: ['card-one'] },
        },
        'card-one': {
          type: 'js.card',
          props: {},
        },
      },
    });
    expect(prepared.preview).toBe(true);
    expect(prepared.needsOuterRegion).toBe(true);
    expect(prepared.emptyRegion).toBe(false);
  });

  it('preserves literal Canvas props for renderer adapters', () => {
    const rawElement: CanvasComponentTree['elements'][string] = {
      type: 'js.card',
      props: {
        businessData: { $state: '/must-remain-literal' },
      },
      children: [],
    };
    const prepared = prepareCanvasJsonRender({
      root: 'card-one',
      elements: { 'card-one': rawElement },
    });

    expect(prepared.spec?.elements['card-one'].props).toEqual({
      businessData: { $state: '/must-remain-literal' },
    });
    expect(prepared.rootRenderKey).toBe('card-one');

    const nonCanvasElement: CanvasComponentTree['elements'][string] = {
      type: 'CustomCard',
      props: { businessData: { $state: '/still-literal' } },
      children: [],
    };
    const nonCanvasPrepared = prepareCanvasJsonRender({
      root: 'generated-key',
      elements: { 'generated-key': nonCanvasElement },
    });
    const renderedElement = nonCanvasPrepared.spec?.elements['generated-key'];
    expect(renderedElement).toBeDefined();
    expect(renderedElement?.props).toEqual({
      businessData: { $state: '/still-literal' },
    });
  });

  it('does not request editor regions for live output', () => {
    const prepared = prepareCanvasJsonRender(tree);

    expect(prepared.preview).toBe(false);
    expect(prepared.needsOuterRegion).toBe(false);
    expect(prepared.emptyRegion).toBe(false);
    expect(prepared.spec).not.toHaveProperty('canvasDraftMode');
  });

  it('validates document structure and references at runtime', () => {
    expect(CanvasComponentTreeSchema.parse(tree)).toEqual(tree);
    expect(
      CanvasComponentTreeSchema.safeParse({ root: '', elements: {} }).success,
    ).toBe(true);

    const invalidDocument = CanvasComponentTreeSchema.safeParse({
      root: 'missing-root',
      elements: {
        card: {
          type: 'js.card',
          props: {},
          children: ['missing-child'],
          slots: { aside: ['card'] },
        },
      },
    });

    expect(invalidDocument.success).toBe(false);
    if (!invalidDocument.success) {
      expect(invalidDocument.error.issues).toEqual(
        expect.arrayContaining([
          expect.objectContaining({
            message: 'Root element "missing-root" does not exist.',
            path: ['root'],
          }),
          expect.objectContaining({
            message: 'Referenced element "missing-child" does not exist.',
            path: ['elements', 'card', 'children', 0],
          }),
          expect.objectContaining({
            message: 'Element reference to "card" creates a cycle.',
            path: ['elements', 'card', 'slots', 'aside', 0],
          }),
        ]),
      );
    }
  });

  it('validates a document before preparing it for a renderer', () => {
    expect(() =>
      prepareCanvasJsonRender(
        {
          root: 'root',
          elements: {
            root: {
              type: 'renderless-container',
              props: {},
              children: ['missing'],
            },
          },
        },
        { preview: true },
      ),
    ).toThrow(z.ZodError);

    expect(() =>
      prepareCanvasJsonRender(
        {
          root: 'root',
          elements: {
            root: {
              type: 'renderless-container',
              props: {},
              children: ['child'],
            },
            child: {
              type: 'renderless-container',
              props: {},
              children: ['root'],
            },
          },
        },
        { preview: true },
      ),
    ).toThrow(z.ZodError);
  });

  it('ignores unknown element keys, so newer Canvas keys do not break parsing', () => {
    expect(
      CanvasComponentTreeSchema.safeParse({
        root: 'card-one',
        elements: {
          'card-one': {
            type: 'js.card',
            props: {},
            children: [],
            canvasSlots: ['aside'],
          },
        },
      }).success,
    ).toBe(true);
  });

  it('validates the style keys when present', () => {
    const parse = (element: Record<string, unknown>) =>
      CanvasComponentTreeSchema.safeParse({
        root: 'hero',
        elements: {
          hero: { type: 'js.hero', props: {}, children: [], ...element },
        },
      }).success;
    expect(
      parse({
        styles: { tone: 'dark', spacing: { base: 'large', md: 'small' } },
        attributes: { class: 'p-8', style: { '--gap': '1rem' } },
      }),
    ).toBe(true);
    expect(parse({ styles: { tone: 1 } })).toBe(false);
    expect(parse({ attributes: { class: 'p-8', onclick: 'x' } })).toBe(false);
  });

  it('defines catalogs with framework structural and application types', () => {
    const catalog = defineCatalog({
      'js.card': {
        props: z.strictObject({ title: z.string() }),
        slots: ['default', 'aside'],
        description: 'Displays a card.',
      },
    });

    expect(catalog.componentNames).toEqual(
      expect.arrayContaining([
        'js.card',
        'renderless-container',
        'drupal-markup',
        'canvas-preview-content-region',
        CANVAS_MARKER_ELEMENT,
        CANVAS_SLOT_PLACEHOLDER_ELEMENT,
      ]),
    );
    expect(
      catalog.validate({
        root: 'card-one',
        elements: {
          'card-one': {
            type: 'js.card',
            props: { title: 'Hello' },
            children: ['empty-slot'],
          },
          'empty-slot': {
            type: CANVAS_SLOT_PLACEHOLDER_ELEMENT,
            props: {},
            children: [],
          },
        },
      }).success,
    ).toBe(true);

    expect(
      catalog.validate({
        root: 'future-marker-one',
        elements: {
          'future-marker-one': {
            type: CANVAS_MARKER_ELEMENT,
            props: {
              markerId: 'future_marker',
            },
            children: [],
          },
        },
      }).success,
    ).toBe(true);
    expect(
      catalog.validate({
        root: 'future-marker-one',
        elements: {
          'future-marker-one': {
            type: CANVAS_MARKER_ELEMENT,
            props: {},
            children: [],
          },
        },
      }).success,
    ).toBe(false);

    expect(
      catalog.validate({
        root: 'card-one',
        elements: {
          'card-one': {
            type: 'js.card',
            props: { title: 42 },
            children: [],
          },
        },
      }).success,
    ).toBe(false);
    expect(
      catalog.validate({
        root: 'card-one',
        elements: {
          'card-one': {
            type: 'unknown-component',
            props: {},
            children: [],
          },
        },
      }).success,
    ).toBe(false);
    expect(
      catalog.validate({
        root: 'card-one',
        elements: {
          'card-one': {
            type: 'js.card',
            props: { title: 'Missing child' },
            children: ['missing'],
          },
        },
      }).success,
    ).toBe(false);
  });
});

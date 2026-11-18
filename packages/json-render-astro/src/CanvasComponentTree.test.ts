import { experimental_AstroContainer as AstroContainer } from 'astro/container';
import { render as renderTemplate } from 'astro/runtime/server/index.js';
import { describe, expect, it, vi } from 'vitest';
import {
  CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS,
  CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS,
  formatCanvasCommentMarker,
} from '@drupal-canvas/json-render/renderer';

import CanvasComponentTree from './CanvasComponentTree.astro';

import type {
  CanvasComponentTreeElement,
  CanvasComponentTree as CanvasComponentTreeSpec,
} from '@drupal-canvas/json-render';
import type { AstroComponentFactory } from 'astro/runtime/server/index.js';

const CanvasComponentFixture: AstroComponentFactory = (_result, props) =>
  renderTemplate`<span>${props.label}</span>`;
CanvasComponentFixture.isAstroComponentFactory = true;

const LiteralPropsFixture: AstroComponentFactory = (_result, props) =>
  renderTemplate`<span>${JSON.stringify(props.payload)}</span>`;
LiteralPropsFixture.isAstroComponentFactory = true;

const SlottedComponentFixture: AstroComponentFactory = (result, props, slots) =>
  renderTemplate`<article><h2>${props.label}</h2><main>${slots.default?.(
    result,
  )}</main><aside>${slots.sidebar?.(result)}</aside><footer>${slots.empty?.(
    result,
  )}</footer></article>`;
SlottedComponentFixture.isAstroComponentFactory = true;

const components = {
  'site-header': CanvasComponentFixture,
  article: CanvasComponentFixture,
  'sdc.article-card': CanvasComponentFixture,
  'site-footer': CanvasComponentFixture,
  layout: SlottedComponentFixture,
  literal: LiteralPropsFixture,
};

function component(element: string, label: string): CanvasComponentTreeElement {
  return {
    type: element,
    props: { label },
    children: [],
  };
}

function tree(
  children: Array<
    CanvasComponentTreeElement | [string, CanvasComponentTreeElement]
  >,
): CanvasComponentTreeSpec {
  const childEntries = children.map((child, index) =>
    Array.isArray(child) ? child : ([`el-${index + 1}`, child] as const),
  );
  return {
    root: 'el-0',
    elements: {
      'el-0': {
        type: 'renderless-container',
        props: {},
        children: childEntries.map(([key]) => key),
      },
      ...Object.fromEntries(childEntries),
    },
  };
}

describe('CanvasComponentTree', () => {
  it('renders empty published content', async () => {
    const container = await AstroContainer.create();

    await expect(
      container.renderToString(CanvasComponentTree, {
        props: { tree: null, components: {} },
      }),
    ).resolves.toBe('');
  });

  it('renders the standard empty-region placeholder in preview mode', async () => {
    const container = await AstroContainer.create();
    const html = await container.renderToString(CanvasComponentTree, {
      props: {
        tree: { root: '', elements: {} },
        components: {},
        preview: true,
      },
    });

    expect(html).toContain(
      `<!-- ${formatCanvasCommentMarker({ type: 'region', position: 'start', id: 'content' })} -->`,
    );
    expect(html).toContain(`class="${CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS}"`);
    expect(html).toContain(
      `<!-- ${formatCanvasCommentMarker({ type: 'region', position: 'end', id: 'content' })} -->`,
    );
  });

  it('renders an empty page content region between page variant chrome', async () => {
    const container = await AstroContainer.create();
    const html = await container.renderToString(CanvasComponentTree, {
      props: {
        tree: tree([
          ['header-one', component('js.site-header', 'Header')],
          {
            type: 'canvas-preview-content-region',
            props: {},
            children: [],
          },
          ['footer-one', component('js.site-footer', 'Footer')],
        ]),
        components,
        preview: true,
      },
    });

    const regionStart = html.indexOf('canvas-region-start-content');
    const placeholder = html.indexOf(CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS);
    const regionEnd = html.indexOf('canvas-region-end-content');
    expect(html.indexOf('Header</span>')).toBeLessThan(regionStart);
    expect(regionStart).toBeLessThan(placeholder);
    expect(placeholder).toBeLessThan(regionEnd);
    expect(regionEnd).toBeLessThan(html.indexOf('<span>Footer'));
    expect(html.match(/canvas-region-start-content/g)).toHaveLength(1);
    expect(html.match(/canvas-region-end-content/g)).toHaveLength(1);
  });

  it('renders a marker as a Canvas component', async () => {
    const container = await AstroContainer.create();
    const html = await container.renderToString(CanvasComponentTree, {
      props: {
        tree: {
          root: 'page-content-one',
          elements: {
            'page-content-one': {
              type: 'canvas-marker',
              props: { markerId: 'page_content' },
              children: ['markup'],
            },
            markup: {
              type: 'drupal-markup',
              props: {
                markup:
                  '<div class="canvas--page-content-marker-placeholder">Page content</div>',
              },
              children: [],
            },
          },
        },
        components: {},
        preview: true,
      },
    });

    expect(html).toContain('canvas--page-content-marker-placeholder');
    expect(html).toContain('canvas-start-page-content-one');
    expect(html).toContain('canvas-end-page-content-one');
  });

  it('renders children of a synthetic multi-root wrapper in order', async () => {
    const container = await AstroContainer.create();
    const html = await container.renderToString(CanvasComponentTree, {
      props: {
        tree: tree([
          ['header-one', component('js.site-header', 'Header')],
          ['article-one', component('js.article', 'Content')],
          ['footer-one', component('js.site-footer', 'Footer')],
        ]),
        components,
      },
    });

    expect(html).toBe(
      '<span>Header</span><span>Content</span><span>Footer</span>',
    );
  });

  it('renders default and named slots from the json-render document', async () => {
    const container = await AstroContainer.create();
    const html = await container.renderToString(CanvasComponentTree, {
      props: {
        tree: {
          root: 'layout-one',
          elements: {
            'layout-one': {
              type: 'js.layout',
              props: { label: 'Page' },
              children: ['main-one'],
              slots: { sidebar: ['side-one'] },
            },
            'main-one': component('js.article', 'Main'),
            'side-one': component('js.article', 'Side'),
          },
        },
        components,
      },
    });

    expect(html).toBe(
      '<article><h2>Page</h2><main><span>Main</span></main><aside><span>Side</span></aside><footer></footer></article>',
    );
  });

  it('renders component types registered by their exact catalog key', async () => {
    const container = await AstroContainer.create();
    const html = await container.renderToString(CanvasComponentTree, {
      props: {
        tree: tree([
          {
            type: 'sdc.article-card',
            props: { label: 'Article' },
            children: [],
          },
        ]),
        components,
      },
    });

    expect(html).toBe('<span>Article</span>');
  });

  it('passes expression-shaped props through unchanged', async () => {
    const container = await AstroContainer.create();
    const html = await container.renderToString(CanvasComponentTree, {
      props: {
        tree: {
          root: 'literal-one',
          elements: {
            'literal-one': {
              type: 'js.literal',
              props: { payload: { $state: '/application-data' } },
              children: [],
            },
          },
        },
        components,
      },
    });

    expect(html).toContain('$state');
    expect(html).toContain('/application-data');
  });

  it('adds component and slot markers only to preview output', async () => {
    const container = await AstroContainer.create();
    const content: CanvasComponentTreeSpec = {
      root: 'layout-one',
      elements: {
        'layout-one': {
          type: 'js.layout',
          props: { label: 'Page' },
          children: ['main-one'],
          slots: { sidebar: ['side-one'], empty: ['default'] },
        },
        'main-one': component('js.article', 'Main'),
        'side-one': component('js.article', 'Side'),
        default: {
          type: 'drupal-markup',
          props: { markup: '<p>Default</p>' },
          children: [],
        },
      },
    };

    const published = await container.renderToString(CanvasComponentTree, {
      props: { tree: content, components },
    });
    const draft = await container.renderToString(CanvasComponentTree, {
      props: {
        tree: content,
        components,
        preview: true,
      },
    });

    expect(published).toContain('<p>Default</p>');
    expect(published).not.toContain('canvas-start-layout-one');
    expect(published).not.toContain('canvas-slot-start');
    expect(published).not.toContain(CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS);

    expect(draft).toContain('canvas-start-layout-one');
    expect(draft).toContain('canvas-slot-start-layout-one/default');
    expect(draft).toContain('canvas-slot-start-layout-one/sidebar');
    expect(draft).toContain('canvas-slot-start-layout-one/empty');
    expect(draft).toContain(CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS);
    expect(draft).not.toContain('<p>Default</p>');
  });

  it('renders markers for slots declared by structural placeholders', async () => {
    const container = await AstroContainer.create();
    const html = await container.renderToString(CanvasComponentTree, {
      props: {
        tree: {
          root: 'layout-one',
          elements: {
            'layout-one': {
              type: 'js.layout',
              props: { label: 'Page' },
              children: ['default-placeholder'],
              slots: {
                sidebar: ['sidebar-placeholder'],
                empty: ['empty-placeholder'],
              },
            },
            'default-placeholder': {
              type: 'canvas-slot-placeholder',
              props: {},
              children: [],
            },
            'sidebar-placeholder': {
              type: 'canvas-slot-placeholder',
              props: {},
              children: [],
            },
            'empty-placeholder': {
              type: 'canvas-slot-placeholder',
              props: {},
              children: [],
            },
          },
        },
        components,
        preview: true,
      },
    });

    expect(html).toContain('canvas-slot-start-layout-one/default');
    expect(html).toContain('canvas-slot-start-layout-one/sidebar');
    expect(html).toContain('canvas-slot-start-layout-one/empty');
    expect(html.match(/canvas--slot-empty-placeholder/g)).toHaveLength(3);
    expect(html).not.toContain('canvas-slot-placeholder');
  });

  it('keeps an explicit preview region transparent in published output', async () => {
    const container = await AstroContainer.create();
    const html = await container.renderToString(CanvasComponentTree, {
      props: {
        tree: {
          root: 'region',
          elements: {
            region: {
              type: 'canvas-preview-content-region',
              props: {},
              children: ['article-one'],
            },
            'article-one': component('js.article', 'Content'),
          },
        },
        components,
      },
    });

    expect(html).toBe('<span>Content</span>');
    expect(html).not.toContain('canvas-region-start-content');
  });

  it('reports and omits an unregistered Canvas component', async () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});
    const container = await AstroContainer.create();

    const html = await container.renderToString(CanvasComponentTree, {
      props: {
        tree: tree([['missing-one', component('js.missing', 'Missing')]]),
        components: {},
      },
    });

    expect(html).toBe('');
    expect(error).toHaveBeenCalledWith(
      expect.stringContaining(
        'Canvas component "missing" (instance "missing-one") is not registered',
      ),
    );
    error.mockRestore();
  });
});

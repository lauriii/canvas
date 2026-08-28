import { experimental_AstroContainer as AstroContainer } from 'astro/container';
import { render as renderTemplate } from 'astro/runtime/server/index.js';
import { describe, expect, it } from 'vitest';
import {
  CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS,
  formatCanvasCommentMarker,
} from '@drupal-canvas/headless';

import CanvasComponentTree from './CanvasComponentTree.astro';

import type { CanvasComponentTreeElement } from '@drupal-canvas/headless/server';
import type { AstroComponentFactory } from 'astro/runtime/server/index.js';

const CanvasComponentFixture: AstroComponentFactory = (_result, props) =>
  renderTemplate`<span>${props.label}</span>`;
CanvasComponentFixture.isAstroComponentFactory = true;

const components = {
  'site-header': CanvasComponentFixture,
  article: CanvasComponentFixture,
  'site-footer': CanvasComponentFixture,
};

function component(
  element: string,
  canvasUuid: string,
  label: string,
): CanvasComponentTreeElement {
  return {
    element,
    props: { canvasUuid, label },
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

  it('renders the standard empty-region placeholder for an empty draft tree', async () => {
    const container = await AstroContainer.create();
    const html = await container.renderToString(CanvasComponentTree, {
      props: {
        tree: {
          element: 'renderless-container',
          canvasDraftMode: true,
        },
        components: {},
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
        tree: {
          element: 'renderless-container',
          canvasDraftMode: true,
          slots: {
            default: [
              component('js-site-header', 'header-one', 'Header'),
              { element: 'canvas-preview-content-region' },
              component('js-site-footer', 'footer-one', 'Footer'),
            ],
          },
        },
        components,
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

  it('renders children of a synthetic multi-root wrapper in order', async () => {
    const container = await AstroContainer.create();
    const html = await container.renderToString(CanvasComponentTree, {
      props: {
        tree: {
          element: 'renderless-container',
          slots: {
            default: [
              component('js-site-header', 'header-one', 'Header'),
              component('js-article', 'article-one', 'Content'),
              component('js-site-footer', 'footer-one', 'Footer'),
            ],
          },
        },
        components,
      },
    });

    expect(html).toBe(
      '<span>Header</span><span>Content</span><span>Footer</span>',
    );
  });
});

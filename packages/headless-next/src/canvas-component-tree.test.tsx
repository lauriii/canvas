import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';

import { CanvasComponentTree } from './canvas-component-tree';

import type { CanvasComponentTree as CanvasComponentTreeSpec } from '@drupal-canvas/headless';

describe('CanvasComponentTree', () => {
  const tree: CanvasComponentTreeSpec = {
    root: 'server-card-one',
    elements: {
      'server-card-one': {
        type: 'js.server-card',
        props: { title: 'Rendered by Next' },
        children: ['content'],
      },
      content: {
        type: 'drupal-markup',
        props: { markup: '<p>Server-rendered content</p>' },
        children: [],
      },
    },
  };

  it('server-renders generated components and their slots', () => {
    expect(renderToStaticMarkup(<CanvasComponentTree tree={tree} />)).toBe(
      '<article><h2>Rendered by Next</h2><span style="display:contents"><p>Server-rendered content</p></span></article>',
    );
  });

  it('server-renders Canvas markers when preview is enabled', () => {
    const html = renderToStaticMarkup(
      <CanvasComponentTree tree={tree} preview />,
    );

    expect(html).toContain('data-canvas-type="region"');
    expect(html).toContain('data-canvas-uuid="server-card-one"');
  });

  it('server-renders a marker as a Canvas component', () => {
    const markerTree: CanvasComponentTreeSpec = {
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
    };
    const html = renderToStaticMarkup(
      <CanvasComponentTree tree={markerTree} preview />,
    );

    expect(html).toContain('canvas--page-content-marker-placeholder');
    expect(html.match(/data-canvas-uuid="page-content-one"/g)).toHaveLength(2);
  });
});

import { experimental_AstroContainer as AstroContainer } from 'astro/container';
import { render as renderTemplate } from 'astro/runtime/server/index.js';
import { describe, expect, it, vi } from 'vitest';

import CanvasComponentTree from './CanvasComponentTree.astro';

import type { CanvasComponentTree as CanvasComponentTreeSpec } from '@drupal-canvas/headless/server';
import type { AstroComponentFactory } from 'astro/runtime/server/index.js';

vi.mock('virtual:@drupal-canvas/headless/components', async () => {
  const { render } = await import('astro/runtime/server/index.js');
  const GeneratedCard: AstroComponentFactory = (_result, props) =>
    render`<article>${props.title}</article>`;
  GeneratedCard.isAstroComponentFactory = true;
  return { default: { card: GeneratedCard } };
});

const tree: CanvasComponentTreeSpec = {
  root: 'card-one',
  elements: {
    'card-one': {
      type: 'js.card',
      props: { title: 'Hello' },
      children: [],
    },
  },
};

describe('CanvasComponentTree adapter', () => {
  const legacyTree: CanvasComponentTreeSpec = {
    element: 'js-card',
    props: { title: 'Hello', canvasUuid: 'card-one' },
  };

  it.each([
    ['json-render', false, tree],
    ['json-render', true, tree],
    ['Custom Elements', false, legacyTree],
    ['Custom Elements', true, legacyTree],
  ] as const)(
    'renders %s with the generated registry and preview=%s',
    async (_format, preview, content) => {
      const container = await AstroContainer.create();
      const html = await container.renderToString(CanvasComponentTree, {
        props: { tree: content, ...(preview ? { preview } : {}) },
      });

      expect(html).toContain('<article>Hello</article>');
      expect(html.includes('canvas-start-card-one')).toBe(preview);
      expect(html.includes('canvas-region-start-content')).toBe(preview);
    },
  );

  it('forwards an explicit registry instead of the generated registry', async () => {
    const OverrideCard: AstroComponentFactory = (_result, props) =>
      renderTemplate`<aside>${props.title}</aside>`;
    OverrideCard.isAstroComponentFactory = true;
    const container = await AstroContainer.create();

    await expect(
      container.renderToString(CanvasComponentTree, {
        props: { tree, components: { card: OverrideCard } },
      }),
    ).resolves.toBe('<aside>Hello</aside>');
  });
});

import { describe, expect, it, vi } from 'vitest';
import { createSSRApp, defineComponent, h } from 'vue';
import { renderToString } from 'vue/server-renderer';

import CanvasComponentTree from './CanvasComponentTree';

import type { CanvasComponentTree as CanvasComponentTreeSpec } from '@drupal-canvas/headless/server';

vi.mock('virtual:@drupal-canvas/headless/components', async () => {
  const { defineComponent, h } = await import('vue');
  return {
    default: {
      card: defineComponent({
        props: { title: { type: String, required: true } },
        setup: (props) => () => h('article', props.title),
      }),
    },
  };
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
      const app = createSSRApp({
        setup: () => () =>
          h(CanvasComponentTree, {
            tree: content,
            ...(preview ? { preview } : {}),
          }),
      });
      const html = await renderToString(app);

      expect(html).toContain('<article>Hello</article>');
      expect(html.includes('canvas-start-card-one')).toBe(preview);
      expect(html.includes('canvas-region-start-content')).toBe(preview);
    },
  );

  it('forwards an explicit registry instead of the generated registry', async () => {
    const OverrideCard = defineComponent({
      props: { title: { type: String, required: true } },
      setup: (props) => () => h('aside', props.title),
    });
    const app = createSSRApp({
      setup: () => () =>
        h(CanvasComponentTree, {
          tree,
          components: { card: OverrideCard },
        }),
    });
    const html = await renderToString(app);

    expect(html).toContain('<aside>Hello</aside>');
    expect(html).not.toContain('<article>');
  });
});

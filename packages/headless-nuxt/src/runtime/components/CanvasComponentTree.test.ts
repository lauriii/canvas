// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createApp, defineComponent, h, nextTick, ref } from 'vue';

import CanvasComponentTree from './CanvasComponentTree';

import type { CanvasComponentTreeElement } from '@drupal-canvas/headless/server';

vi.mock('virtual:@drupal-canvas/headless/components', () => ({ default: {} }));

const components = {
  'hello-card': defineComponent({
    setup:
      (_props, { slots }) =>
      () =>
        h('article', slots.content?.()),
  }),
  'action-link': defineComponent({
    setup: () => () => h('a', { href: '/' }, 'Action'),
  }),
};

function component(
  element: string,
  uuid: string,
  slots?: CanvasComponentTreeElement['slots'],
): CanvasComponentTreeElement {
  return {
    element,
    props: { canvasUuid: uuid },
    ...(slots ? { slots } : {}),
  };
}

function tree(
  children: CanvasComponentTreeElement[],
): CanvasComponentTreeElement {
  return {
    element: 'drupal-markup',
    canvasDraftMode: true,
    slots: { content: children },
  };
}

function commentMarkers(root: Node): string[] {
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_COMMENT);
  const comments: string[] = [];
  let node = walker.nextNode();
  while (node) {
    const value = node.nodeValue?.trim();
    if (value?.startsWith('canvas-')) {
      comments.push(value);
    }
    node = walker.nextNode();
  }
  return comments;
}

describe('CanvasComponentTree', () => {
  afterEach(() => {
    document.body.replaceChildren();
  });

  it('renders the standard empty-region placeholder for an empty draft tree', () => {
    const container = document.createElement('div');
    document.body.append(container);
    const app = createApp({
      setup: () => () =>
        h(CanvasComponentTree, {
          tree: { element: 'canvas-page', canvasDraftMode: true },
          components: {},
        }),
    });
    app.mount(container);

    const placeholder = container.querySelector<HTMLElement>(
      '.canvas--region-empty-placeholder',
    );
    expect(placeholder?.getAttribute('style')).toBeNull();
    expect(commentMarkers(container)).toEqual([
      'canvas-region-start-content',
      'canvas-region-end-content',
    ]);

    app.unmount();
  });

  it('keeps marker identity when a component moves from a slot to the root', async () => {
    const action = component('js-action-link', 'action-one');
    const firstCard = component('js-hello-card', 'card-one', {
      content: [action],
    });
    const secondCard = component('js-hello-card', 'card-two', {
      content: [],
    });
    const value = ref(tree([firstCard, secondCard]));
    const container = document.createElement('div');
    document.body.append(container);
    const app = createApp({
      setup: () => () =>
        h(CanvasComponentTree, {
          tree: value.value,
          components,
        }),
    });
    app.mount(container);

    value.value = tree([
      component('js-hello-card', 'card-one', { content: [] }),
      secondCard,
      action,
    ]);
    await nextTick();

    const starts = commentMarkers(container).filter((marker) =>
      /^canvas-start-/.test(marker),
    );
    expect(starts).toEqual([
      'canvas-start-card-one',
      'canvas-start-card-two',
      'canvas-start-action-one',
    ]);
    expect(new Set(starts).size).toBe(starts.length);

    app.unmount();
  });
});

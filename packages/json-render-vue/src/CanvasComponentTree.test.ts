// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  createApp,
  createSSRApp,
  defineComponent,
  h,
  nextTick,
  ref,
} from 'vue';
import { renderToString } from 'vue/server-renderer';

import CanvasComponentTree from './CanvasComponentTree';

import type {
  CanvasComponentTreeElement,
  CanvasComponentTreeSlot,
  CanvasComponentTree as CanvasComponentTreeSpec,
  JsonValue,
} from '@drupal-canvas/json-render';

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
  'site-header': defineComponent({
    setup: () => () => h('header', 'Header'),
  }),
  article: defineComponent({
    setup: () => () => h('main', 'Content'),
  }),
  'sdc.article-card': defineComponent({
    props: { title: { type: String, required: true } },
    setup: (props) => () => h('article', props.title),
  }),
  'site-footer': defineComponent({
    setup: () => () => h('footer', 'Footer'),
  }),
  'raw-props': defineComponent({
    props: {
      payload: {
        type: Object,
        required: true,
      },
    },
    setup: (props) => () => h('pre', JSON.stringify(props.payload)),
  }),
  'slot-ignoring': defineComponent({
    setup: () => () => h('section', 'Visible content'),
  }),
};

type TestSlot = string | TestElement | TestSlot[];

interface TestElement {
  uuid?: string;
  element: string;
  props?: Record<string, JsonValue>;
  slots?: Record<string, TestSlot>;
}

function flattenSlot(slot: TestSlot): Array<string | TestElement> {
  return Array.isArray(slot) ? slot.flatMap(flattenSlot) : [slot];
}

function component(
  element: string,
  uuid: string,
  slots?: Record<string, TestSlot>,
): TestElement {
  return {
    element,
    uuid,
    ...(slots ? { slots } : {}),
  };
}

function tree(children: TestElement[]): CanvasComponentTreeSpec {
  const elements: Record<string, CanvasComponentTreeElement> = {};
  let nextId = 0;
  const addElement = (value: string | TestElement): string => {
    const fallbackKey = `el-${nextId++}`;
    const key =
      typeof value === 'string' ? fallbackKey : (value.uuid ?? fallbackKey);
    const element: CanvasComponentTreeElement =
      typeof value === 'string'
        ? { type: 'drupal-markup', props: { markup: value }, children: [] }
        : {
            type: value.element,
            props: value.props ?? {},
            children: [],
          };
    elements[key] = element;
    if (typeof value === 'string') {
      return key;
    }
    for (const [name, slot] of Object.entries(value.slots ?? {})) {
      const childKeys = flattenSlot(slot).map(addElement);
      if (name === 'default' || name === 'children') {
        element.children.push(...childKeys);
      } else {
        const slots: Record<string, CanvasComponentTreeSlot> =
          element.slots ?? {};
        slots[name] = childKeys;
        element.slots = slots;
      }
    }
    return key;
  };
  const root = addElement({
    element: 'renderless-container',
    slots: { content: children },
  });
  return {
    root,
    elements,
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
    vi.restoreAllMocks();
  });

  it('renders empty published content', () => {
    const container = document.createElement('div');
    document.body.append(container);
    const app = createApp({
      setup: () => () =>
        h(CanvasComponentTree, {
          tree: null,
          components: {},
        }),
    });
    app.mount(container);

    expect(container.textContent).toBe('');
    expect(commentMarkers(container)).toEqual([]);

    app.unmount();
  });

  it('renders the standard empty-region placeholder in preview mode', () => {
    const container = document.createElement('div');
    document.body.append(container);
    const app = createApp({
      setup: () => () =>
        h(CanvasComponentTree, {
          tree: { root: '', elements: {} },
          components: {},
          preview: true,
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

  it('renders an empty page content region between page variant chrome', () => {
    const container = document.createElement('div');
    document.body.append(container);
    const app = createApp({
      setup: () => () =>
        h(CanvasComponentTree, {
          tree: tree([
            component('js.site-header', 'header-one'),
            { element: 'canvas-preview-content-region' },
            component('js.site-footer', 'footer-one'),
          ]),
          components,
          preview: true,
        }),
    });
    app.mount(container);

    const html = container.innerHTML;
    const regionStart = html.indexOf('canvas-region-start-content');
    const placeholder = html.indexOf('canvas--region-empty-placeholder');
    const regionEnd = html.indexOf('canvas-region-end-content');
    expect(html.indexOf('</header>')).toBeLessThan(regionStart);
    expect(regionStart).toBeLessThan(placeholder);
    expect(placeholder).toBeLessThan(regionEnd);
    expect(regionEnd).toBeLessThan(html.indexOf('<footer>'));
    expect(commentMarkers(container)).toEqual([
      'canvas-start-header-one',
      'canvas-end-header-one',
      'canvas-region-start-content',
      'canvas-region-end-content',
      'canvas-start-footer-one',
      'canvas-end-footer-one',
    ]);

    app.unmount();
  });

  it('renders children of a synthetic multi-root wrapper in order', () => {
    const container = document.createElement('div');
    document.body.append(container);
    const app = createApp({
      setup: () => () =>
        h(CanvasComponentTree, {
          tree: tree([
            component('js.site-header', 'header-one'),
            component('js.article', 'article-one'),
            component('js.site-footer', 'footer-one'),
          ]),
          components,
        }),
    });
    app.mount(container);

    expect(container.textContent).toBe('HeaderContentFooter');
    expect(commentMarkers(container)).toEqual([]);
    expect(
      container.querySelector('.canvas--region-empty-placeholder'),
    ).toBeNull();
    expect(
      container.querySelector('.canvas--slot-empty-placeholder'),
    ).toBeNull();

    app.unmount();
  });

  it('renders named slots through json-render', () => {
    const container = document.createElement('div');
    document.body.append(container);
    const app = createApp({
      setup: () => () =>
        h(CanvasComponentTree, {
          tree: tree([
            component('js.hello-card', 'card-one', {
              content: component('js.action-link', 'action-one'),
            }),
          ]),
          components,
          preview: true,
        }),
    });
    app.mount(container);

    expect(container.querySelector('article a')?.textContent).toBe('Action');
    expect(commentMarkers(container)).toEqual([
      'canvas-region-start-content',
      'canvas-start-card-one',
      'canvas-slot-start-card-one/content',
      'canvas-start-action-one',
      'canvas-end-action-one',
      'canvas-slot-end-card-one/content',
      'canvas-end-card-one',
      'canvas-region-end-content',
    ]);

    app.unmount();
  });

  it('renders component types registered by their exact catalog key', () => {
    const container = document.createElement('div');
    document.body.append(container);
    const app = createApp({
      setup: () => () =>
        h(CanvasComponentTree, {
          tree: tree([
            {
              element: 'sdc.article-card',
              props: { title: 'Article' },
            },
          ]),
          components,
        }),
    });
    app.mount(container);

    expect(container.innerHTML).toContain('<article>Article</article>');

    app.unmount();
  });

  it('server-renders published content and named slots', async () => {
    const app = createSSRApp({
      setup: () => () =>
        h(CanvasComponentTree, {
          tree: tree([
            component('js.hello-card', 'card-one', {
              content: component('js.action-link', 'action-one'),
            }),
          ]),
          components,
        }),
    });

    expect(await renderToString(app)).toBe(
      '<!--[--><!--[--><article><a href="/">Action</a></article><!--]--><!--]-->',
    );
  });

  it('server-renders Canvas markers when preview is enabled', async () => {
    const app = createSSRApp({
      setup: () => () =>
        h(CanvasComponentTree, {
          tree: tree([
            component('js.hello-card', 'card-one', {
              content: component('js.action-link', 'action-one'),
            }),
          ]),
          components,
          preview: true,
        }),
    });

    const html = await renderToString(app);
    expect(html).toContain('canvas-region-start-content');
    expect(html).toContain('canvas-start-card-one');
    expect(html).toContain('canvas-slot-start-card-one/content');
  });

  it('server-renders a marker as a Canvas component', async () => {
    const app = createSSRApp({
      setup: () => () =>
        h(CanvasComponentTree, {
          tree: tree([
            {
              uuid: 'page-content-one',
              element: 'canvas-marker',
              props: { markerId: 'page_content' },
              slots: {
                default:
                  '<div class="canvas--page-content-marker-placeholder">Page content</div>',
              },
            },
          ]),
          components,
          preview: true,
        }),
    });

    const html = await renderToString(app);
    expect(html).toContain('canvas--page-content-marker-placeholder');
    expect(html).toContain('canvas-start-page-content-one');
    expect(html).toContain('canvas-end-page-content-one');
  });

  it('does not traverse slots that a component does not render', () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});
    const container = document.createElement('div');
    document.body.append(container);
    const app = createApp({
      setup: () => () =>
        h(CanvasComponentTree, {
          tree: tree([
            component('js.slot-ignoring', 'parent-one', {
              unused: component('js.missing-card', 'missing-one'),
            }),
          ]),
          components,
        }),
    });
    app.mount(container);

    expect(container.textContent).toBe('Visible content');
    expect(error).not.toHaveBeenCalled();

    app.unmount();
  });

  it('renders markers for a slot declared by a structural placeholder', () => {
    const container = document.createElement('div');
    document.body.append(container);
    const app = createApp({
      setup: () => () =>
        h(CanvasComponentTree, {
          tree: {
            root: 'card-one',
            elements: {
              'card-one': {
                type: 'js.hello-card',
                props: {},
                children: [],
                slots: { content: ['content-placeholder'] },
              },
              'content-placeholder': {
                type: 'canvas-slot-placeholder',
                props: {},
                children: [],
              },
            },
          },
          components,
          preview: true,
        }),
    });
    app.mount(container);

    expect(commentMarkers(container)).toContain(
      'canvas-slot-start-card-one/content',
    );
    expect(
      container.querySelector('.canvas--slot-empty-placeholder'),
    ).not.toBeNull();
    expect(container.querySelector('canvas-slot-placeholder')).toBeNull();

    app.unmount();
  });

  it('passes Canvas props through without resolving json-render expressions', () => {
    const container = document.createElement('div');
    document.body.append(container);
    const app = createApp({
      setup: () => () =>
        h(CanvasComponentTree, {
          tree: tree([
            {
              uuid: 'raw-one',
              element: 'js.raw-props',
              props: { payload: { $state: '/untrusted-as-an-expression' } },
            },
          ]),
          components,
        }),
    });
    app.mount(container);

    expect(container.textContent).toBe(
      '{"$state":"/untrusted-as-an-expression"}',
    );

    app.unmount();
  });

  it('reports and omits an unregistered Canvas component', () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});
    const container = document.createElement('div');
    document.body.append(container);
    const app = createApp({
      setup: () => () =>
        h(CanvasComponentTree, {
          tree: tree([component('js.missing-card', 'missing-one')]),
          components,
        }),
    });
    app.mount(container);

    expect(container.textContent).toBe('');
    expect(error).toHaveBeenCalledWith(
      '[canvas] Canvas component "missing-card" (instance "missing-one") is not registered; omitted subtree at "elements.missing-one".',
    );

    app.unmount();
  });

  it('keeps marker identity when a component moves from a slot to the root', async () => {
    const action = component('js.action-link', 'action-one');
    const firstCard = component('js.hello-card', 'card-one', {
      content: [action],
    });
    const secondCard = component('js.hello-card', 'card-two', {
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
          preview: true,
        }),
    });
    app.mount(container);

    value.value = tree([
      component('js.hello-card', 'card-one', { content: [] }),
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

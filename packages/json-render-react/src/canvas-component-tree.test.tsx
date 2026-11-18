// @vitest-environment jsdom

import { act, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { CanvasComponentTree } from './canvas-component-tree';

import type {
  CanvasComponentTreeElement,
  CanvasComponentTree as CanvasComponentTreeSpec,
  JsonValue,
} from '@drupal-canvas/json-render';

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

function spec(rootElement: TestElement): CanvasComponentTreeSpec {
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
        element.slots = { ...element.slots, [name]: childKeys };
      }
    }
    return key;
  };

  return {
    root: addElement(rootElement),
    elements,
  };
}

describe('CanvasComponentTree', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('reports and omits an unregistered component subtree', () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});
    const html = renderToStaticMarkup(
      <CanvasComponentTree
        tree={spec({
          element: 'canvas-page',
          slots: {
            content: [
              {
                uuid: 'missing-instance',
                element: 'js.missing-card',
                props: {},
                slots: {
                  default: {
                    element: 'js.registered-card',
                    props: { label: 'Nested content' },
                  },
                },
              },
              {
                element: 'js.registered-card',
                props: { label: 'Sibling content' },
              },
            ],
          },
        })}
        components={{
          'registered-card': ({ label }: { label: string }) => <p>{label}</p>,
        }}
      />,
    );

    expect(html).toBe('<p>Sibling content</p>');
    expect(error).toHaveBeenCalledWith(
      '[canvas] Canvas component "missing-card" (instance "missing-instance") is not registered; omitted subtree at "elements.el-0:content:0".',
    );
  });

  it.each(['hello-card', 'hello_card', 'HeroBanner'])(
    'keeps published markup marker-free for js.%s',
    (name) => {
      const tree = spec({
        uuid: 'card-one',
        element: `js.${name}`,
        props: { label: 'Hello' },
      });
      const components = {
        [name]: ({ label }: { label: string }) => <article>{label}</article>,
      };

      expect(
        renderToStaticMarkup(
          <CanvasComponentTree tree={tree} components={components} />,
        ),
      ).toBe('<article>Hello</article>');
    },
  );

  it('renders a marker as a structural Canvas component', () => {
    const tree = spec({
      uuid: 'page-content-one',
      element: 'canvas-marker',
      props: { markerId: 'page_content' },
      slots: {
        default:
          '<div class="canvas--page-content-marker-placeholder">Page content</div>',
      },
    });

    const preview = renderToStaticMarkup(
      <CanvasComponentTree preview tree={tree} components={{}} />,
    );
    expect(preview).toContain('canvas--page-content-marker-placeholder');
    expect(preview.match(/data-canvas-uuid="page-content-one"/g)).toHaveLength(
      2,
    );
    expect(
      renderToStaticMarkup(<CanvasComponentTree tree={tree} components={{}} />),
    ).toBe(
      '<span style="display:contents"><div class="canvas--page-content-marker-placeholder">Page content</div></span>',
    );
  });

  it('renders empty published content', () => {
    expect(
      renderToStaticMarkup(<CanvasComponentTree tree={null} components={{}} />),
    ).toBe('');
  });

  it('renders children of a synthetic multi-root wrapper in order', () => {
    expect(
      renderToStaticMarkup(
        <CanvasComponentTree
          tree={spec({
            element: 'renderless-container',
            slots: {
              children: [
                { uuid: 'header-one', element: 'js.site-header', props: {} },
                {
                  uuid: 'article-one',
                  element: 'js.article',
                  props: { title: 'Hello' },
                },
                { uuid: 'footer-one', element: 'js.site-footer', props: {} },
              ],
            },
          })}
          components={{
            'site-header': () => <header>Header</header>,
            article: ({ title }: { title: string }) => <main>{title}</main>,
            'site-footer': () => <footer>Footer</footer>,
          }}
        />,
      ),
    ).toBe('<header>Header</header><main>Hello</main><footer>Footer</footer>');
  });

  it('keeps a string slot default in published output', () => {
    expect(
      renderToStaticMarkup(
        <CanvasComponentTree
          tree={spec({
            element: 'js.card',
            slots: {
              default: {
                element: 'drupal-markup',
                props: { markup: '<p>Default example content</p>' },
              },
            },
          })}
          components={{
            card: ({ children }: { children?: React.ReactNode }) => (
              <article>{children}</article>
            ),
          }}
        />,
      ),
    ).toContain('Default example content');
  });

  it('maps named slots to React component props', () => {
    const html = renderToStaticMarkup(
      <CanvasComponentTree
        tree={spec({
          uuid: 'card-one',
          element: 'js.card',
          props: {},
          slots: {
            heading: '<h2>Heading</h2>',
            default: '<p>Content</p>',
          },
        })}
        components={{
          card: ({
            children,
            heading,
          }: {
            children?: React.ReactNode;
            heading?: React.ReactNode;
          }) => (
            <article>
              <header>{heading}</header>
              {children}
            </article>
          ),
        }}
      />,
    );

    expect(html).toBe(
      '<article><header><span style="display:contents"><h2>Heading</h2></span></header><span style="display:contents"><p>Content</p></span></article>',
    );
  });

  it.each(['js.card', 'sdc.article-card'])(
    'renders component types registered by their exact catalog key %s',
    (type) => {
      expect(
        renderToStaticMarkup(
          <CanvasComponentTree
            tree={spec({
              element: type,
              props: { title: 'Article' },
            })}
            components={{
              [type]: ({ title }: { title: string }) => (
                <article>{title}</article>
              ),
            }}
          />,
        ),
      ).toBe('<article>Article</article>');
    },
  );

  it('passes json-render expression-shaped props through unchanged', () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});
    const businessData = {
      $state: '/this-is-application-data',
    };
    let received: unknown;

    renderToStaticMarkup(
      <CanvasComponentTree
        tree={spec({
          element: 'js.card',
          props: { businessData },
        })}
        components={{
          card: (props: { businessData: unknown }) => {
            received = props.businessData;
            return null;
          },
        }}
      />,
    );

    expect(received).toEqual(businessData);
    expect(error).not.toHaveBeenCalled();
  });

  it.each(['js.card', 'js.HeroBanner'])(
    'renders component, empty slot, and top-level region boundaries in draft mode for %s',
    (type) => {
      const html = renderToStaticMarkup(
        <CanvasComponentTree
          preview
          tree={spec({
            uuid: 'card-one',
            element: type,
            props: {},
            slots: {
              default: {
                element: 'drupal-markup',
                props: { markup: '<p>Default example content</p>' },
              },
            },
          })}
          components={{
            [type.slice(3)]: ({ children }: { children?: React.ReactNode }) => (
              <article>{children}</article>
            ),
          }}
        />,
      );

      expect(html).toContain(
        '<template data-canvas-marker="start" data-canvas-type="region" data-canvas-region-id="content"></template>',
      );
      expect(html).toContain(
        '<template data-canvas-marker="start" data-canvas-type="component" data-canvas-uuid="card-one"></template>',
      );
      expect(html).toContain(
        '<template data-canvas-marker="start" data-canvas-type="slot" data-canvas-slot-name="default" data-canvas-uuid="card-one/default"></template>',
      );
      expect(html).toContain('class="canvas--slot-empty-placeholder"');
      expect(html).not.toContain(
        'class="canvas--slot-empty-placeholder" style=',
      );
      expect(html).not.toContain('Default example content');
      expect(html).toContain(
        '<template data-canvas-marker="end" data-canvas-type="slot" data-canvas-slot-name="default" data-canvas-uuid="card-one/default"></template>',
      );
      expect(html).toContain(
        '<template data-canvas-marker="end" data-canvas-type="component" data-canvas-uuid="card-one"></template>',
      );
      expect(html).toContain(
        '<template data-canvas-marker="end" data-canvas-type="region" data-canvas-region-id="content"></template>',
      );
    },
  );

  it('renders markers for slots declared by structural placeholders', () => {
    const tree: CanvasComponentTreeSpec = {
      root: 'card-one',
      elements: {
        'card-one': {
          type: 'js.card',
          props: {},
          children: ['default-placeholder'],
          slots: { sidebar: ['sidebar-placeholder'] },
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
      },
    };
    const components = {
      card: ({
        children,
        sidebar,
      }: {
        children?: React.ReactNode;
        sidebar?: React.ReactNode;
      }) => (
        <article>
          <main>{children}</main>
          <aside>{sidebar}</aside>
        </article>
      ),
    };
    const html = renderToStaticMarkup(
      <CanvasComponentTree preview tree={tree} components={components} />,
    );

    expect(html).toContain('data-canvas-uuid="card-one/default"');
    expect(html).toContain('data-canvas-uuid="card-one/sidebar"');
    expect(html.match(/canvas--slot-empty-placeholder/g)).toHaveLength(2);
    expect(
      renderToStaticMarkup(
        <CanvasComponentTree tree={tree} components={components} />,
      ),
    ).toBe('<article><main></main><aside></aside></article>');
  });

  it.each(['', ' \n', '<h2>SDC page content</h2>'])(
    'renders the top-level preview region for markup %j',
    (markup) => {
      const html = renderToStaticMarkup(
        <CanvasComponentTree
          preview
          tree={
            markup === ''
              ? { root: '', elements: {} }
              : spec({ element: 'drupal-markup', props: { markup } })
          }
          components={{}}
        />,
      );

      expect(html).toContain(
        '<template data-canvas-marker="start" data-canvas-type="region" data-canvas-region-id="content"></template>',
      );
      expect(html.includes('class="canvas--region-empty-placeholder"')).toBe(
        markup.trim() === '',
      );
      if (markup.trim() !== '') {
        expect(html).toContain(markup);
      }
      expect(html).not.toContain(
        'class="canvas--region-empty-placeholder" style=',
      );
      expect(html).toContain(
        '<template data-canvas-marker="end" data-canvas-type="region" data-canvas-region-id="content"></template>',
      );
    },
  );

  it.each(['', ' \n', '<h2>SDC page content</h2>'])(
    'renders the page content region between page variant chrome for markup %j',
    (markup) => {
      const html = renderToStaticMarkup(
        <CanvasComponentTree
          preview
          tree={spec({
            element: 'renderless-container',
            slots: {
              default: [
                { uuid: 'header', element: 'js.site-header', props: {} },
                {
                  element: 'canvas-preview-content-region',
                  slots: {
                    default: [{ element: 'drupal-markup', props: { markup } }],
                  },
                },
                { uuid: 'footer', element: 'js.site-footer', props: {} },
              ],
            },
          })}
          components={{
            'site-header': () => <header>Header</header>,
            'site-footer': () => <footer>Footer</footer>,
          }}
        />,
      );

      const regionStart = html.indexOf('data-canvas-type="region"');
      const placeholder = html.indexOf('canvas--region-empty-placeholder');
      const regionEnd = html.lastIndexOf('data-canvas-type="region"');
      expect(html.indexOf('</header>')).toBeLessThan(regionStart);
      if (markup.trim() === '') {
        expect(regionStart).toBeLessThan(placeholder);
        expect(placeholder).toBeLessThan(regionEnd);
      } else {
        expect(placeholder).toBe(-1);
        expect(html).toContain(markup);
        expect(regionStart).toBeLessThan(html.indexOf(markup));
        expect(html.indexOf(markup)).toBeLessThan(regionEnd);
      }
      expect(regionEnd).toBeLessThan(html.indexOf('<footer>'));
      expect(html.match(/data-canvas-type="region"/g)).toHaveLength(2);
    },
  );

  it('renders registered non-Canvas components without editor markers', () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});
    const html = renderToStaticMarkup(
      <CanvasComponentTree
        preview
        tree={spec({ element: 'CustomCard' })}
        components={{ CustomCard: () => <article>Card</article> }}
      />,
    );

    expect(html).toContain('<article>Card</article>');
    expect(html).not.toContain('data-canvas-type="component"');
    expect(error).toHaveBeenCalledWith(
      '[canvas] Canvas component "CustomCard" has no instance UUID; editor markers were omitted at "elements.el-0".',
    );
  });

  it('reports exact paths for identical non-Canvas components', () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});

    renderToStaticMarkup(
      <CanvasComponentTree
        preview
        tree={spec({
          element: 'canvas-page',
          slots: {
            content: [
              { element: 'CustomCard', props: { label: 'Same' } },
              { element: 'CustomCard', props: { label: 'Same' } },
            ],
          },
        })}
        components={{ CustomCard: () => null }}
      />,
    );

    expect(error).toHaveBeenNthCalledWith(
      1,
      '[canvas] Canvas component "CustomCard" has no instance UUID; editor markers were omitted at "elements.el-0:content:0".',
    );
    expect(error).toHaveBeenNthCalledWith(
      2,
      '[canvas] Canvas component "CustomCard" has no instance UUID; editor markers were omitted at "elements.el-0:content:1".',
    );
  });

  it('preserves component identity when siblings are reordered', async () => {
    let nextInstance = 0;
    const Card = ({ label }: { label: string }) => {
      const [instance] = useState(() => ++nextInstance);
      return <article data-label={label} data-instance={instance} />;
    };
    const tree = (order: string[]) =>
      spec({
        element: 'canvas-page',
        slots: {
          content: order.map((label) => ({
            element: 'js.card',
            uuid: `card-${label}`,
            props: { label },
          })),
        },
      });
    const container = document.createElement('div');
    const root = createRoot(container);

    await act(async () => {
      root.render(
        <CanvasComponentTree
          preview
          tree={tree(['one', 'two'])}
          components={{ card: Card }}
        />,
      );
    });
    const initialInstances = Object.fromEntries(
      Array.from(container.querySelectorAll('article')).map((element) => [
        element.getAttribute('data-label'),
        element.getAttribute('data-instance'),
      ]),
    );

    await act(async () => {
      root.render(
        <CanvasComponentTree
          preview
          tree={tree(['two', 'one'])}
          components={{ card: Card }}
        />,
      );
    });
    const reorderedInstances = Object.fromEntries(
      Array.from(container.querySelectorAll('article')).map((element) => [
        element.getAttribute('data-label'),
        element.getAttribute('data-instance'),
      ]),
    );

    expect(reorderedInstances).toEqual(initialInstances);

    await act(async () => {
      root.render(
        <CanvasComponentTree
          preview
          tree={spec({
            uuid: 'root-one',
            element: 'js.card',
            props: { label: 'root' },
          })}
          components={{ card: Card }}
        />,
      );
    });
    const firstRootInstance = container
      .querySelector('article')
      ?.getAttribute('data-instance');

    await act(async () => {
      root.render(
        <CanvasComponentTree
          preview
          tree={spec({
            uuid: 'root-two',
            element: 'js.card',
            props: { label: 'root' },
          })}
          components={{ card: Card }}
        />,
      );
    });
    expect(
      container.querySelector('article')?.getAttribute('data-instance'),
    ).not.toBe(firstRootInstance);

    await act(async () => root.unmount());
  });
});
